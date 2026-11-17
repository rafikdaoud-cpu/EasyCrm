<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, Presence, CallDashboard};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\{IRequest,IGroupManager};

class DashboardController extends Controller {
    public function __construct(IRequest $request, private Access $access, private Presence $presence,
        private CallDashboard $dashboard, private IGroupManager $gm) {
        parent::__construct(Application::APP_ID,$request);
    }
    private function manager(): bool { return in_array($this->access->role(),['admin','superviseur'],true); }
    #[NoAdminRequired]
    public function report(): JSONResponse {
        if (!$this->manager() && $this->access->role()!=='commercial') return new JSONResponse(['error'=>'Accès refusé'],403);
        $users=[];
        if ($this->manager()) foreach ($this->gm->get(Access::groups()['commercial'])?->getUsers()??[] as $u) $users[$u->getUID()]=$u->getDisplayName();
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
    #[NoAdminRequired]
    public function followup(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $id=filter_var($this->request->getParam('id'),FILTER_VALIDATE_INT);$state=$this->request->getParam('state');
        if (!$id || $id<1 || !is_string($state)) return new JSONResponse(['error'=>'Suivi invalide'],400);
        try { $this->dashboard->followup($id,$state,$this->access->uid()); return new JSONResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
    }
}
