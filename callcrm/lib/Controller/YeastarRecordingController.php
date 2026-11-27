<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access,Store,YeastarRecordings};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse,DataDownloadResponse,DataDisplayResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCP\IRequest;

class YeastarRecordingController extends Controller {
    public function __construct(IRequest $request, private Access $access, private Store $store, private YeastarRecordings $recordings, private \OCP\IConfig $config) {
        parent::__construct(Application::APP_ID,$request);
    }
    private function manager(): bool { return in_array($this->access->role(),['admin','superviseur'],true); }
    private function visible(int $pid): bool {
        $p=$this->store->get($pid);
        return $p && ($this->manager() || ($this->access->role()==='commercial' && $p['assigned_to']===$this->access->uid()));
    }
    private function allowed(array $r): bool {
        // Service qualité : écoute tous les appels enregistrés pour les évaluer.
        if ($this->access->can('quality')) return true;
        return $r['prospect_id'] ? $this->visible((int)$r['prospect_id']) : $this->manager();
    }
    private function recordingList(?int $pid): JSONResponse {
        $result = $this->recordings->list($pid,max(0,(int)$this->request->getParam('offset',0)));
        $users = \OCP\Server::get(\OCP\IUserManager::class);
        $names = [];
        foreach ($result['rows'] as &$row) {
            $uid = (string)$row['user_id'];
            $row['user_name'] = $uid === '' ? '' : ($names[$uid] ??= ($users->getDisplayName($uid) ?: ''));
            if ($row['user_name'] === $uid) $row['user_name'] = '';
            unset($row['user_id']);
        }
        unset($row);
        return new JSONResponse($result);
    }
    #[NoAdminRequired]
    public function list(int $id): JSONResponse {
        if(!$this->visible($id))return new JSONResponse(['error'=>'Dossier introuvable'],404);
        return $this->recordingList($id);
    }
    #[NoAdminRequired]
    public function reconcile(int $id): JSONResponse {
        if (!$this->visible($id)) return new JSONResponse(['error'=>'Dossier introuvable'],404);
        $prospect = $this->store->get($id);
        if ($prospect) $this->recordings->attachUnlinkedForProspect($prospect);
        return new JSONResponse(['ok'=>true]);
    }
    #[NoAdminRequired]
    public function inbox(): JSONResponse {
        if(!$this->manager())return new JSONResponse(['error'=>'Accès refusé'],403);
        return $this->recordingList(null);
    }
    #[NoAdminRequired]
    public function callHistory(): JSONResponse {
        $role = $this->access->role();
        $export = $this->request->getParam('export','')==='1';
        if (!$this->access->can('view') || $this->access->can('mask') || (!$this->manager() && $role !== 'commercial') || ($export && !$this->access->can('export'))) {
            return new JSONResponse(['error'=>'Accès refusé'],403);
        }
        $filters = [];
        foreach (['from','to','user','client','number','status','direction','sort','dir'] as $key) {
            $value = $this->request->getParam($key, '');
            if (!is_string($value)) return new JSONResponse(['error'=>'Filtre invalide'],400);
            $filters[$key] = mb_substr(trim($value), 0, 120);
        }
        foreach (['from','to'] as $key) {
            $value = $filters[$key];
            if ($value === '') continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) return new JSONResponse(['error'=>'Date invalide'],400);
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            return new JSONResponse(['error'=>'La date de fin précède la date de début.'],400);
        }
        $filters['number'] = preg_replace('/[^0-9+]/', '', $filters['number']);
        if (preg_match('/^(?:0|(?:00|\+)?213)(\d{9})$/D', $filters['number'], $match)) $filters['number'] = $match[1];
        $uid = $role === 'commercial' ? $this->access->uid() : null;
        $includeAgents = !$export && $uid === null && $this->request->getParam('agents', '') === '1';
        $names = [];
        $users = $uid === null ? \OCP\Server::get(\OCP\IUserManager::class) : null;
        if ($includeAgents || ($uid === null && $filters['sort'] === 'agent')) {
            foreach ($this->recordings->callHistoryAgentIds() as $agent) {
                $names[$agent] = $users->getDisplayName($agent) ?: $agent;
            }
        }
        $result = $this->recordings->callHistory($uid,$filters,$export?0:(int)$this->request->getParam('offset',0),
            $names,$export?20000:50);
        if ($export && $result['has_more']) {
            return new JSONResponse(['error'=>'Plus de 20 000 appels correspondent. Choisissez une période plus courte.'],400);
        }
        if ($users) {
            foreach ($result['rows'] as &$row) {
                $agent = $row['user_id'];
                $row['user_name'] = $agent === '' ? '' : ($names[$agent] ??= $users->getDisplayName($agent) ?: $agent);
            }
            unset($row);
        }
        if ($includeAgents) {
            asort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $result['agents'] = [];
            foreach ($names as $agent => $name) $result['agents'][] = ['uid'=>(string)$agent,'name'=>$name];
        }
        if ($export) {
            $withPhone = $this->access->can('export_phone');
            if (!$withPhone) {
                foreach ($result['rows'] as &$row) unset($row['phone']);
                unset($row);
            }
            $result['export_phone'] = $withPhone;
            $this->store->audit($this->access->uid(),'export',null,count($result['rows']).' appel(s) de l’historique'
                .($withPhone?' avec téléphone':' sans téléphone'));
        }
        return new JSONResponse($result);
    }
    /** Onglet « Enregistrements » : tous les appels, réservé à l'administrateur et aux superviseurs. */
    #[NoAdminRequired]
    public function all(): JSONResponse {
        if(!$this->manager())return new JSONResponse(['error'=>'Accès refusé'],403);
        $p=fn($k)=>trim((string)$this->request->getParam($k,''));
        foreach(['from','to'] as $k)if($p($k)!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$p($k)))return new JSONResponse(['error'=>'Date invalide'],400);
        $phone=preg_replace('/[^0-9+]/','',$p('phone'));
        $f=['q'=>mb_substr($p('q'),0,80),'direction'=>in_array($p('direction'),['inbound','outbound'],true)?$p('direction'):'',
            'user'=>mb_substr($p('user'),0,64),'from'=>$p('from'),'to'=>$p('to'),'audio'=>$p('audio')==='1',
            'link'=>in_array($p('link'),['linked','unlinked'],true)?$p('link'):'','phone'=>mb_substr($phone,0,40)];
        $offset=max(0,(int)$this->request->getParam('offset',0));
        if ($p('group')==='1') return new JSONResponse($this->recordings->searchGrouped($f,max(0,min(600,(int)$this->config->getAppValue('callcrm','missed_min_talk','25'))),$offset));
        return new JSONResponse($this->recordings->search($f,$offset));
    }
    #[NoAdminRequired]
    public function attach(int $id): JSONResponse {
        if(!$this->manager())return new JSONResponse(['error'=>'Accès refusé'],403);
        $pid=(int)$this->request->getParam('prospect_id',0);$r=$this->recordings->find($id);
        if(!$r || !$this->visible($pid))return new JSONResponse(['error'=>'Dossier ou audio introuvable'],404);
        $this->recordings->attach($id,$pid);return new JSONResponse(['ok'=>true]);
    }
    #[NoAdminRequired]
    public function retry(int $id): JSONResponse {
        if(!$this->manager())return new JSONResponse(['error'=>'Accès refusé'],403);
        try{$this->recordings->retry($id);return new JSONResponse(['ok'=>true]);}
        catch(\InvalidArgumentException $e){return new JSONResponse(['error'=>$e->getMessage()],400);}
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function audio(int $id): DataDownloadResponse|DataDisplayResponse|JSONResponse {
        $r=$this->recordings->find($id);
        if(!$r || !$this->allowed($r))return new JSONResponse(['error'=>'Audio introuvable'],404);
        try{$bytes=$this->recordings->bytes($r);}catch(\Throwable $e){return new JSONResponse(['error'=>'Audio indisponible'],404);}
        $name='appel-'.$id.($r['mime']==='audio/wav'?'.wav':'.mp3');
        $response=$this->request->getParam('download','0')==='1' ? new DataDownloadResponse($bytes,$name,$r['mime']) : new DataDisplayResponse($bytes,200,['Content-Type'=>$r['mime']]);
        $response->addHeader('Cache-Control','private, no-store');
        $response->addHeader('X-Content-Type-Options','nosniff');
        return $response;
    }
}
