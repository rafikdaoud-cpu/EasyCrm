<?php
namespace OCA\CallCrm\Controller;
use OCA\CallCrm\AppInfo\Application;use OCA\CallCrm\Service\Access;use OCA\CallCrm\Service\Store;use OCA\CallCrm\Service\Collect;use OCA\CallCrm\Service\ClientActivity;
use OCP\AppFramework\Controller;use OCP\AppFramework\Http\JSONResponse;use OCP\AppFramework\Http\Attribute\NoAdminRequired;use OCP\IRequest;use OCP\IURLGenerator;
/** Côté CRM (utilisateurs connectés) : réglages, création et suivi des liens de dépôt de documents. */
class CollectController extends Controller {
 public function __construct(IRequest $request,private Access $access,private Store $store,private Collect $collect,private ClientActivity $activity,private IURLGenerator $url){parent::__construct(Application::APP_ID,$request);}
 private function error(string $m,int $s=400):JSONResponse{return new JSONResponse(['error'=>$m],$s);}
 private function manager():bool{return in_array($this->access->role(),['admin','superviseur'],true);}
 private function prospect(int $id):?array{$p=$this->store->get($id);return $p&&$this->access->can('view')&&($this->access->can('all')||$p['assigned_to']===$this->access->uid())?$p:null;}
 private function canSend():bool{return $this->manager()||$this->access->can('call');}
 #[NoAdminRequired]
 /** Liste et validité : réservées à l'administrateur (le commercial ne les voit pas). */
 public function settings():JSONResponse{if(!$this->access->can('fields'))return $this->error('Accès refusé',403);return new JSONResponse($this->collect->settings());}
 #[NoAdminRequired]
 public function configure():JSONResponse{
  if(!$this->access->can('fields'))return $this->error('Accès refusé',403);
  try{return new JSONResponse($this->collect->configure($this->request->getParam('documents'),$this->request->getParam('days')));}catch(\InvalidArgumentException $e){return $this->error($e->getMessage());}
 }
 #[NoAdminRequired]
 public function list(int $id):JSONResponse{if(!$this->prospect($id))return $this->error('Client introuvable',404);return new JSONResponse(array_map(fn($l)=>$this->collect->summary($l),$this->collect->forProspect($id)));}
 #[NoAdminRequired]
 public function create(int $id):JSONResponse{
  $p=$this->prospect($id);if(!$p)return $this->error('Client introuvable',404);if(!$this->canSend())return $this->error('Accès refusé',403);
  $uid=$this->access->uid();$owner=(string)($p['assigned_to']??'')!==''?(string)$p['assigned_to']:$uid;
  // Liste et durée imposées par les réglages de l'administrateur ; toute valeur envoyée par le navigateur est ignorée.
  $s=$this->collect->settings();
  try{$r=$this->collect->create($id,$uid,$owner,$s['documents'],$s['days']);}catch(\InvalidArgumentException $e){return $this->error($e->getMessage());}
  $link=$this->url->linkToRouteAbsolute('callcrm.collect_public.page',['token'=>$r['token']]);
  try{$this->activity->event($id,$uid,'documents',['action'=>'link','count'=>count($this->collect->docs($this->collect->find($r['id']))),'expires_at'=>$r['expires_at']]);}catch(\Throwable $e){}
  return new JSONResponse(['url'=>$link,'link'=>$this->collect->summary($this->collect->find($r['id']))]);
 }
 #[NoAdminRequired]
 public function revoke(int $id):JSONResponse{
  $l=$this->collect->find($id);if(!$l||!$this->prospect((int)$l['prospect_id']))return $this->error('Lien introuvable',404);if(!$this->canSend())return $this->error('Accès refusé',403);
  if($l['state']!=='open')return $this->error('Ce lien n’est plus actif.',409);
  $this->collect->revoke($id);return new JSONResponse(['ok'=>true]);
 }
}
