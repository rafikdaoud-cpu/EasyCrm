<?php
namespace OCA\EasyCrm\Controller;
use OCA\EasyCrm\AppInfo\Application;use OCA\EasyCrm\Service\Access;use OCA\EasyCrm\Service\Store;use OCA\EasyCrm\Service\Documents;use OCA\EasyCrm\Service\DocumentMailbox;
use OCP\AppFramework\Controller;use OCP\AppFramework\Http\JSONResponse;use OCP\AppFramework\Http\DataDownloadResponse;use OCP\AppFramework\Http\Attribute\NoAdminRequired;use OCP\AppFramework\Http\Attribute\NoCSRFRequired;use OCP\IRequest;
class DocumentController extends Controller {
 public function __construct(IRequest $request,private Access $access,private Store $store,private Documents $documents,private DocumentMailbox $mailbox){parent::__construct(Application::APP_ID,$request);}
 private function error(string $message,int $status=400):JSONResponse{return new JSONResponse(['error'=>$message],$status);}
 private function manager():bool{return in_array($this->access->role(),['admin','superviseur'],true);}
 private function visible(int $id):bool{$p=$this->store->get($id);return $p&&$this->access->can('view')&&($this->access->can('all')||$p['assigned_to']===$this->access->uid());}
 private function canDocument(array $row):bool{return $row['prospect_id']?$this->visible((int)$row['prospect_id']):$this->manager();}
 #[NoAdminRequired]
 public function list(int $id):JSONResponse{if(!$this->visible($id))return $this->error('Dossier introuvable',404);return new JSONResponse($this->documents->list($id,(int)$this->request->getParam('offset',0)));}
 #[NoAdminRequired]
 public function inbox():JSONResponse{if(!$this->manager())return $this->error('Accès refusé',403);return new JSONResponse($this->documents->list(null,(int)$this->request->getParam('offset',0)));}
 #[NoAdminRequired]
 public function settings():JSONResponse{if(!$this->access->can('fields'))return $this->error('Accès refusé',403);return new JSONResponse($this->documents->settings());}
 #[NoAdminRequired]
 public function configure():JSONResponse{if(!$this->access->can('fields'))return $this->error('Accès refusé',403);$in=[];foreach(['mail_enabled','mail_host','mail_port','mail_security','mail_user','mail_folder','password']as $key){$v=$this->request->getParam($key);if($v!==null)$in[$key]=$v;}try{return new JSONResponse($this->documents->configure($in));}catch(\InvalidArgumentException $e){return $this->error($e->getMessage());}}
 #[NoAdminRequired]
 public function test():JSONResponse{if(!$this->access->can('fields'))return $this->error('Accès refusé',403);try{$this->mailbox->test();return new JSONResponse(['ok'=>true]);}catch(\Throwable $e){return $this->error($e->getMessage());}}
 #[NoAdminRequired]
 public function attach(int $id):JSONResponse{if(!$this->manager())return $this->error('Accès refusé',403);$pid=(int)$this->request->getParam('prospect_id',0);if(!$this->visible($pid))return $this->error('Client introuvable',404);try{$this->documents->attach($id,$pid);return new JSONResponse(['ok'=>true]);}catch(\OutOfBoundsException $e){return $this->error($e->getMessage(),404);}}
 #[NoAdminRequired]
 public function retry(int $id):JSONResponse{$row=$this->documents->find($id);if(!$row||!$this->canDocument($row))return $this->error('Pièce introuvable',404);if(!$this->manager()&&!$this->access->can('call'))return $this->error('Accès refusé',403);try{$this->documents->retry($id);return new JSONResponse(['ok'=>true]);}catch(\InvalidArgumentException $e){return $this->error($e->getMessage());}}
 #[NoAdminRequired]
 public function upload(int $id):JSONResponse{if(!$this->visible($id))return $this->error('Client introuvable',404);if(!$this->manager()&&!$this->access->can('call'))return $this->error('Accès refusé',403);$f=$this->request->getUploadedFile('file');if(!$f||($f['error']??1)!==UPLOAD_ERR_OK||empty($f['tmp_name'])||!is_uploaded_file($f['tmp_name'])||($f['size']??0)>Documents::MAX_SIZE)return $this->error('Fichier refusé (25 Mo maximum).');try{$bytes=file_get_contents($f['tmp_name']);$key=$this->access->uid().':'.$id.':'.hash('sha256',$bytes);$doc=$this->documents->receive($id,'manual',$key,(string)$f['name'],$bytes,$this->access->uid());return new JSONResponse(['ok'=>true,'id'=>$doc]);}catch(\InvalidArgumentException $e){return $this->error($e->getMessage());}}
 #[NoAdminRequired]
 #[NoCSRFRequired]
 public function download(int $id):DataDownloadResponse|JSONResponse{$row=$this->documents->find($id);if(!$row||!$this->canDocument($row))return $this->error('Pièce introuvable',404);$pdf=$this->request->getParam('format','original')==='pdf';try{$bytes=$this->documents->stream($row,$pdf);}catch(\Throwable $e){return $this->error('Fichier indisponible',404);}$name=$pdf?pathinfo($row['name'],PATHINFO_FILENAME).'.pdf':$row['name'];$response=new DataDownloadResponse($bytes,$name,$pdf?'application/pdf':'application/octet-stream');$response->addHeader('X-Content-Type-Options','nosniff');return $response;}
}
