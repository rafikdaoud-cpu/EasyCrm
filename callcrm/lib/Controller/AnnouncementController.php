<?php
namespace OCA\CallCrm\Controller;
use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\Access;
use OCA\CallCrm\Service\Announcements;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

class AnnouncementController extends Controller {
	public function __construct(IRequest $r, private Access $a, private Announcements $announcements, private IGroupManager $gm, private IUserManager $um) {
		parent::__construct(Application::APP_ID, $r);
	}
	private function error(string $message, int $status = 400): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}

	#[NoAdminRequired]
	public function send(): JSONResponse {
		if (!in_array($this->a->role(), ['admin', 'superviseur'], true)) { return $this->error('Accès refusé', 403); }
		$body = $this->request->getParam('body', '');
		$uids = $this->request->getParam('uids', []);
		if (!is_string($body) || trim($body) === '' || mb_strlen($body) > 2000) {
			return $this->error('Saisissez un message de 1 à 2 000 caractères.');
		}
		if (!is_array($uids) || !$uids || count($uids) > 1000) { return $this->error('Choisissez de 1 à 1 000 commerciaux.'); }
		$group = Access::groups()['commercial'];
		$recipients = [];
		foreach ($uids as $uid) {
			if (!is_string($uid) || $uid === '' || !$this->gm->isInGroup($uid, $group)) {
				return $this->error('Un destinataire ne fait pas partie des commerciaux.');
			}
			$recipients[] = $uid;
		}
		$recipients = array_values(array_unique($recipients));
		$sender = $this->a->uid();
		$name = $this->um->get($sender)?->getDisplayName() ?: $sender;
		$this->announcements->send($sender, mb_substr($name, 0, 255), $recipients, trim($body));
		return new JSONResponse(['sent' => count($recipients)]);
	}

	#[NoAdminRequired]
	public function pending(): JSONResponse {
		if ($this->a->role() !== 'commercial') { return $this->error('Accès refusé', 403); }
		return new JSONResponse($this->announcements->pending($this->a->uid()));
	}

	#[NoAdminRequired]
	public function dismiss(int $id): JSONResponse {
		if ($this->a->role() !== 'commercial') { return $this->error('Accès refusé', 403); }
		$this->announcements->dismiss($id, $this->a->uid());
		return new JSONResponse(['ok' => true]);
	}
}
