<?php
namespace OCA\CallCrm\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
class Store {
	public const RESULTS = ['interesse', 'injoignable', 'rappel', 'pas_interesse', 'non_eligible', 'faux_numero'];
	public function __construct(private IDBConnection $db) {}

	private function where(IQueryBuilder $qb, ?string $uid, array $f): void {
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($uid))); }
		if (!empty($f['status'])) { $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($f['status']))); }
		if (!empty($f['assigned'])) { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($f['assigned']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->in('c2', $qb->createNamedParameter((array)$f['cat'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (isset($f['users']) && is_array($f['users'])) { $qb->andWhere($qb->expr()->in('assigned_to', $qb->createNamedParameter($f['users'] ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (!empty($f['rappels'])) {
			$qb->andWhere($qb->expr()->isNotNull('callback_at'));
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter(\OCA\CallCrm\Service\Statuses::callbackKeys() ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY)));
		}
		foreach (['reg' => 'created_at', 'proc' => 'last_call_at'] as $k => $col) {
			if (!empty($f[$k . '_from'])) { $qb->andWhere($qb->expr()->gte($col, $qb->createNamedParameter((int)$f[$k . '_from'], IQueryBuilder::PARAM_INT))); }
			if (!empty($f[$k . '_to'])) { $qb->andWhere($qb->expr()->lt($col, $qb->createNamedParameter((int)$f[$k . '_to'], IQueryBuilder::PARAM_INT))); }
		}
		if (!empty($f['q'])) {
			$l = '%' . $this->db->escapeLikeParameter($f['q']) . '%';
			$or = $qb->expr()->orX();
			foreach (empty($f['nophone']) ? ['lastname', 'firstname', 'email', 'phone'] : ['lastname', 'firstname', 'email'] as $c) { $or->add($qb->expr()->like($c, $qb->createNamedParameter($l))); }
			$qb->andWhere($or);
		}
	}
	public function search(?string $uid, array $f, int $limit, int $off): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_prospects');
		$this->where($qb, $uid, $f);
		$cols = ['nom' => 'lastname', 'date' => 'created_at', 'statut' => 'status', 'wilaya' => 'c5', 'categorie' => 'c2', 'appel' => 'last_call_at', 'rappel' => 'callback_at'];
		if (!empty($f['sort']) && isset($cols[$f['sort']])) {
			$qb->orderBy($cols[$f['sort']], ($f['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC')->addOrderBy('id', 'DESC');
		} elseif (!empty($f['rappels'])) { $qb->orderBy('callback_at', 'ASC'); } else { $qb->orderBy('id', !empty($f['asc']) ? 'ASC' : 'DESC'); }
		$qb->setMaxResults($limit)->setFirstResult($off);
		return $qb->executeQuery()->fetchAll();
	}
	public function count(?string $uid, array $f): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('callcrm_prospects');
		$this->where($qb, $uid, $f);
		return (int)$qb->executeQuery()->fetchOne();
	}
	public function get(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_prospects')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	public function calls(int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_calls')->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($id)))->orderBy('id', 'DESC');
		return $qb->executeQuery()->fetchAll();
	}
	public function insert(string $table, array $v): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($v as $k => $x) { $qb->setValue($k, $qb->createNamedParameter($x)); }
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}
	public function update(int $id, array $v): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('callcrm_prospects');
		foreach ($v as $k => $x) { $qb->set($k, $qb->createNamedParameter($x)); }
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}
	public function delete(int $id): void {
		foreach (['callcrm_recordings' => 'prospect_id', 'callcrm_calls' => 'prospect_id', 'callcrm_prospects' => 'id'] as $t => $c) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($t)->where($qb->expr()->eq($c, $qb->createNamedParameter($id)))->executeStatement();
		}
	}
	public function prospectByPhone(string $p): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_prospects')->where($qb->expr()->eq('phone', $qb->createNamedParameter($p)))->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	/** Dernier appel saisi par ce commercial pour ce prospect, entre 10 min avant et 6 h après le début de l'enregistrement */
	public function nearestCall(string $uid, int $prospectId, int $ts): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('callcrm_calls')
			->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($prospectId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($ts - 600, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($ts + 21600, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ? (int)$r['id'] : null;
	}
	public function recordingExists(string $uid, string $title, int $size): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('callcrm_recordings')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->andWhere($qb->expr()->eq('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT)))->setMaxResults(1);
		return (bool)$qb->executeQuery()->fetch();
	}
	public function getRecording(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_recordings')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	public function setRecordingFile(int $id, string $file): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('callcrm_recordings')->set('file', $qb->createNamedParameter($file))->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}
	public function recordingsFor(int $prospectId, ?string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_recordings')->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($prospectId)))->orderBy('recorded_at', 'DESC');
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid))); }
		return $qb->executeQuery()->fetchAll();
	}
	public function unmatchedRecordings(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_recordings')->where($qb->expr()->isNull('prospect_id'))->orderBy('recorded_at', 'DESC')->setMaxResults($limit);
		return $qb->executeQuery()->fetchAll();
	}
	public function phoneExists(string $p): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('callcrm_prospects')->where($qb->expr()->eq('phone', $qb->createNamedParameter($p)))->setMaxResults(1);
		return (bool)$qb->executeQuery()->fetch();
	}
	private function logWhere(IQueryBuilder $qb, ?string $uid, array $f): void {
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid))); }
		if (!empty($f['user'])) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($f['user']))); }
		if (!empty($f['result'])) { $qb->andWhere($qb->expr()->eq('c.result', $qb->createNamedParameter($f['result']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->in('p.c2', $qb->createNamedParameter((array)$f['cat'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (isset($f['users']) && is_array($f['users'])) { $qb->andWhere($qb->expr()->in('c.user_id', $qb->createNamedParameter($f['users'] ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (!empty($f['from'])) { $qb->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter((int)$f['from'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['to'])) { $qb->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter((int)$f['to'], IQueryBuilder::PARAM_INT))); }
		$q = trim((string)($f['q'] ?? ''));
		if ($q !== '') {
			$l = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($q) . '%');
			$or = [];
			foreach (empty($f['nophone']) ? ['p.lastname', 'p.firstname', 'p.phone', 'c.note', 'c.user_id'] : ['p.lastname', 'p.firstname', 'c.note', 'c.user_id'] as $col) { $or[] = $qb->expr()->like($col, $l); }
			if (!empty($f['qusers'])) { $or[] = $qb->expr()->in('c.user_id', $qb->createNamedParameter($f['qusers'], IQueryBuilder::PARAM_STR_ARRAY)); }
			$qb->andWhere($qb->expr()->orX(...$or));
		}
	}
	public function callLog(?string $uid, array $f, int $limit, int $off): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.user_id', 'c.result', 'c.note', 'c.callback_at', 'c.created_at', 'c.prospect_id', 'c.reason', 'p.lastname', 'p.firstname', 'p.phone', 'p.c2')
			->from('callcrm_calls', 'c')->innerJoin('c', 'callcrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		$cols = ['date' => 'c.created_at', 'user' => 'c.user_id', 'client' => 'p.lastname', 'phone' => 'p.phone', 'cat' => 'p.c2', 'result' => 'c.result', 'note' => 'c.note'];
		$dir = ($f['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
		if (!empty($f['sort']) && isset($cols[$f['sort']])) { $qb->orderBy($cols[$f['sort']], $dir)->addOrderBy('c.id', 'DESC'); }
		else { $qb->orderBy('c.id', 'DESC'); }
		$qb->setMaxResults($limit)->setFirstResult($off);
		$this->logWhere($qb, $uid, $f);
		return $qb->executeQuery()->fetchAll();
	}
	public function callLogCount(?string $uid, array $f): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('callcrm_calls', 'c')->innerJoin('c', 'callcrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		$this->logWhere($qb, $uid, $f);
		return (int)$qb->executeQuery()->fetchOne();
	}
	/** Prospects assignés à quelqu'un qui n'est plus commercial (id, c2, status). */
	public function orphans(array $valid, bool $onlyNew): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'c2', 'status')->from('callcrm_prospects')
			->where($qb->expr()->isNotNull('assigned_to'))
			->andWhere($qb->expr()->notIn('assigned_to', $qb->createNamedParameter($valid, IQueryBuilder::PARAM_STR_ARRAY)));
		if ($onlyNew) { $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('nouveau'))); }
		return $qb->orderBy('id', 'ASC')->executeQuery()->fetchAll();
	}
	/** Identifiants des prospects d'un commercial (ou non assignés si $from === '') filtrés par statut, les plus anciens d'abord. */
	public function idsFor(string $from, string $status, int $limit = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('callcrm_prospects');
		if ($from === '') { $qb->where($qb->expr()->isNull('assigned_to')); } else { $qb->where($qb->expr()->eq('assigned_to', $qb->createNamedParameter($from))); }
		if ($status !== '') { $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status))); }
		$qb->orderBy('id', 'ASC');
		if ($limit > 0) { $qb->setMaxResults($limit); }
		return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'id'));
	}
	/** Réassigne une liste de prospects ; retourne le nombre de lignes modifiées. */
	public function reassign(array $ids, string $to): int {
		$n = 0;
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('callcrm_prospects')->set('assigned_to', $qb->createNamedParameter($to))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$n += $qb->executeStatement();
		}
		return $n;
	}
	/** Prospects par statut(s) (vide = tous) et par commercial ('__all' = tous, '' = non assignés, sinon identifiant). */
	public function idsByStatus(array $statuses, string $assigned, int $limit = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('callcrm_prospects')->where($qb->expr()->neq('id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		if ($statuses) { $qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY))); }
		if ($assigned === '') { $qb->andWhere($qb->expr()->isNull('assigned_to')); }
		elseif ($assigned !== '__all') { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($assigned))); }
		$qb->orderBy('id', 'ASC');
		if ($limit > 0) { $qb->setMaxResults($limit); }
		return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'id'));
	}
	/** Change le statut en masse (rappel effacé, compteur de tentatives remis à zéro) ; $assign : false = inchangé, null = non assigné, sinon identifiant. */
	public function setStatus(array $ids, string $status, $assign = false): int {
		$n = 0; $now = time();
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('callcrm_prospects')->set('status', $qb->createNamedParameter($status))
				->set('callback_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('reset_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
			if ($assign !== false) { $qb->set('assigned_to', $qb->createNamedParameter($assign, $assign === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)); }
			$qb->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$n += $qb->executeStatement();
		}
		return $n;
	}
	/** Nombre d'appels de ce prospect dont le résultat demande un rappel (statuts « avec date de rappel »). */
	public function callbackCalls(int $prospectId): int {
		$keys = \OCA\CallCrm\Service\Statuses::callbackKeys();
		if (!$keys) { return 0; }
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('callcrm_calls')
			->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($prospectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('result', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
		$ra = (int)($this->get($prospectId)['reset_at'] ?? 0);
		if ($ra > 0) { $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($ra, IQueryBuilder::PARAM_INT))); }
		return (int)$qb->executeQuery()->fetchOne();
	}
	/** Journal d'activité (transferts, clôtures automatiques, exports, imports…). */
	public function audit(?string $uid, string $action, ?int $pid = null, string $detail = ''): void {
		try { $this->insert('callcrm_audit', ['created_at' => time(), 'user_id' => $uid, 'action' => $action, 'prospect_id' => $pid, 'detail' => mb_substr($detail, 0, 4000)]); }
		catch (\Throwable $e) { /* le journal ne doit jamais bloquer l'action */ }
	}
	public function auditList(int $limit, int $off, string $action = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('callcrm_audit')->orderBy('id', 'DESC')->setMaxResults($limit)->setFirstResult($off);
		if ($action !== '') { $qb->where($qb->expr()->eq('action', $qb->createNamedParameter($action))); }
		return $qb->executeQuery()->fetchAll();
	}
	public function auditCount(string $action = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('callcrm_audit');
		if ($action !== '') { $qb->where($qb->expr()->eq('action', $qb->createNamedParameter($action))); }
		return (int)$qb->executeQuery()->fetchOne();
	}
	/** Rappels dont l'heure est dépassée (statuts « avec date de rappel »). */
	public function overdueCount(?string $uid, int $now): int {
		$keys = \OCA\CallCrm\Service\Statuses::callbackKeys();
		if (!$keys) { return 0; }
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('callcrm_prospects')
			->where($qb->expr()->isNotNull('callback_at'))->andWhere($qb->expr()->lt('callback_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($uid))); }
		return (int)$qb->executeQuery()->fetchOne();
	}
	/** Nombre d'appels passés depuis $since, par commercial (user_id => n). */
	public function callsSince(?string $uid, int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')->selectAlias($qb->func()->count('*'), 'n')->from('callcrm_calls')->groupBy('user_id')
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid))); }
		$o = []; foreach ($qb->executeQuery()->fetchAll() as $r) { $o[$r['user_id']] = (int)$r['n']; }
		return $o;
	}
	/** Motifs de refus (motif => nombre d'appels) sur la même sélection que le journal. */
	public function reasonCounts(?string $uid, array $f): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.reason')->selectAlias($qb->func()->count('*'), 'n')->from('callcrm_calls', 'c')
			->innerJoin('c', 'callcrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'))
			->groupBy('c.reason')->orderBy('n', 'DESC');
		$this->logWhere($qb, $uid, $f);
		$qb->andWhere($qb->expr()->isNotNull('c.reason'))->andWhere($qb->expr()->neq('c.reason', $qb->createNamedParameter('')));
		return $qb->executeQuery()->fetchAll();
	}
	public function unassigned(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'c2')->from('callcrm_prospects')->where($qb->expr()->isNull('assigned_to'))->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('nouveau')))->orderBy('id', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}
	public function statusCounts(?string $uid, array $f = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')->selectAlias($qb->func()->count('*'), 'n')->from('callcrm_prospects')->groupBy('status');
		$this->where($qb, $uid, $f);
		if (!empty($f['from'])) { $qb->andWhere($qb->expr()->gte('last_call_at', $qb->createNamedParameter((int)$f['from'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['to'])) { $qb->andWhere($qb->expr()->lt('last_call_at', $qb->createNamedParameter((int)$f['to'], IQueryBuilder::PARAM_INT))); }
		return $qb->executeQuery()->fetchAll();
	}
	public function callCounts(?string $uid, array $f = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.user_id', 'c.result')->selectAlias($qb->func()->count('*'), 'n')->from('callcrm_calls', 'c')
			->innerJoin('c', 'callcrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'))->groupBy('c.user_id', 'c.result');
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid))); }
		if (!empty($f['user'])) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($f['user']))); }
		if (!empty($f['result'])) { $qb->andWhere($qb->expr()->eq('c.result', $qb->createNamedParameter($f['result']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->in('p.c2', $qb->createNamedParameter((array)$f['cat'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (isset($f['users']) && is_array($f['users'])) { $qb->andWhere($qb->expr()->in('c.user_id', $qb->createNamedParameter($f['users'] ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (!empty($f['from'])) { $qb->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter((int)$f['from'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['to'])) { $qb->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter((int)$f['to'], IQueryBuilder::PARAM_INT))); }
		return $qb->executeQuery()->fetchAll();
	}
	/** Horodatages des appels (pour le graphique par heure). */
	public function callTimes(?string $uid, array $f): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.created_at')->from('callcrm_calls', 'c')->innerJoin('c', 'callcrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'))->setMaxResults(200000);
		$this->logWhere($qb, $uid, $f);
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}
	public function callCountsOld(?string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'result')->selectAlias($qb->func()->count('*'), 'n')->from('callcrm_calls')->groupBy('user_id', 'result');
		if ($uid !== null) { $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid))); }
		return $qb->executeQuery()->fetchAll();
	}
}
