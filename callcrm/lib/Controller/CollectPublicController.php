<?php
namespace OCA\CallCrm\Controller;
use OCA\CallCrm\AppInfo\Application;use OCA\CallCrm\Service\Store;use OCA\CallCrm\Service\Collect;use OCA\CallCrm\Service\ClientActivity;
use OCP\AppFramework\Controller;use OCP\AppFramework\Http\JSONResponse;use OCP\AppFramework\Http\TemplateResponse;use OCP\AppFramework\Http\ContentSecurityPolicy;use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\Attribute\PublicPage;use OCP\AppFramework\Http\Attribute\NoCSRFRequired;use OCP\AppFramework\Http\Attribute\BruteForceProtection;use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\IRequest;use OCP\IURLGenerator;use OCP\IUserManager;use Psr\Log\LoggerInterface;
/**
 * Page publique du client (sans compte). Le jeton du lien est la seule autorisation :
 * 32 caractères aléatoires, stocké haché, durée limitée, fermé après l'envoi.
 * Jetons inconnus : réponse ralentie par la protection anti-force brute de Nextcloud.
 */
class CollectPublicController extends Controller {
 public function __construct(IRequest $request,private Collect $collect,private Store $store,private ClientActivity $activity,private IURLGenerator $url,private IUserManager $users,private LoggerInterface $logger){parent::__construct(Application::APP_ID,$request);}
 private function json(array $d,int $s=200):JSONResponse{return new JSONResponse($d,$s,['Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer']);}
 /** Jeton inconnu : 404 et ralentissement anti-force brute de Nextcloud. */
 private function unknown():JSONResponse{$r=$this->json(['error'=>'Lien invalide.','state'=>'unknown'],404);if(method_exists($r,'throttle'))$r->throttle(['action'=>'callcrm_collect']);return $r;}
 /** Lien utilisable, sinon la réponse à renvoyer (inconnu, expiré, révoqué, déjà envoyé). */
 private function link(string $token):array|JSONResponse{
  $l=$this->collect->open($token);if($l)return $l;
  $any=$this->collect->byToken($token);if(!$any)return $this->unknown();
  $state=$any['state']==='open'?'expired':$any['state'];
  return $this->json(['error'=>['submitted'=>'Vos documents ont déjà été envoyés. Merci !','revoked'=>'Ce lien a été remplacé ou annulé. Demandez un nouveau lien à votre conseiller.','expired'=>'Ce lien a expiré. Demandez un nouveau lien à votre conseiller.','submitting'=>'Envoi en cours…'][$state]??'Lien indisponible.','state'=>$state],410);
 }

 #[PublicPage]
 #[NoCSRFRequired]
 #[BruteForceProtection(action: 'callcrm_collect')]
 public function page(string $token):TemplateResponse{
  $r=new TemplateResponse(Application::APP_ID,'collect',['api'=>rtrim($this->url->linkToRoute('callcrm.page.index'),'/').'/api/public/collecte/'.rawurlencode($token)],TemplateResponse::RENDER_AS_BASE);
  // Scanner : caméra du téléphone, et OpenCV.js (eval + WASM) pour la détection des bords.
  $policy=new FeaturePolicy();$policy->addAllowedCameraDomain("'self'");$r->setFeaturePolicy($policy);
  $csp=new ContentSecurityPolicy();if(method_exists($csp,'allowEvalScript'))$csp->allowEvalScript(true);if(method_exists($csp,'allowEvalWasm'))$csp->allowEvalWasm(true);$r->setContentSecurityPolicy($csp);
  $r->addHeader('Referrer-Policy','no-referrer');$r->addHeader('X-Robots-Tag','noindex, nofollow');
  if(!$this->collect->byToken($token))$r->throttle(['action'=>'callcrm_collect']);
  return $r;
 }

 #[PublicPage]
 #[NoCSRFRequired]
 #[BruteForceProtection(action: 'callcrm_collect')]
 #[AnonRateLimit(limit: 120, period: 600)]
 public function info(string $token):JSONResponse{$l=$this->link($token);return $l instanceof JSONResponse?$l:$this->json($this->collect->publicView($l));}

 #[PublicPage]
 #[NoCSRFRequired]
 #[BruteForceProtection(action: 'callcrm_collect')]
 #[AnonRateLimit(limit: 120, period: 600)]
 public function upload(string $token,int $index):JSONResponse{
  $l=$this->link($token);if($l instanceof JSONResponse)return $l;
  $f=$this->request->getUploadedFile('file');
  if(!$f||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||empty($f['tmp_name'])||!is_uploaded_file($f['tmp_name']))return $this->json(['error'=>in_array($f['error']??0,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)?'Fichier trop volumineux pour le serveur.':'Aucun fichier reçu : vérifiez la connexion et réessayez.'],400);
  if(($f['size']??0)>Collect::MAX_FILE)return $this->json(['error'=>'Fichier supérieur à 20 Mo.'],413);
  try{return $this->json(['ok'=>true]+$this->collect->upload($l,$index,(string)file_get_contents($f['tmp_name']))+['view'=>$this->collect->publicView($l)]);}
  catch(\OutOfBoundsException $e){return $this->json(['error'=>$e->getMessage()],404);}
  catch(\InvalidArgumentException $e){return $this->json(['error'=>$e->getMessage()],400);}
  catch(\Throwable $e){$this->logger->error('Call CRM : dépôt client impossible',['app'=>'callcrm','exception'=>$e]);return $this->json(['error'=>'Erreur technique : réessayez dans un instant.'],500);}
 }

 #[PublicPage]
 #[NoCSRFRequired]
 #[BruteForceProtection(action: 'callcrm_collect')]
 #[AnonRateLimit(limit: 120, period: 600)]
 public function remove(string $token,int $index):JSONResponse{
  $l=$this->link($token);if($l instanceof JSONResponse)return $l;
  $this->collect->remove($l,$index);return $this->json(['ok'=>true,'view'=>$this->collect->publicView($l)]);
 }

 #[PublicPage]
 #[NoCSRFRequired]
 #[BruteForceProtection(action: 'callcrm_collect')]
 #[AnonRateLimit(limit: 20, period: 600)]
 public function submit(string $token):JSONResponse{
  $l=$this->link($token);if($l instanceof JSONResponse)return $l;
  $p=$this->store->get((int)$l['prospect_id']);if(!$p)return $this->json(['error'=>'Dossier indisponible : contactez votre conseiller.'],410);
  // Commercial actuel de la fiche ; à défaut celui du lien.
  $owner=(string)($p['assigned_to']??'');if($owner===''||!$this->users->get($owner))$owner=(string)$l['owner'];
  if($owner===''||!$this->users->get($owner))return $this->json(['error'=>'Dossier sans conseiller : contactez l’agence.'],409);
  try{$r=$this->collect->submit($l,$p,$owner);}
  catch(\InvalidArgumentException $e){return $this->json(['error'=>$e->getMessage()],400);}
  catch(\Throwable $e){$this->logger->error('Call CRM : envoi final des documents client impossible',['app'=>'callcrm','exception'=>$e]);return $this->json(['error'=>'L’envoi n’a pas abouti. Vos documents sont conservés : réessayez dans un instant.'],500);}
  try{$this->activity->event((int)$p['id'],$owner,'documents',['action'=>'received','count'=>$r['count'],'folder'=>$r['folder']]);}catch(\Throwable $e){}
  return $this->json(['ok'=>true,'count'=>$r['count']]);
 }
}
