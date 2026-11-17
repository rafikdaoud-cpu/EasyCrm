<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access,Store,YeastarRecordings};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{JSONResponse,DataDownloadResponse,DataDisplayResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCP\IRequest;

class YeastarRecordingController extends Controller {
    public function __construct(IRequest $request, private Access $access, private Store $store, private YeastarRecordings $recordings) {
        parent::__construct(Application::APP_ID,$request);
    }
    private function manager(): bool { return in_array($this->access->role(),['admin','superviseur'],true); }
    private function visible(int $pid): bool {
        $p=$this->store->get($pid);
        return $p && ($this->manager() || ($this->access->role()==='commercial' && $p['assigned_to']===$this->access->uid()));
    }
    private function allowed(array $r): bool {
        return $r['prospect_id'] ? $this->visible((int)$r['prospect_id']) : $this->manager();
    }
    #[NoAdminRequired]
    public function list(int $id): JSONResponse {
        if(!$this->visible($id))return new JSONResponse(['error'=>'Dossier introuvable'],404);
        return new JSONResponse($this->recordings->list($id,max(0,(int)$this->request->getParam('offset',0))));
    }
    #[NoAdminRequired]
    public function inbox(): JSONResponse {
        if(!$this->manager())return new JSONResponse(['error'=>'Accès refusé'],403);
        return new JSONResponse($this->recordings->list(null,max(0,(int)$this->request->getParam('offset',0))));
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
