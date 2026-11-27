<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, ClientActivity, Sms, Store};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUserManager;

/** SMS ICOSNET : réglages (administrateur), envoi depuis une fiche client. L'envoi groupé est dans ApiController (mêmes filtres que la liste). */
class SmsController extends Controller {
	public function __construct(IRequest $request, private Access $access, private Store $store, private Sms $sms, private ClientActivity $activity, private IUserManager $users) {
		parent::__construct(Application::APP_ID, $request);
	}
	private function error(string $m, int $s = 400): JSONResponse { return new JSONResponse(['error' => $m], $s); }
	private function admin(): bool { return $this->access->can('fields'); }

	#[NoAdminRequired]
	public function settings(): JSONResponse {
		if (!$this->admin()) return $this->error('Accès refusé', 403);
		return new JSONResponse($this->sms->settings());
	}

	#[NoAdminRequired]
	public function configure(): JSONResponse {
		if (!$this->admin()) return $this->error('Accès refusé', 403);
		$in = [];
		foreach (['enabled', 'url', 'username', 'password', 'clear_password', 'sender', 'dlr', 'max_bulk', 'commercial_send', 'templates', 'auto', 'auto_wa'] as $k) {
			$v = $this->request->getParam($k);
			if ($v !== null) $in[$k] = $v;
		}
		try {
			$before = $this->sms->settings();
			$after = $this->sms->save($in);
			if ($before['enabled'] !== $after['enabled']) $this->store->audit($this->access->uid(), 'sms_settings', null, $after['enabled'] ? 'Envoi de SMS activé' : 'Envoi de SMS désactivé');
			return new JSONResponse($after);
		} catch (\InvalidArgumentException $e) { return $this->error($e->getMessage()); }
	}

	#[NoAdminRequired]
	public function credit(): JSONResponse {
		if (!$this->admin()) return $this->error('Accès refusé', 403);
		try { return new JSONResponse(['credit' => $this->sms->credit()]); }
		catch (\RuntimeException $e) { return $this->error($e->getMessage(), 502); }
	}

	/** SMS de test vers un numéro saisi par l'administrateur (fonctionne même option désactivée, pour valider les accès). */
	#[NoAdminRequired]
	public function test(): JSONResponse {
		if (!$this->admin()) return $this->error('Accès refusé', 403);
		$n = Sms::number((string)$this->request->getParam('phone', ''));
		if (!$n) return $this->error('Numéro mobile algérien invalide (ex. 0550 12 34 56).');
		try {
			$r = $this->sms->send([$n], 'Test CRM : l’envoi de SMS fonctionne.')[$n];
			$this->store->audit($this->access->uid(), 'sms_test', null, $n . ' · ' . ($r['ok'] ? 'envoyé' : $r['error']));
			return $r['ok'] ? new JSONResponse(['ok' => true, 'id' => $r['id']]) : $this->error($r['error'], 502);
		} catch (\RuntimeException | \InvalidArgumentException $e) { return $this->error($e->getMessage(), 502); }
	}

	private function canSend(array $p): bool {
		$rt = $this->sms->runtime();
		if (!$rt['enabled']) return false;
		$role = $this->access->role();
		if (in_array($role, ['admin', 'superviseur'], true)) return true;
		return $role === 'commercial' && $rt['commercial_send'] && ($p['assigned_to'] ?? null) === $this->access->uid();
	}

	#[NoAdminRequired]
	public function send(int $id): JSONResponse {
		$p = $this->store->get($id);
		if (!$p || !$this->access->can('view') || ((!$this->access->can('all') || !\OCA\CallCrm\Service\TeamScope::canSee($p['assigned_to'] ?? null)) && ($p['assigned_to'] ?? null) !== $this->access->uid())) return $this->error('Client introuvable', 404);
		if (!$this->canSend($p)) return $this->error('L’envoi de SMS n’est pas disponible pour votre compte.', 403);
		$n = Sms::number((string)($p['phone'] ?? ''));
		if (!$n) return $this->error('Le numéro de ce client n’est pas un mobile algérien valide.');
		$uid = $this->access->uid();
		$text = Sms::render(str_replace("\r", '', (string)$this->request->getParam('text', '')), $p, $this->users->get($uid)?->getDisplayName() ?? $uid);
		try { Sms::check($text); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage()); }
		try { $r = $this->sms->send([$n], $text)[$n]; } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 502); }
		$seg = Sms::segments($text);
		try { $this->activity->event($id, $uid, 'sms', ['text' => $text, 'ok' => $r['ok'], 'error' => $r['error'], 'message_id' => $r['id'], 'segments' => $seg['segments']]); } catch (\Throwable $e) {}
		$this->store->audit($uid, 'sms', $id, ($r['ok'] ? 'envoyé' : 'échec : ' . $r['error']) . ' · ' . $seg['segments'] . ' SMS');
		return $r['ok'] ? new JSONResponse(['ok' => true, 'segments' => $seg['segments']]) : $this->error($r['error'], 502);
	}
}
