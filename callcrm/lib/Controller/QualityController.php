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
		$rec = $this->quality->recording($id);
		if ($rec) {
			$p = $rec['prospect_id'] ? $this->store->get((int)$rec['prospect_id']) : null;
			$rec = ['id' => (int)$rec['id'], 'phone' => $rec['phone'], 'direction' => $rec['direction'], 'started_at' => $rec['started_at'], 'talk_duration' => $rec['talk_duration'],
				'firstname' => $p['firstname'] ?? '', 'lastname' => $p['lastname'] ?? '', 'agent_name' => $this->users->getDisplayName((string)$rec['user_id']) ?: (string)$rec['user_id']];
		}
		return new JSONResponse(['evaluation' => $this->quality->forRecording($id), 'grid' => $this->quality->grid(), 'recording' => $rec]);
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

	/** Le commercial répond à son évaluation (compris / contesté) ; l'évaluateur est prévenu. */
	#[NoAdminRequired]
	public function ack(int $id): JSONResponse {
		if (!$this->access->can('view')) return $this->deny();
		try { $e = $this->quality->ack($id, $this->access->uid(), (int)$this->request->getParam('state', 0), (string)$this->request->getParam('comment', '')); }
		catch (\InvalidArgumentException $x) { return new JSONResponse(['error' => $x->getMessage()], 400); }
		if (!$e) return $this->deny();
		ClientNotify::clear($this->access->uid(), 'quality', (string)$id);
		$this->store->audit($this->access->uid(), 'quality_ack', null, 'évaluation ' . $id . ' · ' . ((int)$e['ack'] === 2 ? 'contestée' : 'comprise'));
		ClientNotify::send((string)$e['evaluator_uid'], 'quality_ack', 'quality_ack', (string)$e['recording_id'],
			['name' => $this->users->getDisplayName($this->access->uid()) ?: $this->access->uid(), 'state' => (int)$e['ack'], 'text' => mb_substr((string)$e['ack_comment'], 0, 200)]);
		return new JSONResponse(['evaluation' => $e]);
	}

	/** Tableau de bord qualité (droit « quality ») : période en jours (from / to), commercial facultatif. */
	#[NoAdminRequired]
	public function stats(): JSONResponse {
		if (!$this->access->can('quality')) return $this->deny();
		$day = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$this->request->getParam($k, '')) ? strtotime((string)$this->request->getParam($k)) : null;
		$to = ($day('to') ?? strtotime('today')) + 86399; $from = $day('from') ?? strtotime('today -89 days');
		$r = $this->quality->stats($from, $to, trim((string)$this->request->getParam('agent', '')));
		foreach ($r['agents'] as &$a) $a['name'] = $this->users->getDisplayName($a['uid']) ?: $a['uid'];
		unset($a);
		return new JSONResponse($r + ['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to)]);
	}
}
