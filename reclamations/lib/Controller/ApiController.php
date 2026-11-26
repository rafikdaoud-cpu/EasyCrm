<?php
namespace OCA\Reclamations\Controller;
use OCA\Reclamations\AppInfo\Application;
use OCA\Reclamations\Service\Access;
use OCA\Reclamations\Service\Lookup;
use OCA\Reclamations\Service\Notify;
use OCA\Reclamations\Service\Services;
use OCA\Reclamations\Service\Store;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserManager;

class ApiController extends Controller {
	public function __construct(IRequest $r, private Access $a, private Store $s, private IGroupManager $gm, private IUserManager $um, private ITempManager $tm, private Notify $notify) {
		parent::__construct(Application::APP_ID, $r);
	}
	private function err(string $m, int $c = Http::STATUS_BAD_REQUEST): JSONResponse { return new JSONResponse(['error' => $m], $c); }
	private function deny(): JSONResponse { return $this->err('Accès refusé', Http::STATUS_FORBIDDEN); }
	private function scope(): ?string {
		if ($this->a->can('all')) { return null; }
		$this->s->svc = Services::forUser($this->a->uid());
		return $this->a->uid();
	}
	private function name(string $u): string { return $this->um->getDisplayName($u) ?: $u; }
	/** Tous les utilisateurs (comptes Nextcloud et annuaire AD/LDAP), triés par nom. */
	private function agents(): array {
		$out = [];
		foreach ($this->um->search('', 2000) as $u) { $out[] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName() ?: $u->getUID()]; }
		usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}
	private function normPhone(string $raw): string {
		$d = preg_replace('/\D+/', '', Lookup::latin($raw));
		if ($d === '') { return ''; }
		if (str_starts_with($d, '00213')) { $d = substr($d, 5); } elseif (str_starts_with($d, '213') && strlen($d) >= 11) { $d = substr($d, 3); }
		if ($d[0] !== '0') { $d = '0' . $d; }
		return (preg_match('/^0[567]\d{8}$/', $d) || preg_match('/^0[2349]\d{7,8}$/', $d)) ? $d : trim($raw);
	}
	private function visible(int $id): ?array {
		$c = $this->s->get($id);
		if (!$c) { return null; }
		$sc = $this->scope();
		return ($sc === null || $c['assigned_to'] === $sc || $c['created_by'] === $sc || (!empty($c['service']) && in_array($c['service'], $this->s->svc, true))) ? $c : null;
	}
	private function pick(string $k, array $o, string $def = ''): string {
		$v = trim((string)$this->request->getParam($k, $def));
		return in_array($v, $o, true) ? $v : '';
	}
	private function due(string $prio, ?int $from = null): int { return ($from ?? time()) + (Store::SLA_DAYS[$prio] ?? 7) * 86400; }

	#[NoAdminRequired]
	public function me(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$can = [];
		foreach (['create', 'handle', 'assign', 'close', 'import', 'delete', 'all', 'config'] as $k) { $can[$k] = $this->a->can($k); }
		return new JSONResponse(['role' => $this->a->role(), 'uid' => $this->a->uid(), 'can' => $can,
			'status' => Store::STATUS, 'types' => Store::TYPES, 'priorities' => Store::PRIORITIES, 'sla' => Store::SLA_DAYS,
			'categories' => Lookup::CATEGORIES, 'wilayas' => array_keys(Lookup::WILAYAS), 'agents' => $this->agents(), 'services' => Services::all(), 'my_services' => Services::forUser($this->a->uid()),
			'counts' => $this->s->counts($this->scope()), 'late' => $this->s->lateCount($this->scope())]);
	}

	#[NoAdminRequired]
	public function claims(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$f = [];
		foreach (['q', 'status', 'assigned', 'type', 'cat', 'service'] as $k) { $f[$k] = trim((string)$this->request->getParam($k, '')); }
		$f['late'] = (bool)$this->request->getParam('late', false);
		$f['unassigned'] = (bool)$this->request->getParam('unassigned', false);
		// « Mes réclamations » : prises par moi ; « À prendre » : non prises, des groupes dont je suis membre.
		if ($this->request->getParam('mine')) { $f['assigned'] = $this->a->uid(); $f['open'] = true; }
		if ($this->request->getParam('togrp')) { $f['services'] = Services::forUser($this->a->uid()); $f['unassigned'] = true; $f['open'] = true; }
		$sts = trim((string)$this->request->getParam('statuses', ''));
		if ($sts !== '') { $f['statuses'] = array_values(array_intersect(explode(',', $sts), array_keys(Store::STATUS))); }
		$rows = $this->s->search($this->scope(), $f, 300, (int)$this->request->getParam('offset', 0));
		foreach ($rows as &$r) { $r['assigned_name'] = $r['assigned_to'] ? $this->name($r['assigned_to']) : ''; $r['created_name'] = $r['created_by'] ? $this->name($r['created_by']) : ''; }
		return new JSONResponse($rows);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$c = $this->visible($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$c['assigned_name'] = $c['assigned_to'] ? $this->name($c['assigned_to']) : '';
		$c['created_name'] = $c['created_by'] ? $this->name($c['created_by']) : '';
		$ev = $this->s->events($id);
		foreach ($ev as &$e) { $e['user_name'] = $this->name($e['user_id']); }
		return new JSONResponse(['claim' => $c, 'events' => $ev]);
	}

	/** Champs saisissables d'une réclamation */
	private function fields(): array {
		$g = fn($k) => trim((string)$this->request->getParam($k, ''));
		$prio = $this->pick('priority', Store::PRIORITIES, 'Normale') ?: 'Normale';
		return ['client_name' => $g('client_name'), 'phone' => $this->normPhone($g('phone')), 'wilaya' => Lookup::wilaya($g('wilaya')),
			'invoice_no' => $g('invoice_no'), 'product' => $g('product'), 'category' => Lookup::categorie($g('category')),
			'type' => $this->pick('type', Store::TYPES) ?: 'Autre', 'priority' => $prio, 'description' => $g('description')]
			+ (($this->a->can('assign') || $this->a->can('handle')) && $this->request->getParam('service') !== null ? ['service' => $this->pick('service', Services::names()) ?: null] : []);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		if (!$this->a->can('create')) { return $this->deny(); }
		$v = $this->fields();
		if ($v['client_name'] === '') { return $this->err('Le nom du client est obligatoire'); }
		if ($v['description'] === '') { return $this->err('La description est obligatoire'); }
		$now = time();
		$due = (int)$this->request->getParam('due_at', 0);
		$to = trim((string)$this->request->getParam('assigned_to', ''));
		if ($to === '' || !$this->a->can('assign') || !$this->um->userExists($to)) { $to = null; }
		$id = $this->s->create($v + ['status' => 'nouvelle', 'assigned_to' => $to, 'created_by' => $this->a->uid(),
			'due_at' => $due > $now ? $due : $this->due($v['priority'], $now), 'created_at' => $now, 'updated_at' => $now]);
		$this->s->event($id, $this->a->uid(), 'creation', 'nouvelle', 'Réclamation enregistrée');
		if (!empty($v['service'])) { $this->notifyGroup($id, $v['service']); }
		return new JSONResponse(['id' => $id]);
	}

	private function notifyGroup(int $id, string $gid): void {
		$n = $this->notify->group($this->s->get($id), $gid, $this->a->uid());
		$this->s->event($id, $this->a->uid(), 'notification', null, $n . ' membre(s) de ' . $gid . ' prévenu(s) par mail');
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		if (!$this->a->can('handle')) { return $this->deny(); }
		$c = $this->visible($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$v = $this->fields();
		if ($v['client_name'] === '' || $v['description'] === '') { return $this->err('Nom du client et description obligatoires'); }
		$due = (int)$this->request->getParam('due_at', 0);
		$v['due_at'] = $due > 0 ? $due : $c['due_at'];
		$v['updated_at'] = time();
		$this->s->update($id, $v);
		$this->s->event($id, $this->a->uid(), 'edition', null, 'Fiche modifiée');
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function action(int $id): JSONResponse {
		if (!$this->a->can('handle')) { return $this->deny(); }
		$c = $this->visible($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$note = trim((string)$this->request->getParam('note', ''));
		$st = trim((string)$this->request->getParam('status', ''));
		$upd = ['updated_at' => time()];
		$kind = 'note';
		if ($this->request->getParam('take')) {
			// Bouton « Prendre » : réservé aux membres du groupe affecté (ou à qui peut affecter).
			if (empty($c['service'])) { return $this->err('Affectez d’abord la réclamation à un groupe'); }
			if (!in_array($c['service'], Services::forUser($this->a->uid()), true) && !$this->a->can('assign')) { return $this->err('Réservé aux membres de ' . $c['service'], Http::STATUS_FORBIDDEN); }
			if (!in_array($c['status'], Store::OPEN, true)) { return $this->err('Réclamation déjà traitée'); }
			if (!$this->s->take($id, $this->a->uid(), $c['status'] === 'nouvelle')) {
				$now = $this->s->get($id);
				return $this->err('Déjà prise par ' . ($now && $now['assigned_to'] ? $this->name($now['assigned_to']) : 'quelqu’un d’autre'), Http::STATUS_CONFLICT);
			}
			$this->s->event($id, $this->a->uid(), 'affectation', $c['status'] === 'nouvelle' ? 'en_cours' : null, 'Prise en charge par ' . $this->name($this->a->uid()));
			return new JSONResponse(['ok' => true]);
		}
		$newGroup = null;
		if ($this->request->getParam('service') !== null && ($this->a->can('assign') || $this->a->can('handle'))) {
			$sv = trim((string)$this->request->getParam('service'));
			if ($sv !== '' && !in_array($sv, Services::names(), true)) { return $this->err('Service inconnu'); }
			if ($sv !== (string)($c['service'] ?? '')) {
				$upd['service'] = $sv === '' ? null : $sv;
				$this->s->event($id, $this->a->uid(), 'affectation', null, $sv === '' ? 'Groupe retiré' : 'Affectée au groupe ' . $sv);
				$newGroup = $sv === '' ? null : $sv;
			}
		}
		if ($this->request->getParam('assigned_to') !== null && $this->a->can('assign')) {
			$to = trim((string)$this->request->getParam('assigned_to'));
			if ($to !== '' && !$this->um->userExists($to)) { return $this->err('Utilisateur inconnu'); }
			$upd['assigned_to'] = $to === '' ? null : $to;
			if ($to !== ($c['assigned_to'] ?? '')) {
				$this->s->event($id, $this->a->uid(), 'affectation', null, $to === '' ? 'Affectation retirée' : 'Affectée à ' . $this->name($to));
				if ($st === '' && $c['status'] === 'nouvelle' && $to !== '') { $st = 'en_cours'; }
			}
		}
		if ($st !== '') {
			if (!isset(Store::STATUS[$st])) { return $this->err('Statut invalide'); }
			if ($st === 'cloturee' && !$this->a->can('close')) { return $this->err('Seul un superviseur peut clôturer', Http::STATUS_FORBIDDEN); }
			if (in_array($st, ['resolue', 'rejetee'], true) && $note === '') { return $this->err('Une note (solution ou motif) est obligatoire'); }
			if ($st !== $c['status']) {
				$upd['status'] = $st; $kind = 'statut';
				$upd['closed_at'] = in_array($st, ['resolue', 'cloturee', 'rejetee'], true) ? time() : null;
				if (in_array($st, ['resolue', 'rejetee'], true)) { $upd['resolution'] = $note; }
			}
		}
		if ($kind === 'note' && $note === '' && count($upd) === 1) { return $this->err('Rien à enregistrer'); }
		$this->s->update($id, $upd);
		if ($kind === 'statut' || $note !== '') { $this->s->event($id, $this->a->uid(), $kind, $upd['status'] ?? null, $note); }
		if ($newGroup !== null) { $this->notifyGroup($id, $newGroup); }
		return new JSONResponse(['ok' => true]);
	}

	/** Enregistre la liste des services et leurs membres (administrateur). */
	#[NoAdminRequired]
	public function saveServices(): JSONResponse {
		if (!$this->a->can('config')) { return $this->deny(); }
		$in = $this->request->getParam('services', []);
		try { $l = Services::save(is_array($in) ? $in : [], fn($u) => $this->um->userExists($u)); }
		catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
		return new JSONResponse(['services' => $l]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		if (!$this->a->can('delete')) { return $this->deny(); }
		$this->s->delete($id);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function import(): JSONResponse {
		if (!$this->a->can('import')) { return $this->deny(); }
		$f = $this->request->getUploadedFile('file');
		if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) { return $this->err('Fichier manquant'); }
		$h = fopen($f['tmp_name'], 'r');
		$first = (string)fgets($h);
		rewind($h);
		$d = ';';
		foreach ([',', "\t"] as $c) { if (substr_count($first, $c) > substr_count($first, $d)) { $d = $c; } }
		$fix = fn($v) => mb_check_encoding((string)$v, 'UTF-8') ? trim(str_replace("\xEF\xBB\xBF", '', (string)$v)) : trim(mb_convert_encoding((string)$v, 'UTF-8', 'Windows-1252'));
		$k = fn($s) => Lookup::fold($s);
		$typeIdx = []; foreach (Store::TYPES as $t) { $typeIdx[$k($t)] = $t; }
		$n = 0; $skip = 0; $unk = 0; $i = 0; $now = time(); $me = $this->a->uid();
		while (($row = fgetcsv($h, 0, $d)) !== false && $n < 20000) {
			$row = array_map($fix, $row);
			if ($i++ === 0) { continue; }
			$row = array_pad($row, 9, '');
			if ($row[0] === '' || $row[7] === '') { $skip++; continue; }
			$cat = Lookup::categorie($row[5]);
			$type = $typeIdx[$k($row[6])] ?? '';
			$desc = $row[7];
			if ($type === '') { $type = 'Autre'; if ($row[6] !== '') { $unk++; $desc .= ' [Import, type non reconnu : ' . $row[6] . ']'; } }
			$prio = in_array($row[8], Store::PRIORITIES, true) ? $row[8] : 'Normale';
			$id = $this->s->create(['client_name' => $row[0], 'phone' => $this->normPhone($row[1]), 'wilaya' => Lookup::wilaya($row[2]),
				'invoice_no' => $row[3], 'product' => $row[4], 'category' => $cat, 'type' => $type, 'priority' => $prio, 'description' => $desc,
				'status' => 'nouvelle', 'created_by' => $me, 'due_at' => $this->due($prio, $now), 'created_at' => $now, 'updated_at' => $now]);
			$this->s->event($id, $me, 'creation', 'nouvelle', 'Importée');
			$n++;
		}
		fclose($h);
		return new JSONResponse(['imported' => $n, 'skipped' => $skip, 'unrecognized' => $unk]);
	}

	private function range(): array {
		$p = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$this->request->getParam($k, '')) ? strtotime($this->request->getParam($k)) : 0;
		$from = $p('from'); $to = $p('to');
		return ['from' => $from ?: null, 'to' => $to ? $to + 86400 : null];
	}

	#[NoAdminRequired]
	public function stats(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$rows = $this->s->search($this->scope(), $this->range(), 20000, 0);
		$now = time();
		$by = ['status' => [], 'type' => [], 'category' => [], 'agent' => []];
		$late = 0; $sum = 0; $nres = 0;
		foreach ($rows as $r) {
			$by['status'][$r['status']] = ($by['status'][$r['status']] ?? 0) + 1;
			$by['type'][$r['type'] ?: '—'] = ($by['type'][$r['type'] ?: '—'] ?? 0) + 1;
			$by['category'][$r['category'] ?: '—'] = ($by['category'][$r['category'] ?: '—'] ?? 0) + 1;
			$a = $r['assigned_to'] ? $this->name($r['assigned_to']) : 'Non affectée';
			$by['agent'][$a] = ($by['agent'][$a] ?? 0) + 1;
			$open = in_array($r['status'], Store::OPEN, true);
			if ($open && $r['due_at'] && (int)$r['due_at'] < $now) { $late++; }
			if (!$open && $r['closed_at']) { $sum += (int)$r['closed_at'] - (int)$r['created_at']; $nres++; }
		}
		return new JSONResponse(['total' => count($rows), 'late' => $late, 'avg_days' => $nres ? round($sum / $nres / 86400, 1) : null, 'by' => $by]);
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
		$rows = [['Réf.', 'Date', 'Client', 'Téléphone', 'Wilaya', 'N° facture', 'Produit', 'Catégorie', 'Type', 'Priorité', 'Statut', 'Affectée à', 'Service', 'Saisie par', 'Échéance', 'Clôture', 'Description', 'Solution / motif']];
		foreach ($this->s->search($this->scope(), $this->range(), 20000, 0) as $r) {
			$rows[] = [$r['ref'], $d($r['created_at']), $r['client_name'], $r['phone'], $r['wilaya'], $r['invoice_no'], $r['product'], $r['category'], $r['type'], $r['priority'],
				Store::STATUS[$r['status']] ?? $r['status'], $r['assigned_to'] ? $this->name($r['assigned_to']) : '', (string)($r['service'] ?? ''), $r['created_by'] ? $this->name($r['created_by']) : '', $d($r['due_at']), $d($r['closed_at']), (string)$r['description'], (string)$r['resolution']];
		}
		return new DataDownloadResponse($this->xlsx(['Réclamations' => $rows]), 'reclamations_' . date('Ymd_His') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	}
}
