<?php
/** Lien de dépôt de documents client (Call CRM) : réglages, droits, jeton, dépôts, envoi vers les Fichiers du commercial. */
namespace OCP\Files {interface IAppData{} interface IRootFolder{} interface Node{} interface File extends Node{} interface Folder extends Node{} class NotFoundException extends \Exception{}}
namespace OCP {interface ITempManager{} interface IURLGenerator{}}
namespace OCP\Security {interface ICrypto{}}
namespace Psr\Log {interface LoggerInterface{}}
namespace {
$argv[1]='callcrm';
ob_start();require __DIR__.'/client-activity.php';ob_end_clean(); // base SQLite, prospects, rôles, Store, ClientActivity
foreach(['DocumentPdf','Documents','Collect']as $name)require $root.'/lib/Service/'.$name.'.php';
require $root.'/lib/Controller/CollectController.php';require $root.'/lib/Controller/CollectPublicController.php';
use OCA\CallCrm\Service\Collect;

/** Système de fichiers en mémoire : dossiers des utilisateurs et données de l'application. */
class MemFile implements \OCP\Files\File{public function __construct(public MemFolder $parent,public string $name,public string $bytes=''){}public function putContent($v){$this->bytes=$v;}public function getContent(){return $this->bytes;}public function getSize(){return strlen($this->bytes);}public function delete(){unset($this->parent->children[$this->name]);}public function getPath(){return $this->parent->getPath().'/'.$this->name;}}
class MemFolder implements \OCP\Files\Folder{
 public array $children=[];public function __construct(public string $path,public ?MemFolder $parent=null){}
 public function getPath(){return $this->path;}public function nodeExists($n){return isset($this->children[$n]);}
 public function get($n){if(!isset($this->children[$n]))throw new \OCP\Files\NotFoundException($n);return $this->children[$n];}
 public function getFile($n){return $this->get($n);}public function fileExists($n){return isset($this->children[$n]);}
 public function newFolder($n){return $this->children[$n]=new MemFolder($this->path.'/'.$n,$this);}
 public function newFile($n,$content=null){return $this->children[$n]=new MemFile($this,$n,(string)$content);}
 public function delete(){if($this->parent)unset($this->parent->children[basename($this->path)]);}
}
class MemAppData implements \OCP\Files\IAppData{public MemFolder $root;public function __construct(){$this->root=new MemFolder('/appdata');}public function getFolder($n){return $this->root->get($n);}public function newFolder($n){return $this->root->newFolder($n);}}
class MemRoot implements \OCP\Files\IRootFolder{public array $users=[];public function getUserFolder($uid){return $this->users[$uid]??=new MemFolder('/'.$uid.'/files');}}
class NoTemp implements \OCP\ITempManager{public function getTemporaryFolder($p){return sys_get_temp_dir();}}
class NoCrypto implements \OCP\Security\ICrypto{public function encrypt($v){return $v;}public function decrypt($v){return $v;}}
class Urls implements \OCP\IURLGenerator{public function linkToRouteAbsolute($route,$args=[]){return 'https://cloud.test/apps/callcrm/collecte/'.$args['token'];}public function linkToRoute($route,$args=[]){return '/apps/callcrm';}}
class Logs implements \Psr\Log\LoggerInterface{public array $logs=[];public function error($m,$c=[]){$this->logs[]=$m;}}

$db->pdo->exec("CREATE TABLE {$app}_collect_links(id INTEGER PRIMARY KEY AUTOINCREMENT,prospect_id INTEGER,token_hash TEXT UNIQUE,created_by TEXT,owner TEXT,state TEXT,folder_path TEXT,documents TEXT,created_at INTEGER DEFAULT 0,expires_at INTEGER DEFAULT 0,submitted_at INTEGER DEFAULT 0);
 CREATE TABLE {$app}_collect_files(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,doc_index INTEGER,file_token TEXT,name TEXT,mime TEXT,size INTEGER DEFAULT 0,created_at INTEGER DEFAULT 0,UNIQUE(link_id,doc_index));
 CREATE TABLE IF NOT EXISTS {$app}_documents(id INTEGER PRIMARY KEY,prospect_id INTEGER,source TEXT,source_key TEXT UNIQUE,name TEXT,mime TEXT,original_file TEXT,pdf_file TEXT,state TEXT,error TEXT,sender TEXT,subject TEXT,source_ref TEXT,size INTEGER DEFAULT 0,created_at INTEGER DEFAULT 0,lease_until INTEGER DEFAULT 0);");
$db->pdo->exec("UPDATE {$app}_prospects SET assigned_to='alice',lastname='Nadia/../Test',firstname='Exemple' WHERE id=1");
$appData=new MemAppData();$files=new MemRoot();$logs=new Logs();
$documents=new \OCA\CallCrm\Service\Documents($db,$appData,$files,new NoTemp(),$config,new NoCrypto());
$collect=new Collect($db,$appData,$files,$config,$documents);
$crm=function($uid)use($session,$groups,$request,$store,$collect,$activity){$session->uid=$uid;return new \OCA\CallCrm\Controller\CollectController($request,new \OCA\CallCrm\Service\Access($session,$groups),$store,$collect,$activity,new Urls());};
$public=new \OCA\CallCrm\Controller\CollectPublicController($request,$collect,$store,$activity,new Urls(),$users,$logs);
$n=0;function ok($c,$label){global $n;if(!$c)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$PDF="%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";$JPEG="\xFF\xD8\xFF\xE0".str_repeat('x',100);
$tokenOf=fn($url)=>substr($url,strrpos($url,'/')+1);

// Réglages
$s=$collect->settings();ok(count($s['documents'])===10&&$s['days']===3,'default list of 10 documents and 3-day validity');
foreach([[[],3],[[['label'=>'A'],['label'=>'a']],3],[array_fill(0,31,['label'=>'x']),3],[[['label'=>'A']],0],[[['label'=>'A']],31],[[['label'=>str_repeat('x',121)]],3]] as [$docs,$days]){try{$collect->configure($docs,$days);throw new LogicException('accepted');}catch(InvalidArgumentException $e){}}
ok($collect->settings()['days']===3,'invalid lists and durations rejected (empty, duplicate, >30, 0 or 31 days, long label)');
$collect->configure([['label'=>'CNI recto','required'=>true],['label'=>'CNI verso','required'=>true],['label'=>'Fiche de paie','required'=>false]],5);
ok($collect->settings()['documents'][2]===['label'=>'Fiche de paie','required'=>false]&&$collect->settings()['days']===5,'administrator customizes default list and validity');
$request->params=['documents'=>[['label'=>'X']],'days'=>3];ok($crm('alice')->configure()->status===403,'commercial cannot change default list');
$request->params=[];ok($crm('alice')->settings()->status===403&&$crm('super')->settings()->status===403&&$crm('admin')->settings()->status===200,'only the administrator sees the document list and validity');
$collect->configure($collect->settings()['documents'],3);

// Création du lien
// Valeurs envoyées par le navigateur : ignorées, la liste et la durée de l'administrateur s'appliquent.
$request->params=['documents'=>[['label'=>'Document pirate','required'=>false]],'days'=>30];
ok($crm('bob')->create(1)->status===404,'another commercial cannot create a link for this client');
ok($crm('reader')->create(1)->status===403,'read-only user cannot send a document request');
$r=$crm('alice')->create(1);$url=$r->data['url'];$token=$tokenOf($url);
ok($r->status===200&&preg_match('/^[A-Za-z0-9_-]{32}$/',$token)===1,'assigned commercial creates a 32-character link');
ok(!str_contains(json_encode($db->pdo->query("SELECT * FROM {$app}_collect_links")->fetchAll()),$token),'token stored only as a hash');
$row=$collect->open($token);ok($row&&(int)$row['expires_at']-(int)$row['created_at']===3*86400&&$row['owner']==='alice','link valid 3 days, owner = assigned commercial');
ok(array_column($collect->docs($row),'label')===['CNI recto','CNI verso','Fiche de paie'],'document list and validity sent by the browser are ignored (administrator settings apply)');
ok(!isset($r->data['link']['documents']),'commercial response does not list the documents');
ok($collect->open('x'.substr($token,1))===null&&$collect->open('court')===null,'wrong or malformed token rejected');
ok($db->pdo->query("SELECT count(*) FROM {$app}_client_events WHERE kind='documents'")->fetchColumn()==1,'link creation recorded in client chronology');
$r2=$crm('alice')->create(1);$token2=$tokenOf($r2->data['url']);
ok($collect->open($token)===null&&$collect->open($token2),'new link replaces the previous open link');
$request->params=[];$list=$crm('alice')->list(1)->data;ok($list[0]['state']==='open'&&$list[1]['state']==='revoked'&&!isset($list[0]['token'])&&!isset($list[0]['token_hash']),'client card lists links without tokens');
ok($crm('bob')->list(1)->status===404,'another commercial cannot see the links');
$token=$token2;$link=$collect->open($token);

// Vue publique et dépôts
$v=$public->info($token);ok($v->status===200&&count($v->data['documents'])===3&&!str_contains(json_encode($v->data),'Nadia')&&!isset($v->data['prospect_id']),'public view lists documents without client data');
ok($public->info(str_repeat('A',32))->status===404,'unknown token answers 404');
foreach(['<html>%%EOF','%PDF-1.4 coupé','',"GIF89a"] as $bad){try{$collect->upload($link,0,$bad);throw new LogicException('accepted');}catch(InvalidArgumentException $e){}}
ok(count($collect->files((int)$link['id']))===0,'HTML, truncated PDF, empty and GIF files refused');
try{$collect->upload($link,7,$PDF);throw new LogicException('accepted');}catch(OutOfBoundsException $e){}ok(true,'unknown document index refused');
$collect->upload($link,0,$PDF);$collect->upload($link,0,$PDF.' ');ok(count($collect->files((int)$link['id']))===1&&count($appData->getFolder('collect-'.$link['id'])->children)===1,'re-upload replaces the document without leftovers');
$request->params=[];$s=$public->submit($token);ok($s->status===400&&str_contains($s->data['error'],'CNI verso'),'submit refused while a required document is missing');
$collect->upload($link,1,$JPEG);$collect->remove($link,1);ok(!isset($collect->files((int)$link['id'])[1]),'client can remove a document before submitting');
$collect->upload($link,1,$JPEG);

// Envoi final : dossier du commercial actuel de la fiche
$store->reassign([1],'bob','admin');
$s=$public->submit($token);ok($s->status===200&&$s->data['count']===2,'submit with all required documents succeeds');
$base=$files->getUserFolder('bob')->get('Documents clients');$client=array_key_first($base->children);
ok($client==='Nadia .. Test Exemple (n°1)','client folder name sanitized (no path separators)');
$dated=$base->get($client)->children;$out=reset($dated)->children;
ok(isset($out['01 - CNI recto.pdf'],$out['02 - CNI verso.jpg'])&&$out['01 - CNI recto.pdf']->bytes===$PDF.' ','files written to current commercial Nextcloud folder with numbered names');
ok(!isset($files->users['alice']),'previous commercial does not receive the files');
ok($db->pdo->query("SELECT count(*) FROM {$app}_documents WHERE prospect_id=1 AND source='client_link'")->fetchColumn()==2,'documents also listed in the client card attachments');
$l=$collect->find((int)$link['id']);ok($l['state']==='submitted'&&str_starts_with($l['folder_path'],'/Documents clients/')&&$l['owner']==='bob','link closed with Nextcloud folder path');
ok(!isset($appData->root->children['collect-'.$link['id']])&&count($collect->files((int)$link['id']))===0,'temporary uploads removed after submit');
$again=$public->submit($token);ok($again->status===410&&$again->data['state']==='submitted','link cannot be reused after submission');
ok($public->info($token)->data['state']==='submitted','client sees documents already sent');

// Expiration, révocation, droits
$store->reassign([1],'alice','admin');$collect->configure([['label'=>'A']],1);$request->params=[];$t3=$tokenOf($crm('alice')->create(1)->data['url']);$l3=$collect->open($t3);$collect->upload($l3,0,$PDF);
ok($collect->open($t3,time()+86401)===null&&$public->info($t3)->status===200,'link expires after its validity');
ok($collect->cleanup(time()+86401)===1&&$collect->find((int)$l3['id'])['state']==='expired'&&!isset($appData->root->children['collect-'.$l3['id']]),'background job expires links and deletes pending uploads');
$request->params=[];$t4=$tokenOf($crm('alice')->create(1)->data['url']);$id4=(int)$collect->open($t4)['id'];
ok($crm('bob')->revoke($id4)->status===404,'another commercial cannot cancel the link');
ok($crm('alice')->revoke($id4)->status===200&&$public->info($t4)->status===410,'commercial cancels the link; client is told');
foreach(['info','upload','remove','submit','page']as $m){$ref=new ReflectionMethod(\OCA\CallCrm\Controller\CollectPublicController::class,$m);ok($ref->getAttributes('OCP\\AppFramework\\Http\\Attribute\\PublicPage')&&$ref->getAttributes('OCP\\AppFramework\\Http\\Attribute\\BruteForceProtection'),"public $m endpoint has brute-force protection");}
foreach(['create','revoke','configure']as $m){$ref=new ReflectionMethod(\OCA\CallCrm\Controller\CollectController::class,$m);ok(!$ref->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired'),"CRM $m keeps CSRF protection");}
ok(Collect::safeSegment('../a/b\\c:*?')==='a b c'&&Collect::safeSegment('..')==='Client'&&Collect::safeSegment('')==='Client','folder names cannot escape the client folder');
echo "callcrm: $n client document link checks passed\n";
}
