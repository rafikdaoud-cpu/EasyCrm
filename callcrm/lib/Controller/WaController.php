<?php
namespace OCA\CallCrm\Controller;
use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\Access;
use OCA\CallCrm\Service\Store;
use OCA\CallCrm\Service\Wa;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
/**
 * WhatsApp dans le CRM.
 * Entrées publiques (inbound, status) : appelées par le service wa-nextcloud, protégées par un secret partagé (wa_secret).
 * Envoi : le CRM demande au service local (wa_service, 127.0.0.1) d'envoyer ; le jeton Meta ne quitte jamais le service.
 */
class WaController extends Controller {
	public function __construct(IRequest $r, private Access $a, private Store $s, private Wa $wa, private IGroupManager $gm, private IUserManager $um,
		private IRootFolder $root, private IClientService $http) {
		parent::__construct(Application::APP_ID, $r);
	}
	private function err(string $m, int $c = Http::STATUS_BAD_REQUEST): JSONResponse { return new JSONResponse(['error' => $m], $c); }
	private function deny(): JSONResponse { return $this->err('Accès refusé', Http::STATUS_FORBIDDEN); }
	private function canReply(): bool { return in_array($this->a->role(), ['admin', 'superviseur', 'commercial'], true); }
	private function canAssign(): bool { return in_array($this->a->role(), ['admin', 'superviseur'], true); }
	/** null = voit tout ; sinon uid du commercial (ne voit que ses conversations) */
	private function scope(): ?string { return $this->a->can('all') ? null : $this->a->uid(); }
	private ?array $memoCom = null;
	private function commerciaux(): array {
		if ($this->memoCom !== null) { return $this->memoCom; }
		$g = $this->gm->get(Access::groups()['commercial']);
		return $this->memoCom = $g ? array_values(array_map(fn($u) => $u->getUID(), $g->getUsers())) : [];
	}
	private function name(?string $uid): string { return $uid ? ($this->um->getDisplayName($uid) ?: $uid) : ''; }
	/** Conversation si l'utilisateur a le droit de la voir */
	private function visible(int $id): ?array {
		$c = $this->wa->conv($id);
		if (!$c) { return null; }
		$sc = $this->scope();
		return ($sc === null || $c['owner'] === $sc) ? $c : null;
	}
	private function out(array $c): array {
		return ['id' => (int)$c['id'], 'wa_id' => $c['wa_id'], 'phone' => $c['phone'], 'name' => $c['name'], 'prospect_id' => $c['prospect_id'] ? (int)$c['prospect_id'] : null,
			'prospect_name' => $c['linked'] ? trim(($c['p_lastname'] ?? '') . ' ' . ($c['p_firstname'] ?? '')) : null, 'prospect_status' => $c['linked'] ? $c['p_status'] : null,
			'owner' => $c['owner'], 'owner_name' => $this->name($c['owner']), 'status' => $c['status'], 'unread' => (int)$c['unread'],
			'last_msg_at' => (int)$c['last_msg_at'], 'last_in_at' => (int)$c['last_in_at'], 'preview' => $c['last_preview'],
			'window_open' => $c['window_open'], 'window_ends' => $c['window_ends']];
	}
	private function secretOk(): bool {
		$want = $this->wa->conf('wa_secret');
		$got = (string)$this->request->getHeader('X-CRM-Secret');
		return strlen($want) >= 24 && $got !== '' && hash_equals($want, $got);
	}

	// ---------- appels du service WhatsApp ----------
	#[PublicPage]
	#[NoCSRFRequired]
	public function inbound(): JSONResponse {
		if (!$this->secretOk()) { return $this->deny(); }
		$ev = $this->request->getParams();
		try { $r = $this->wa->record($ev); } catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
		if ($r['created'] && ($ev['direction'] ?? 'in') === 'in') {
			$c = $this->wa->conv($r['conv']);
			$this->s->audit(null, 'wa_in', $c && $c['linked'] ? (int)$c['prospect_id'] : null, ($c['wa_id'] ?? '') . ' → ' . ($c && $c['owner'] ? $c['owner'] : 'file d’attente'));
		}
		return new JSONResponse(['ok' => true] + $r);
	}
	#[PublicPage]
	#[NoCSRFRequired]
	public function status(): JSONResponse {
		if (!$this->secretOk()) { return $this->deny(); }
		$ok = $this->wa->setStatus((string)$this->request->getParam('wamid', ''), (string)$this->request->getParam('status', ''), (string)$this->request->getParam('error', ''));
		return new JSONResponse(['ok' => $ok]);
	}

	// ---------- interface ----------
	#[NoAdminRequired]
	public function list(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$box = (string)$this->request->getParam('box', 'open');
		if (!in_array($box, ['mine', 'queue', 'all', 'open', 'resolved'], true)) { $box = 'open'; }
		if ($box === 'queue' && $this->scope() !== null) { $box = 'mine'; }
		$q = trim((string)$this->request->getParam('q', ''));
		$user = $this->scope() === null ? trim((string)$this->request->getParam('user', '')) : '';
		$lim = 30; $off = max(0, (int)$this->request->getParam('offset', 0));
		$rows = array_map(fn($c) => $this->out($c), $this->wa->search($this->scope(), $box, $q, $user, $lim, $off));
		return new JSONResponse(['rows' => $rows, 'total' => $this->wa->count($this->scope(), $box, $q, $user), 'limit' => $lim]);
	}
	#[NoAdminRequired]
	public function counters(): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$sc = $this->scope();
		// commercial : ses messages non lus ; superviseur / admin / lecture : messages non lus de la file d'attente
		return new JSONResponse(['unread' => $sc === null ? $this->wa->unread(null, 'queue') : $this->wa->unread($sc, 'all'),
			'queue' => $sc === null ? $this->wa->count(null, 'queue') : 0]);
	}
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$c = $this->visible($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$me = $this->a->uid();
		// marquer comme lu : le commercial propriétaire, ou un superviseur pour une conversation en file d'attente
		if ((int)$c['unread'] > 0 && ($c['owner'] === $me || ($c['owner'] === null && $this->canAssign()))) { $this->wa->updateConv($id, ['unread' => 0]); $c['unread'] = 0; }
		$msgs = array_map(function ($m) {
			return ['id' => (int)$m['id'], 'dir' => $m['direction'], 'type' => $m['type'], 'body' => $m['body'], 'file' => $m['file_path'] ? true : false,
				'file_name' => $m['file_name'] ?: ($m['file_path'] ? basename((string)$m['file_path']) : null), 'mime' => $m['mime'], 'user' => $m['user_id'], 'user_name' => $this->name($m['user_id'] && !in_array($m['user_id'], ['auto', 'app'], true) ? $m['user_id'] : null) ?: ($m['user_id'] === 'auto' ? 'Réponse automatique' : ($m['user_id'] === 'app' ? 'Appli WhatsApp' : '')),
				'status' => $m['status'], 'at' => (int)$m['created_at']];
		}, $this->wa->messages($id));
		return new JSONResponse(['conv' => $this->out($c), 'messages' => $msgs, 'can' => ['reply' => $this->canReply(), 'assign' => $this->canAssign()]]);
	}
	/** Conversation liée à une fiche (bloc WhatsApp de la fiche prospect) */
	#[NoAdminRequired]
	public function forProspect(int $pid): JSONResponse {
		if (!$this->a->can('view')) { return $this->deny(); }
		$p = $this->s->get($pid);
		if (!$p || ($this->scope() !== null && $p['assigned_to'] !== $this->a->uid())) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$c = $this->wa->convForProspect($pid, $p['phone'] ?? null);
		if ($c && !$c['linked'] && !empty($p['phone'])) { $this->wa->relinkPhone($p['phone'], $pid); $c = $this->wa->conv((int)$c['id']); }
		return new JSONResponse(['conv' => $c ? $this->out($c) : null]);
	}
	#[NoAdminRequired]
	public function resolve(int $id): JSONResponse {
		$c = $this->visible($id);
		if (!$c || !$this->canReply()) { return $this->deny(); }
		$st = $c['status'] === 'resolu' ? 'ouvert' : 'resolu';
		$this->wa->updateConv($id, ['status' => $st, 'unread' => 0]);
		return new JSONResponse(['ok' => true, 'status' => $st]);
	}
	/** Attribuer : conversation rattachée → on réattribue la fiche (la conversation suit) ; sinon la conversation seule. */
	#[NoAdminRequired]
	public function assign(int $id): JSONResponse {
		if (!$this->canAssign()) { return $this->deny(); }
		$c = $this->wa->conv($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$uid = trim((string)$this->request->getParam('uid', ''));
		if ($uid !== '' && !in_array($uid, $this->commerciaux(), true)) { return $this->err('Utilisateur non commercial'); }
		if ($c['linked']) {
			$this->s->update((int)$c['prospect_id'], ['assigned_to' => $uid === '' ? null : $uid]);
			$this->s->audit($this->a->uid(), 'assign', (int)$c['prospect_id'], ($c['owner'] ?? '—') . ' → ' . ($uid === '' ? '—' : $uid) . ' (depuis WhatsApp)');
		} else {
			$this->wa->updateConv($id, ['assigned_to' => $uid === '' ? null : $uid]);
			$this->s->audit($this->a->uid(), 'wa_assign', null, $c['wa_id'] . ' : ' . ($c['owner'] ?? '—') . ' → ' . ($uid === '' ? '—' : $uid));
		}
		return new JSONResponse(['ok' => true]);
	}
	/** Créer la fiche prospect d'un numéro inconnu (ou rattacher la fiche existante du même numéro). */
	#[NoAdminRequired]
	public function createProspect(int $id): JSONResponse {
		if (!$this->canAssign()) { return $this->deny(); }
		$c = $this->wa->conv($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		if ($c['linked']) { return new JSONResponse(['ok' => true, 'id' => (int)$c['prospect_id'], 'existing' => true]); }
		$phone = Wa::localPhone((string)$c['wa_id']);
		if (!$phone) { return $this->err('Numéro non algérien : la fiche doit être créée à la main.'); }
		$uid = trim((string)$this->request->getParam('uid', ''));
		if ($uid !== '' && !in_array($uid, $this->commerciaux(), true)) { return $this->err('Utilisateur non commercial'); }
		if ($uid === '' && $c['assigned_to']) { $uid = (string)$c['assigned_to']; }
		if ($ex = $this->s->prospectByPhone($phone)) {
			$this->wa->updateConv($id, ['prospect_id' => (int)$ex['id']]);
			return new JSONResponse(['ok' => true, 'id' => (int)$ex['id'], 'existing' => true]);
		}
		$name = trim((string)$this->request->getParam('name', '')) ?: (trim((string)$c['name']) ?: 'Client WhatsApp ' . $phone);
		$cat = trim((string)$this->request->getParam('c2', ''));
		$pid = $this->s->insert('callcrm_prospects', ['lastname' => mb_substr($name, 0, 255), 'firstname' => '', 'email' => '', 'phone' => $phone, 'c1' => '', 'c2' => mb_substr($cat, 0, 255),
			'status' => 'nouveau', 'created_at' => time(), 'assigned_to' => $uid !== '' ? $uid : null, 'last_note' => 'Fiche créée depuis WhatsApp']);
		$this->wa->updateConv($id, ['prospect_id' => $pid]);
		$this->s->audit($this->a->uid(), 'wa_prospect', $pid, $phone . ' → ' . ($uid !== '' ? $uid : 'non assigné'));
		return new JSONResponse(['ok' => true, 'id' => $pid, 'existing' => false]);
	}
	/** Réponse d'un commercial : texte et/ou fichier (photo, PDF…), dans la fenêtre de 24 h. */
	#[NoAdminRequired]
	public function send(int $id): JSONResponse {
		if (!$this->canReply()) { return $this->deny(); }
		$c = $this->visible($id);
		if (!$c) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		if ($this->a->role() === 'commercial' && $c['owner'] !== $this->a->uid()) { return $this->deny(); }
		if (!$c['window_open']) { return $this->err('Plus de 24 h depuis le dernier message du client : WhatsApp n’autorise plus de réponse libre. Utilisez un modèle approuvé ou appelez le client.'); }
		$text = trim((string)$this->request->getParam('text', ''));
		$f = $this->request->getUploadedFile('file');
		$file = null;
		if ($f && !empty($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
			if ((int)$f['size'] > 50 * 1024 * 1024) { return $this->err('Fichier trop volumineux (50 Mo maximum)'); }
			$file = ['name' => basename((string)$f['name']), 'mime' => (string)($f['type'] ?: 'application/octet-stream'), 'b64' => base64_encode((string)file_get_contents($f['tmp_name']))];
		}
		if ($text === '' && !$file) { return $this->err('Message vide'); }
		if (mb_strlen($text) > 4000) { return $this->err('Message trop long (4000 caractères maximum)'); }
		$me = $this->a->uid();
		$payload = ['to' => $c['wa_id'], 'text' => $text, 'user' => $me, 'user_name' => $this->name($me)];
		if ($file) { $payload['file'] = $file; }
		try {
			$res = $this->http->newClient()->post(rtrim($this->wa->conf('wa_service', 'http://127.0.0.1:8085'), '/') . '/send', [
				'headers' => ['X-CRM-Secret' => $this->wa->conf('wa_secret'), 'Content-Type' => 'application/json'], 'body' => json_encode($payload), 'timeout' => 120, 'http_errors' => false, 'nextcloud' => ['allow_local_address' => true]]);
			$j = json_decode((string)$res->getBody(), true) ?: [];
			if ($res->getStatusCode() >= 300 || empty($j['wamid'])) { return $this->err('Envoi refusé : ' . ($j['error'] ?? ('erreur ' . $res->getStatusCode())), Http::STATUS_BAD_GATEWAY); }
		} catch (\Throwable $e) { return $this->err('Service WhatsApp injoignable : ' . $e->getMessage(), Http::STATUS_BAD_GATEWAY); }
		$type = $file ? (str_starts_with($file['mime'], 'image/') ? 'image' : 'document') : 'text';
		$this->wa->record(['wa_id' => $c['wa_id'], 'wamid' => $j['wamid'], 'direction' => 'out', 'type' => $type, 'text' => $text, 'file_path' => $j['file_path'] ?? '',
			'file_name' => $file['name'] ?? '', 'mime' => $file['mime'] ?? '', 'user' => $me, 'ts' => time(), 'status' => 'sent']);
		$this->wa->updateConv($id, ['status' => 'ouvert']);
		return new JSONResponse(['ok' => true, 'wamid' => $j['wamid']]);
	}
	/** Affiche / télécharge une pièce (stockée dans CsmCloud, dossier WhatsApp/<numéro>/ du compte de service) */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function file(int $mid): StreamResponse|JSONResponse {
		$m = $this->wa->message($mid);
		if (!$m || !$m['file_path'] || !$this->visible((int)$m['conv_id'])) { return $this->err('Introuvable', Http::STATUS_NOT_FOUND); }
		$owner = $this->wa->conf('wa_files_user');
		if ($owner === '') { return $this->err('Réglage wa_files_user manquant', Http::STATUS_INTERNAL_SERVER_ERROR); }
		$path = ltrim(str_replace(['..', '\\'], '', (string)$m['file_path']), '/');
		try {
			$node = $this->root->getUserFolder($owner)->get($path);
			if (!($node instanceof \OCP\Files\File)) { throw new \Exception('pas un fichier'); }
			$mime = $node->getMimeType() ?: 'application/octet-stream';
			$r = new StreamResponse($node->fopen('rb'));
			$inline = preg_match('#^(image/|audio/|video/|application/pdf)#', $mime);
			$r->addHeader('Content-Type', $mime);
			$r->addHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($node->getName()) . '"');
			$r->addHeader('X-Content-Type-Options', 'nosniff');
			return $r;
		} catch (\Throwable $e) { return $this->err('Fichier introuvable dans CsmCloud', Http::STATUS_NOT_FOUND); }
	}
}
