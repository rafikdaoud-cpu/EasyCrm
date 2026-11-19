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
use OCA\CallCrm\Service\TeamScope;
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
	/** Groupes d'équipe affichés : tous, ou ceux du superviseur limité. */
	private function visibleTeams(): array {
		$all = $this->getTeams();
		if (TeamScope::users() === null) { return $all; }
		$mine = TeamScope::config()[$this->a->uid()] ?? [];
		return array_intersect_key($all, array_flip($mine));
	}
	/** Ne garde que les fiches visibles (superviseur limité à ses groupes). */
	private function scopedIds(array $ids): array {
		if (TeamScope::users() === null || !$ids) { return $ids; }
		return array_values(array_filter($ids, fn($id) => ($p = $this->s->get((int)$id)) && TeamScope::canSee($p['assigned_to'])));
	}
	private function computeTeams(): array {
		$com = array_column($this->allCommerciaux(), 'uid'); $out = [];
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
	/**
	 * Case téléphone d'un import : un ou deux numéros (séparés par / , ; | « et » « ou », ou collés : 20 chiffres).
	 * Renvoie [premier numéro valide, second numéro valide différent ou null] ; premier null si aucun n'est valide.
	 */
	private function splitPhones(string $raw): array {
		$parts = preg_split('#\s*(?:[/,;|]|\s(?:et|ou)\s|\n)\s*#iu', trim($raw)) ?: [];
		$found = [];
		foreach ($parts as $part) {
			if (trim($part) === '') { continue; }
			$n = $this->normPhone($part);
			if ($n === null) {
				// Deux mobiles collés ou séparés par des espaces : 0776459672 0560898201
				$d = preg_replace('/\D+/', '', Lookup::latin($part));
				if (preg_match_all('/0[567]\d{8}/', $d, $m) && strlen($d) === 10 * count($m[0])) { foreach ($m[0] as $x) { $found[] = $x; } }
				continue;
			}
			$found[] = $n;
		}
		$found = array_values(array_unique($found));
		return [$found[0] ?? null, $found[1] ?? null];
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
	/** Commerciaux visibles : tous, ou ceux des groupes du superviseur (TeamScope). */
	private function commerciaux(): array {
		$all = $this->allCommerciaux(); $scope = TeamScope::users();
		return $scope === null ? $all : array_values(array_filter($all, fn($c) => in_array($c['uid'], $scope, true)));
	}
	private function allCommerciaux(): array {
		return $this->memoCom ??= $this->cached('commerciaux', function () {
			$out = [];
			$gids = [Access::groups()['commercial']];
			foreach (Access::customProfiles() as $cp) { if ($cp['base'] === 'commercial') { $gids[] = $cp['group']; } }
			foreach ($gids as $gid) { foreach ($this->gm->get($gid)?->getUsers() ?? [] as $u) { $out[$u->getUID()] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName()]; } }
			return array_values($out);
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
		return (($sc === null && TeamScope::canSee($p['assigned_to'])) || $p['assigned_to'] === $sc) ? $p : null;
	}

	#[NoAdminRequired]
	public function me(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$can = [];
		foreach (['call', 'assign', 'create', 'import', 'invite', 'teams', 'fields', 'labels', 'delete', 'all', 'audit', 'export', 'export_phone'] as $k) { $can[$k] = $this->a->can($k); }
		return new JSONResponse(['statuses' => Statuses::all(), 'fields' => Fields::all(), 'role' => $this->a->role(), 'uid' => $this->a->uid(), 'name' => $this->um->get($this->a->uid())?->getDisplayName() ?: $this->a->uid(), 'labels' => $this->getLabels(), 'options' => self::OPT + ['c5' => array_keys(Lookup::WILAYAS)], 'teams' => $this->visibleTeams(), 'groups' => array_values(array_filter(array_map(fn($g, $n) => ['id' => $g, 'name' => $n, 'cats' => array_keys(array_filter(Access::cats(), fn($x) => $x === $g))], array_keys(Access::teams()), array_values(Access::teams())), fn($x) => isset($this->visibleTeams()[$x['id']]))), 'scope_teams' => TeamScope::users() !== null, 'supervisors' => $this->a->can('teams') ? $this->supervisors() : [], 'rights' => $this->a->can('teams') ? $this->rightsMatrix() : null, 'profiles' => $this->a->can('teams') ? $this->profilesList() : null, 'custom_teams' => $this->a->can('teams') ? array_keys(Access::customTeams()) : [], 'commerciaux' => $this->commerciaux(), 'max_callbacks' => $this->maxCallbacks(), 'prefs' => Prefs::all(), 'can' => $can, 'sms' => $this->smsRuntime()]);
	}

	#[NoAdminRequired]
	public function prospects(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$f = $this->listFilter();
		$off = max(0, (int)$this->request->getParam('offset', 0));
		if ($this->request->getParam('paged')) {
			$lim = (int)$this->request->getParam('limit', 10); if (!in_array($lim, [10, 20, 30, 0], true)) { $lim = 10; }
			$eff = $lim === 0 ? 5000 : $lim; if ($lim === 0) { $off = 0; }
			return new JSONResponse(['rows' => $this->maskRows($this->s->search($this->scope(), $f, $eff, $off)), 'total' => $this->s->count($this->scope(), $f), 'limit' => $lim]);
		}
		return new JSONResponse($this->maskRows($this->s->search($this->scope(), $f, 50, $off)));
	}

	/** Rien n'est exposé tant que l'administrateur n'a pas activé l'option : aucun bouton SMS n'apparaît. */
	private function smsRuntime(): array {
		try { $rt = \OCP\Server::get(\OCA\CallCrm\Service\Sms::class)->runtime(); } catch (\Throwable $e) { return ['enabled' => false]; }
		$role = $this->a->role(); $manager = in_array($role, ['admin', 'superviseur'], true);
		if (!$rt['enabled'] || !($manager || ($role === 'commercial' && $rt['commercial_send']))) { return ['enabled' => false]; }
		return ['enabled' => true, 'bulk' => $manager, 'max_bulk' => $rt['max_bulk'], 'templates' => $rt['templates']];
	}

	/** Filtres de la liste « Dossiers clients » (aussi utilisés par l'envoi de SMS groupé, pour viser exactement la même sélection). */
	private function listFilter(): array {
		$st = (string)$this->request->getParam('status', '');
		$f = ['q' => trim((string)$this->request->getParam('q', '')), 'status' => in_array($st, Statuses::keys(), true) ? $st : '',
			'assigned' => $this->a->can('all') ? (string)$this->request->getParam('assigned', '') : '', 'rappels' => (bool)$this->request->getParam('rappels', false), 'asc' => (bool)$this->request->getParam('asc', false),
			'reg_from' => (int)$this->request->getParam('reg_from', 0), 'reg_to' => (int)$this->request->getParam('reg_to', 0), 'proc_from' => (int)$this->request->getParam('proc_from', 0), 'proc_to' => (int)$this->request->getParam('proc_to', 0)];
		// Same union-of-groups / intersection-with-users rules as the reports.
		$selected = $this->filters();
		$f['users'] = $selected['users'];
		$f['cat'] = $selected['cat'];
		$f['src'] = array_values(array_intersect(array_filter(explode('|', (string)$this->request->getParam('src', ''))), \OCA\CallCrm\Service\ClientActivity::SOURCES));
		$f['sort'] = (string)$this->request->getParam('sort', ''); $f['dir'] = (string)$this->request->getParam('dir', '');
		if ($this->a->can('mask')) { $f['nophone'] = true; }
		return $f;
	}

	/**
	 * SMS groupé aux clients de la sélection affichée (administrateur et superviseur).
	 * preview=1 : nombre de clients, de numéros valides et de SMS facturés, sans rien envoyer.
	 */
	#[NoAdminRequired]
	public function smsBulk(): JSONResponse {
		$sms = \OCP\Server::get(\OCA\CallCrm\Service\Sms::class);
		$rt = $sms->runtime();
		if (!$rt['enabled'] || !in_array($this->a->role(), ['admin', 'superviseur'], true)) { return $this->deny(); }
		$f = $this->listFilter(); unset($f['sort'], $f['dir']);
		$total = $this->s->count($this->scope(), $f);
		if ($total > $rt['max_bulk']) { return new JSONResponse(['error' => 'Sélection trop grande : ' . $total . ' clients pour ' . $rt['max_bulk'] . ' au maximum par envoi. Affinez les filtres.'], 400); }
		$template = str_replace("\r", '', (string)$this->request->getParam('text', ''));
		$uid = $this->a->uid(); $me = $this->um->get($uid)?->getDisplayName() ?? $uid;
		$groups = []; $invalid = 0; $seen = []; $billed = 0; $names = [];
		foreach ($this->s->search($this->scope(), $f, $rt['max_bulk'], 0) as $p) {
			$n = \OCA\CallCrm\Service\Sms::number((string)($p['phone'] ?? ''));
			if (!$n) { $invalid++; continue; }
			if (isset($seen[$n])) { continue; }
			$seen[$n] = (int)$p['id'];
			$owner = (string)($p['assigned_to'] ?? '');
			$commercial = $owner === '' ? $me : ($names[$owner] ??= ($this->um->get($owner)?->getDisplayName() ?? $owner));
			$text = \OCA\CallCrm\Service\Sms::render($template, $p, $commercial);
			try { \OCA\CallCrm\Service\Sms::check($text); } catch (\InvalidArgumentException $e) { return new JSONResponse(['error' => $e->getMessage()], 400); }
			$groups[$text][] = $n; $billed += \OCA\CallCrm\Service\Sms::segments($text)['segments'];
		}
		$summary = ['clients' => $total, 'recipients' => count($seen), 'invalid' => $invalid, 'duplicates' => $total - $invalid - count($seen), 'billed' => $billed];
		if ($this->request->getParam('preview')) { return new JSONResponse($summary); }
		if ((int)$this->request->getParam('expected', -1) !== count($seen)) { return new JSONResponse(['error' => 'La sélection a changé depuis l’aperçu : vérifiez de nouveau avant d’envoyer.'], 409); }
		if (!$seen) { return new JSONResponse(['error' => 'Aucun numéro mobile valide dans la sélection.'], 400); }
		@set_time_limit(900);
		$batch = bin2hex(random_bytes(6)); $sent = 0; $failed = []; $activity = \OCP\Server::get(\OCA\CallCrm\Service\ClientActivity::class);
		foreach ($groups as $text => $numbers) {
			try { $res = $sms->send($numbers, $text); }
			catch (\RuntimeException $e) { return new JSONResponse(['error' => $e->getMessage(), 'sent' => $sent], 502); }
			$seg = \OCA\CallCrm\Service\Sms::segments($text)['segments'];
			foreach ($res as $n => $r) {
				if ($r['ok']) { $sent++; } else { $failed[$r['error']] = ($failed[$r['error']] ?? 0) + 1; }
				try { $activity->event($seen[$n], $uid, 'sms', ['text' => $text, 'ok' => $r['ok'], 'error' => $r['error'], 'message_id' => $r['id'], 'segments' => $seg, 'batch' => $batch]); } catch (\Throwable $e) {}
			}
		}
		$this->s->audit($uid, 'sms_bulk', null, $sent . ' envoyé(s) sur ' . count($seen) . ' · lot ' . $batch . ($failed ? ' · échecs : ' . json_encode($failed, JSON_UNESCAPED_UNICODE) : ''));
		return new JSONResponse($summary + ['sent' => $sent, 'failed' => $failed]);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		// Fiche ouverte : la notification « documents reçus » de cette fiche est lue.
		try { $nm = \OCP\Server::get(\OCP\Notification\IManager::class); $n = $nm->createNotification(); $n->setApp('callcrm')->setUser($this->a->uid())->setObject('prospect', (string)$id); $nm->markProcessed($n); } catch (\Throwable $e) {}
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
		if ((!empty($sd['reason']) && $reason==='') || ($reason!=='' && !in_array($reason, $sd['reason_options'], true))) { return $this->err('Choisissez un motif valide pour « ' . $sd['label'] . ' »'); }
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
		$old = $this->s->get($id);
		if (!$old || !TeamScope::canSee($old['assigned_to'])) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$this->s->update($id, ['assigned_to' => $uid === '' ? null : $uid], $this->a->uid());
		$this->s->audit($this->a->uid(), 'assign', $id, ($old['assigned_to'] ?? '—') . ' → ' . ($uid === '' ? '—' : $uid));
		return new JSONResponse(['ok' => true]);
	}

	private function maxCallbacks(): int { return Prefs::all()['max_callbacks']; }

	#[NoAdminRequired]
	public function saveCallPrefs(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$in = [];
		foreach (['max_callbacks', 'cb_from', 'cb_to', 'cb_gap', 'goal', 'tz', 'reasons', 'wa_quick'] as $k) { $v = $this->request->getParam($k); if ($v !== null) { $in[$k] = $v; } }
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
		$out = ['overdue' => $this->s->overdueCount($sc, time()), 'today' => null, 'goal' => $p['goal']];
		if ($this->a->role() === 'commercial') {
			$uid = $this->a->uid(); $g = Prefs::goalFor($uid);
			$w = (clone $d)->modify('monday this week');
			$out = ['overdue' => $out['overdue'], 'today' => $this->s->callsSince($uid, $d->getTimestamp())[$uid] ?? 0, 'goal' => $g['day'],
				'week' => $this->s->callsSince($uid, $w->getTimestamp())[$uid] ?? 0, 'week_goal' => $g['week']];
		}
		return new JSONResponse($out);
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
		$mine = ($this->scope() === null && TeamScope::canSee($r['assigned_to'])) || $r['assigned_to'] === $this->a->uid();
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

	/**
	 * Change le groupe d'équipe d'un commercial (administrateurs) : ajout au nouveau groupe puis retrait des autres
	 * groupes d'équipe. Les groupes viennent de l'AD : l'écriture passe par ldap_write_support ; un refus est rapporté.
	 * to = '' retire le commercial de tous les groupes d'équipe.
	 */
	#[NoAdminRequired]
	public function teamMove(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$uid = trim((string)$this->request->getParam('uid', ''));
		$to = trim((string)$this->request->getParam('to', ''));
		$teams = Access::teams();
		if (!in_array($uid, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Commercial inconnu'); }
		if ($to !== '' && !isset($teams[$to])) { return $this->err('Groupe inconnu'); }
		$user = $this->um->get($uid);
		if (!$user) { return $this->err('Compte introuvable'); }
		$before = [];
		foreach ($teams as $gid => $name) { $g = $this->gm->get($gid); if ($g && $g->inGroup($user)) { $before[] = $gid; } }
		if ($before === ($to === '' ? [] : [$to])) { return $this->err('Ce commercial est déjà dans ce groupe.'); }
		$refused = 'L’annuaire a refusé la modification du groupe (groupe de l’AD sans droit d’écriture pour Nextcloud). Utilisez des groupes d’équipe locaux Nextcloud ou modifiez le groupe dans l’AD.';
		try {
			if ($to !== '' && !in_array($to, $before, true)) {
				$g = $this->gm->get($to);
				if (!$g || !$g->canAddUser()) { return $this->err('Ce groupe ne peut pas être modifié depuis Call CRM (groupe en lecture seule).'); }
				if (!$this->groupWrite($g, $user, true)) { return $this->err($refused, Http::STATUS_BAD_GATEWAY); }
			}
			foreach ($before as $gid) {
				if ($gid === $to) { continue; }
				$g = $this->gm->get($gid);
				if ($g && $g->canRemoveUser() && !$this->groupWrite($g, $user, false)) {
					return $this->err($refused . ' Le commercial a été ajouté au nouveau groupe mais reste aussi dans « ' . ($teams[$gid] ?? $gid) . ' ».', Http::STATUS_BAD_GATEWAY);
				}
			}
		} catch (\Throwable $e) {
			return $this->err('L’annuaire (AD) a refusé la modification : ' . $e->getMessage(), Http::STATUS_BAD_GATEWAY);
		}
		try { $c = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('callcrm'); $c->remove('teams'); } catch (\Throwable $e) {}
		$this->memoTeams = null;
		$label = fn($g) => $g === '' ? 'aucun groupe' : ($teams[$g] ?? $g);
		$this->s->audit($this->a->uid(), 'team_move', null, $uid . ' : ' . ($before ? implode(', ', array_map($label, $before)) : 'aucun groupe') . ' → ' . $label($to));
		return new JSONResponse(['teams' => $this->computeTeams(), 'from' => $before, 'to' => $to]);
	}

	/**
	 * Demande de documents : envoie aussi le message (texte du modèle + lien) à l'e-mail du client s'il est connu.
	 * Le texte vient du navigateur (le lien en clair n'est connu qu'à sa création) : il doit contenir un lien de dépôt de ce serveur.
	 */
	#[NoAdminRequired]
	public function collectMail(int $id): JSONResponse {
		if (!$this->a->can('call') && !$this->a->can('assign')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$mail = trim((string)($p['email'] ?? ''));
		if ($mail === '' || !$this->mailer->validateMailAddress($mail)) { return $this->err('Pas d’adresse e-mail valide sur la fiche.'); }
		$text = str_replace("\r", '', (string)$this->request->getParam('text', ''));
		$host = preg_quote((string)parse_url($this->urlGen->getAbsoluteURL('/'), PHP_URL_HOST), '#');
		if ($text === '' || mb_strlen($text) > 2000 || !preg_match('#https://' . $host . '/(?:d/|(?:index\.php/)?apps/callcrm/collecte/)[A-Za-z0-9_-]{20,64}#', $text)) {
			return $this->err('Message de demande de documents invalide.');
		}
		$name = trim(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? ''));
		try {
			$msg = $this->mailer->createMessage();
			$msg->setTo([$mail => $name !== '' ? $name : $mail]);
			$msg->setSubject('Vos documents · وثائقكم — CSM Taksit');
			$msg->setPlainBody($text . "\n\n— CSM Taksit");
			$bad = $this->mailer->send($msg);
			if ($bad) { return $this->err('Le serveur e-mail a refusé l’envoi.', Http::STATUS_BAD_GATEWAY); }
		} catch (\Throwable $e) {
			return $this->err('Envoi de l’e-mail impossible : vérifiez le serveur e-mail de Nextcloud.', Http::STATUS_BAD_GATEWAY);
		}
		$this->s->audit($this->a->uid(), 'collect_mail', $id, 'Demande de documents envoyée par e-mail');
		try { \OCP\Server::get(\OCA\CallCrm\Service\ClientActivity::class)->event($id, $this->a->uid(), 'documents', ['action' => 'mail']); } catch (\Throwable $e) {}
		return new JSONResponse(['ok' => true, 'email' => $mail]);
	}

	/** Identifiant technique à partir d'un nom (minuscules, sans accents), unique parmi $taken. */
	private function slug(string $name, array $taken, string $prefix): string {
		$t = class_exists('Normalizer') ? preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($name, \Normalizer::FORM_D)) : $name;
		$base = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower((string)$t)), '_') ?: 'groupe';
		$base = substr($base, 0, 30); $id = $prefix . $base; $i = 2;
		while (in_array($id, $taken, true) || $this->gm->groupExists($id)) { $id = $prefix . $base . '_' . $i++; }
		return $id;
	}
	private function cleanName(string $v): ?string {
		$v = trim(preg_replace('/\s+/u', ' ', $v));
		return ($v === '' || mb_strlen($v) > 60 || preg_match('/[\x00-\x1f<>]/', $v)) ? null : $v;
	}
	/** Profils pour l'administration : modèles (fixes) et profils créés, avec leurs membres. */
	private function profilesList(): array {
		$out = [];
		foreach (Access::BASES as $id => $name) {
			$out[] = ['id' => $id, 'name' => $name, 'base' => $id, 'builtin' => true, 'group' => Access::groups()[$id], 'members' => []];
		}
		foreach (Access::customProfiles() as $p) {
			$m = [];
			foreach ($this->gm->get($p['group'])?->getUsers() ?? [] as $u) { $m[] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName()]; }
			usort($m, fn($a, $b) => strcasecmp($a['name'], $b['name']));
			$out[] = $p + ['builtin' => false, 'members' => $m];
		}
		return $out;
	}
	private function saveProfiles(array $list): void { $this->cfg->setAppValue(Application::APP_ID, 'custom_profiles', json_encode(array_values($list), JSON_UNESCAPED_UNICODE)); }

	/** Administrateurs : créer un profil (nom + modèle de fonctionnement) ; ses droits partent de ceux du modèle. */
	#[NoAdminRequired]
	public function profileCreate(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$name = $this->cleanName((string)$this->request->getParam('name', ''));
		$base = (string)$this->request->getParam('base', '');
		if ($name === null) { return $this->err('Nom du profil obligatoire (60 caractères maximum).'); }
		if (!isset(Access::BASES[$base])) { return $this->err('Choisissez le modèle du profil.'); }
		$list = Access::customProfiles();
		if (count($list) >= 20) { return $this->err('20 profils au maximum.'); }
		foreach (array_merge(array_values(Access::BASES), array_column($list, 'name')) as $n) { if (mb_strtolower($n) === mb_strtolower($name)) { return $this->err('Un profil porte déjà ce nom.'); } }
		$id = $this->slug($name, array_column($list, 'id'), 'p_');
		$gid = 'crm_profil_' . substr($id, 2);
		$g = $this->gm->groupExists($gid) ? $this->gm->get($gid) : $this->gm->createGroup($gid);
		if (!$g) { return $this->err('Création du groupe du profil impossible.'); }
		if (method_exists($g, 'setDisplayName')) { $g->setDisplayName('Call CRM – profil ' . $name); }
		$list[] = ['id' => $id, 'name' => $name, 'base' => $base, 'group' => $gid];
		$this->saveProfiles($list);
		$this->s->audit($this->a->uid(), 'profile_create', null, $name . ' (' . $id . ', modèle ' . Access::BASES[$base] . ')');
		return new JSONResponse(['profiles' => $this->profilesList(), 'rights' => $this->rightsMatrix()]);
	}

	/** Administrateurs : supprimer un profil créé, s'il n'a plus aucun membre. */
	#[NoAdminRequired]
	public function profileDelete(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$id = (string)$this->request->getParam('id', '');
		$list = Access::customProfiles(); $p = null;
		foreach ($list as $x) { if ($x['id'] === $id) { $p = $x; } }
		if (!$p) { return $this->err('Profil inconnu (les profils d’origine ne se suppriment pas).'); }
		$g = $this->gm->get($p['group']);
		if ($g && count($g->getUsers())) { return $this->err('Retirez d’abord les membres de ce profil.'); }
		if ($g && method_exists($g, 'delete')) { $g->delete(); }
		$this->saveProfiles(array_filter($list, fn($x) => $x['id'] !== $id));
		$raw = json_decode((string)$this->cfg->getAppValue(Application::APP_ID, 'role_rights', ''), true);
		if (is_array($raw)) {
			foreach ($raw as $k => $roles) { if (is_array($roles)) { $raw[$k] = array_values(array_diff($roles, [$id])); } }
			$this->cfg->setAppValue(Application::APP_ID, 'role_rights', json_encode($raw));
		}
		$this->s->audit($this->a->uid(), 'profile_delete', null, $p['name'] . ' (' . $id . ')');
		return new JSONResponse(['profiles' => $this->profilesList(), 'rights' => $this->rightsMatrix()]);
	}

	/** Administrateurs : ajouter / retirer un utilisateur d'un profil créé (un seul profil créé par personne). */
	#[NoAdminRequired]
	public function profileMember(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$id = (string)$this->request->getParam('id', ''); $uid = trim((string)$this->request->getParam('uid', ''));
		$add = (bool)$this->request->getParam('add', true);
		$list = Access::customProfiles(); $p = null;
		foreach ($list as $x) { if ($x['id'] === $id) { $p = $x; } }
		if (!$p) { return $this->err('Profil inconnu.'); }
		$user = $uid !== '' ? $this->um->get($uid) : null;
		if (!$user) { return $this->err('Utilisateur introuvable.'); }
		if ($add && ($this->gm->isAdmin($uid) || $this->gm->isInGroup($uid, Access::groups()['admin']))) { return $this->err('Un administrateur garde son profil administrateur.'); }
		$g = $this->gm->get($p['group']);
		if (!$g) { return $this->err('Groupe du profil introuvable.'); }
		if ($add) {
			foreach ($list as $x) {
				if ($x['id'] !== $id && ($o = $this->gm->get($x['group'])) && $o->inGroup($user) && !$this->groupWrite($o, $user, false)) { return $this->err('Impossible de retirer cette personne de son profil actuel.'); }
			}
			if (!$g->inGroup($user) && !$this->groupWrite($g, $user, true)) { return $this->err('Ajout au profil refusé.'); }
		} elseif ($g->inGroup($user) && !$this->groupWrite($g, $user, false)) { return $this->err('Retrait du profil refusé.'); }
		try { $c = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('callcrm'); $c->remove('commerciaux'); $c->remove('teams'); } catch (\Throwable $e) {}
		$this->memoCom = null; $this->memoTeams = null;
		$this->s->audit($this->a->uid(), 'profile_member', null, ($add ? '+' : '-') . $uid . ' · ' . $p['name']);
		return new JSONResponse(['profiles' => $this->profilesList()]);
	}

	/** Administrateurs : recherche d'utilisateurs Nextcloud (nom ou identifiant) pour les ajouter à un profil. */
	#[NoAdminRequired]
	public function userSearch(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$q = trim((string)$this->request->getParam('q', ''));
		if (mb_strlen($q) < 2) { return new JSONResponse([]); }
		$out = [];
		foreach ($this->um->searchDisplayName(mb_substr($q, 0, 60), 20) as $u) { $out[] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName()]; }
		return new JSONResponse($out);
	}

	/** Administrateurs : créer un groupe d'équipe (groupe local Nextcloud). */
	#[NoAdminRequired]
	public function teamCreate(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$name = $this->cleanName((string)$this->request->getParam('name', ''));
		if ($name === null) { return $this->err('Nom du groupe obligatoire (60 caractères maximum).'); }
		foreach (Access::teams() as $n) { if (mb_strtolower($n) === mb_strtolower($name)) { return $this->err('Un groupe porte déjà ce nom.'); } }
		$custom = Access::customTeams();
		if (count($custom) >= 30) { return $this->err('30 groupes créés au maximum.'); }
		$gid = $this->slug($name, array_keys(Access::teams()), 'crm_equipe_');
		$g = $this->gm->createGroup($gid);
		if (!$g) { return $this->err('Création du groupe impossible.'); }
		if (method_exists($g, 'setDisplayName')) { $g->setDisplayName('Équipe ' . $name); }
		$custom[$gid] = $name;
		$this->cfg->setAppValue(Application::APP_ID, 'custom_teams', json_encode($custom, JSON_UNESCAPED_UNICODE));
		try { \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('callcrm')->remove('teams'); } catch (\Throwable $e) {}
		$this->memoTeams = null;
		$this->s->audit($this->a->uid(), 'team_create', null, $name . ' (' . $gid . ')');
		return new JSONResponse(['teams' => $this->computeTeams(), 'groups' => $this->groupsPayload(), 'custom_teams' => array_keys(Access::customTeams())]);
	}

	/** Administrateurs : supprimer un groupe créé, s'il est vide. */
	#[NoAdminRequired]
	public function teamDelete(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$gid = (string)$this->request->getParam('gid', '');
		$custom = Access::customTeams();
		if (!isset($custom[$gid])) { return $this->err('Seuls les groupes créés dans Call CRM se suppriment.'); }
		$g = $this->gm->get($gid);
		if ($g && count($g->getUsers())) { return $this->err('Déplacez d’abord les commerciaux de ce groupe.'); }
		if ($g && method_exists($g, 'delete')) { $g->delete(); }
		$name = $custom[$gid]; unset($custom[$gid]);
		$this->cfg->setAppValue(Application::APP_ID, 'custom_teams', json_encode($custom, JSON_UNESCAPED_UNICODE));
		$sv = TeamScope::config($this->cfg);
		foreach ($sv as $u => $l) { $sv[$u] = array_values(array_diff($l, [$gid])); if (!$sv[$u]) { unset($sv[$u]); } }
		$this->cfg->setAppValue(Application::APP_ID, TeamScope::CONFIG, json_encode($sv, JSON_UNESCAPED_UNICODE));
		try { \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('callcrm')->remove('teams'); } catch (\Throwable $e) {}
		$this->memoTeams = null;
		$this->s->audit($this->a->uid(), 'team_delete', null, $name . ' (' . $gid . ')');
		return new JSONResponse(['teams' => $this->computeTeams(), 'groups' => $this->groupsPayload(), 'custom_teams' => array_keys(Access::customTeams())]);
	}
	private function groupsPayload(): array {
		return array_values(array_filter(array_map(fn($g, $n) => ['id' => $g, 'name' => $n, 'cats' => array_keys(array_filter(Access::cats(), fn($x) => $x === $g))], array_keys(Access::teams()), array_values(Access::teams())), fn($x) => isset($this->visibleTeams()[$x['id']])));
	}

	/** Matrice des droits pour l'écran d'administration. */
	private function rightsMatrix(): array {
		$map = Access::rights(); $out = []; $editable = array_keys(Access::editableProfiles());
		foreach (Access::RIGHTS as $k => [$label, $help]) {
			$out[] = ['key' => $k, 'label' => $label, 'help' => $help, 'roles' => array_values(array_intersect($map[$k] ?? [], $editable)), 'locked' => $k === 'teams'];
		}
		return $out;
	}

	/** Administrateurs : droits des profils superviseur, commercial et lecture seule (reset = valeurs d'origine). */
	#[NoAdminRequired]
	public function saveRights(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$before = $this->rightsMatrix();
		if ($this->request->getParam('reset')) {
			$this->cfg->setAppValue(Application::APP_ID, 'role_rights', '');
		} else {
			$in = $this->request->getParam('rights', []);
			if (!is_array($in)) { return $this->err('Droits invalides'); }
			$store = [];
			foreach (Access::RIGHTS as $k => $_) {
				if ($k === 'teams') { continue; }
				$store[$k] = array_values(array_unique(array_intersect(array_map('strval', (array)($in[$k] ?? [])), array_keys(Access::editableProfiles()))));
			}
			$store['_profiles'] = array_keys(Access::editableProfiles());
			$this->cfg->setAppValue(Application::APP_ID, 'role_rights', json_encode($store));
		}
		$after = $this->rightsMatrix(); $diff = [];
		foreach ($after as $i => $r) {
			$old = $before[$i]['roles'];
			foreach (array_diff($r['roles'], $old) as $role) { $diff[] = '+' . $role . ':' . $r['key']; }
			foreach (array_diff($old, $r['roles']) as $role) { $diff[] = '-' . $role . ':' . $r['key']; }
		}
		$this->s->audit($this->a->uid(), 'rights', null, $this->request->getParam('reset') ? 'Droits remis par défaut' . ($diff ? ' : ' . implode(' ', $diff) : '') : ($diff ? implode(' ', $diff) : 'aucun changement'));
		return new JSONResponse(['rights' => $after]);
	}

	/** Superviseurs (membres du groupe superviseur) et leurs groupes d'équipe attribués. */
	private function supervisors(): array {
		$cfg = TeamScope::config($this->cfg); $out = [];
		$gids = [Access::groups()['superviseur']];
		foreach (Access::customProfiles() as $cp) { if ($cp['base'] === 'superviseur') { $gids[] = $cp['group']; } }
		$seen = [];
		foreach ($gids as $gid) { foreach ($this->gm->get($gid)?->getUsers() ?? [] as $u) {
			if (isset($seen[$u->getUID()])) { continue; } $seen[$u->getUID()] = true;
			$out[] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName(), 'teams' => $cfg[$u->getUID()] ?? []];
		} }
		usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	/** Administrateurs : groupes d'équipe gérés par un superviseur (aucun = il voit tout, comme avant). */
	#[NoAdminRequired]
	public function supervisorTeams(): JSONResponse {
		if (!$this->a->can('teams')) { return $this->deny(); }
		$uid = trim((string)$this->request->getParam('uid', ''));
		$teams = $this->request->getParam('teams', []);
		$teams = is_array($teams) ? array_values(array_unique(array_intersect(array_map('strval', $teams), array_keys(Access::teams())))) : [];
		if (!in_array($uid, array_column($this->supervisors(), 'uid'), true)) { return $this->err('Superviseur inconnu'); }
		$cfg = TeamScope::config($this->cfg);
		$before = $cfg[$uid] ?? [];
		if ($teams) { $cfg[$uid] = $teams; } else { unset($cfg[$uid]); }
		$this->cfg->setAppValue(Application::APP_ID, TeamScope::CONFIG, json_encode($cfg, JSON_UNESCAPED_UNICODE));
		$names = fn($l) => $l ? implode(', ', array_map(fn($g) => Access::teams()[$g] ?? $g, $l)) : 'tous les groupes';
		$this->s->audit($this->a->uid(), 'supervisor_teams', null, $uid . ' : ' . $names($before) . ' → ' . $names($teams));
		return new JSONResponse(['supervisors' => $this->supervisors()]);
	}

	/**
	 * Écrit l'appartenance via le moteur du groupe et vérifie sa réponse : Group::addUser() ignore le résultat,
	 * or ldap_write_support renvoie false (sans exception) quand l'AD refuse (« Insufficient access »).
	 */
	private function groupWrite($group, $user, bool $add): bool {
		$uid = $user->getUID(); $gid = $group->getGID();
		if (!method_exists($this->gm, 'getBackends')) { $add ? $group->addUser($user) : $group->removeUser($user); return true; }
		foreach ($this->gm->getBackends() as $b) {
			if (!$b->groupExists($gid)) { continue; }
			// Moteurs récents (interfaces) ou anciens (implementsActions : 256 = ajout, 512 = retrait), dont l'AD (Group_Proxy).
			$can = $add ? ($b instanceof \OCP\Group\Backend\IAddToGroupBackend || (method_exists($b, 'implementsActions') && $b->implementsActions(256)))
				: ($b instanceof \OCP\Group\Backend\IRemoveFromGroupBackend || (method_exists($b, 'implementsActions') && $b->implementsActions(512)));
			if (!$can) { continue; }
			return (bool)($add ? $b->addToGroup($uid, $gid) : $b->removeFromGroup($uid, $gid));
		}
		return false;
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
		if ($to !== '__none' && !in_array($to, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Choisissez le commercial destinataire'); }
		if (!is_array($this->request->getParam('ids')) && $from === ($to === '__none' ? '' : $to)) { return $this->err('Le commercial source et le destinataire sont identiques'); }
		$sel = $this->request->getParam('ids');
		if (is_array($sel)) {
			$ids = array_values(array_unique(array_filter(array_map('intval', $sel), fn($x) => $x > 0)));
			if (count($ids) > 2000) { return $this->err('Sélection trop grande (2000 maximum)'); }
			if (!$ids) { return $this->err('Aucun prospect sélectionné'); }
		} else { $ids = $this->s->idsFor($from, $st, $limit); }
		$ids = $this->scopedIds($ids);
		if ($dry) { return new JSONResponse(['count' => count($ids)]); }
		$n = $ids ? $this->s->reassign($ids, $to === '__none' ? null : $to, $this->a->uid()) : 0;
		$this->s->audit($this->a->uid(), 'transfer', null, $n . ' prospect(s) → ' . ($to === '__none' ? 'Non assigné' : $to) . (is_array($sel) ? ' (sélection dans la liste)' : ' depuis ' . ($from === '' ? 'non assignés' : $from) . ($st !== '' ? ', statut ' . $st : '')) . ' · ids : ' . implode(',', array_slice($ids, 0, 30)) . (count($ids) > 30 ? '…' : ''));
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
		$ids = $this->scopedIds($ids);
		if ($dry) { return new JSONResponse(['count' => count($ids)]); }
		$n = $ids ? $this->s->setStatus($ids, $to, $assign, $this->a->uid()) : 0;
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
		if (!in_array($mode, ['auto', 'categorie', 'none'], true)) { return $this->err('Affectation invalide'); }
		// « none » : prospects importés en « Nouveau », non affectés (à répartir plus tard).
		$team = $mode === 'none' ? [] : $this->pickedTeam();
		if ($mode !== 'none' && !$team) { return $this->err('Sélectionnez au moins un commercial à qui affecter les prospects'); }
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
		$n = 0; $skip = 0; $dup = 0; $bad = 0; $na = 0; $unk = 0; $two = 0; $i = 0; $rr = []; $samples = []; $now = time();
		while (($row = fgetcsv($h, 0, $d)) !== false && $n < 20000) {
			$row = array_map($fix, $row);
			if ($i++ === 0) { continue; } // en-tête ignoré
			$row = array_pad($row, 7 + count($exKeys), '');
			if ($row[0] === '') { $skip++; continue; }
			// Case téléphone avec deux numéros (« 0776459672 /0560898201 ») : le 1er en Téléphone 1, le 2e en Numéro WhatsApp.
			[$phone, $second] = $this->splitPhones($row[1]);
			if ($phone === null) { $bad++; if (count($samples) < 10) { $samples[] = 'ligne ' . $i . ' : ' . $row[1]; } continue; }
			if ($this->s->phoneExists($phone)) { $dup++; continue; }
			$wil = Lookup::wilaya($row[2]); $cat = Lookup::categorie($row[3]); $job = Lookup::emploi($row[4]);
			$rem = $row[6]; $why = [];
			if ($row[2] !== '' && $wil === '') { $why[] = 'Wilaya : ' . $row[2]; }
			if ($row[3] !== '' && $cat === '') { $why[] = 'Catégorie : ' . $row[3]; }
			if ($row[4] !== '' && $job === '') { $why[] = 'Emploi : ' . $row[4]; }
			if ($why) { $unk++; $rem = trim($rem . ' [Import, valeur non reconnue — ' . implode(' ; ', $why) . ']'); }
			$to = $mode === 'none' ? null : ($this->pick($mode, $cat, $team, $teams, $rr) ?? $this->pick('auto', $cat, $team, $teams, $rr));
			if ($to === null) { $na++; }
			$extra = [];
			foreach ($exKeys as $xi => $xk) { $xv = trim((string)$row[7 + $xi]); if ($xk && $xv !== '') { $extra[$xk] = mb_substr($xv, 0, 4000); } }
			if ($second !== null) {
				$waKey = null; foreach (Fields::all() as $wf) { if (($wf['type'] ?? '') === 'whatsapp' && empty($wf['builtin']) && empty($wf['hidden'])) { $waKey = $wf['key']; break; } }
				if ($waKey !== null && empty($extra[$waKey])) { $extra[$waKey] = Fields::whatsapp($second) ?? $second; $two++; }
				else { $rem = trim($rem . ' [Autre numéro : ' . $second . ']'); $two++; }
			}
			$this->s->insert('callcrm_prospects', ['source' => 'import', 'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null, 'lastname' => $row[0], 'firstname' => '', 'email' => '', 'phone' => $phone,
				'c1' => '', 'c2' => $cat, 'c3' => $job, 'c4' => Lookup::latin($row[5]), 'c5' => $wil, 'remarque' => $rem,
				'assigned_to' => $to, 'status' => 'nouveau', 'created_at' => $now]);
			$n++;
		}
		fclose($h);
		$this->s->audit($this->a->uid(), 'import', null, $n . ' importé(s), ' . $dup . ' doublon(s), ' . ($mode === 'none' ? 'non affectés (Nouveau)' : 'répartis entre : ' . implode(', ', $team) . ' (' . $mode . ')'));
		return new JSONResponse(['imported' => $n, 'duplicates' => $dup, 'skipped' => $skip, 'invalid' => $bad, 'samples' => $samples, 'unassigned' => $na, 'unrecognized' => $unk, 'two_numbers' => $two]);
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
			if ($to) { $this->s->update((int)$r['id'], ['assigned_to' => $to], $this->a->uid()); $n++; }
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
			if ($to) { $this->s->update((int)$r['id'], ['assigned_to' => $to], $this->a->uid()); $n++; } else { $none++; }
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
		$v += ['source' => $this->request->getParam('origin') === 'appel' ? 'appel' : 'manuel', 'firstname' => '', 'email' => '', 'c1' => '', 'status' => 'nouveau', 'created_at' => time(), 'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null];
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

		$base=$this->request->getParam('_base_note');
		if ($base!==null && (!is_string($base) || $base!==(string)($p['remarque']??''))) return $this->err('La note a changé. Rechargez la fiche avant de sauvegarder.',409);
		try { $this->s->update($id, $v, $this->a->uid(), $p); } catch (\DomainException $e) { return $this->err($e->getMessage(),409); }
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
		try {
			$cfg = Sim::all();
			$r = Sim::compute($cfg, $in);
			$clean = ['w'=>(float)$in['w'], 'l'=>(float)$in['l'], 'hl'=>!empty($in['hl']), 'model'=>$in['model'], 'eq'=>[], 'extras'=>$r['extras_n']];
			foreach ($cfg['equip'] as $e) { $clean['eq'][$e['name']] = !empty($in['eq'][$e['name']]); }
			$note = Sim::summary($cfg, $clean, $r);
			$text = $this->s->saveSimulation($id, $this->a->uid(), $clean, $r, $note, $p['assigned_to']);
		} catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
		catch (\DomainException $e) { return $this->err($e->getMessage(), Http::STATUS_CONFLICT); }
		return new JSONResponse(['ok'=>true, 'remarque'=>$text, 'note'=>$note, 'sims'=>$this->s->simsFor($id)]);
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
