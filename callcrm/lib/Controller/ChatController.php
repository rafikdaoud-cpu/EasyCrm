<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\Access;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IRequest;

/** Discussion interne (s'appuie sur Nextcloud Talk) : annuaire des personnes du CRM, tous rôles confondus. */
class ChatController extends Controller {
	public function __construct(IRequest $request, private Access $access, private IGroupManager $groups) { parent::__construct(Application::APP_ID, $request); }

	#[NoAdminRequired]
	public function people(): JSONResponse {
		if (!$this->access->can('view')) return new JSONResponse(['error' => 'Accès refusé'], 403);
		$me = $this->access->uid(); $out = [];
		$labels = ['admin' => 'Administrateur', 'superviseur' => 'Superviseur', 'commercial' => 'Commercial', 'lecture' => 'Consultation'];
		$add = function ($user, string $role) use (&$out, $me, $labels) {
			$uid = $user->getUID();
			if ($uid === $me || isset($out[$uid]) || (method_exists($user, 'isEnabled') && !$user->isEnabled())) return;
			$out[$uid] = ['uid' => $uid, 'name' => $user->getDisplayName() ?: $uid, 'role' => $labels[$role] ?? ''];
		};
		$adminGroup = $this->groups->get('admin');
		if ($adminGroup) foreach ($adminGroup->getUsers() as $u) $add($u, 'admin');
		foreach (Access::groups() as $role => $gid) { $g = $this->groups->get($gid); if ($g) foreach ($g->getUsers() as $u) $add($u, $role); }
		$list = array_values($out);
		usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
		return new JSONResponse($list);
	}
}
