<?php
namespace OCA\EasyCrm\Controller;

use OCA\EasyCrm\AppInfo\Application;
use OCA\EasyCrm\Service\Statuses;
use OCA\EasyCrm\Service\Store;
use OCA\EasyCrm\Service\Telephony;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/** Webhooks entrants (fin d'appel du standard, messages WhatsApp reçus). Protégés par un jeton secret. */
class HookController extends Controller {
	public function __construct(IRequest $r, private Store $s, private IConfig $cfg) { parent::__construct(Application::APP_ID, $r); }

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
		$n = 0;
		foreach (Telephony::parseWaInbound($this->payload()) as $m) {
			$p = $m['from'] !== '' ? $this->s->prospectByTail($m['from']) : null;
			if (!$p) { continue; }
			$this->note($p, $p['assigned_to'] ?: 'whatsapp', '💬 WhatsApp reçu : ' . $m['text']);
			$n++;
		}
		return new JSONResponse(['ok' => true, 'logged' => $n]);
	}
}
