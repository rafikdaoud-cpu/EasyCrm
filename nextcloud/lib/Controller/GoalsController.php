<?php
namespace OCA\EasyCrm\Controller;

use OCA\EasyCrm\AppInfo\Application;
use OCA\EasyCrm\Service\{Access, Prefs};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\{IRequest, IGroupManager, IDBConnection};

/** Objectifs d'appels par commercial (jour, semaine) : lecture et écriture réservées aux responsables. */
class GoalsController extends Controller {
    public function __construct(IRequest $request, private Access $access, private IGroupManager $gm, private IDBConnection $db) {
        parent::__construct(Application::APP_ID, $request);
    }
    private function manager(): bool { return in_array($this->access->role(), ['admin', 'superviseur'], true); }
    private function users(): array { return $this->gm->get(Access::groups()['commercial'])?->getUsers() ?? []; }
    private function calls(string $uid, int $since): int {
        $q = $this->db->getQueryBuilder();
        return (int)$q->select($q->func()->count('*'))->from('easycrm_calls')->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->gte('created_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
    }
    #[NoAdminRequired]
    public function list(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error' => 'Accès refusé'], 403);
        $d = (new \DateTime('now', new \DateTimeZone(Prefs::all()['tz'])))->setTime(0, 0, 0); $w = (clone $d)->modify('monday this week');
        $out = [];
        foreach ($this->users() as $u) {
            $uid = $u->getUID(); $g = Prefs::goalFor($uid);
            $out[] = ['uid' => $uid, 'name' => $u->getDisplayName(), 'day' => $g['day'], 'week' => $g['week'], 'personal' => $g['personal'],
                'today' => $this->calls($uid, $d->getTimestamp()), 'this_week' => $this->calls($uid, $w->getTimestamp())];
        }
        return new JSONResponse(['rows' => $out, 'default' => Prefs::all()['goal']]);
    }
    #[NoAdminRequired]
    public function save(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error' => 'Accès refusé'], 403);
        $uid = (string)$this->request->getParam('uid', '');
        if (!in_array($uid, array_map(fn($u) => $u->getUID(), $this->users()), true)) return new JSONResponse(['error' => 'Commercial inconnu'], 400);
        Prefs::setGoal($uid, (int)$this->request->getParam('day', 0), (int)$this->request->getParam('week', 0));
        return new JSONResponse(['ok' => true] + Prefs::goalFor($uid));
    }
}
