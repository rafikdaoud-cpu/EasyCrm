<?php
namespace OCA\CallCrm\Service;
use OCP\IDBConnection;use OCP\IConfig;use OCP\Files\IAppData;use OCP\Files\IRootFolder;use OCP\Files\Folder;use OCP\Files\NotFoundException;

/**
 * Lien de dépôt de documents envoyé à un client (page publique, sans compte).
 *
 * Le jeton n'est stocké que haché (SHA-256) : la base ne permet pas de reconstituer le lien.
 * Chaque document est livré dès son envoi : copié dans les Fichiers Nextcloud du commercial
 * (Documents clients/<client> (n°ID)/<date du lien>/) et enregistré dans les pièces de la fiche.
 * « Terminer » ne fait que fermer le lien (documents obligatoires présents).
 */
class Collect {
 const MAX_DOCS=30;
 const MAX_FILE=20971520;      // 20 Mo par document
 const MAX_TOTAL=157286400;    // 150 Mo par lien
 const MAX_DAYS=30;
 const ROOT_FOLDER='Documents clients';
 const DEFAULT_DOCS=[
  ['label'=>'Carte d’identité (recto)','required'=>true],['label'=>'Carte d’identité (verso)','required'=>true],
  ['label'=>'Extrait de naissance','required'=>true],['label'=>'Fiche familiale','required'=>false],
  ['label'=>'Certificat de résidence','required'=>true],['label'=>'Attestation de travail','required'=>true],
  ['label'=>'Fiches de paie (3 derniers mois)','required'=>true],['label'=>'Relevé bancaire / chèque barré','required'=>true],
  ['label'=>'Facture proforma','required'=>false],['label'=>'Photo d’identité','required'=>false],
 ];
 const ACCEPTED=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];

 public function __construct(private IDBConnection $db,private IAppData $data,private IRootFolder $root,private IConfig $config,private Documents $documents){}

 // ---------- Réglages (liste par défaut et durée) ----------

 /** Valide une liste de documents : libellé 1–120 caractères, sans doublon, 30 au plus. */
 public static function normalizeDocs($docs):array{
  if(!is_array($docs)||!$docs)throw new \InvalidArgumentException('Indiquez au moins un document à demander.');
  if(count($docs)>self::MAX_DOCS)throw new \InvalidArgumentException(self::MAX_DOCS.' documents au maximum.');
  $out=[];$seen=[];
  foreach($docs as $d){
   $label=is_array($d)?($d['label']??null):$d;
   if(!is_string($label))throw new \InvalidArgumentException('Libellé de document invalide.');
   $label=trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',$label)??'');
   if($label===''||mb_strlen($label)>120)throw new \InvalidArgumentException('Chaque document doit avoir un libellé de 1 à 120 caractères.');
   $key=mb_strtolower($label);if(isset($seen[$key]))throw new \InvalidArgumentException('Document en double : '.$label);$seen[$key]=true;
   $out[]=['label'=>$label,'required'=>is_array($d)?(bool)($d['required']??true):true];
  }
  return $out;
 }
 public static function normalizeDays($days):int{
  if(!is_numeric($days)||(int)$days!=$days||(int)$days<1||(int)$days>self::MAX_DAYS)throw new \InvalidArgumentException('Durée de validité : de 1 à '.self::MAX_DAYS.' jours.');
  return (int)$days;
 }
 public function settings():array{
  $docs=json_decode((string)$this->config->getAppValue('callcrm','collect_docs',''),true);
  try{$docs=self::normalizeDocs($docs);}catch(\InvalidArgumentException $e){$docs=self::DEFAULT_DOCS;}
  $days=(int)$this->config->getAppValue('callcrm','collect_days','3');
  return ['documents'=>$docs,'days'=>$days>=1&&$days<=self::MAX_DAYS?$days:3,'max_days'=>self::MAX_DAYS,'max_documents'=>self::MAX_DOCS];
 }
 public function configure($docs,$days):array{
  $docs=self::normalizeDocs($docs);$days=self::normalizeDays($days);
  $this->config->setAppValue('callcrm','collect_docs',json_encode($docs,JSON_UNESCAPED_UNICODE));$this->config->setAppValue('callcrm','collect_days',(string)$days);
  return $this->settings();
 }

 // ---------- Liens ----------

 public static function hashToken(string $token):string{return hash('sha256','callcrm-collect:'.$token);}
 public static function validTokenFormat(string $token):bool{return (bool)preg_match('/^[A-Za-z0-9_-]{32}$/D',$token);}

 private function q(){return $this->db->getQueryBuilder();}
 private function row(string $table,string $col,$value):?array{$q=$this->q();$q->select('*')->from($table)->where($q->expr()->eq($col,$q->createNamedParameter($value)));return $q->executeQuery()->fetch()?:null;}
 private function update(int $id,array $values):void{$q=$this->q();$q->update('callcrm_collect_links');foreach($values as $k=>$v)$q->set($k,$q->createNamedParameter($v));$q->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}

 /** Crée un lien ; un lien encore ouvert pour ce client est révoqué. Le jeton n'est rendu qu'ici. */
 public function create(int $pid,string $creator,string $owner,$docs,$days,?int $now=null):array{
  $docs=self::normalizeDocs($docs);$days=self::normalizeDays($days);$now??=time();
  $token=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
  $this->db->beginTransaction();
  try{
   foreach($this->forProspect($pid) as $old)if($old['state']==='open')$this->revoke((int)$old['id']);
   $q=$this->q();$q->insert('callcrm_collect_links');
   foreach(['prospect_id'=>$pid,'token_hash'=>self::hashToken($token),'created_by'=>$creator,'owner'=>$owner,'state'=>'open','folder_path'=>'','documents'=>json_encode($docs,JSON_UNESCAPED_UNICODE),'created_at'=>$now,'expires_at'=>$now+$days*86400,'submitted_at'=>0]as $k=>$v)$q->setValue($k,$q->createNamedParameter($v));
   $q->executeStatement();$id=(int)$q->getLastInsertId();
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
  return ['id'=>$id,'token'=>$token,'expires_at'=>$now+$days*86400];
 }

 /** Lien ouvert et non expiré correspondant au jeton, sinon null. */
 public function open(string $token,?int $now=null):?array{
  if(!self::validTokenFormat($token))return null;
  $r=$this->row('callcrm_collect_links','token_hash',self::hashToken($token));
  if(!$r||$r['state']!=='open'||(int)$r['expires_at']<=($now??time()))return null;
  return $r;
 }
 /** Lien quel que soit son état (pour afficher « déjà envoyé » / « expiré »). */
 public function byToken(string $token):?array{return self::validTokenFormat($token)?$this->row('callcrm_collect_links','token_hash',self::hashToken($token)):null;}

 public function find(int $id):?array{return $this->row('callcrm_collect_links','id',$id);}
 /** Annule le lien ; les documents déjà livrés restent chez le commercial. */
 public function revoke(int $id):void{$this->update($id,['state'=>'revoked']);}

 public function files(int $linkId):array{$q=$this->q();$q->select('*')->from('callcrm_collect_files')->where($q->expr()->eq('link_id',$q->createNamedParameter($linkId)))->orderBy('doc_index','ASC');$o=[];foreach($q->executeQuery()->fetchAll()as $f)$o[(int)$f['doc_index']]=$f;return $o;}

 public function docs(array $link):array{$d=json_decode((string)$link['documents'],true);return is_array($d)?$d:[];}

 /** Vue publique : aucun nom de client, aucun identifiant interne. */
 public function publicView(array $link):array{
  $files=$this->files((int)$link['id']);$docs=[];
  foreach($this->docs($link)as $i=>$d)$docs[]=['index'=>$i,'label'=>$d['label'],'required'=>(bool)$d['required'],'uploaded'=>isset($files[$i]),'size'=>isset($files[$i])?(int)$files[$i]['size']:0];
  return ['state'=>$link['state'],'expires_at'=>(int)$link['expires_at'],'documents'=>$docs,'max_file'=>self::MAX_FILE];
 }

 /** Liste pour la fiche client (sans jeton). */
 public function forProspect(int $pid):array{
  $q=$this->q();$q->select('*')->from('callcrm_collect_links')->where($q->expr()->eq('prospect_id',$q->createNamedParameter($pid)))->orderBy('id','DESC')->setMaxResults(20);
  return $q->executeQuery()->fetchAll();
 }
 public function summary(array $link,?int $now=null):array{
  $docs=$this->docs($link);$files=$this->files((int)$link['id']);$state=$link['state'];
  if($state==='open'&&(int)$link['expires_at']<=($now??time()))$state='expired';
  return ['id'=>(int)$link['id'],'state'=>$state,'created_at'=>(int)$link['created_at'],'expires_at'=>(int)$link['expires_at'],'submitted_at'=>(int)$link['submitted_at'],'created_by'=>$link['created_by'],'owner'=>$link['owner'],'folder_path'=>$link['folder_path'],'requested'=>count($docs),'uploaded'=>count($files)];
 }

 // ---------- Dépôts du client : livraison immédiate ----------

 /** Ancien dossier de dépôt (versions ≤ 0.28.3) dans les données privées de l'application, ou null. */
 private function staging(int $linkId){
  try{return $this->data->getFolder('collect-'.$linkId);}catch(NotFoundException $e){return null;}
 }

 /** Contrôle du contenu réel (pas de l'extension) : PDF, JPEG ou PNG. */
 public static function detect(string $bytes):?string{
  if(str_starts_with($bytes,'%PDF-'))return str_contains(substr($bytes,-2048),'%%EOF')?'application/pdf':null;
  if(str_starts_with($bytes,"\xFF\xD8\xFF"))return 'image/jpeg';
  if(str_starts_with($bytes,"\x89PNG\r\n\x1a\n"))return 'image/png';
  return null;
 }

 public static function safeSegment(string $s):string{
  $s=preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u',' ',$s)??'';$s=trim(preg_replace('/\s+/u',' ',$s)??'',' .');
  return mb_substr($s===''?'Client':$s,0,80);
 }
 private function ensureFolder(Folder $base,array $segments):Folder{
  foreach($segments as $seg){$base=$base->nodeExists($seg)?$base->get($seg):$base->newFolder($seg);if(!($base instanceof Folder))throw new \RuntimeException('Un fichier porte déjà le nom du dossier « '.$seg.' ».');}
  return $base;
 }
 /** Dossier du lien dans les Fichiers du commercial : Documents clients/<client> (n°ID)/<date de création du lien>/. */
 private function ownerFolder(array $link,array $prospect,string $owner):Folder{
  $client=self::safeSegment(trim(($prospect['lastname']??'').' '.($prospect['firstname']??'')).' (n°'.$prospect['id'].')');
  return $this->ensureFolder($this->root->getUserFolder($owner),[self::ROOT_FOLDER,$client,date('Y-m-d H\hi',(int)$link['created_at'])]);
 }
 private function relative(Folder $folder,string $owner):string{
  $path=$folder->getPath();$prefix='/'.$owner.'/files';
  return mb_substr(str_starts_with($path,$prefix)?substr($path,strlen($prefix)):$path,0,512);
 }
 private function fileName(int $index,string $label,string $mime):string{
  return sprintf('%02d - %s.%s',$index+1,self::safeSegment($label),self::ACCEPTED[$mime]??'bin');
 }

 /**
  * Livre immédiatement le document n°index : écrit dans les Fichiers du commercial (en
  * remplaçant une version précédente du même document), l'ajoute aux pièces de la fiche et
  * l'inscrit sur le lien. Le client n'a pas besoin d'aller au bout pour que le commercial le reçoive.
  */
 public function upload(array $link,array $prospect,string $owner,int $index,string $bytes):array{
  $docs=$this->docs($link);
  if(!isset($docs[$index]))throw new \OutOfBoundsException('Document inconnu.');
  if($bytes===''||strlen($bytes)>self::MAX_FILE)throw new \InvalidArgumentException('Fichier vide ou supérieur à 20 Mo.');
  $mime=self::detect($bytes);if(!$mime)throw new \InvalidArgumentException('Format refusé : envoyez un PDF ou une photo (JPEG, PNG).');
  $files=$this->files((int)$link['id']);$total=strlen($bytes);foreach($files as $i=>$f)if($i!==$index)$total+=(int)$f['size'];
  if($total>self::MAX_TOTAL)throw new \InvalidArgumentException('Taille totale maximale atteinte (150 Mo).');
  $folder=$this->ownerFolder($link,$prospect,$owner);
  $old=$files[$index]??null;
  if($old&&empty($old['file_token'])&&$folder->nodeExists((string)$old['name'])){try{$folder->get((string)$old['name'])->delete();}catch(\Throwable $e){}}
  $name=$this->fileName($index,$docs[$index]['label'],$mime);
  if($folder->nodeExists($name))$folder->get($name)->delete();
  $folder->newFile($name,$bytes);
  try{
   if($old){$q=$this->q();$q->update('callcrm_collect_files')->set('file_token',$q->createNamedParameter(''))->set('name',$q->createNamedParameter($name))->set('mime',$q->createNamedParameter($mime))->set('size',$q->createNamedParameter(strlen($bytes)))->set('created_at',$q->createNamedParameter(time()))->where($q->expr()->eq('id',$q->createNamedParameter($old['id'])))->executeStatement();}
   else{$q=$this->q();$q->insert('callcrm_collect_files');foreach(['link_id'=>(int)$link['id'],'doc_index'=>$index,'file_token'=>'','name'=>$name,'mime'=>$mime,'size'=>strlen($bytes),'created_at'=>time()]as $k=>$v)$q->setValue($k,$q->createNamedParameter($v));$q->executeStatement();}
   $this->update((int)$link['id'],['owner'=>$owner,'folder_path'=>$this->relative($folder,$owner)]);
  }catch(\Throwable $e){try{$folder->get($name)->delete();}catch(\Throwable $x){}throw $e;}
  if($old&&!empty($old['file_token'])){try{$this->staging((int)$link['id'])?->getFile($old['file_token'])->delete();}catch(\Throwable $e){}}
  $this->documents->receive((int)$prospect['id'],'client_link','link:'.$link['id'].':'.$index.':'.hash('sha256',$bytes),$name,$bytes,'Lien client','Document envoyé par le client');
  return ['index'=>$index,'size'=>strlen($bytes),'mime'=>$mime,'first'=>!$files];
 }

 /** Retire un document envoyé (fichier du commercial supprimé ; la pièce reste dans la fiche). */
 public function remove(array $link,array $prospect,string $owner,int $index):void{
  $f=$this->files((int)$link['id'])[$index]??null;if(!$f)return;
  $q=$this->q();$q->delete('callcrm_collect_files')->where($q->expr()->eq('id',$q->createNamedParameter($f['id'])))->executeStatement();
  try{
   if(!empty($f['file_token']))$this->staging((int)$link['id'])?->getFile($f['file_token'])->delete();
   else{$folder=$this->ownerFolder($link,$prospect,$owner);if($folder->nodeExists((string)$f['name']))$folder->get((string)$f['name'])->delete();}
  }catch(\Throwable $e){}
 }

 /**
  * « Terminer » : documents obligatoires présents, lien fermé. Les documents sont déjà livrés ;
  * seuls d'éventuels dépôts d'une version précédente (en attente) sont livrés ici.
  */
 public function submit(array $link,array $prospect,string $owner,?int $now=null):array{
  $now??=time();$docs=$this->docs($link);$files=$this->files((int)$link['id']);
  $missing=[];foreach($docs as $i=>$d)if($d['required']&&!isset($files[$i]))$missing[]=$d['label'];
  if($missing)throw new \InvalidArgumentException('Documents obligatoires manquants : '.implode(', ',$missing).'.');
  if(!$files)throw new \InvalidArgumentException('Aucun document envoyé.');
  $q=$this->q();$q->update('callcrm_collect_links')->set('state',$q->createNamedParameter('submitting'))->where($q->expr()->eq('id',$q->createNamedParameter($link['id'])))->andWhere($q->expr()->eq('state',$q->createNamedParameter('open')));
  if($q->executeStatement()!==1)throw new \RuntimeException('Envoi déjà en cours ou terminé.');
  try{
   $folder=$this->ownerFolder($link,$prospect,$owner);
   foreach($files as $i=>$f){
    if(empty($f['file_token']))continue;
    $staging=$this->staging((int)$link['id']);if(!$staging)throw new \RuntimeException('Fichiers déposés introuvables.');
    $bytes=$staging->getFile($f['file_token'])->getContent();$name=$this->fileName($i,$docs[$i]['label']??$f['name'],$f['mime']);
    if($folder->nodeExists($name))$folder->get($name)->delete();
    $folder->newFile($name,$bytes);
    $this->documents->receive((int)$prospect['id'],'client_link','link:'.$link['id'].':'.$i.':'.hash('sha256',$bytes),$name,$bytes,'Lien client','Document envoyé par le client');
    $u=$this->q();$u->update('callcrm_collect_files')->set('file_token',$u->createNamedParameter(''))->set('name',$u->createNamedParameter($name))->where($u->expr()->eq('id',$u->createNamedParameter($f['id'])))->executeStatement();
   }
   $path=$this->relative($folder,$owner);
   $this->update((int)$link['id'],['state'=>'submitted','submitted_at'=>$now,'folder_path'=>$path,'owner'=>$owner]);
  }catch(\Throwable $e){$this->update((int)$link['id'],['state'=>'open']);throw $e;}
  try{$this->staging((int)$link['id'])?->delete();}catch(\Throwable $e){}
  return ['folder'=>$path,'count'=>count($files)];
 }

 /** Tâche de fond : expire les liens ouverts arrivés à échéance (les documents déjà envoyés restent livrés). */
 public function cleanup(?int $now=null):int{
  $q=$this->q();$q->select('id')->from('callcrm_collect_links')->where($q->expr()->eq('state',$q->createNamedParameter('open')))->andWhere($q->expr()->lt('expires_at',$q->createNamedParameter(($now??time())+1)))->setMaxResults(200);
  $n=0;foreach($q->executeQuery()->fetchAll()as $r){$this->update((int)$r['id'],['state'=>'expired']);try{$this->staging((int)$r['id'])?->delete();}catch(\Throwable $e){}$n++;}
  return $n;
 }
}
