<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, ClientNotify, Quality, Store};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUserManager;

/** Qualité : écoute et notation des appels enregistrés (droit « quality »), évaluations reçues par le commercial. */
class QualityController extends Controller {
	public function __construct(IRequest $request, private Access $access, private Quality $quality, private Store $store, private IUserManager $users) {
		parent::__construct(Application::APP_ID, $request);
	}
	private function deny(): JSONResponse { return new JSONResponse(['error' => 'Accès refusé'], 403); }
	private function names(array $rows, array $keys): array {
		$memo = [];
		foreach ($rows as &$r) foreach ($keys as $k => $out) { $u = (string)($r[$k] ?? ''); $r[$out] = $u === '' ? '' : ($memo[$u] ??= ($this->users->getDisplayName($u) ?: $u)); }
		return $rows;
	}

	#[NoAdminRequired]
	public function calls(): JSONResponse {
		if (!$this->access->can('quality')) return $this->deny();
		$p = fn($k) => trim((string)$this->request->getParam($k, ''));
		$sample = (int)$p('sample');
		$r = $sample > 0 ? $this->quality->sample($sample) : $this->quality->calls([
			'agent' => $p('agent'), 'direction' => in_array($p('direction'), ['inbound', 'outbound'], true) ? $p('direction') : '',
			'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $p('from')) ? $p('from') : '', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $p('to')) ? $p('to') : '',
			'min' => (int)$p('min'), 'state' => in_array($p('state'), ['todo', 'done'], true) ? $p('state') : ''], max(0, (int)$p('offset')));
		$r['rows'] = $this->names($r['rows'], ['user_id' => 'agent_name', 'evaluator_uid' => 'evaluator_name']);
		$agents = [];
		foreach (\OCP\Server::get(\OCP\IGroupManager::class)->get(Access::groups()['commercial'])?->getUsers() ?? [] as $u) $agents[] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName()];
		usort($agents, fn($a, $b) => strcmp($a['name'], $b['name']));
		return new JSONResponse($r + ['agents' => $agents, 'grid' => $this->quality->grid()]);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		if (!$this->access->can('quality')) return $this->deny();
		return new JSONResponse(['evaluation' => $this->quality->forRecording($id), 'grid' => $this->quality->grid()]);
	}

	#[NoAdminRequired]
	public function evaluate(int $id): JSONResponse {
		if (!$this->access->can('quality')) return $this->deny();
		$values = $this->request->getParam('values', []);
		try { $e = $this->quality->evaluate($id, $this->access->uid(), is_array($values) ? $values : [], (string)$this->request->getParam('comment', '')); }
		catch (\InvalidArgumentException $x) { return new JSONResponse(['error' => $x->getMessage()], 400); }
		$this->store->audit($this->access->uid(), 'quality_eval', null, 'appel ' . $id . ' · ' . $e['agent_uid'] . ' · ' . $e['score'] . '/100' . ($e['eliminated'] ? ' (éliminatoire)' : ''));
		ClientNotify::send((string)$e['agent_uid'], 'quality_eval', 'quality', (string)$e['id'], ['score' => (int)$e['score'], 'eliminated' => (int)$e['eliminated']]);
		return new JSONResponse(['evaluation' => $e]);
	}

	#[NoAdminRequired]
	public function mine(): JSONResponse {
		if (!$this->access->can('view')) return $this->deny();
		$r = $this->quality->mine($this->access->uid(), max(0, (int)$this->request->getParam('offset', 0)));
		$r['rows'] = $this->names($r['rows'], ['evaluator_uid' => 'evaluator_name']);
		return new JSONResponse($r);
	}

	#[NoAdminRequired]
	public function read(int $id): JSONResponse {
		$this->quality->markRead($id, $this->access->uid());
		ClientNotify::clear($this->access->uid(), 'quality', (string)$id);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function grid(): JSONResponse {
		if (!$this->access->can('quality') && !$this->access->can('fields')) return $this->deny();
		return new JSONResponse($this->quality->grid());
	}

	#[NoAdminRequired]
	public function saveGrid(): JSONResponse {
		if (!$this->access->can('fields')) return $this->deny();
		try { $g = $this->quality->saveGrid(['criteria' => $this->request->getParam('criteria', [])]); }
		catch (\InvalidArgumentException $x) { return new JSONResponse(['error' => $x->getMessage()], 400); }
		$this->store->audit($this->access->uid(), 'quality_grid', null, count($g['criteria']) . ' critère(s)');
		return new JSONResponse($g);
	}
}
