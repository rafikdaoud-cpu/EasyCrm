<?php
namespace OCA\EasyCrm\Service;
use OCP\IDBConnection;use OCP\IConfig;use OCP\Files\IAppData;use OCP\Files\IRootFolder;use OCP\ITempManager;use OCP\Security\ICrypto;
/** Private CRM document storage. Download rights always follow the current client assignment. */
class Documents {
 const MAX_SIZE=26214400;
 public function __construct(private IDBConnection $db,private IAppData $data,private IRootFolder $root,private ITempManager $temp,private IConfig $config,private ICrypto $crypto){}
 private function cfg(string $key,string $default=''):string{return (string)$this->config->getAppValue('easycrm','doc_'.$key,$default);}
 public function setConfig(string $key,string $value):void{$this->config->setAppValue('easycrm','doc_'.$key,$value);}
 public function settings():array{return ['mail_enabled'=>$this->cfg('mail_enabled')==='1','mail_host'=>$this->cfg('mail_host'),'mail_port'=>$this->cfg('mail_port','993'),'mail_security'=>$this->cfg('mail_security','ssl'),'mail_user'=>$this->cfg('mail_user'),'mail_folder'=>$this->cfg('mail_folder','INBOX'),'has_password'=>$this->cfg('mail_password')!=='','imap_available'=>function_exists('imap_open'),'gd_available'=>function_exists('imagecreatefromstring'),'office_available'=>is_executable('/usr/bin/libreoffice')&&function_exists('proc_open'),'last_poll'=>$this->cfg('last_poll'),'mail_error'=>$this->cfg('mail_error'),'wa_error'=>$this->cfg('wa_error')];}
 public function configure(array $in):array{
  $current=$this->settings();$values=[];foreach(['mail_host','mail_port','mail_security','mail_user','mail_folder']as $k){$v=$in[$k]??$current[$k];if(!is_string($v)||strlen($v)>255||preg_match('/[\r\n\x00{}]/',$v))throw new \InvalidArgumentException('Réglage de boîte mail invalide.');$values[$k]=trim($v);}
  if($values['mail_host']!==''&&!preg_match('/^[a-zA-Z0-9.-]+$/D',$values['mail_host']))throw new \InvalidArgumentException('Indiquez un nom de serveur IMAP sans URL.');
  if(!in_array($values['mail_port'],['993','143'],true)||!in_array($values['mail_security'],['ssl','tls'],true))throw new \InvalidArgumentException('Connexion IMAP chiffrée obligatoire (993 ou 143).');
  $password=$in['password']??'';if(!is_string($password)||strlen($password)>2048)throw new \InvalidArgumentException('Mot de passe invalide.');
  if(isset($in['mail_enabled'])&&!is_bool($in['mail_enabled']))throw new \InvalidArgumentException('État de collecte invalide.');
  $enabled=$in['mail_enabled']??$current['mail_enabled'];if($enabled&&($values['mail_host']===''||$values['mail_user']===''||($password===''&&!$current['has_password'])))throw new \InvalidArgumentException('Renseignez le serveur, l’utilisateur et le mot de passe avant activation.');
  if($current['has_password']&&$password===''&&($values['mail_host']!==$current['mail_host']||$values['mail_user']!==$current['mail_user']))throw new \InvalidArgumentException('Ressaisissez le mot de passe pour changer le serveur ou le compte.');$encrypted=$password!==''?$this->crypto->encrypt($password):null;
  $changed=false;foreach($values as $k=>$v){if($v!==(string)$current[$k])$changed=true;}
  foreach($values as $k=>$v)$this->setConfig($k,$v);if($password!=='')$this->setConfig('mail_password',$encrypted);$this->setConfig('mail_enabled',$enabled?'1':'0');
  if($changed){$this->setConfig('mail_uid','0');$this->setConfig('mail_validity','');$this->setConfig('mail_error','');}return $this->settings();
 }
 public function mailState():array{return ['uid'=>$this->cfg('mail_uid','0'),'validity'=>$this->cfg('mail_validity')];}
 public function mailboxCredentials():array{$s=$this->settings();return $s+['password'=>$this->cfg('mail_password')!==''?$this->crypto->decrypt($this->cfg('mail_password')):''];}
 private function folder(){try{return $this->data->getFolder('client-documents');}catch(\OCP\Files\NotFoundException $e){return $this->data->newFolder('client-documents');}}
 public function find(int $id):?array{$q=$this->db->getQueryBuilder();$q->select('*')->from('easycrm_documents')->where($q->expr()->eq('id',$q->createNamedParameter($id)));return $q->executeQuery()->fetch()?:null;}
 private function byKey(string $key):?array{$q=$this->db->getQueryBuilder();$q->select('*')->from('easycrm_documents')->where($q->expr()->eq('source_key',$q->createNamedParameter($key)));return $q->executeQuery()->fetch()?:null;}
 private function update(int $id,array $values):void{$q=$this->db->getQueryBuilder();$q->update('easycrm_documents');foreach($values as $k=>$v)$q->set($k,$q->createNamedParameter($v));$q->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
 public function resolveEmail(string $email):?int{$email=mb_strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return null;$q=$this->db->getQueryBuilder();$q->select('id')->from('easycrm_prospects')->where($q->expr()->eq($q->func()->lower('email'),$q->createNamedParameter($email)))->setMaxResults(2);$rows=$q->executeQuery()->fetchAll();return count($rows)===1?(int)$rows[0]['id']:null;}
 public static function safeName(string $name):string{$name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1f\x7f]/u','',$name);return mb_substr(trim($name?:'document'),0,240);}
 private function insert(array $values):int{$q=$this->db->getQueryBuilder();$q->insert('easycrm_documents');foreach($values as $k=>$v)$q->setValue($k,$q->createNamedParameter($v));$q->executeStatement();return(int)$q->getLastInsertId();}
 public function receive(?int $pid,string $source,string $sourceKey,string $name,string $bytes,string $sender='',string $subject=''):int{
  if(strlen($bytes)>self::MAX_SIZE||$bytes==='')throw new \InvalidArgumentException('Pièce vide ou supérieure à 25 Mo.');
  $key=hash('sha256',$source.':'.$sourceKey);if($old=$this->byKey($key))return(int)$old['id'];
  $name=self::safeName($name);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)?:'application/octet-stream';$token=bin2hex(random_bytes(16));$file=$this->folder()->newFile($token);$file->putContent($bytes);
  try{return $this->insert(['prospect_id'=>$pid,'source'=>$source,'source_key'=>$key,'name'=>$name,'mime'=>$mime,'original_file'=>$token,'pdf_file'=>null,'state'=>'queued','error'=>'','sender'=>mb_substr($sender,0,255),'subject'=>mb_substr($subject,0,255),'size'=>strlen($bytes),'created_at'=>time(),'lease_until'=>0]);}
  catch(\Throwable $e){$file->delete();if($old=$this->byKey($key))return(int)$old['id'];throw $e;}
 }
 public function publicRow(array $r):array{return array_intersect_key($r,array_flip(['id','prospect_id','source','name','mime','size','state','error','sender','subject','created_at']))+['has_original'=>!empty($r['original_file']),'has_pdf'=>!empty($r['pdf_file'])];}
 public function list(?int $pid,int $offset=0):array{$offset=max(0,min(10000,$offset));$q=$this->db->getQueryBuilder();$q->select('*')->from('easycrm_documents')->where($pid===null?$q->expr()->isNull('prospect_id'):$q->expr()->eq('prospect_id',$q->createNamedParameter($pid)))->orderBy('id','DESC')->setMaxResults(21)->setFirstResult($offset);$rows=$q->executeQuery()->fetchAll();return ['rows'=>array_map(fn($r)=>$this->publicRow($r),array_slice($rows,0,20)),'has_more'=>count($rows)>20,'next_offset'=>$offset+20];}
 public function attach(int $id,int $pid):void{if(!$this->find($id))throw new \OutOfBoundsException('Pièce introuvable.');$this->update($id,['prospect_id'=>$pid]);}
 public function retry(int $id):void{$r=$this->find($id);if(!$r||in_array($r['state'],['processing','ready'],true))throw new \InvalidArgumentException('Cette pièce ne peut pas être relancée maintenant.');$this->update($id,['state'=>$r['original_file']?'queued':'waiting_original','error'=>'','lease_until'=>0]);}
 public function stream(array $r,bool $pdf){$token=$r[$pdf?'pdf_file':'original_file']??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token))throw new \OutOfBoundsException('Fichier indisponible.');return $this->folder()->getFile($token)->getContent();}
 /** Import source media into the document register, even before a client is identified. */
 public function scanWhatsApp():void{
  $q=$this->db->getQueryBuilder();$q->select('m.id','m.file_path','m.file_name','m.conv_id','c.prospect_id','c.phone')->from('easycrm_wa_msg','m')->innerJoin('m','easycrm_wa_conv','c',$q->expr()->eq('c.id','m.conv_id'))
   ->where($q->expr()->gt('m.id',$q->createNamedParameter((int)$this->cfg('wa_cursor','0'))))->andWhere($q->expr()->eq('m.direction',$q->createNamedParameter('in')))->andWhere($q->expr()->isNotNull('m.file_path'))->andWhere($q->expr()->neq('m.file_path',$q->createNamedParameter('')))->orderBy('m.id','ASC')->setMaxResults(30);
  foreach($q->executeQuery()->fetchAll()as $r){if(!empty($r['file_path'])){
   $key=hash('sha256','whatsapp:'.$r['id']);if(!$this->byKey($key)){
    $path=ltrim((string)$r['file_path'],'/');if(!str_starts_with($path,'WhatsApp/')||in_array('..',explode('/',$path),true)||str_contains($path,'\\')){$this->setConfig('wa_error','Chemin média WhatsApp refusé.');break;}
    $pid=$r['prospect_id']?(int)$r['prospect_id']:null;if(!empty($r['phone'])){$match=$this->db->getQueryBuilder();$match->select('id')->from('easycrm_prospects')->where($match->expr()->eq('phone',$match->createNamedParameter($r['phone'])))->setMaxResults(2);$matches=$match->executeQuery()->fetchAll();if(count($matches)!==1||(int)$matches[0]['id']!==$pid)$pid=null;}
    $this->insert(['prospect_id'=>$pid,'source'=>'whatsapp','source_key'=>$key,'name'=>self::safeName($r['file_name']?:basename($path)),'mime'=>'','original_file'=>null,'pdf_file'=>null,'state'=>'waiting_original','error'=>'','sender'=>$r['phone']??'','subject'=>'WhatsApp','source_ref'=>json_encode(['path'=>$path,'owner'=>(string)$this->config->getAppValue('easycrm','wa_files_user','')]),'created_at'=>time(),'size'=>0,'lease_until'=>0]);
   }
  }$this->setConfig('wa_cursor',(string)$r['id']);}
 }
 protected function convertFile(string $bytes,array $row):string{return DocumentPdf::convert($bytes,$row['name'],$row['mime'],$this->temp->getTemporaryFolder('crm-pdf-'));}
 public function processQueue():void{
  $q=$this->db->getQueryBuilder();$q->select('*')->from('easycrm_documents')->where($q->expr()->in('state',$q->createNamedParameter(['queued','waiting_original','processing'],102)))->andWhere($q->expr()->lt('lease_until',$q->createNamedParameter(time())))->orderBy('id','ASC')->setMaxResults(3);
  foreach($q->executeQuery()->fetchAll()as $r){$claim=$this->db->getQueryBuilder();$claim->update('easycrm_documents')->set('state',$claim->createNamedParameter('processing'))->set('lease_until',$claim->createNamedParameter(time()+180))->where($claim->expr()->eq('id',$claim->createNamedParameter($r['id'])))->andWhere($claim->expr()->eq('state',$claim->createNamedParameter($r['state'])))->andWhere($claim->expr()->eq('lease_until',$claim->createNamedParameter($r['lease_until'])));if($claim->executeStatement()!==1)continue;
   try{
    if(empty($r['original_file'])){$ref=json_decode($r['source_ref']??'',true);if($ref&&empty($ref['owner']))$ref['owner']=(string)$this->config->getAppValue('easycrm','wa_files_user','');if(!$ref||empty($ref['owner']))throw new \RuntimeException('Compte de stockage WhatsApp non configuré.');$node=$this->root->getUserFolder($ref['owner'])->get($ref['path']);if(!($node instanceof \OCP\Files\File)||$node->getSize()>self::MAX_SIZE)throw new \RuntimeException('Média absent ou supérieur à 25 Mo.');$bytes=$node->getContent();$r['original_file']=bin2hex(random_bytes(16));$this->folder()->newFile($r['original_file'])->putContent($bytes);$r['mime']=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$this->update((int)$r['id'],['original_file'=>$r['original_file'],'mime'=>$r['mime'],'size'=>strlen($bytes)]);}
    else $bytes=$this->stream($r,false);
    if($r['mime']!=='application/pdf'&&!str_starts_with($r['mime'],'image/')&&!in_array(strtolower(pathinfo($r['name'],PATHINFO_EXTENSION)),['doc','docx','odt','rtf','txt','xls','xlsx','ods','ppt','pptx','odp'],true)){$this->update((int)$r['id'],['state'=>'original','error'=>'','lease_until'=>0]);continue;}
    $pdf=$this->convertFile($bytes,$r);
    if(strlen($pdf)>self::MAX_SIZE)throw new \RuntimeException('Le PDF converti dépasse 25 Mo.');$token=bin2hex(random_bytes(16));$this->folder()->newFile($token)->putContent($pdf);$this->update((int)$r['id'],['pdf_file'=>$token,'state'=>'ready','error'=>'','lease_until'=>0]);
   }catch(\Throwable $e){$this->update((int)$r['id'],['state'=>'failed','error'=>mb_substr($e->getMessage(),0,500),'lease_until'=>0]);}
  }
 }
}
