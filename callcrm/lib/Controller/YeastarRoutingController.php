<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, Store, YeastarRouting};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

/** Administration : appels des clients attribués envoyés directement au poste de leur commercial. */
class YeastarRoutingController extends Controller {
	public function __construct(IRequest $request, private Access $access, private Store $store, private YeastarRouting $routing) { parent::__construct(Application::APP_ID, $request); }
	private function error(string $m, int $s = 400): JSONResponse { return new JSONResponse(['error' => $m], $s); }

	#[NoAdminRequired]
	public function status(): JSONResponse {
		if (!$this->access->can('fields')) return $this->error('Accès refusé', 403);
		return new JSONResponse($this->routing->status());
	}
	#[NoAdminRequired]
	public function configure(): JSONResponse {
		if (!$this->access->can('fields')) return $this->error('Accès refusé', 403);
		$on = filter_var($this->request->getParam('enabled'), FILTER_VALIDATE_BOOLEAN);
		try {
			$r = $this->routing->setEnabled($on);
			$this->store->audit($this->access->uid(), 'yeastar_routing', null, $on ? 'Routage vers le commercial activé' : 'Routage vers le commercial désactivé');
			return new JSONResponse($r);
		} catch (\RuntimeException $e) { return $this->error($e->getMessage(), 502); }
	}
	#[NoAdminRequired]
	public function sync(): JSONResponse {
		if (!$this->access->can('fields')) return $this->error('Accès refusé', 403);
		try { return new JSONResponse($this->routing->sync(true)); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 502); }
	}
}
