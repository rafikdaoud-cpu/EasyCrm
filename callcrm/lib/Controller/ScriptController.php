<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, CallScripts, Store};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

/** Guides d'appel : lecture par ceux qui appellent, modification par l'administrateur (droit « fields »). */
class ScriptController extends Controller {
	public function __construct(IRequest $request, private Access $access, private CallScripts $scripts, private Store $store) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function get(): JSONResponse {
		if (!$this->access->can('call') && !$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
		return new JSONResponse($this->scripts->get() + ['kinds' => CallScripts::KINDS]);
	}

	#[NoAdminRequired]
	public function save(): JSONResponse {
		if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
		try {
			$v = $this->scripts->save(['guides' => $this->request->getParam('guides', []), 'objections' => $this->request->getParam('objections', [])]);
		} catch (\InvalidArgumentException $e) { return new JSONResponse(['error' => $e->getMessage()], 400); }
		$this->store->audit($this->access->uid(), 'call_scripts', null, count($v['guides']) . ' guide(s), ' . count($v['objections']) . ' objection(s)');
		return new JSONResponse($v + ['kinds' => CallScripts::KINDS]);
	}

	#[NoAdminRequired]
	public function reset(): JSONResponse {
		if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
		$v = $this->scripts->save(CallScripts::defaults());
		$this->store->audit($this->access->uid(), 'call_scripts', null, 'Guides remis à la première rédaction');
		return new JSONResponse($v + ['kinds' => CallScripts::KINDS]);
	}
}
