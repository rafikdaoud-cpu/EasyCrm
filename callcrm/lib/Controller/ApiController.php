<?php
namespace OCA\CallCrm\Controller;
use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\Access;
use OCA\CallCrm\Service\Fields;
use OCA\CallCrm\Service\Prefs;
use OCA\CallCrm\Service\Sim;
use OCA\CallCrm\Service\Statuses;
use OCA\CallCrm\Service\Lookup;
use OCA\CallCrm\Service\Store;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\IUserManager;
use OCP\ITempManager;
use OCP\AppFramework\Http\DataDownloadResponse;
class ApiController extends Controller {
	private const STATUS = ['nouveau', 'interesse', 'injoignable', 'rappel', 'pas_interesse', 'non_eligible', 'faux_numero'];
	private const OPT = ['c2' => Lookup::CATEGORIES, 'c3' => Lookup::EMPLOIS];
	private const LAB = ['nouveau' => 'Nouveau', 'interesse' => 'Intéressé', 'injoignable' => 'Ne répond pas', 'rappel' => 'À rappeler (occupé)', 'pas_interesse' => 'Pas intéressé', 'non_eligible' => 'Non éligible', 'faux_numero' => 'Injoignable / faux numéro'];
	public function __construct(IRequest $r, private Access $a, private Store $s, private IConfig $cfg, private IGroupManager $gm, private IUserManager $um, private ITempManager $tm, private IAppData $appData, private IMailer $mailer, private IURLGenerator $urlGen) {
		parent::__construct(Application::APP_ID, $r);
	}
	private function err(string $m, int $c = Http::STATUS_BAD_REQUEST): JSONResponse { return new JSONResponse(['error' => $m], $c); }
	private function deny(): JSONResponse { return $this->err('Accès refusé', Http::STATUS_FORBIDDEN); }
	/** Profil lecture seule : numéro partiellement masqué (0555****56). */
	private function maskPhone(?string $p): ?string {
		if ($p === null || $p === '' || !$this->a->can('mask')) { return $p; }
		$n = mb_strlen($p);
		return $n <= 6 ? str_repeat('*', $n) : mb_substr($p, 0, 4) . str_repeat('*', $n - 6) . mb_substr($p, -2);
	}
	private function maskRows(array $rows): array {
		if (!$this->a->can('mask')) { return $rows; }
		foreach ($rows as &$r) { if (array_key_exists('phone', $r)) { $r['phone'] = $this->maskPhone($r['phone']); } if (array_key_exists('email', $r)) { $r['email'] = ''; } }
		return $rows;
	}
	private function scope(): ?string { return $this->a->can('all') ? null : $this->a->uid(); }
	private function getLabels(): array { return ['Sexe', 'Catégorie produit', 'Type d’emploi', 'Salaire', 'Wilaya']; }
	/** Retrouve la valeur de liste correspondante (sans tenir compte de la casse ni des accents), sinon chaîne vide */
	private function canon(string $v, array $opts): string {
		$k = function ($s) { $s = mb_strtolower(trim($s)); return class_exists('Normalizer') ? preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D)) : $s; };
		$alias = ['h' => 'homme', 'm' => 'homme', 'f' => 'femme'];
		$x = $k($v); $x = $alias[$x] ?? $x;
		foreach ($opts as $o) { if ($k($o) === $x) { return $o; } }
		return '';
	}
	private function getTeams(): array { return $this->memoTeams ??= $this->cached('teams', fn() => $this->computeTeams()); }
	private function computeTeams(): array {
		$com = array_column($this->commerciaux(), 'uid'); $out = [];
		foreach (Access::teams() as $gid => $name) {
			$g = $this->gm->get($gid);
			$out[$gid] = $g ? array_values(array_intersect(array_map(fn($u) => $u->getUID(), array_values($g->getUsers())), $com)) : [];
		}
		return $out;
	}
	private function getTeamsOld3(): array {
		$com = array_column($this->commerciaux(), 'uid'); $out = [];
		foreach (Access::cats() as $cat => $gid) {
			$g = $this->gm->get($gid);
			$out[$cat] = $g ? array_values(array_intersect(array_map(fn($u) => $u->getUID(), array_values($g->getUsers())), $com)) : [];
		}
		return $out;
	}
	/** Numéro algérien : mobile 05/06/07 + 8 chiffres, ou fixe 02/03/04/09 ; sinon null */
	private function normPhone(string $raw): ?string {
		$d = preg_replace('/\D+/', '', Lookup::latin($raw));
		if (str_starts_with($d, '00213')) { $d = substr($d, 5); } elseif (str_starts_with($d, '213') && strlen($d) >= 11) { $d = substr($d, 3); }
		if ($d !== '' && $d[0] !== '0') { $d = '0' . $d; }
		return (preg_match('/^0[567]\d{8}$/', $d) || preg_match('/^0[2349]\d{7,8}$/', $d)) ? $d : null;
	}
	private function filters(): array {
		$list = fn($k) => array_values(array_filter(explode('|', (string)$this->request->getParam($k, ''))));
		$res = (string)$this->request->getParam('result', '');
		$f = ['result' => in_array($res, Statuses::keys(), true) ? $res : '', 'cat' => array_values(array_intersect($list('cat'), Lookup::CATEGORIES)),
			'from' => (int)$this->request->getParam('from', 0), 'to' => (int)$this->request->getParam('to', 0), 'user' => '', 'assigned' => '', 'users' => null];
		if ($this->a->can('all')) {
			$u = $list('user'); $m = null;
			$gids = array_values(array_filter($list('group'), fn($g) => isset(Access::teams()[$g])));
			if ($gids) {
				$m = [];
				foreach ($gids as $gid) { $g = $this->gm->get($gid); if ($g) { foreach ($g->getUsers() as $x) { $m[] = $x->getUID(); } } }
				$m = array_values(array_unique($m));
			}
			if ($u && $m !== null) { $f['users'] = array_values(array_intersect($u, $m)); } elseif ($u) { $f['users'] = $u; } elseif ($m !== null) { $f['users'] = $m; }
		}
		return $f;
	}
	private function filtersOld(): array {
		$p = fn($k) => (string)$this->request->getParam($k, '');
		$res = $p('result'); $cat = $p('cat'); $gid = $p('group');
		$f = ['result' => in_array($res, Statuses::keys(), true) ? $res : '', 'cat' => in_array($cat, self::OPT['c2'], true) ? $cat : '',
			'from' => (int)$p('from'), 'to' => (int)$p('to'), 'user' => '', 'assigned' => '', 'users' => null];
		if ($this->a->can('all')) {
			$f['user'] = $f['assigned'] = $p('user');
			if (in_array($gid, Access::cats(), true)) {
				$g = $this->gm->get($gid);
				$f['users'] = $g ? array_values(array_map(fn($u) => $u->getUID(), array_values($g->getUsers()))) : [];
			}
		}
		return $f;
	}
	private function getTeamsOld(): array {
		$t = json_decode($this->cfg->getAppValue(Application::APP_ID, 'teams', '{}'), true);
		return is_array($t) ? $t : [];
	}
	/** Choisit le commercial : 'auto' = tour de rôle, 'categorie' = équipe de la catégorie, sinon uid fixe */
	/** 'auto' = tous les commerciaux ; 'categorie' = groupe de la catégorie du prospect ; 'grp:<id>' = un groupe précis */
	private function pick(string $mode, string $cat, array $team, array $teams, array &$rr): ?string {
		if ($mode === '') { return null; }
		if ($mode === 'auto') { $rr['*'] = ($rr['*'] ?? -1) + 1; return $team[$rr['*'] % count($team)]; }
		$gid = $mode === 'categorie' ? (Access::cats()[$cat] ?? null) : (str_starts_with($mode, 'grp:') ? substr($mode, 4) : null);
		if ($gid === null) { return null; }
		$t = array_values(array_intersect($teams[$gid] ?? [], $team));
		if (!$t) { return null; }
		$rr[$gid] = ($rr[$gid] ?? -1) + 1;
		return $t[$rr[$gid] % count($t)];
	}
	private function pickOld(string $mode, string $cat, array $team, array $teams, array &$rr): ?string {
		if ($mode === '') { return null; }
		if (str_starts_with($mode, 'cat:')) { $cat = substr($mode, 4); $mode = 'categorie'; }
		if ($mode === 'auto') { $rr['*'] = ($rr['*'] ?? -1) + 1; return $team[$rr['*'] % count($team)]; }
		if ($mode === 'categorie') {
			$t = array_values(array_intersect($teams[$cat] ?? [], $team));
			if (!$t) { return null; }
			$rr[$cat] = ($rr[$cat] ?? -1) + 1;
			return $t[$rr[$cat] % count($t)];
		}
		return $mode;
	}
	private function getLabelsOld(): array {
		$l = json_decode($this->cfg->getAppValue(Application::APP_ID, 'labels', '[]'), true) ?: [];
		$out = [];
		for ($i = 0; $i < 5; $i++) { $out[] = trim((string)($l[$i] ?? '')) ?: 'Champ ' . ($i + 1); }
		return $out;
	}
	private ?array $memoCom = null;
	private ?array $memoTeams = null;
	/** Cache court (2 min) : l'énumération des membres de groupes LDAP est lente. */
	private function cached(string $key, callable $fn): array {
		try { $c = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('callcrm'); } catch (\Throwable $e) { $c = null; }
		if ($c) { $v = $c->get($key); if (is_string($v)) { $a = json_decode($v, true); if (is_array($a)) { return $a; } } }
		$a = $fn();
		if ($c) { $c->set($key, json_encode($a), 120); }
		return $a;
	}
	private function commerciaux(): array {
		return $this->memoCom ??= $this->cached('commerciaux', function () {
			$g = $this->gm->get(Access::groups()['commercial']);
			if (!$g) { return []; }
			return array_values(array_map(fn($u) => ['uid' => $u->getUID(), 'name' => $u->getDisplayName()], $g->getUsers()));
		});
	}
	/** Commerciaux choisis dans l'interface (uids), restreints aux vrais commerciaux ; null si aucun. */
	private function pickedTeam(): ?array {
		$raw = $this->request->getParam('uids');
		if (is_string($raw)) { $j = json_decode($raw, true); $raw = is_array($j) ? $j : array_filter(explode('|', $raw)); }
		if (!is_array($raw)) { return null; }
		$t = array_values(array_intersect(array_column($this->commerciaux(), 'uid'), array_map('strval', $raw)));
		return $t ?: null;
	}
	/** Retourne le prospect si l'utilisateur a le droit de le voir */
	private function visible(int $id): ?array {
		$p = $this->s->get($id);
		if (!$p) { return null; }
		$sc = $this->scope();
		return ($sc === null || $p['assigned_to'] === $sc) ? $p : null;
	}

	#[NoAdminRequired]
	public function me(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$can = [];
		foreach (['call', 'assign', 'create', 'import', 'invite', 'fields', 'labels', 'delete', 'all', 'audit', 'export', 'export_phone'] as $k) { $can[$k] = $this->a->can($k); }
		return new JSONResponse(['statuses' => Statuses::all(), 'fields' => Fields::all(), 'role' => $this->a->role(), 'uid' => $this->a->uid(), 'labels' => $this->getLabels(), 'options' => self::OPT + ['c5' => array_keys(Lookup::WILAYAS)], 'teams' => $this->getTeams(), 'groups' => array_map(fn($g, $n) => ['id' => $g, 'name' => $n, 'cats' => array_keys(array_filter(Access::cats(), fn($x) => $x === $g))], array_keys(Access::teams()), array_values(Access::teams())), 'commerciaux' => $this->commerciaux(), 'max_callbacks' => $this->maxCallbacks(), 'prefs' => Prefs::all(), 'can' => $can]);
	}

	#[NoAdminRequired]
	public function prospects(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$st = (string)$this->request->getParam('status', '');
		$f = ['q' => trim((string)$this->request->getParam('q', '')), 'status' => in_array($st, Statuses::keys(), true) ? $st : '',
			'assigned' => $this->a->can('all') ? (string)$this->request->getParam('assigned', '') : '', 'rappels' => (bool)$this->request->getParam('rappels', false), 'asc' => (bool)$this->request->getParam('asc', false),
			'reg_from' => (int)$this->request->getParam('reg_from', 0), 'reg_to' => (int)$this->request->getParam('reg_to', 0), 'proc_from' => (int)$this->request->getParam('proc_from', 0), 'proc_to' => (int)$this->request->getParam('proc_to', 0)];
		$off = max(0, (int)$this->request->getParam('offset', 0));
		$f['sort'] = (string)$this->request->getParam('sort', ''); $f['dir'] = (string)$this->request->getParam('dir', '');
		if ($this->a->can('mask')) { $f['nophone'] = true; }
		if ($this->request->getParam('paged')) {
			$lim = (int)$this->request->getParam('limit', 10); if (!in_array($lim, [10, 20, 30, 0], true)) { $lim = 10; }
			$eff = $lim === 0 ? 5000 : $lim; if ($lim === 0) { $off = 0; }
			return new JSONResponse(['rows' => $this->maskRows($this->s->search($this->scope(), $f, $eff, $off)), 'total' => $this->s->count($this->scope(), $f), 'limit' => $lim]);
		}
		return new JSONResponse($this->maskRows($this->s->search($this->scope(), $f, 50, $off)));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$role = $this->a->role();
		$recs = in_array($role, ['admin', 'superviseur'], true) ? $this->s->recordingsFor($id, null) : ($role === 'commercial' ? $this->s->recordingsFor($id, $this->a->uid()) : []);
		foreach ($recs as &$r) { $r['user_name'] = $this->um->getDisplayName($r['user_id']) ?: $r['user_id']; unset($r['file']); }
		unset($r);
		$ex = !empty($p['extra']) ? json_decode((string)$p['extra'], true) : null; $p['extra'] = is_array($ex) ? $ex : (object)[];
		if ($this->a->can('mask')) { $p['phone'] = $this->maskPhone($p['phone']); $p['email'] = ''; $p['remarque'] = ''; }
		return new JSONResponse(['prospect' => $p, 'calls' => $this->s->calls($id), 'recordings' => $recs, 'sims' => $this->s->simsFor($id)]);
	}

	#[NoAdminRequired]
	public function call(int $id): JSONResponse {
		if (!$this->a->can('call')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		if ($this->a->role() === 'commercial' && $p['assigned_to'] !== $this->a->uid()) { return $this->deny(); }
		$res = (string)$this->request->getParam('result', '');
		$note = trim((string)$this->request->getParam('note', ''));
		$reason = trim((string)$this->request->getParam('reason', ''));
		$cb = $this->request->getParam('callback_at');
		$cb = ($cb === null || $cb === '' ) ? null : (int)$cb;
		if (!in_array($res, Statuses::results(), true)) { return $this->err('Résultat invalide'); }
		$sd = Statuses::get($res);
		$now = time();
		// instant réel de l'appel (saisie hors ligne puis synchronisée) : au plus 2 jours en arrière
		$at = (int)$this->request->getParam('at', 0);
		$ref = ($at > $now - 172800 && $at <= $now) ? $at : $now;
		if ($sd['callback'] && !$cb) { return $this->err('Date et heure de rappel obligatoires'); }
		if ($sd['callback'] && $cb && ($e = Prefs::checkCallback($cb, $ref))) { return $this->err($e); }
		if ($sd['note'] && $note === '') { return $this->err('Une note est obligatoire pour « ' . $sd['label'] . ' »'); }
		if (!empty($sd['reason'])) {
			if ($reason === '' || !in_array($reason, Prefs::all()['reasons'], true)) { return $this->err('Un motif est obligatoire pour « ' . $sd['label'] . ' »'); }
		} else { $reason = ''; }
		if (!$sd['callback']) { $cb = null; }
		$this->s->insert('callcrm_calls', ['prospect_id' => $id, 'user_id' => $this->a->uid(), 'result' => $res, 'note' => $note, 'reason' => $reason !== '' ? $reason : null, 'callback_at' => $cb, 'created_at' => $now]);
		$max = $this->maxCallbacks(); $auto = false; $n = 0;
		if ($sd['callback'] && $max > 0) {
			$n = $this->s->callbackCalls($id);
			if ($n >= $max) {
				$auto = true;
				$res = 'faux_numero'; $cb = null;
				$note = trim($note . ' [Auto] ' . $n . ' tentative(s) sans succès : passé en « ' . (Statuses::get('faux_numero')['label'] ?? 'Injoignable / faux numéro') . ' ».');
				$this->s->audit($this->a->uid(), 'auto_close', $id, $n . ' tentative(s) sans succès (limite ' . $max . ') → faux numéro');
			}
		}
		$this->s->update($id, ['status' => $res, 'callback_at' => $cb, 'last_note' => $note, 'last_call_at' => $now]);
		return new JSONResponse(['ok' => true, 'auto_closed' => $auto, 'attempts' => $n, 'max' => $max]);
	}

	#[NoAdminRequired]
	public function assign(int $id): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$uid = trim((string)$this->request->getParam('uid', ''));
		if ($uid !== '' && !in_array($uid, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Utilisateur non commercial'); }
		if (!$this->s->get($id)) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$old = $this->s->get($id);
		$this->s->update($id, ['assigned_to' => $uid === '' ? null : $uid]);
		$this->s->audit($this->a->uid(), 'assign', $id, ($old['assigned_to'] ?? '—') . ' → ' . ($uid === '' ? '—' : $uid));
		return new JSONResponse(['ok' => true]);
	}

	private function maxCallbacks(): int { return Prefs::all()['max_callbacks']; }

	#[NoAdminRequired]
	public function saveCallPrefs(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$in = [];
		foreach (['max_callbacks', 'cb_from', 'cb_to', 'cb_gap', 'goal', 'tz', 'reasons'] as $k) { $v = $this->request->getParam($k); if ($v !== null) { $in[$k] = $v; } }
		try { $r = Prefs::save($in); } catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
		$this->s->audit($this->a->uid(), 'settings', null, 'Réglages des appels modifiés');
		return new JSONResponse($r + ['prefs' => $r]);
	}

	/** Compteurs de la barre : rappels en retard, appels du jour et objectif. */
	#[NoAdminRequired]
	public function counters(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$p = Prefs::all();
		$sc = $this->a->role() === 'commercial' ? $this->a->uid() : $this->scope();
		$d = (new \DateTime('now', new \DateTimeZone($p['tz'])))->setTime(0, 0, 0);
		return new JSONResponse(['overdue' => $this->s->overdueCount($sc, time()), 'today' => $this->a->role() === 'commercial' ? ($this->s->callsSince($this->a->uid(), $d->getTimestamp())[$this->a->uid()] ?? 0) : null, 'goal' => $p['goal']]);
	}

	/** Alerte doublon à la saisie : le numéro existe-t-il déjà ? */
	#[NoAdminRequired]
	public function duplicate(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$ph = $this->normPhone(trim((string)$this->request->getParam('phone', '')));
		$ex = $this->request->getParam('except'); 
		if ($ph === null) { return new JSONResponse(['exists' => false]); }
		$r = $this->s->prospectByPhone($ph);
		if (!$r || ($ex !== null && (int)$ex === (int)$r['id'])) { return new JSONResponse(['exists' => false]); }
		$mine = $this->scope() === null || $r['assigned_to'] === $this->a->uid();
		return new JSONResponse(['exists' => true, 'id' => $mine ? (int)$r['id'] : null, 'name' => $mine ? trim(($r['lastname'] ?? '') . ' ' . ($r['firstname'] ?? '')) : null,
			'assigned' => $mine && $r['assigned_to'] ? ($this->um->getDisplayName($r['assigned_to']) ?: $r['assigned_to']) : null, 'status' => $mine ? $r['status'] : null]);
	}

	/** Journal d'activité (admin, superviseur). */
	#[NoAdminRequired]
	public function audit(): JSONResponse {
		if (!$this->a->can('audit')) { return $this->deny(); }
		$act = (string)$this->request->getParam('action', '');
		$lim = in_array((int)$this->request->getParam('limit', 20), [10, 20, 30, 100], true) ? (int)$this->request->getParam('limit', 20) : 20;
		$off = max(0, (int)$this->request->getParam('offset', 0));
		$rows = $this->s->auditList($lim, $off, $act);
		foreach ($rows as &$r) { $r['user_name'] = $r['user_id'] ? ($this->um->getDisplayName($r['user_id']) ?: $r['user_id']) : ''; }
		unset($r);
		return new JSONResponse(['rows' => $rows, 'total' => $this->s->auditCount($act)]);
	}

	/** Transfert en masse : de $from (commercial, ou '' = non assignés) vers $to, filtré par statut, avec quantité max. */
	#[NoAdminRequired]
	public function transfer(): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$from = trim((string)$this->request->getParam('from', '__none'));
		$to = trim((string)$this->request->getParam('to', ''));
		$st = trim((string)$this->request->getParam('status', 'nouveau'));
		$limit = max(0, (int)$this->request->getParam('limit', 0));
		$dry = (bool)$this->request->getParam('dry', false);
		if ($from === '__none') { $from = ''; }
		if ($st !== '' && !in_array($st, Statuses::keys(), true)) { return $this->err('Statut invalide'); }
		if (!in_array($to, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Choisissez le commercial destinataire'); }
		if (!is_array($this->request->getParam('ids')) && $from === $to) { return $this->err('Le commercial source et le destinataire sont identiques'); }
		$sel = $this->request->getParam('ids');
		if (is_array($sel)) {
			$ids = array_values(array_unique(array_filter(array_map('intval', $sel), fn($x) => $x > 0)));
			if (count($ids) > 2000) { return $this->err('Sélection trop grande (2000 maximum)'); }
			if (!$ids) { return $this->err('Aucun prospect sélectionné'); }
		} else { $ids = $this->s->idsFor($from, $st, $limit); }
		if ($dry) { return new JSONResponse(['count' => count($ids)]); }
		$n = $ids ? $this->s->reassign($ids, $to) : 0;
		$this->s->audit($this->a->uid(), 'transfer', null, $n . ' prospect(s) → ' . $to . (is_array($sel) ? ' (sélection dans la liste)' : ' depuis ' . ($from === '' ? 'non assignés' : $from) . ($st !== '' ? ', statut ' . $st : '')) . ' · ids : ' . implode(',', array_slice($ids, 0, 30)) . (count($ids) > 30 ? '…' : ''));
		return new JSONResponse(['transferred' => $n]);
	}

	/** Changement de statut en masse : sélection (ids) ou filtre (statuts d'origine, commercial) ; option de réaffectation. */
	#[NoAdminRequired]
	public function bulkStatus(): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$to = trim((string)$this->request->getParam('to_status', ''));
		if (!in_array($to, Statuses::keys(), true)) { return $this->err('Choisissez le nouveau statut'); }
		$dry = (bool)$this->request->getParam('dry', false);
		$mode = (string)$this->request->getParam('assign_mode', 'keep');
		$uid = trim((string)$this->request->getParam('assign_to', ''));
		$assign = false;
		if ($mode === 'none') { $assign = null; }
		elseif ($mode === 'user') {
			if (!in_array($uid, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Choisissez le commercial destinataire'); }
			$assign = $uid;
		} elseif ($mode !== 'keep') { return $this->err('Affectation invalide'); }
		$sel = $this->request->getParam('ids');
		if (is_array($sel)) {
			$ids = array_values(array_unique(array_filter(array_map('intval', $sel), fn($x) => $x > 0)));
			if (count($ids) > 2000) { return $this->err('Sélection trop grande (2000 maximum)'); }
			if (!$ids) { return $this->err('Aucun prospect sélectionné'); }
			$from = '';
		} else {
			$fs = $this->request->getParam('from_status', []);
			$fs = is_array($fs) ? array_values(array_filter(array_map('strval', $fs), fn($x) => in_array($x, Statuses::keys(), true))) : [];
			$who = (string)$this->request->getParam('assigned', '__all');
			if ($who !== '__all' && $who !== '' && !in_array($who, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Commercial invalide'); }
			$ids = $this->s->idsByStatus($fs, $who, max(0, (int)$this->request->getParam('limit', 0)));
			$from = ($fs ? implode('+', $fs) : 'tous statuts') . ', ' . ($who === '__all' ? 'tous commerciaux' : ($who === '' ? 'non assignés' : $who));
		}
		if ($dry) { return new JSONResponse(['count' => count($ids)]); }
		$n = $ids ? $this->s->setStatus($ids, $to, $assign) : 0;
		$this->s->audit($this->a->uid(), 'status', null, $n . ' prospect(s) → statut ' . $to . ($assign === false ? '' : ($assign === null ? ', rendus non assignés' : ', affectés à ' . $assign)) . (is_array($sel) ? ' (sélection dans la liste)' : ' depuis ' . $from) . ' · ids : ' . implode(',', array_slice($ids, 0, 30)) . (count($ids) > 30 ? '…' : ''));
		return new JSONResponse(['changed' => $n]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		if (!$this->a->can('delete')) { return $this->deny(); }
		foreach ($this->s->recordingsFor($id, null) as $r) { try { $this->recFolder()->getFile($r['file'])->delete(); } catch (\Throwable $e) { } }
		$pp = $this->s->get($id);
		$this->s->delete($id);
		$this->s->audit($this->a->uid(), 'delete', $id, trim(($pp['lastname'] ?? '') . ' ' . ($pp['firstname'] ?? '')) . ' ' . ($pp['phone'] ?? ''));
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function labels(): JSONResponse {
		if (!$this->a->can('labels')) { return $this->deny(); }
		$l = $this->request->getParam('labels', []);
		$l = is_array($l) ? array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), array_slice(array_values($l), 0, 5)) : [];
		$this->cfg->setAppValue(Application::APP_ID, 'labels', json_encode($l));
		return new JSONResponse(['labels' => $this->getLabels()]);
	}

	#[NoAdminRequired]
	public function import(): JSONResponse {
		if (!$this->a->can('import')) { return $this->deny(); }
		$f = $this->request->getUploadedFile('file');
		if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) { return $this->err('Fichier manquant'); }
		$mode = (string)$this->request->getParam('assign', 'auto');
		if (!in_array($mode, ['auto', 'categorie'], true)) { return $this->err('Affectation invalide'); }
		$team = $this->pickedTeam();
		if (!$team) { return $this->err('Sélectionnez au moins un commercial à qui affecter les prospects'); }
		$teams = $this->getTeams();
		$exKeys = [];
		$cust = array_column(array_filter(Fields::all(), fn($x) => !$x['builtin']), null, 'key');
		$exj = json_decode((string)$this->request->getParam('extras', '[]'), true);
		if (is_array($exj)) { foreach (array_slice($exj, 0, 30) as $kk) { if (isset($cust[$kk])) { $exKeys[] = $kk; } else { $exKeys[] = null; } } }
		$h = fopen($f['tmp_name'], 'r');
		$first = (string)fgets($h);
		rewind($h);
		$d = ';';
		foreach ([',', "\t"] as $c) { if (substr_count($first, $c) > substr_count($first, $d)) { $d = $c; } }
		$fix = fn($v) => mb_check_encoding((string)$v, 'UTF-8') ? trim(str_replace("\xEF\xBB\xBF", '', (string)$v)) : trim(mb_convert_encoding((string)$v, 'UTF-8', 'Windows-1252'));
		$n = 0; $skip = 0; $dup = 0; $bad = 0; $na = 0; $unk = 0; $i = 0; $rr = []; $samples = []; $now = time();
		while (($row = fgetcsv($h, 0, $d)) !== false && $n < 20000) {
			$row = array_map($fix, $row);
			if ($i++ === 0) { continue; } // en-tête ignoré
			$row = array_pad($row, 7 + count($exKeys), '');
			if ($row[0] === '') { $skip++; continue; }
			$phone = $this->normPhone($row[1]);
			if ($phone === null) { $bad++; if (count($samples) < 10) { $samples[] = 'ligne ' . $i . ' : ' . $row[1]; } continue; }
			if ($this->s->phoneExists($phone)) { $dup++; continue; }
			$wil = Lookup::wilaya($row[2]); $cat = Lookup::categorie($row[3]); $job = Lookup::emploi($row[4]);
			$rem = $row[6]; $why = [];
			if ($row[2] !== '' && $wil === '') { $why[] = 'Wilaya : ' . $row[2]; }
			if ($row[3] !== '' && $cat === '') { $why[] = 'Catégorie : ' . $row[3]; }
			if ($row[4] !== '' && $job === '') { $why[] = 'Emploi : ' . $row[4]; }
			if ($why) { $unk++; $rem = trim($rem . ' [Import, valeur non reconnue — ' . implode(' ; ', $why) . ']'); }
			$to = $this->pick($mode, $cat, $team, $teams, $rr) ?? $this->pick('auto', $cat, $team, $teams, $rr);
			if ($to === null) { $na++; }
			$extra = [];
			foreach ($exKeys as $xi => $xk) { $xv = trim((string)$row[7 + $xi]); if ($xk && $xv !== '') { $extra[$xk] = mb_substr($xv, 0, 4000); } }
			$this->s->insert('callcrm_prospects', ['extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null, 'lastname' => $row[0], 'firstname' => '', 'email' => '', 'phone' => $phone,
				'c1' => '', 'c2' => $cat, 'c3' => $job, 'c4' => Lookup::latin($row[5]), 'c5' => $wil, 'remarque' => $rem,
				'assigned_to' => $to, 'status' => 'nouveau', 'created_at' => $now]);
			$n++;
		}
		fclose($h);
		$this->s->audit($this->a->uid(), 'import', null, $n . ' importé(s), ' . $dup . ' doublon(s), répartis entre : ' . implode(', ', $team) . ' (' . $mode . ')');
		return new JSONResponse(['imported' => $n, 'duplicates' => $dup, 'skipped' => $skip, 'invalid' => $bad, 'samples' => $samples, 'unassigned' => $na, 'unrecognized' => $unk]);
	}

	#[NoAdminRequired]
	public function distribute(): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$mode = (string)$this->request->getParam('mode', '');
		$team = $this->pickedTeam() ?? array_column($this->commerciaux(), 'uid');
		if (!in_array($mode, ['auto', 'categorie'], true) && !(str_starts_with($mode, 'grp:') && isset(Access::teams()[substr($mode, 4)]))) { return $this->err('Mode invalide'); }
		if (!$team) { return $this->err('Aucun commercial dans le groupe callcrm_commercial'); }
		$teams = $this->getTeams(); $rr = []; $n = 0;
		foreach ($this->s->unassigned() as $r) {
			$to = $this->pick($mode, (string)$r['c2'], $team, $teams, $rr);
			if ($to) { $this->s->update((int)$r['id'], ['assigned_to' => $to]); $n++; }
		}
		$this->s->audit($this->a->uid(), 'distribute', null, $n . ' prospect(s) répartis (' . $mode . ') entre : ' . implode(', ', $team));
		return new JSONResponse(['assigned' => $n]);
	}

	/** Réassigne les prospects dont le commercial n'est plus dans le groupe (superviseur, supprimé...). */
	#[NoAdminRequired]
	public function release(): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$mode = (string)$this->request->getParam('mode', '');
		$all = (bool)$this->request->getParam('all', false);
		$team = array_column($this->commerciaux(), 'uid');
		if (!$team) { return $this->err('Aucun commercial dans le groupe callcrm_commercial : opération annulée'); }
		if (!in_array($mode, ['auto', 'categorie'], true) && !(str_starts_with($mode, 'grp:') && isset(Access::teams()[substr($mode, 4)]))) { return $this->err('Mode invalide'); }
		$teams = $this->getTeams(); $rr = []; $n = 0; $none = 0;
		foreach ($this->s->orphans($team, !$all) as $r) {
			$to = $this->pick($mode, (string)$r['c2'], $team, $teams, $rr);
			if ($to) { $this->s->update((int)$r['id'], ['assigned_to' => $to]); $n++; } else { $none++; }
		}
		$this->s->audit($this->a->uid(), 'release', null, $n . ' prospect(s) réattribué(s)');
		return new JSONResponse(['reassigned' => $n, 'not_assigned' => $none]);
	}

	private function xlsx(array $sheets): string {
		$tmp = $this->tm->getTemporaryFile('.xlsx');
		$z = new \ZipArchive();
		$z->open($tmp, \ZipArchive::OVERWRITE);
		$x = fn($s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s), ENT_XML1 | ENT_COMPAT, 'UTF-8');
		$hd = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$ct = ''; $wb = ''; $rel = ''; $i = 1;
		foreach ($sheets as $name => $rows) {
			$xml = $hd . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
			foreach ($rows as $r => $row) {
				$xml .= '<row r="' . ($r + 1) . '">';
				foreach (array_values($row) as $c => $v) { $xml .= '<c r="' . chr(65 + $c) . ($r + 1) . '" t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>'; }
				$xml .= '</row>';
			}
			$z->addFromString("xl/worksheets/sheet$i.xml", $xml . '</sheetData></worksheet>');
			$ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb .= '<sheet name="' . $x($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
			$rel .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
			$i++;
		}
		$z->addFromString('[Content_Types].xml', $hd . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $ct . '</Types>');
		$z->addFromString('_rels/.rels', $hd . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
		$z->addFromString('xl/workbook.xml', $hd . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>');
		$z->addFromString('xl/_rels/workbook.xml.rels', $hd . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rel . '</Relationships>');
		$z->close();
		return (string)file_get_contents($tmp);
	}

	/** Synthèse par commercial : appels, contacts utiles, intéressés et taux (même définition que l'écran Statistiques). */
	private function perfRows(array $calls, callable $who): array {
		$cbk = Statuses::callbackKeys(); $by = [];
		foreach ($calls as $c) {
			$u = $c['user_id']; $by[$u]['n'] = ($by[$u]['n'] ?? 0) + (int)$c['n'];
			if (!in_array($c['result'], $cbk, true) && $c['result'] !== 'faux_numero') { $by[$u]['contact'] = ($by[$u]['contact'] ?? 0) + (int)$c['n']; }
			if ($c['result'] === 'interesse') { $by[$u]['int'] = ($by[$u]['int'] ?? 0) + (int)$c['n']; }
		}
		$out = [['Commercial', 'Appels', 'Contacts', 'Intéressés', 'Taux de contact', 'Taux d’intérêt']];
		foreach ($by as $u => $r) {
			$n = $r['n']; $ct = $r['contact'] ?? 0; $in = $r['int'] ?? 0;
			$out[] = [$who($u), $n, $ct, $in, $n ? round(100 * $ct / $n) . ' %' : '', $ct ? round(100 * $in / $ct) . ' %' : ''];
		}
		return $out;
	}

	#[NoAdminRequired]
	public function export(): DataDownloadResponse|JSONResponse {
		if (!$this->a->can('export')) { return $this->deny(); }
		$ph = $this->a->can('export_phone');
		$off = (int)$this->request->getParam('tz', 0) * 60;
		$d = fn($t) => $t ? gmdate('d/m/Y H:i', (int)$t + $off) : '';
		$f = $this->filters();
		$names = [];
		$who = function ($u) use (&$names) { return $names[$u] ??= ($this->um->getDisplayName($u) ?: $u); };
		$calls = [array_merge(['Date et heure', 'Commercial', 'Client'], $ph ? ['Téléphone'] : [], ['Catégorie', 'Résultat', 'Motif', 'Note', 'Rappel prévu'])];
		foreach ($this->s->callLog($this->scope(), $f, 20000, 0) as $c) {
			$calls[] = array_merge([$d($c['created_at']), $who($c['user_id']), trim($c['lastname'] . ' ' . $c['firstname'])], $ph ? [$c['phone']] : [],
				[$c['c2'], (Statuses::labels()[$c['result']] ?? $c['result']), (string)($c['reason'] ?? ''), (string)$c['note'], $d($c['callback_at'])]);
		}
		$cf = array_values(array_filter(Fields::all(), fn($x) => !$x['builtin']));
		$pros = [array_merge(['Nom et prénom'], $ph ? ['Téléphone'] : [], ['Wilaya', 'Catégorie produit', 'Type emploi', 'Salaire', 'Statut', 'Commercial', 'Rappel prévu', 'Heure d’enregistrement', 'Commentaire'], array_column($cf, 'label'))];
		$np = 0;
		foreach ($this->s->search($this->scope(), [], 20000, 0) as $p) {
			$row = array_merge([trim($p['lastname'] . ' ' . $p['firstname'])], $ph ? [$p['phone']] : [], [$p['c5'], $p['c2'], $p['c3'], $p['c4'], (Statuses::labels()[$p['status']] ?? $p['status']),
				$p['assigned_to'] ? $who($p['assigned_to']) : '', $d($p['callback_at']), $d($p['created_at']), (string)($p['remarque'] ?? '')]);
			$ex = !empty($p['extra']) ? (json_decode((string)$p['extra'], true) ?: []) : [];
			foreach ($cf as $c) { $row[] = (string)($ex[$c['key']] ?? ''); }
			$pros[] = $row; $np++;
		}
		$perf = $this->perfRows($this->s->callCounts($this->scope(), $f), $who);
		$this->s->audit($this->a->uid(), 'export', null, (count($calls) - 1) . ' appel(s), ' . $np . ' prospect(s)' . ($ph ? ' avec téléphone' : ' sans téléphone') . ' (' . $this->a->role() . ')');
		return new DataDownloadResponse($this->xlsx(['Synthèse' => $perf, 'Appels' => $calls, 'Prospects' => $pros]), 'callcrm_' . date('Ymd_His') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	}

	/** Envoie par e-mail l'invitation d'accès (identifiant + adresse) aux commerciaux choisis */
	#[NoAdminRequired]
	public function invite(): JSONResponse {
		if (!$this->a->can('invite')) { return $this->deny(); }
		$subject = trim((string)$this->request->getParam('subject', ''));
		$body = trim((string)$this->request->getParam('body', ''));
		if ($subject === '' || $body === '') { return $this->err('Objet et message obligatoires'); }
		$test = (bool)$this->request->getParam('test', false);
		$allowed = array_column($this->commerciaux(), 'uid');
		$uids = $test ? [$this->a->uid()] : array_values(array_intersect((array)$this->request->getParam('uids', []), $allowed));
		if (!$uids) { return $this->err('Aucun destinataire'); }
		$url = $this->urlGen->linkToRouteAbsolute('callcrm.page.index');
		$sent = 0; $noMail = []; $failed = [];
		foreach (array_slice($uids, 0, 300) as $uid) {
			$u = $this->um->get($uid);
			if (!$u) { continue; }
			$mail = trim((string)$u->getEMailAddress());
			$name = $u->getDisplayName() ?: $uid;
			if ($mail === '' || !$this->mailer->validateMailAddress($mail)) { $noMail[] = $name; continue; }
			// comptes AD : l'identifiant interne est un GUID, on indique l'adresse e-mail ; comptes locaux : l'identifiant
			$login = preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-/i', $uid) ? $mail : $uid;
			$text = strtr($body, ['{nom}' => $name, '{identifiant}' => $login, '{url}' => $url]);
			try {
				$msg = $this->mailer->createMessage();
				$msg->setTo([$mail => $name]);
				$msg->setSubject($subject);
				$msg->setPlainBody($text);
				$bad = $this->mailer->send($msg);
				if ($bad) { $failed[] = $name; } else { $sent++; }
			} catch (\Throwable $e) { $failed[] = $name . ' (' . $e->getMessage() . ')'; }
		}
		return new JSONResponse(['sent' => $sent, 'no_mail' => $noMail, 'failed' => $failed]);
	}

	/** Construit colonnes + champs personnalisés depuis la requête, selon la configuration des champs. Renvoie [colonnes, extra, erreur]. */
	private function buildFields(?array $old): array {
		$v = []; $extra = [];
		if ($old && !empty($old['extra'])) { $e = json_decode((string)$old['extra'], true); if (is_array($e)) { $extra = $e; } }
		$req = $this->request;
		foreach (Fields::all() as $f) {
			$k = $f['key'];
			if ($f['hidden']) { continue; }
			$cur = $f['builtin'] ? (string)($old[$k] ?? '') : (string)($extra[$k] ?? '');
			$val = trim((string)$req->getParam($k, $cur));
			if ($k === 'lastname') { $val = mb_substr($val, 0, 255); }
			elseif ($k === 'phone') {
				if ($val !== '') { $val = $this->normPhone($val); if ($val === null) { return [null, null, 'Numéro algérien invalide (ex. 0555 12 34 56)']; } }
			}
			elseif ($k === 'c2') { $val = $this->canon($val, self::OPT['c2']); }
			else {
				$n = Fields::norm($val, $f);
				if ($val !== '' && $n === '') { if ($val !== $cur) { return [null, null, $f['label'] . ' : valeur invalide']; } } else { $val = $n; }
			}
			if ($f['required'] && $val === '') { return [null, null, $f['label'] . ' : champ obligatoire']; }
			if ($f['builtin']) { $v[$k] = ($k === 'phone' && $val === '') ? null : $val; } else { if ($val === '') { unset($extra[$k]); } else { $extra[$k] = $val; } }
		}
		return [$v, $extra, null];
	}

	private function dupMsg(array $r): string {
		if ($this->scope() !== null && $r['assigned_to'] !== $this->a->uid()) { return 'Ce numéro existe déjà dans la base (fiche d’un autre commercial).'; }
		return 'Ce numéro existe déjà : fiche « ' . trim(($r['lastname'] ?? '') . ' ' . ($r['firstname'] ?? '')) . ' »' . ($r['assigned_to'] ? ' (assignée à ' . ($this->um->getDisplayName($r['assigned_to']) ?: $r['assigned_to']) . ')' : ' (non assignée)') . '.';
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		if (!$this->a->can('create')) { return $this->deny(); }
		[$v, $extra, $err] = $this->buildFields(null);
		if ($err) { return $this->err($err); }
		if (!empty($v['phone']) && ($dup = $this->s->prospectByPhone($v['phone']))) { return $this->err($this->dupMsg($dup)); }
		$v += ['firstname' => '', 'email' => '', 'c1' => '', 'status' => 'nouveau', 'created_at' => time(), 'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null];
		if ($this->a->role() === 'commercial') { $v['assigned_to'] = $this->a->uid(); }
		else {
			$to = trim((string)$this->request->getParam('assigned_to', ''));
			$v['assigned_to'] = ($to !== '' && in_array($to, array_column($this->commerciaux(), 'uid'), true)) ? $to : null;
		}
		$id = $this->s->insert('callcrm_prospects', $v);
		return new JSONResponse(['ok' => true, 'id' => $id]);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		if (!$this->a->can('call')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		if ($this->a->role() === 'commercial' && $p['assigned_to'] !== $this->a->uid()) { return $this->deny(); }
		[$v, $extra, $err] = $this->buildFields($p);
		if ($err) { return $this->err($err); }
		if (!empty($v['phone']) && $v['phone'] !== ($p['phone'] ?? '') && ($dup = $this->s->prospectByPhone($v['phone']))) { return $this->err($this->dupMsg($dup)); }
		$v['extra'] = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;
		$this->s->update($id, $v);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function simConfig(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		return new JSONResponse(['cfg' => Sim::all()]);
	}
	#[NoAdminRequired]
	public function simSaveConfig(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$in = $this->request->getParam('cfg', []);
		if (!is_array($in)) { return $this->err('Données invalides'); }
		try { $c = Sim::save($in); } catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
		$this->s->audit($this->a->uid(), 'settings', null, 'Prix du simulateur de cuisine modifiés');
		return new JSONResponse(['cfg' => $c]);
	}
	/** Enregistre une simulation sur la fiche (le montant est recalculé côté serveur). */
	#[NoAdminRequired]
	public function simSave(int $id): JSONResponse {
		if (!$this->a->can('call')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		if ($this->a->role() === 'commercial' && $p['assigned_to'] !== $this->a->uid()) { return $this->deny(); }
		$in = $this->request->getParam('in', []);
		if (!is_array($in)) { return $this->err('Données invalides'); }
		try { $r = Sim::compute(Sim::all(), $in); } catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
		$this->s->audit($this->a->uid(), 'sim', $id, json_encode(['in' => $in, 'total' => $r['total']], JSON_UNESCAPED_UNICODE));
		return new JSONResponse(['ok' => true, 'sims' => $this->s->simsFor($id)]);
	}

	#[NoAdminRequired]
	public function saveStatuses(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$in = $this->request->getParam('statuses', []);
		if (!is_array($in) || !$in) { return $this->err('Liste invalide'); }
		$use = []; foreach ($this->s->statusCounts(null, []) as $r) { $use[$r['status']] = (int)$r['n']; }
		try { return new JSONResponse(['statuses' => Statuses::save($in, $use)]); }
		catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
	}

	#[NoAdminRequired]
	public function saveFields(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$in = $this->request->getParam('fields', []);
		if (!is_array($in) || !$in) { return $this->err('Liste de champs invalide'); }
		return new JSONResponse(['fields' => Fields::save($in)]);
	}

	#[NoAdminRequired]
	public function journal(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$f = $this->filters();
		$f['q'] = mb_substr(trim((string)$this->request->getParam('q', '')), 0, 100);
		$f['sort'] = (string)$this->request->getParam('sort', '');
		$f['dir'] = (string)$this->request->getParam('dir', '');
		if ($f['q'] !== '') {
			$f['qusers'] = [];
			foreach ($this->um->searchDisplayName($f['q'], 20) as $u) { $f['qusers'][] = $u->getUID(); }
		}
		if ($this->a->can('mask')) { $f['nophone'] = true; }
		$limit = in_array((int)$this->request->getParam('limit', 10), [10, 20, 30, 0], true) ? (int)$this->request->getParam('limit', 10) : 10;
		$off = max(0, (int)$this->request->getParam('offset', 0));
		if ($limit === 0) { $limit = 5000; $off = 0; }
		$total = $this->s->callLogCount($this->scope(), $f);
		$rows = $this->maskRows($this->s->callLog($this->scope(), $f, $limit, $off));
		foreach ($rows as &$r) { $r['user_name'] = $this->um->getDisplayName($r['user_id']) ?: $r['user_id']; }
		unset($r);
		return new JSONResponse(['rows' => $rows, 'total' => $total]);
	}

	private function recFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try { return $this->appData->getFolder('rec'); } catch (NotFoundException $e) { return $this->appData->newFolder('rec'); }
	}
	private function canHear(array $r): bool {
		$role = $this->a->role();
		return $role === 'admin' || $role === 'superviseur' || ($role === 'commercial' && $r['user_id'] === $this->a->uid());
	}

	/** Reçoit un enregistrement envoyé par l'application Android (authentification par mot de passe d'application) */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadRecording(): JSONResponse {
		if (!$this->a->can('call')) { return $this->deny(); }
		$f = $this->request->getUploadedFile('audio');
		if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) { return $this->err('Fichier manquant'); }
		$size = (int)($f['size'] ?? 0);
		if ($size < 1 || $size > 100 * 1024 * 1024) { return $this->err('Taille invalide'); }
		$uid = $this->a->uid();
		$title = mb_substr(trim((string)$this->request->getParam('filename', '')), 0, 255);
		if ($title !== '' && $this->s->recordingExists($uid, $title, $size)) { return new JSONResponse(['ok' => true, 'duplicate' => true]); }
		$ext = strtolower(pathinfo($title, PATHINFO_EXTENSION));
		if (!in_array($ext, ['m4a', 'mp3', 'amr', '3gp', 'aac', 'wav', 'ogg', 'mp4'], true)) { $ext = 'm4a'; }
		$ts = (int)$this->request->getParam('recorded_at', 0);
		if ($ts < 946684800 || $ts > time() + 86400) { $ts = time(); }
		$phone = $this->normPhone((string)$this->request->getParam('phone', ''));
		$p = $phone ? $this->s->prospectByPhone($phone) : null;
		$call = $p ? $this->s->nearestCall($uid, (int)$p['id'], $ts) : null;
		// un commercial ne rattache un enregistrement qu'à un prospect qui lui est affecté
		if ($p && $this->a->role() === 'commercial' && $p['assigned_to'] !== $uid) { $p = null; $call = null; }
		$id = $this->s->insert('callcrm_recordings', ['user_id' => $uid, 'prospect_id' => $p ? (int)$p['id'] : null, 'call_id' => $call, 'phone' => $phone ?? mb_substr(preg_replace('/[^\d+]/', '', (string)$this->request->getParam('phone', '')), 0, 32),
			'title' => $title, 'file' => 'pending', 'size' => $size, 'recorded_at' => $ts, 'created_at' => time()]);
		$name = 'r' . $id . '_' . $ts . '.' . $ext;
		try { $this->recFolder()->newFile($name, (string)file_get_contents($f['tmp_name'])); }
		catch (\Throwable $e) { return $this->err('Stockage impossible', Http::STATUS_INTERNAL_SERVER_ERROR); }
		$this->s->setRecordingFile($id, $name);
		return new JSONResponse(['ok' => true, 'matched' => $p !== null]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadRecording(int $id): DataDownloadResponse|JSONResponse {
		$r = $this->s->getRecording($id);
		if (!$r || !$this->canHear($r)) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		try { $data = $this->recFolder()->getFile($r['file'])->getContent(); }
		catch (\Throwable $e) { return $this->err('Fichier introuvable', Http::STATUS_NOT_FOUND); }
		$ext = strtolower(pathinfo($r['file'], PATHINFO_EXTENSION));
		$mime = ['m4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'amr' => 'audio/amr', '3gp' => 'audio/3gpp', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg'][$ext] ?? 'application/octet-stream';
		return new DataDownloadResponse($data, $r['file'], $mime);
	}

	#[NoAdminRequired]
	public function unmatchedRecordings(): JSONResponse {
		if (!$this->a->can('import')) { return $this->deny(); }
		$rows = $this->s->unmatchedRecordings(50);
		foreach ($rows as &$r) { $r['user_name'] = $this->um->getDisplayName($r['user_id']) ?: $r['user_id']; unset($r['file']); }
		unset($r);
		return new JSONResponse($rows);
	}

	#[NoAdminRequired]
	public function stats(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$sc = $this->scope();
		$f = $this->filters();
		$tz = max(-840, min(840, (int)$this->request->getParam('tz', 60))) * 60;
		$hours = array_fill(0, 24, 0);
		foreach ($this->s->callTimes($sc, $f) as $t) { $hours[(int)(((($t + $tz) % 86400) + 86400) % 86400 / 3600)]++; }
		$pr = Prefs::all();
		$day0 = (new \DateTime('now', new \DateTimeZone($pr['tz'])))->setTime(0, 0, 0)->getTimestamp();
		return new JSONResponse(['status' => $this->s->statusCounts($sc, $f), 'calls' => $this->s->callCounts($sc, $f), 'users' => $this->commerciaux(), 'hours' => $hours,
			'reasons' => $this->s->reasonCounts($sc, $f), 'today' => $this->s->callsSince($sc, $day0), 'goal' => $pr['goal']]);
	}
}
