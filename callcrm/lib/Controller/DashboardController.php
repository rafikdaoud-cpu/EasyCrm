<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, Presence, CallDashboard, Prefs};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\{IRequest,IGroupManager,IDBConnection};

class DashboardController extends Controller {
    public function __construct(IRequest $request, private Access $access, private Presence $presence,
        private CallDashboard $dashboard, private IGroupManager $gm, private IDBConnection $db) {
        parent::__construct(Application::APP_ID,$request);
    }
    private function manager(): bool { return in_array($this->access->role(),['admin','superviseur'],true); }
    #[NoAdminRequired]
    public function report(): JSONResponse {
        if (!$this->manager() && $this->access->role()!=='commercial') return new JSONResponse(['error'=>'Accès refusé'],403);
        $users=[];
        if ($this->manager()) foreach ($this->gm->get(Access::groups()['commercial'])?->getUsers()??[] as $u) if (\OCA\CallCrm\Service\TeamScope::canTarget($u->getUID())) $users[$u->getUID()]=$u->getDisplayName();
        else $users[$this->access->uid()]='Moi';
        try {
            $filter=['period'=>$this->request->getParam('period','day'),'from'=>$this->request->getParam('from'),'to'=>$this->request->getParam('to')];
            $p=$this->presence->report(array_keys($users),null,$filter);
            $r=$this->dashboard->report($p['range'],$this->manager()?null:$this->access->uid(),array_keys($users));
            $rows=[];
            foreach ($users as $uid=>$name) $rows[]=['uid'=>$uid,'name'=>$name,'telephony'=>$r['agents'][$uid]]+$p['rows'][$uid];
            return new JSONResponse(['rows'=>$rows,'totals'=>$r['totals'],'incoming'=>$r['incoming'],
                'range'=>$p['range'],'timezone'=>$p['timezone'],'server_now'=>$p['server_now']]);
        } catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
    }
    /** Objectifs par commercial (jour, semaine) et appels réalisés ; lecture et écriture réservées aux responsables. */
    #[NoAdminRequired]
    public function goals(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $tz=new \DateTimeZone(Prefs::all()['tz']);$d=(new \DateTime('now',$tz))->setTime(0,0,0);$w=(clone $d)->modify('monday this week');
        $out=[];
        foreach ($this->gm->get(Access::groups()['commercial'])?->getUsers()??[] as $u) {
            $uid=$u->getUID();if(!\OCA\CallCrm\Service\TeamScope::canTarget($uid))continue;$g=Prefs::goalFor($uid);
            $out[]=['uid'=>$uid,'name'=>$u->getDisplayName(),'day'=>$g['day'],'week'=>$g['week'],'personal'=>$g['personal'],
                'today'=>$this->calls($uid,$d->getTimestamp()),'this_week'=>$this->calls($uid,$w->getTimestamp())];
        }
        return new JSONResponse(['rows'=>$out,'default'=>Prefs::all()['goal']]);
    }
    #[NoAdminRequired]
    public function setGoal(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $uid=(string)$this->request->getParam('uid','');
        $known=false;foreach ($this->gm->get(Access::groups()['commercial'])?->getUsers()??[] as $u) if ($u->getUID()===$uid && \OCA\CallCrm\Service\TeamScope::canTarget($uid)) $known=true;
        if (!$known) return new JSONResponse(['error'=>'Commercial inconnu'],400);
        Prefs::setGoal($uid,(int)$this->request->getParam('day',0),(int)$this->request->getParam('week',0));
        return new JSONResponse(['ok'=>true]+Prefs::goalFor($uid));
    }
    private function calls(string $uid,int $since): int {
        $q=$this->db->getQueryBuilder();
        return (int)$q->select($q->func()->count('*'))->from('callcrm_calls')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->gte('created_at',$q->createNamedParameter($since,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
    }
    #[NoAdminRequired]
    public function followup(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $id=filter_var($this->request->getParam('id'),FILTER_VALIDATE_INT);$state=$this->request->getParam('state');
        if (!$id || $id<1 || !is_string($state)) return new JSONResponse(['error'=>'Suivi invalide'],400);
        try { $this->dashboard->followup($id,$state,$this->access->uid()); return new JSONResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
    }
}
