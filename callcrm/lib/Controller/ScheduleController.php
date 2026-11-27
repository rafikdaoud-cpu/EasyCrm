<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, Schedule, Store};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

/** Jours et horaires de travail, jours fériés : lecture pour les responsables, modification par l'administrateur (droit « fields »). */
class ScheduleController extends Controller {
	public function __construct(IRequest $request, private Access $access, private Store $store) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function get(): JSONResponse {
		if (!$this->access->can('fields') && !$this->access->can('assign')) return new JSONResponse(['error' => 'Accès refusé'], 403);
		return new JSONResponse(Schedule::current() + ['labels' => Schedule::DAYS]);
	}

	#[NoAdminRequired]
	public function save(): JSONResponse {
		if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
		try {
			$s = Schedule::save(['days' => (array)$this->request->getParam('days', []), 'open' => (string)$this->request->getParam('open', ''), 'close' => (string)$this->request->getParam('close', ''),
				'week_start' => (int)$this->request->getParam('week_start', 7), 'holidays' => (array)$this->request->getParam('holidays', [])]);
		} catch (\InvalidArgumentException $e) { return new JSONResponse(['error' => $e->getMessage()], 400); }
		$kinds = ['open' => 'travaillé', 'brigade' => 'brigade', 'closed' => 'fermé'];
		$this->store->audit($this->access->uid(), 'work_schedule', null, implode(', ', array_map(fn($n) => Schedule::DAYS[$n] . ' ' . $kinds[$s['days'][$n]], array_keys(Schedule::DAYS)))
			. ' · ' . $s['open'] . '–' . $s['close'] . ' · ' . count($s['holidays']) . ' férié(s)');
		return new JSONResponse($s + ['labels' => Schedule::DAYS]);
	}
}
