<?php
namespace OCA\EasyCrm\Controller;

use OCA\EasyCrm\AppInfo\Application;
use OCA\EasyCrm\Service\Statuses;
use OCA\EasyCrm\Service\Store;
use OCA\EasyCrm\Service\Telephony;
use OCA\EasyCrm\Service\Wa;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;

/** Webhooks entrants (fin d'appel du standard, messages WhatsApp reçus). Protégés par un jeton secret. */
class HookController extends Controller {
	public function __construct(IRequest $r, private Store $s, private IConfig $cfg, private Wa $wa, private IAppData $appData) { parent::__construct(Application::APP_ID, $r); }

	private function authorized(): bool {
		$secret = Telephony::get()['secret'];
		if ($secret === '') { return false; }
		$tok = (string)($this->request->getParam('token', '') ?: $this->request->getHeader('X-Webhook-Token'));
		return $tok !== '' && hash_equals($secret, $tok);
	}
	private function payload(): array {
		$d = $this->request->getParams();
		foreach (['token', '_route', 'format'] as $k) { unset($d[$k]); }
		if (!$d) { $j = json_decode((string)file_get_contents('php://input'), true); if (is_array($j)) { $d = $j; } }
		return $d;
	}
	private function note(array $p, string $uid, string $text, ?string $result = null): void {
		$this->s->insert('easycrm_calls', ['prospect_id' => (int)$p['id'], 'user_id' => $uid, 'result' => $result ?? $p['status'], 'note' => mb_substr($text, 0, 1000), 'callback_at' => null, 'created_at' => time()]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function pbx(): JSONResponse {
		if (!$this->authorized()) { return new JSONResponse(['error' => 'Jeton invalide'], Http::STATUS_FORBIDDEN); }
		$e = Telephony::parsePbxEvent($this->payload());
		$p = $e['number'] !== '' ? $this->s->prospectByTail($e['number']) : null;
		if (!$p) { return new JSONResponse(['ok' => true, 'matched' => false]); }
		$uids = $e['ext'] !== '' ? $this->cfg->getUsersForUserValue('easycrm', 'ext', $e['ext']) : [];
		$uid = $uids[0] ?? ($p['assigned_to'] ?: 'pbx');
		$t = Telephony::get();
		$map = $e['answered'] ? $t['answered'] : $t['missed'];
		$txt = '☎ ' . ($e['inbound'] ? 'Appel entrant' : 'Appel sortant') . ' — ' . $e['status'] . ($e['duration'] ? ' — ' . $e['duration'] . ' s' : '') . ($e['recording'] ? ' — enregistrement : ' . $e['recording'] : '');
		$result = ($map !== '' && in_array($map, Statuses::results(), true) && !(Statuses::get($map)['callback'] ?? false) && !(Statuses::get($map)['note'] ?? false)) ? $map : null;
		$now = time();
		$this->note($p, $uid, $txt, $result);
		if ($result !== null) { $this->s->update((int)$p['id'], ['status' => $result, 'last_note' => $txt, 'last_call_at' => $now]); }
		return new JSONResponse(['ok' => true, 'matched' => true]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function waVerify(): DataDisplayResponse {
		$q = fn($k) => (string)($this->request->getParam('hub_' . $k, '') ?: $this->request->getParam('hub.' . $k, ''));
		$secret = Telephony::get()['secret'];
		if ($q('mode') === 'subscribe' && $secret !== '' && hash_equals($secret, $q('verify_token'))) { return new DataDisplayResponse($q('challenge')); }
		return new DataDisplayResponse('Forbidden', Http::STATUS_FORBIDDEN);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function wa(): JSONResponse {
		if (!$this->authorized()) { return new JSONResponse(['error' => 'Jeton invalide'], Http::STATUS_FORBIDDEN); }
		$c = Telephony::get()['wa'];
		$ev = Telephony::parseWaEvents($this->payload());
		$n = 0; $rec = 0;
		foreach ($ev['messages'] as $m) {
			if ($m['from'] === '') { continue; }
			$e = ['wamid' => $m['id'], 'wa_id' => $m['from'], 'name' => $m['name'], 'type' => $m['type'], 'text' => $m['text'], 'ts' => $m['ts'], 'direction' => $m['direction'],
				'user' => $m['direction'] === 'out' ? 'app' : ''];
			if ($m['media'] && !$this->wa->known($m['id'])) {
				[$data, $mime, $err] = Telephony::waMedia($c, $m['media']);
				if ($data !== null) {
					$ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'application/pdf' => '.pdf', 'audio/ogg' => '.ogg', 'audio/mpeg' => '.mp3', 'video/mp4' => '.mp4'][strtolower(explode(';', $mime)[0])] ?? '';
					$orig = (string)($m['media']['filename'] ?? '');
					$name = date('Ymd_His') . '_' . substr(preg_replace('/[^A-Za-z0-9]+/', '', $m['id']), -10) . '_' . ($orig !== '' ? preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig) : $m['type'] . $ext);
					try { $this->folder()->newFile($name, $data); $e += ['file_path' => $name, 'file_name' => $orig !== '' ? $orig : $name, 'mime' => $mime]; }
					catch (\Throwable $x) { $e['text'] = trim($e['text'] . ' [pièce jointe non enregistrée]'); }
				} else { $e['text'] = trim($e['text'] . ' [pièce jointe non récupérée : ' . $err . ']'); }
			}
			try { $r = $this->wa->record($e); } catch (\InvalidArgumentException $x) { continue; }
			if (!$r['created']) { continue; }
			$rec++;
			// trace dans l'historique de la fiche (comme avant), pour les messages reçus
			if ($m['direction'] === 'in') {
				$conv = $this->wa->conv($r['conv']);
				if ($conv && $conv['linked'] && ($p = $this->s->get((int)$conv['prospect_id']))) {
					$this->note($p, $p['assigned_to'] ?: 'whatsapp', '💬 WhatsApp reçu : ' . ($m['text'] !== '' ? $m['text'] : '[' . $m['type'] . ']'));
					$n++;
				}
			}
		}
		foreach ($ev['statuses'] as $st) { $this->wa->setStatus($st['id'], $st['status'], $st['error']); }
		return new JSONResponse(['ok' => true, 'logged' => $n, 'recorded' => $rec, 'statuses' => count($ev['statuses'])]);
	}
	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try { return $this->appData->getFolder('wa'); } catch (NotFoundException $e) { return $this->appData->newFolder('wa'); }
	}
}
