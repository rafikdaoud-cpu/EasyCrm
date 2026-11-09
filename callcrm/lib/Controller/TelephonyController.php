<?php
namespace OCA\CallCrm\Controller;
use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access,Store,YeastarSettings,YeastarClient,YeastarEvents};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired,PublicPage};
use OCP\IRequest;

class TelephonyController extends Controller {
 public function __construct(IRequest $request,private Access $access,private Store $store,private YeastarSettings $settings,private YeastarClient $client,private YeastarEvents $events){parent::__construct(Application::APP_ID,$request);}
 private function error(string $text,int $status=400):JSONResponse{return new JSONResponse(['error'=>$text],$status);}
 private function visible(?array $p):bool{return $p&&$this->access->can('view')&&($this->access->can('all')||$p['assigned_to']===$this->access->uid());}
 private function extension():string{foreach($this->settings->settings()['extensions'] as $m)if($m['uid']===$this->access->uid())return $m['extension'];return '';}
 #[NoAdminRequired]
 public function runtime():JSONResponse{
  if(!$this->access->can('view'))return $this->error('Accès refusé',403);
  $s=$this->settings->settings();return new JSONResponse(['enabled'=>$s['enabled'],'extension'=>$this->extension(),'linkus_url'=>$s['linkus_url']]);
 }
 #[NoAdminRequired]
 public function test():JSONResponse{
  if(!$this->access->can('fields'))return $this->error('Accès refusé',403);
  try{$this->client->test();return new JSONResponse(['ok'=>true]);}catch(\RuntimeException $e){return $this->error($e->getMessage());}
 }
 #[NoAdminRequired]
 public function dial():JSONResponse{
  if(!$this->access->can('call'))return $this->error('Accès refusé',403);
  if(!$this->settings->settings()['enabled'])return $this->error('Activez Yeastar dans les réglages.');
  $phone=(string)$this->request->getParam('phone','');$p=$this->events->match($phone);
  if(!$this->visible($p))return $this->error('Dossier introuvable, inaccessible ou numéro partagé par plusieurs clients.',404);
  $ext=$this->extension();if($ext==='')return $this->error('Aucune extension Yeastar associée à votre compte.');
  try{
   $call=$this->client->dial($ext,YeastarEvents::number($p['phone']));
   // The PBX action cannot be rolled back; never report a failed call because logging failed.
   try{$this->events->record(hash('sha256','dial|'.($call?:bin2hex(random_bytes(16)))),$call,$this->access->uid(),YeastarEvents::number($p['phone']),'outbound','REQUESTED',(int)$p['id']);}catch(\Throwable $e){}
   return new JSONResponse(['ok'=>true,'message'=>'Appel demandé. Répondez dans Linkus Web.']);
  }catch(\RuntimeException|\InvalidArgumentException $e){return $this->error($e->getMessage());}
 }
 #[NoAdminRequired]
 public function incoming():JSONResponse{
  if(!$this->access->can('view'))return $this->error('Accès refusé',403);
  if(!$this->settings->settings()['enabled']||$this->extension()==='')return new JSONResponse(['rows'=>[]]);
  $rows=[];
  foreach($this->events->recent($this->access->uid(),(int)$this->request->getParam('after',0)) as $r){
   $p=$r['prospect_id']?$this->store->get((int)$r['prospect_id']):null;
   $allowed=$this->visible($p);
   $rows[]=['id'=>(int)$r['id'],'call_id'=>$r['call_id'],'phone'=>$r['phone'],'direction'=>$r['direction'],'state'=>$r['state'],'prospect_id'=>$allowed?(int)$p['id']:null,'name'=>$allowed?trim($p['lastname'].' '.($p['firstname']??'')):null];
  }return new JSONResponse(['rows'=>$rows]);
 }
 #[NoAdminRequired]
 public function history(int $id):JSONResponse{
  if(!$this->visible($this->store->get($id)))return $this->error('Dossier introuvable',404);
  return new JSONResponse($this->events->history($id));
 }
 #[PublicPage]
 #[NoCSRFRequired]
 public function webhook():JSONResponse{
  $raw=file_get_contents('php://input',false,null,0,1048577);
  if($raw===false||strlen($raw)>1048576)return $this->error('Message trop volumineux',413);
  try{$this->events->receive($raw,(string)$this->request->getHeader('X-Signature'));return new JSONResponse(['ok'=>true]);}
  catch(\DomainException $e){return $this->error('Signature refusée',403);}
  catch(\JsonException|\InvalidArgumentException $e){return $this->error('Événement invalide');}
 }
}
