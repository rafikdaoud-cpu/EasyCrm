<?php
namespace OCA\EasyCrm\Controller;
use OCA\EasyCrm\AppInfo\Application;
use OCA\EasyCrm\Service\Access;
use OCA\EasyCrm\Service\Fields;
use OCA\EasyCrm\Service\Statuses;
use OCA\EasyCrm\Service\Settings;
use OCA\EasyCrm\Service\Phone;
use OCA\EasyCrm\Service\Teams;
use OCA\EasyCrm\Service\Lookup;
use OCA\EasyCrm\Service\Store;
use OCA\EasyCrm\Service\Telephony;
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
	public function __construct(IRequest $r, private Access $a, private Store $s, private IConfig $cfg, private IGroupManager $gm, private IUserManager $um, private ITempManager $tm, private IAppData $appData, private IMailer $mailer, private IURLGenerator $urlGen) {
		parent::__construct(Application::APP_ID, $r);
	}
	private function err(string $m, int $c = Http::STATUS_BAD_REQUEST): JSONResponse { return new JSONResponse(['error' => $m], $c); }
	private function deny(): JSONResponse { return $this->err('Accès refusé', Http::STATUS_FORBIDDEN); }
	private function scope(): ?string { return $this->a->can('all') ? null : $this->a->uid(); }
	/** Retrouve la valeur de liste correspondante (sans tenir compte de la casse ni des accents), sinon chaîne vide */
	private function getTeams(): array { return $this->memoTeams ??= $this->cached('teams', fn() => $this->computeTeams()); }
	private function computeTeams(): array {
		$com = array_column($this->commerciaux(), 'uid'); $out = [];
		foreach (Access::teams() as $gid => $name) {
			$g = $this->gm->get($gid);
			$out[$gid] = $g ? array_values(array_intersect(array_map(fn($u) => $u->getUID(), array_values($g->getUsers())), $com)) : [];
		}
		return $out;
	}
	private function normPhone(string $raw): ?string { return Phone::normalize($raw); }
	private function filters(): array {
		$list = fn($k) => array_values(array_filter(explode('|', (string)$this->request->getParam($k, ''))));
		$res = (string)$this->request->getParam('result', '');
		$f = ['result' => in_array($res, Statuses::keys(), true) ? $res : '', 'cat' => array_values(array_intersect($list('cat'), Teams::categories())),
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
	private ?array $memoCom = null;
	private ?array $memoTeams = null;
	/** Cache court (2 min) : l'énumération des membres de groupes LDAP est lente. */
	private function cached(string $key, callable $fn): array {
		try { $c = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('easycrm'); } catch (\Throwable $e) { $c = null; }
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
		foreach (['call', 'assign', 'create', 'import', 'invite', 'fields', 'delete', 'all'] as $k) { $can[$k] = $this->a->can($k); }
		return new JSONResponse(['statuses' => Statuses::all(), 'fields' => Fields::all(), 'role' => $this->a->role(), 'uid' => $this->a->uid(), 'settings' => Settings::get(), 'phone' => Phone::info(), 'options' => ['c2' => Teams::categories()], 'teams' => $this->getTeams(), 'groups' => array_map(fn($g, $n) => ['id' => $g, 'name' => $n, 'cats' => array_keys(array_filter(Access::cats(), fn($x) => $x === $g))], array_keys(Access::teams()), array_values(Access::teams())), 'commerciaux' => $this->commerciaux(), 'can' => $can, 'tel' => Telephony::mode() + ['ext' => Telephony::extension($this->a->uid())]] + ($this->a->can('fields') ? ['admin' => ['telephony' => Telephony::forAdmin() + ['url_pbx' => $this->hookUrl('pbx'), 'url_wa' => $this->hookUrl('wa'), 'ext' => $this->extensions()], 'countries' => Settings::COUNTRIES, 'teams' => Teams::all(), 'extra' => Teams::extra(), 'roles' => Access::groups()]] : []));
	}

	#[NoAdminRequired]
	public function prospects(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$st = (string)$this->request->getParam('status', '');
		$f = ['q' => trim((string)$this->request->getParam('q', '')), 'status' => in_array($st, Statuses::keys(), true) ? $st : '',
			'assigned' => $this->a->can('all') ? (string)$this->request->getParam('assigned', '') : '', 'rappels' => (bool)$this->request->getParam('rappels', false), 'asc' => (bool)$this->request->getParam('asc', false)];
		$off = max(0, (int)$this->request->getParam('offset', 0));
		$f['sort'] = (string)$this->request->getParam('sort', ''); $f['dir'] = (string)$this->request->getParam('dir', '');
		if ($this->request->getParam('paged')) {
			$lim = (int)$this->request->getParam('limit', 20); if (!in_array($lim, [10, 20, 30], true)) { $lim = 20; }
			return new JSONResponse(['rows' => $this->s->search($this->scope(), $f, $lim, $off), 'total' => $this->s->count($this->scope(), $f), 'limit' => $lim]);
		}
		return new JSONResponse($this->s->search($this->scope(), $f, 50, $off));
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
		return new JSONResponse(['prospect' => $p, 'calls' => $this->s->calls($id), 'recordings' => $recs]);
	}

	#[NoAdminRequired]
	public function call(int $id): JSONResponse {
		if (!$this->a->can('call')) { return $this->deny(); }
		$p = $this->visible($id);
		if (!$p) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		if ($this->a->role() === 'commercial' && $p['assigned_to'] !== $this->a->uid()) { return $this->deny(); }
		$res = (string)$this->request->getParam('result', '');
		$note = trim((string)$this->request->getParam('note', ''));
		$cb = $this->request->getParam('callback_at');
		$cb = ($cb === null || $cb === '' ) ? null : (int)$cb;
		if (!in_array($res, Statuses::results(), true)) { return $this->err('Résultat invalide'); }
		$sd = Statuses::get($res);
		if ($sd['callback'] && !$cb) { return $this->err('Date et heure de rappel obligatoires'); }
		if ($sd['note'] && $note === '') { return $this->err('Une note est obligatoire pour « ' . $sd['label'] . ' »'); }
		if (!$sd['callback']) { $cb = null; }
		$now = time();
		$this->s->insert('easycrm_calls', ['prospect_id' => $id, 'user_id' => $this->a->uid(), 'result' => $res, 'note' => $note, 'callback_at' => $cb, 'created_at' => $now]);
		$this->s->update($id, ['status' => $res, 'callback_at' => $cb, 'last_note' => $note, 'last_call_at' => $now]);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function assign(int $id): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$uid = trim((string)$this->request->getParam('uid', ''));
		if ($uid !== '' && !in_array($uid, array_column($this->commerciaux(), 'uid'), true)) { return $this->err('Utilisateur non commercial'); }
		if (!$this->s->get($id)) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$this->s->update($id, ['assigned_to' => $uid === '' ? null : $uid]);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		if (!$this->a->can('delete')) { return $this->deny(); }
		foreach ($this->s->recordingsFor($id, null) as $r) { try { $this->recFolder()->getFile($r['file'])->delete(); } catch (\Throwable $e) { } }
		$this->s->delete($id);
		return new JSONResponse(['ok' => true]);
	}


	#[NoAdminRequired]
	public function import(): JSONResponse {
		if (!$this->a->can('import')) { return $this->deny(); }
		$f = $this->request->getUploadedFile('file');
		if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) { return $this->err('Fichier manquant'); }
		$mode = (string)$this->request->getParam('assign', '');
		if ($mode !== '' && !in_array($mode, ['auto', 'categorie'], true) && !(str_starts_with($mode, 'grp:') && isset(Access::teams()[substr($mode, 4)]))) { return $this->err('Affectation invalide'); }
		$team = array_column($this->commerciaux(), 'uid');
		$teams = $this->getTeams();
		if ($mode !== '' && !$team) { return $this->err('Aucun commercial dans le groupe « ' . Access::groups()['commercial'] . ' »'); }
		$exKeys = [];
		$fc = array_column(Fields::all(), null, 'key');
		$cust = array_filter($fc, fn($x) => !$x['builtin']);
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
			$wil = Fields::norm($row[2], $fc['c5']); $cat = Fields::norm($row[3], $fc['c2']); $job = Fields::norm($row[4], $fc['c3']);
			$rem = $row[6]; $why = [];
			if ($row[2] !== '' && $wil === '') { $why[] = $fc['c5']['label'] . ' : ' . $row[2]; }
			if ($row[3] !== '' && $cat === '') { $why[] = $fc['c2']['label'] . ' : ' . $row[3]; }
			if ($row[4] !== '' && $job === '') { $why[] = $fc['c3']['label'] . ' : ' . $row[4]; }
			if ($why) { $unk++; $rem = trim($rem . ' [Import, valeur non reconnue — ' . implode(' ; ', $why) . ']'); }
			$to = $this->pick($mode, $cat, $team, $teams, $rr);
			if ($mode !== '' && $to === null) { $na++; }
			$extra = [];
			foreach ($exKeys as $xi => $xk) { $xv = trim((string)$row[7 + $xi]); if ($xk && $xv !== '') { $extra[$xk] = mb_substr($xv, 0, 4000); } }
			$this->s->insert('easycrm_prospects', ['extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null, 'lastname' => $row[0], 'firstname' => '', 'email' => '', 'phone' => $phone,
				'c1' => '', 'c2' => $cat, 'c3' => $job, 'c4' => Lookup::latin($row[5]), 'c5' => $wil, 'remarque' => $rem,
				'assigned_to' => $to, 'status' => 'nouveau', 'created_at' => $now]);
			$n++;
		}
		fclose($h);
		return new JSONResponse(['imported' => $n, 'duplicates' => $dup, 'skipped' => $skip, 'invalid' => $bad, 'samples' => $samples, 'unassigned' => $na, 'unrecognized' => $unk]);
	}

	#[NoAdminRequired]
	public function distribute(): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$mode = (string)$this->request->getParam('mode', '');
		$team = array_column($this->commerciaux(), 'uid');
		if (!in_array($mode, ['auto', 'categorie'], true) && !(str_starts_with($mode, 'grp:') && isset(Access::teams()[substr($mode, 4)]))) { return $this->err('Mode invalide'); }
		if (!$team) { return $this->err('Aucun commercial dans le groupe « ' . Access::groups()['commercial'] . ' »'); }
		$teams = $this->getTeams(); $rr = []; $n = 0;
		foreach ($this->s->unassigned() as $r) {
			$to = $this->pick($mode, (string)$r['c2'], $team, $teams, $rr);
			if ($to) { $this->s->update((int)$r['id'], ['assigned_to' => $to]); $n++; }
		}
		return new JSONResponse(['assigned' => $n]);
	}

	/** Réassigne les prospects dont le commercial n'est plus dans le groupe (superviseur, supprimé...). */
	#[NoAdminRequired]
	public function release(): JSONResponse {
		if (!$this->a->can('assign')) { return $this->deny(); }
		$mode = (string)$this->request->getParam('mode', '');
		$all = (bool)$this->request->getParam('all', false);
		$team = array_column($this->commerciaux(), 'uid');
		if (!$team) { return $this->err('Aucun commercial dans le groupe « ' . Access::groups()['commercial'] . ' » : opération annulée'); }
		if (!in_array($mode, ['auto', 'categorie'], true) && !(str_starts_with($mode, 'grp:') && isset(Access::teams()[substr($mode, 4)]))) { return $this->err('Mode invalide'); }
		$teams = $this->getTeams(); $rr = []; $n = 0; $none = 0;
		foreach ($this->s->orphans($team, !$all) as $r) {
			$to = $this->pick($mode, (string)$r['c2'], $team, $teams, $rr);
			if ($to) { $this->s->update((int)$r['id'], ['assigned_to' => $to]); $n++; } else { $none++; }
		}
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

	#[NoAdminRequired]
	public function export(): DataDownloadResponse|JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$off = (int)$this->request->getParam('tz', 0) * 60;
		$d = fn($t) => $t ? gmdate('d/m/Y H:i', (int)$t + $off) : '';
		$res = (string)$this->request->getParam('result', '');
		$lg = \OCA\EasyCrm\Service\Tr::lang((string)$this->request->getParam('lang', 'fr'));
		$T = fn($s) => \OCA\EasyCrm\Service\Tr::t((string)$s, $lg);
		$f = $this->filters();
		$names = [];
		$who = function ($u) use (&$names) { return $names[$u] ??= ($this->um->getDisplayName($u) ?: $u); };
		$calls = [array_map($T, ['Date et heure', 'Commercial', 'Client', 'Téléphone', 'Catégorie', 'Résultat', 'Note', 'Rappel prévu'])];
		foreach ($this->s->callLog($this->scope(), $f, 20000, 0) as $c) {
			$calls[] = [$d($c['created_at']), $who($c['user_id']), trim($c['lastname'] . ' ' . $c['firstname']), $c['phone'], $c['c2'], $T(Statuses::labels()[$c['result']] ?? $c['result']), (string)$c['note'], $d($c['callback_at'])];
		}
		$fl = array_column(Fields::all(), 'label', 'key');
		$cf = array_values(array_filter(Fields::all(), fn($x) => !$x['builtin']));
		$pros = [array_merge(array_map($T, [$fl['lastname'], $fl['phone'], $fl['c5'], $fl['c2'], $fl['c3'], $fl['c4'], 'Statut', 'Commercial', 'Rappel prévu', 'Heure d’enregistrement', $fl['remarque']]), array_column($cf, 'label'))];
		foreach ($this->s->search($this->scope(), [], 20000, 0) as $p) {
			$pros[] = [trim($p['lastname'] . ' ' . $p['firstname']), $p['phone'], $p['c5'], $p['c2'], $p['c3'], $p['c4'], $T(Statuses::labels()[$p['status']] ?? $p['status']),
				$p['assigned_to'] ? $who($p['assigned_to']) : '', $d($p['callback_at']), $d($p['created_at']), (string)($p['remarque'] ?? '')];
			$ex = !empty($p['extra']) ? (json_decode((string)$p['extra'], true) ?: []) : [];
			foreach ($cf as $c) { $pros[count($pros) - 1][] = (string)($ex[$c['key']] ?? ''); }
		}
		return new DataDownloadResponse($this->xlsx([$T('Appels') => $calls, $T('Prospects') => $pros]), 'easycrm_' . date('Ymd_His') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
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
		$url = $this->urlGen->linkToRouteAbsolute('easycrm.page.index');
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
				if ($val !== '') { $val = $this->normPhone($val); if ($val === null) { return [null, null, 'Numéro de téléphone invalide pour le pays configuré']; } }
			}
			else {
				$n = Fields::norm($val, $f);
				if ($val !== '' && $n === '') { if ($val !== $cur) { return [null, null, $f['label'] . ' : valeur invalide']; } } else { $val = $n; }
			}
			if ($f['required'] && $val === '') { return [null, null, $f['label'] . ' : champ obligatoire']; }
			if ($f['builtin']) { $v[$k] = ($k === 'phone' && $val === '') ? null : $val; } else { if ($val === '') { unset($extra[$k]); } else { $extra[$k] = $val; } }
		}
		return [$v, $extra, null];
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		if (!$this->a->can('create')) { return $this->deny(); }
		[$v, $extra, $err] = $this->buildFields(null);
		if ($err) { return $this->err($err); }
		if (!empty($v['phone']) && $this->s->phoneExists($v['phone'])) { return $this->err('Ce numéro existe déjà dans la base'); }
		$v += ['firstname' => '', 'email' => '', 'c1' => '', 'status' => 'nouveau', 'created_at' => time(), 'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null];
		if ($this->a->role() === 'commercial') { $v['assigned_to'] = $this->a->uid(); }
		else {
			$to = trim((string)$this->request->getParam('assigned_to', ''));
			$v['assigned_to'] = ($to !== '' && in_array($to, array_column($this->commerciaux(), 'uid'), true)) ? $to : null;
		}
		$id = $this->s->insert('easycrm_prospects', $v);
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
		if (!empty($v['phone']) && $v['phone'] !== ($p['phone'] ?? '') && $this->s->phoneExists($v['phone'])) { return $this->err('Ce numéro existe déjà dans la base'); }
		$v['extra'] = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;
		$this->s->update($id, $v);
		return new JSONResponse(['ok' => true]);
	}

	private function hookUrl(string $k): string { return $this->urlGen->getAbsoluteURL($this->urlGen->linkToRoute('easycrm.hook.' . $k)); }
	private function extensions(): array {
		$o = []; foreach ($this->commerciaux() as $u) { $o[$u['uid']] = Telephony::extension($u['uid']); } return $o;
	}
	private function dialFor(string $national): string {
		$c = Telephony::get()['sip']; [$cc, $trunk] = Phone::rules();
		return Telephony::dialNumber($national, $cc, $trunk, (string)($c['dial_format'] ?? 'national'), (string)($c['dial_prefix'] ?? ''));
	}
	private function callable(int $id): array {
		if (!$this->a->can('call')) { return [null, $this->deny()]; }
		$p = $this->visible($id);
		if (!$p) { return [null, $this->err('Introuvable', Http::STATUS_NOT_FOUND)]; }
		if ($this->a->role() === 'commercial' && $p['assigned_to'] !== $this->a->uid()) { return [null, $this->deny()]; }
		if (empty($p['phone'])) { return [null, $this->err('Aucun numéro de téléphone renseigné.')]; }
		return [$p, null];
	}

	#[NoAdminRequired]
	public function pbxCall(int $id): JSONResponse {
		[$p, $e] = $this->callable($id); if ($e) { return $e; }
		$c = Telephony::get()['sip'];
		$num = $this->dialFor((string)$p['phone']);
		if ($c['provider'] === 'none') { return $this->err('Aucun standard téléphonique configuré.'); }
		if ($c['provider'] === 'uri') { return new JSONResponse(['url' => ($c['uri_scheme'] ?: 'tel') . ':' . $num]); }
		[$ok, $msg] = Telephony::sipDial($c, Telephony::extension($this->a->uid()), $num);
		return $ok ? new JSONResponse(['ok' => true, 'message' => $msg]) : $this->err($msg);
	}

	#[NoAdminRequired]
	public function waSend(int $id): JSONResponse {
		[$p, $e] = $this->callable($id); if ($e) { return $e; }
		$c = Telephony::get()['wa'];
		if ($c['provider'] === 'link') { return $this->err('Aucune API WhatsApp configurée.'); }
		$text = trim((string)$this->request->getParam('text', ''));
		if ($text === '') { return $this->err('Message vide'); }
		[$ok, $msg] = Telephony::waSend($c, Phone::whatsapp((string)$p['phone']), mb_substr($text, 0, 4000), (string)($p['firstname'] ?? ''));
		if (!$ok) { return $this->err($msg); }
		$this->s->insert('easycrm_calls', ['prospect_id' => $id, 'user_id' => $this->a->uid(), 'result' => $p['status'], 'note' => '💬 WhatsApp : ' . mb_substr($text, 0, 500), 'callback_at' => null, 'created_at' => time()]);
		return new JSONResponse(['ok' => true, 'message' => $msg]);
	}

	#[NoAdminRequired]
	public function saveTelephony(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		Telephony::save((array)$this->request->getParam('telephony', []));
		foreach ((array)$this->request->getParam('ext', []) as $uid => $ext) {
			if ($this->um->userExists((string)$uid)) { Telephony::setExtension((string)$uid, (string)$ext); }
		}
		return new JSONResponse(['telephony' => Telephony::forAdmin() + ['url_pbx' => $this->hookUrl('pbx'), 'url_wa' => $this->hookUrl('wa'), 'ext' => $this->extensions()]]);
	}
	#[NoAdminRequired]
	public function testTelephony(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$c = Telephony::get();
		[$ok, $msg] = (string)$this->request->getParam('kind', 'sip') === 'wa' ? Telephony::waTest($c['wa']) : Telephony::sipTest($c['sip']);
		return new JSONResponse(['ok' => $ok, 'message' => $msg]);
	}
	#[NoAdminRequired]
	public function newHookSecret(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		return new JSONResponse(['secret' => Telephony::newSecret()]);
	}
	#[NoAdminRequired]
	public function saveExtension(): JSONResponse {
		if (!$this->a->can('call')) { return $this->deny(); }
		Telephony::setExtension($this->a->uid(), (string)$this->request->getParam('ext', ''));
		return new JSONResponse(['ext' => Telephony::extension($this->a->uid())]);
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

	private function forget(): void {
		try { $c = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('easycrm'); foreach (['commerciaux', 'teams'] as $k) { $c->remove($k); } } catch (\Throwable $e) { }
	}
	#[NoAdminRequired]
	public function saveSettings(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$s = Settings::save((array)$this->request->getParam('settings', []));
		foreach ((array)$this->request->getParam('roles', []) as $role => $gid) {
			if (!isset(Access::DEF_GROUPS[$role])) { continue; }
			$gid = mb_substr(trim((string)$gid), 0, 64);
			if ($gid === '' || $gid === Access::DEF_GROUPS[$role]) { $this->cfg->deleteAppValue(Application::APP_ID, 'group_' . $role); $gid = Access::DEF_GROUPS[$role]; }
			else { $this->cfg->setAppValue(Application::APP_ID, 'group_' . $role, $gid); }
			if (!$this->gm->groupExists($gid)) { $this->gm->createGroup($gid); }
		}
		$this->forget();
		return new JSONResponse(['settings' => $s, 'phone' => Phone::info(), 'roles' => Access::groups(), 'fields' => Fields::all()]);
	}
	#[NoAdminRequired]
	public function saveTeams(): JSONResponse {
		if (!$this->a->can('fields')) { return $this->deny(); }
		$t = Teams::save((array)$this->request->getParam('teams', []), $this->request->getParam('extra', []), $this->gm);
		$this->forget();
		return new JSONResponse(['teams' => $t, 'extra' => Teams::extra(), 'options' => ['c2' => Teams::categories()], 'groups' => array_map(fn($g, $n) => ['id' => $g, 'name' => $n, 'cats' => array_keys(array_filter(Access::cats(), fn($x) => $x === $g))], array_keys(Access::teams()), array_values(Access::teams())), 'fields' => Fields::all()]);
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
		$limit = in_array((int)$this->request->getParam('limit', 20), [10, 20, 30], true) ? (int)$this->request->getParam('limit', 20) : 20;
		$off = max(0, (int)$this->request->getParam('offset', 0));
		$total = $this->s->callLogCount($this->scope(), $f);
		$rows = $this->s->callLog($this->scope(), $f, $limit, $off);
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
		$id = $this->s->insert('easycrm_recordings', ['user_id' => $uid, 'prospect_id' => $p ? (int)$p['id'] : null, 'call_id' => $call, 'phone' => $phone ?? mb_substr(preg_replace('/[^\d+]/', '', (string)$this->request->getParam('phone', '')), 0, 32),
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
		return new JSONResponse(['status' => $this->s->statusCounts($sc, $f), 'calls' => $this->s->callCounts($sc, $f), 'users' => $this->commerciaux(), 'hours' => $hours]);
	}
}
