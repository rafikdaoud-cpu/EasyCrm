<?php
namespace OCA\EasyCrm\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
/**
 * WhatsApp : conversations et messages.
 * Règle d'attribution : client connu (fiche avec commercial) → ce commercial ;
 * numéro inconnu ou fiche non assignée → file d'attente du superviseur.
 * Le propriétaire effectif d'une conversation rattachée à une fiche est toujours le commercial de la fiche
 * (un transfert de fiches emmène donc les conversations).
 */
class Wa {
	public const WINDOW = 86400; // fenêtre de réponse libre de Meta (24 h)
	public function __construct(private IDBConnection $db, private IConfig $cfg) {}

	public function conf(string $k, string $def = ''): string {
		$v = trim((string)$this->cfg->getAppValue('easycrm', $k, ''));
		return $v !== '' ? $v : $def;
	}
	/** Numéro international WhatsApp (chiffres) → format national des fiches, selon le pays réglé ; null si invalide */
	public static function localPhone(string $raw): ?string {
		if (self::isBsuid($raw)) { return null; }
		$d = preg_replace('/\D+/', '', Lookup::latin($raw));
		return $d === '' ? null : Phone::normalize('+' . $d);
	}
	/** Numéro national ou international → identifiant WhatsApp (chiffres internationaux) */
	public static function waId(string $raw): string {
		$t = trim($raw);
		if (self::isBsuid($t)) { return $t; }
		$d = preg_replace('/\D+/', '', Lookup::latin($t));
		if ($d === '' || str_starts_with($t, '+')) { return $d; }
		if (str_starts_with($d, '00')) { return substr($d, 2); }
		$n = Phone::normalize($t);
		return $n !== null ? Phone::whatsapp($n) : $d;
	}

	/** Identifiant WhatsApp sans numéro (client avec nom d'utilisateur), ex. DZ.1828231388191250 */
	public static function isBsuid(string $s): bool { return (bool)preg_match('/^[A-Z]{2}\.[A-Za-z0-9.]{1,140}$/', trim($s)); }

	// ---------- lecture ----------
	public function conv(int $id): ?array {
		$qb = $this->base();
		$qb->where($qb->expr()->eq('c.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		return $r ? $this->decorate($r) : null;
	}
	public function convByWaId(string $waId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_wa_conv')->where($qb->expr()->eq('wa_id', $qb->createNamedParameter($waId)))->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	public function convByBsuid(string $b): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_wa_conv')->where($qb->expr()->eq('bsuid', $qb->createNamedParameter($b)))->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	public function convForProspect(int $pid, ?string $phone): ?array {
		$qb = $this->base();
		$or = [$qb->expr()->eq('c.prospect_id', $qb->createNamedParameter($pid, IQueryBuilder::PARAM_INT))];
		if ($phone) { $or[] = $qb->expr()->eq('c.phone', $qb->createNamedParameter($phone)); }
		$qb->where($qb->expr()->orX(...$or))->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ? $this->decorate($r) : null;
	}
	private function base(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.*')->selectAlias('p.assigned_to', 'p_assigned')->selectAlias('p.lastname', 'p_lastname')->selectAlias('p.firstname', 'p_firstname')->selectAlias('p.status', 'p_status')->selectAlias('p.id', 'p_id')
			->from('easycrm_wa_conv', 'c')->leftJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		return $qb;
	}
	/** Propriétaire effectif + état de la fenêtre de 24 h */
	private function decorate(array $r): array {
		$linked = !empty($r['p_id']);
		$r['owner'] = $linked ? ($r['p_assigned'] ?: null) : ($r['assigned_to'] ?: null);
		$r['linked'] = $linked;
		if (!$linked) { $r['prospect_id'] = null; }
		$r['window_open'] = !empty($r['last_in_at']) && (int)$r['last_in_at'] > time() - self::WINDOW;
		$r['window_ends'] = !empty($r['last_in_at']) ? (int)$r['last_in_at'] + self::WINDOW : null;
		return $r;
	}
	/** @param string $box mine|queue|all|open|resolved ; $uid = utilisateur restreint (commercial) ou null */
	private function filter(IQueryBuilder $qb, ?string $uid, string $box, string $q, string $user): void {
		$e = $qb->expr();
		$owner = fn(string $u) => $e->orX($e->andX($e->isNotNull('p.id'), $e->eq('p.assigned_to', $qb->createNamedParameter($u))),
			$e->andX($e->isNull('p.id'), $e->eq('c.assigned_to', $qb->createNamedParameter($u))));
		if ($uid !== null) { $qb->andWhere($owner($uid)); }
		if ($box === 'queue') {
			$qb->andWhere($e->orX($e->andX($e->isNotNull('p.id'), $e->isNull('p.assigned_to')), $e->andX($e->isNull('p.id'), $e->isNull('c.assigned_to'))));
		}
		if ($box === 'open' || $box === 'mine' || $box === 'queue') { $qb->andWhere($e->eq('c.status', $qb->createNamedParameter('ouvert'))); }
		if ($box === 'resolved') { $qb->andWhere($e->eq('c.status', $qb->createNamedParameter('resolu'))); }
		if ($user !== '') { $qb->andWhere($owner($user)); }
		if ($q !== '') {
			$l = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($q) . '%');
			$qb->andWhere($e->orX($e->like('c.name', $l), $e->like('c.phone', $l), $e->like('c.wa_id', $l), $e->like('p.lastname', $l), $e->like('p.firstname', $l), $e->like('c.last_preview', $l)));
		}
	}
	public function search(?string $uid, string $box, string $q, string $user, int $limit, int $off): array {
		$qb = $this->base();
		$this->filter($qb, $uid, $box, $q, $user);
		$qb->orderBy('c.last_msg_at', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit)->setFirstResult($off);
		return array_map(fn($r) => $this->decorate($r), $qb->executeQuery()->fetchAll());
	}
	public function count(?string $uid, string $box, string $q = '', string $user = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('easycrm_wa_conv', 'c')->leftJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		$this->filter($qb, $uid, $box, $q, $user);
		return (int)$qb->executeQuery()->fetchOne();
	}
	/** Somme des messages non lus des conversations visibles */
	public function unread(?string $uid, string $box = 'all'): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('c.unread'))->from('easycrm_wa_conv', 'c')->leftJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		$this->filter($qb, $uid, $box, '', '');
		return (int)$qb->executeQuery()->fetchOne();
	}
	public function messages(int $convId, int $limit = 300): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_wa_msg')->where($qb->expr()->eq('conv_id', $qb->createNamedParameter($convId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults($limit);
		return array_reverse($qb->executeQuery()->fetchAll());
	}
	public function message(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_wa_msg')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	/** Message déjà enregistré (Meta renvoie parfois le même webhook) */
	public function known(string $wamid): bool { return $wamid !== '' && $this->msgExists($wamid); }
	private function msgExists(string $wamid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('easycrm_wa_msg')->where($qb->expr()->eq('wamid', $qb->createNamedParameter($wamid)))->setMaxResults(1);
		return (bool)$qb->executeQuery()->fetch();
	}
	/** Fiche du numéro : numéro national exact, sinon les 8 derniers chiffres (fiches saisies dans un autre format) */
	private function prospectFor(?string $phone, string $waId): ?array {
		if ($phone) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'assigned_to')->from('easycrm_prospects')->where($qb->expr()->eq('phone', $qb->createNamedParameter($phone)))->setMaxResults(1);
			if ($r = $qb->executeQuery()->fetch()) { return $r; }
		}
		if ($waId === '' || self::isBsuid($waId)) { return null; }
		$t = substr($waId, -8);
		if (strlen($t) < 8) { return null; }
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'assigned_to')->from('easycrm_prospects')->where($qb->expr()->like('phone', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($t))))->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}

	// ---------- écriture ----------
	private function set(string $table, int $id, array $v): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table);
		foreach ($v as $k => $x) { $qb->set($k, $qb->createNamedParameter($x, $x === null ? IQueryBuilder::PARAM_NULL : (is_int($x) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR))); }
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
	private function insert(string $table, array $v): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($v as $k => $x) { $qb->setValue($k, $qb->createNamedParameter($x, $x === null ? IQueryBuilder::PARAM_NULL : (is_int($x) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR))); }
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}
	public function updateConv(int $id, array $v): void { $this->set('easycrm_wa_conv', $id, $v); }

	/**
	 * Conversation du client, créée si besoin et rattachée à la fiche du même numéro.
	 * $waId : numéro international (peut être vide si le client a un nom d'utilisateur) ; $bsuid : identifiant WhatsApp (BSUID).
	 */
	public function ensureConv(string $waId, string $name, string $bsuid = ''): array {
		$c = $waId !== '' ? $this->convByWaId($waId) : null;
		if (!$c && $bsuid !== '') { $c = $this->convByBsuid($bsuid) ?? $this->convByWaId($bsuid); }
		$phone = $waId !== '' ? self::localPhone($waId) : null;
		if (!$c) {
			$p = $waId !== '' ? $this->prospectFor($phone, $waId) : null;
			$key = $waId !== '' ? $waId : $bsuid;
			$this->insert('easycrm_wa_conv', ['wa_id' => $key, 'phone' => $phone, 'name' => mb_substr($name, 0, 255) ?: null, 'bsuid' => $bsuid !== '' ? $bsuid : null,
				'prospect_id' => $p ? (int)$p['id'] : null, 'assigned_to' => null, 'status' => 'ouvert', 'unread' => 0, 'created_at' => time()]);
			return $this->convByWaId($key) ?? [];
		}
		$upd = [];
		if ($name !== '' && $name !== ($c['name'] ?? '')) { $upd['name'] = mb_substr($name, 0, 255); }
		if ($bsuid !== '' && empty($c['bsuid'])) { $upd['bsuid'] = $bsuid; }
		// le numéro est apparu (client avec nom d'utilisateur qui nous a donné son numéro) : la conversation prend le numéro
		if ($waId !== '' && self::isBsuid((string)$c['wa_id']) && !$this->convByWaId($waId)) { $upd['wa_id'] = $waId; $upd['phone'] = $phone; }
		$wk = $waId !== '' ? $waId : (self::isBsuid((string)$c['wa_id']) ? '' : (string)$c['wa_id']);
		if (empty($c['prospect_id']) && ($wk !== '' || !empty($c['phone'])) && ($p = $this->prospectFor($phone ?? $c['phone'], $wk))) { $upd['prospect_id'] = (int)$p['id']; }
		if ($upd) { $this->updateConv((int)$c['id'], $upd); $c = array_merge($c, $upd); }
		return $c;
	}
	public static function preview(string $type, string $body, string $file): string {
		$icon = ['image' => '📷 Photo', 'document' => '📄 ' . ($file ?: 'Document'), 'audio' => '🎤 Vocal', 'video' => '🎬 Vidéo', 'sticker' => 'Autocollant', 'location' => '📍 Position'][$type] ?? '';
		$t = trim(($icon !== '' ? $icon . ($body !== '' ? ' · ' : '') : '') . $body);
		return mb_substr($t !== '' ? $t : '(' . $type . ')', 0, 250);
	}
	/**
	 * Enregistre un message (entrant du service WhatsApp, ou sortant). Idempotent sur wamid.
	 * @return array{conv:int,msg:?int,created:bool}
	 */
	public function record(array $ev): array {
		$waId = trim((string)($ev['wa_id'] ?? '')) !== '' ? self::waId((string)$ev['wa_id']) : '';
		$bsuid = trim((string)($ev['user_id'] ?? ''));
		if (!self::isBsuid($bsuid)) { $bsuid = ''; }
		if (self::isBsuid($waId)) { $bsuid = $bsuid ?: $waId; $waId = ''; }
		if ($waId === '' && $bsuid === '') { throw new \InvalidArgumentException('wa_id manquant'); }
		$wamid = trim((string)($ev['wamid'] ?? ''));
		$c = $this->ensureConv($waId, trim((string)($ev['name'] ?? '')), $bsuid);
		$cid = (int)$c['id'];
		if ($wamid !== '' && $this->msgExists($wamid)) { return ['conv' => $cid, 'msg' => null, 'created' => false]; }
		$dir = ($ev['direction'] ?? 'in') === 'out' ? 'out' : 'in';
		$type = preg_replace('/[^a-z_]/', '', (string)($ev['type'] ?? 'text')) ?: 'text';
		$body = mb_substr((string)($ev['text'] ?? ''), 0, 8000);
		$ts = (int)($ev['ts'] ?? 0); if ($ts <= 0 || $ts > time() + 300) { $ts = time(); }
		$fname = mb_substr((string)($ev['file_name'] ?? ''), 0, 255);
		$mid = $this->insert('easycrm_wa_msg', ['conv_id' => $cid, 'wamid' => $wamid !== '' ? $wamid : null, 'direction' => $dir, 'type' => mb_substr($type, 0, 20), 'body' => $body,
			'file_path' => ($ev['file_path'] ?? '') !== '' ? mb_substr((string)$ev['file_path'], 0, 512) : null, 'file_name' => $fname !== '' ? $fname : null,
			'mime' => ($ev['mime'] ?? '') !== '' ? mb_substr((string)$ev['mime'], 0, 100) : null,
			'user_id' => ($ev['user'] ?? '') !== '' ? mb_substr((string)$ev['user'], 0, 64) : null, 'status' => $dir === 'out' ? (string)($ev['status'] ?? 'sent') : null, 'created_at' => $ts]);
		$upd = ['last_preview' => ($dir === 'out' ? '↪ ' : '') . self::preview($type, $body, $fname)];
		if ((int)($c['last_msg_at'] ?? 0) <= $ts) { $upd['last_msg_at'] = $ts; }
		if ($dir === 'in') {
			$upd['unread'] = (int)($c['unread'] ?? 0) + 1;
			$upd['status'] = 'ouvert';
			if ((int)($c['last_in_at'] ?? 0) < $ts) { $upd['last_in_at'] = $ts; }
		} elseif ((int)($c['last_out_at'] ?? 0) < $ts) { $upd['last_out_at'] = $ts; }
		$this->updateConv($cid, $upd);
		return ['conv' => $cid, 'msg' => $mid, 'created' => true];
	}
	/** Statut de livraison d'un message sortant (sent, delivered, read, failed) */
	public function setStatus(string $wamid, string $status, string $error = ''): bool {
		$rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 9];
		if (!isset($rank[$status]) || $wamid === '') { return false; }
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'status', 'body')->from('easycrm_wa_msg')->where($qb->expr()->eq('wamid', $qb->createNamedParameter($wamid)))->setMaxResults(1);
		$m = $qb->executeQuery()->fetch();
		if (!$m) { return false; }
		if ($status !== 'failed' && ($rank[$m['status']] ?? 0) >= $rank[$status]) { return true; }
		$v = ['status' => $status];
		if ($status === 'failed' && $error !== '') { $v['body'] = trim((string)$m['body'] . "\n⚠ Non délivré : " . mb_substr($error, 0, 300)); }
		$this->set('easycrm_wa_msg', (int)$m['id'], $v);
		return true;
	}
	/** Fiches recréées ou importées après coup : rattache les conversations orphelines au numéro. */
	public function relinkPhone(string $phone, int $pid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('easycrm_wa_conv')->set('prospect_id', $qb->createNamedParameter($pid, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('phone', $qb->createNamedParameter($phone)))->andWhere($qb->expr()->isNull('prospect_id'))->executeStatement();
	}
	/** Délai médian (secondes) de première réponse et volumes par commercial depuis $from */
	public function stats(int $from, int $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.user_id')->selectAlias($qb->func()->count('*'), 'n')->from('easycrm_wa_msg', 'm')
			->where($qb->expr()->eq('m.direction', $qb->createNamedParameter('out')))->andWhere($qb->expr()->isNotNull('m.user_id'))
			->andWhere($qb->expr()->gte('m.created_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('m.created_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))->groupBy('m.user_id');
		$out = []; foreach ($qb->executeQuery()->fetchAll() as $r) { $out[$r['user_id']] = (int)$r['n']; }
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('easycrm_wa_msg')->where($qb->expr()->eq('direction', $qb->createNamedParameter('in')))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		return ['received' => (int)$qb->executeQuery()->fetchOne(), 'sent_by' => $out];
	}
}
