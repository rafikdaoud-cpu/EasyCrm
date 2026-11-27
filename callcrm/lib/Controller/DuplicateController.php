<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, Duplicates, Store};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUserManager;

/** Fiches en double (droit « delete ») : liste, fusion, « pas un doublon ». */
class DuplicateController extends Controller {
	public function __construct(IRequest $request, private Access $access, private Duplicates $dup, private Store $store, private IUserManager $users) {
		parent::__construct(Application::APP_ID, $request);
	}
	private function deny(): JSONResponse { return new JSONResponse(['error' => 'Accès refusé'], 403); }

	#[NoAdminRequired]
	public function groups(): JSONResponse {
		if (!$this->access->can('delete')) return $this->deny();
		$g = $this->dup->groups(); $memo = [];
		$ids = array_merge(...array_map(fn($x) => $x['ids'], $g ?: [['ids' => []]]));
		$calls = $this->dup->callCounts($ids);
		foreach ($g as &$x) foreach ($x['rows'] as &$r) {
			$u = (string)($r['assigned_to'] ?? '');
			$r['assigned_name'] = $u === '' ? '' : ($memo[$u] ??= ($this->users->getDisplayName($u) ?: $u));
			$r['calls'] = $calls[(int)$r['id']] ?? 0;
		}
		unset($x, $r);
		return new JSONResponse(['groups' => $g]);
	}

	#[NoAdminRequired]
	public function merge(): JSONResponse {
		if (!$this->access->can('delete')) return $this->deny();
		$keep = (int)$this->request->getParam('keep', 0);
		$drops = array_values(array_filter(array_map('intval', (array)$this->request->getParam('drop', [])), fn($x) => $x > 0 && $x !== $keep));
		if (!$keep || !$drops) return new JSONResponse(['error' => 'Choisissez la fiche à garder et au moins une fiche à fusionner.'], 400);
		$names = [];
		try {
			foreach ($drops as $d) {
				[$k, $old] = $this->dup->merge($keep, $d);
				$label = trim(($old['lastname'] ?? '') . ' ' . ($old['firstname'] ?? '')) . ' ' . ($old['phone'] ?? '');
				$names[] = $label;
				$this->store->addClientNote($keep, $this->access->uid(), 'Fiche fusionnée : ' . $label . ' (n° ' . $d . '), son historique est rattaché ici.');
				$this->store->audit($this->access->uid(), 'merge', $keep, 'fiche ' . $d . ' (' . $label . ') fusionnée dans ' . $keep);
			}
		} catch (\InvalidArgumentException $e) { return new JSONResponse(['error' => $e->getMessage()], 400); }
		return new JSONResponse(['ok' => true, 'merged' => count($names)]);
	}

	#[NoAdminRequired]
	public function ignore(): JSONResponse {
		if (!$this->access->can('delete')) return $this->deny();
		try { $this->dup->ignore((array)$this->request->getParam('ids', [])); }
		catch (\InvalidArgumentException $e) { return new JSONResponse(['error' => $e->getMessage()], 400); }
		$this->store->audit($this->access->uid(), 'duplicates_ignore', null, 'fiches ' . implode(', ', array_map('intval', (array)$this->request->getParam('ids', []))));
		return new JSONResponse(['ok' => true]);
	}
}
