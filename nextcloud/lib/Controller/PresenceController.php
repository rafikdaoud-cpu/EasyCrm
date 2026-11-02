<?php
namespace OCA\EasyCrm\Controller;
use OCA\EasyCrm\AppInfo\Application;
use OCA\EasyCrm\Service\Access;
use OCA\EasyCrm\Service\Presence;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class PresenceController extends Controller {
	public function __construct(IRequest $r, private Access $a, private Presence $presence, private IGroupManager $gm) {
		parent::__construct(Application::APP_ID, $r);
	}
	private function filter(): array {
		return ['period'=>$this->request->getParam('period','day'), 'from'=>$this->request->getParam('from'), 'to'=>$this->request->getParam('to')];
	}
	private function denied(): JSONResponse { return new JSONResponse(['error' => 'Accès refusé'], 403); }
	#[NoAdminRequired]
	public function heartbeat(): JSONResponse {
		if ($this->a->role() !== 'commercial') { return $this->denied(); }
		return new JSONResponse($this->presence->heartbeat($this->a->uid()));
	}
	#[NoAdminRequired]
	public function pause(): JSONResponse {
		if ($this->a->role() !== 'commercial') { return $this->denied(); }
		$paused = $this->request->getParam('paused');
		if (!is_bool($paused)) { return new JSONResponse(['error' => 'État de pause invalide'], 400); }
		$kind = $this->request->getParam('kind','pause');
		if (!is_string($kind) || !in_array($kind,['pause','lunch'],true)) { return new JSONResponse(['error'=>'Type de pause invalide'],400); }
		try { return new JSONResponse($this->presence->pause($this->a->uid(), $paused, $kind)); }
		catch (\DomainException $e) { return new JSONResponse(['error'=>$e->getMessage()],409); }
	}
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		if ($this->a->role() !== 'commercial') { return $this->denied(); }
		$uid = $this->a->uid();
		try { $report = $this->presence->report([$uid],null,$this->filter()); }
		catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
		return new JSONResponse(['totals'=>$report['rows'][$uid]['totals'], 'periods'=>$report['periods'], 'timezone'=>$report['timezone'], 'server_now'=>$report['server_now'], 'range'=>$report['range'], 'processed'=>$report['rows'][$uid]['processed'], 'by_result'=>$report['rows'][$uid]['by_result']]);
	}
	#[NoAdminRequired]
	public function roster(): JSONResponse {
		if (!in_array($this->a->role(), ['admin', 'superviseur'], true)) { return $this->denied(); }
		$users = $this->gm->get(Access::groups()['commercial'])?->getUsers() ?? [];
		$users = array_values($users);
		try { $report = $this->presence->report(array_map(fn($u) => $u->getUID(), $users),null,$this->filter()); }
		catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
		$report['rows'] = array_map(fn($u) => ['uid'=>$u->getUID(), 'name'=>$u->getDisplayName()] + $report['rows'][$u->getUID()], $users);
		return new JSONResponse($report);
	}
}
