<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, MissedCalls, Prefs};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

/** Appels manqués : le commercial voit les siens et ceux à prendre ; les responsables voient tout et répartissent. */
class MissedController extends Controller {
    public function __construct(IRequest $request, private Access $access, private MissedCalls $missed) {
        parent::__construct(Application::APP_ID, $request);
    }
    private function manager(): bool { return in_array($this->access->role(), ['admin', 'superviseur'], true); }
    private function err(string $m, int $c = 400): JSONResponse { return new JSONResponse(['error' => $m], $c); }
    private function since(string $period): int {
        $d = new \DateTime('now', new \DateTimeZone(Prefs::all()['tz']));
        $d->setTime(0, 0, 0);
        if ($period === 'week') $d->modify('monday this week');
        elseif ($period === 'month') $d->modify('first day of this month');
        return $d->getTimestamp();
    }
    #[NoAdminRequired]
    public function list(): JSONResponse {
        $role = $this->access->role();
        if (!$this->manager() && $role !== 'commercial') return $this->err('Accès refusé', 403);
        $this->missed->sync();
        $uid = $this->manager() ? null : $this->access->uid();
        $period = in_array($this->request->getParam('period', 'day'), ['day', 'week', 'month'], true) ? $this->request->getParam('period', 'day') : 'day';
        return new JSONResponse(['rows' => $this->missed->openList($uid), 'stats' => $this->missed->stats($this->since($period), $uid),
            'commercials' => $this->manager() ? $this->missed->commercials() : null, 'period' => $period]);
    }
    #[NoAdminRequired]
    public function take(int $id): JSONResponse {
        if ($this->access->role() !== 'commercial') return $this->err('Accès refusé', 403);
        return $this->missed->take($id, $this->access->uid()) ? new JSONResponse(['ok' => true]) : $this->err('Cet appel a déjà été pris par un collègue.', 409);
    }
    #[NoAdminRequired]
    public function assign(int $id): JSONResponse {
        if (!$this->manager()) return $this->err('Accès refusé', 403);
        $uid = trim((string)$this->request->getParam('uid', ''));
        if ($uid !== '' && !isset($this->missed->commercials()[$uid])) return $this->err('Commercial inconnu');
        if (!$this->missed->find($id)) return $this->err('Appel manqué introuvable', 404);
        $this->missed->reassign($id, $uid !== '' ? $uid : null);
        return new JSONResponse(['ok' => true]);
    }
    #[NoAdminRequired]
    public function close(int $id): JSONResponse {
        $ep = $this->missed->find($id);
        if (!$ep) return $this->err('Appel manqué introuvable', 404);
        if (!$this->manager() && !($this->access->role() === 'commercial' && $ep['assigned_to'] === $this->access->uid())) return $this->err('Accès refusé', 403);
        try { $this->missed->close($id, $this->access->uid(), (string)$this->request->getParam('how', '')); return new JSONResponse(['ok' => true]); }
        catch (\InvalidArgumentException $e) { return $this->err($e->getMessage()); }
    }
}
