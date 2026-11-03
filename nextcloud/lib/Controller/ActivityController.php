<?php
namespace OCA\EasyCrm\Controller;
use OCA\EasyCrm\AppInfo\Application;
use OCA\EasyCrm\Service\Access;
use OCA\EasyCrm\Service\ClientActivity;
use OCA\EasyCrm\Service\Store;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
class ActivityController extends Controller {
 public function __construct(IRequest $request, private Access $access, private ClientActivity $activity, private Store $store) { parent::__construct(Application::APP_ID,$request); }
 private function error(string $message,int $status): JSONResponse { return new JSONResponse(['error'=>$message],$status); }
 private function scope(): ?string { return $this->access->can('all')?null:$this->access->uid(); }
 private function visible(int $id): bool { $p=$this->store->get($id);return $p && ($this->scope()===null || $p['assigned_to']===$this->scope()); }
 #[NoAdminRequired]
 public function agenda(): JSONResponse {
  if (!$this->access->can('view')) return $this->error('Accès refusé',403);
  foreach (['period','type','assigned','q'] as $key) if (!is_string($this->request->getParam($key,''))) return $this->error('Filtre invalide',400);
  try { $result=$this->activity->agenda($this->scope(),$this->request->getParam('period','today'),$this->request->getParam('type','all'),$this->request->getParam('assigned',''),$this->request->getParam('q',''),(int)$this->request->getParam('offset',0),(int)$this->request->getParam('limit',20)); }
  catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),400); }
  if ($this->access->can('mask')) foreach ($result['rows'] as &$r) { $r['phone']='';$r['notes']=''; } unset($r);
  return new JSONResponse($result);
 }
 #[NoAdminRequired]
 public function timeline(int $id): JSONResponse {
  if (!$this->access->can('view')) return $this->error('Accès refusé',403);
  if (!$this->visible($id)) return $this->error('Dossier introuvable',404);
  if ($this->access->can('mask')) return new JSONResponse(['rows'=>[],'has_more'=>false,'cursor'=>'']);
  $kind=$this->request->getParam('kind','all');$cursor=$this->request->getParam('cursor','');
  if (!is_string($kind)||!is_string($cursor)||strlen($cursor)>256) return $this->error('Page invalide',400);
  try { return new JSONResponse($this->activity->timeline($id,$kind,$cursor)); }
  catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),400); }
 }
 #[NoAdminRequired]
 public function appointments(int $id): JSONResponse {
  if (!$this->access->can('view')) return $this->error('Accès refusé',403);
  if (!$this->visible($id)) return $this->error('Dossier introuvable',404);
  return new JSONResponse(['rows'=>$this->activity->appointments($id),'timezone'=>\OCA\EasyCrm\Service\Prefs::all()['tz']]);
 }
 #[NoAdminRequired]
 public function saveAppointment(int $id): JSONResponse {
  if (!$this->access->can('call') && !$this->access->can('assign')) return $this->error('Accès refusé',403);
  if (!$this->visible($id)) return $this->error('Dossier introuvable',404);
  $in=[];foreach (['id','date','title','notes','state','revision'] as $key) { $v=$this->request->getParam($key);if ($v!==null) $in[$key]=$v; }
  try { return new JSONResponse($this->activity->saveAppointment($id,$this->scope(),$this->access->uid(),$in)); }
  catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(),400); }
  catch (\OutOfBoundsException $e) { return $this->error($e->getMessage(),404); }
  catch (\DomainException $e) { return $this->error($e->getMessage(),409); }
 }
}
