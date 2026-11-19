<?php
// Changement de groupe d'équipe d'un commercial : droits, validation, ajout puis retrait, audit, cache vidé.
namespace OCP { interface IConfig {} interface IUserManager {} interface IUserSession {} interface IGroupManager {} interface IRequest {} interface IDBConnection {} interface ITempManager {} interface IURLGenerator {}
 class Server { public static array $map=[]; public static function get($c){return self::$map[$c];} } }
namespace OCP\Group\Backend { interface IAddToGroupBackend {} interface IRemoveFromGroupBackend {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\Mail { interface IMailer {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework\Bootstrap { interface IBootstrap {} interface IRegistrationContext {} interface IBootContext {} }
namespace OCP\AppFramework { class App {} class Controller { protected $request;public function __construct($n,$r){$this->request=$r;} } class Http {const STATUS_BAD_REQUEST=400,STATUS_FORBIDDEN=403,STATUS_NOT_FOUND=404,STATUS_CONFLICT=409,STATUS_BAD_GATEWAY=502;} }
namespace OCP\AppFramework\Http { class JSONResponse {public function __construct(public $data,public $status=200){}} class DataDownloadResponse {} }
namespace OCP\AppFramework\Http\Attribute { #[\Attribute] class NoAdminRequired {} #[\Attribute] class NoCSRFRequired {} }
namespace {
$root=__DIR__.'/../callcrm';
foreach(['Access','TeamScope','Store','Sms','ClientActivity','Statuses','Lookup','Fields','Prefs','Sim'] as $f) if(is_file("$root/lib/Service/$f.php")) require_once "$root/lib/Service/$f.php";
require_once "$root/lib/AppInfo/Application.php";require_once "$root/lib/Controller/ApiController.php";
use OCA\CallCrm\Service\{Access,Store};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
class User {public function __construct(private string $u){}public function getUID(){return $this->u;}public function getDisplayName(){return ucfirst($this->u);}}
class Session implements \OCP\IUserSession {public string $uid='boss';public function getUser(){return new User($this->uid);}}
class Group {public bool $writable=true;public bool $throw=false;public function __construct(public string $id,public array $m){}public function getGID(){return $this->id;}public function delete(){global $groups;unset($groups->g[$this->id]);}public function setDisplayName($n){}
 public function getUsers(){return array_map(fn($u)=>new User($u),$this->m);}public function inGroup($u){return in_array($u->getUID(),$this->m,true);}
 public function canAddUser(){return $this->writable;}public function canRemoveUser(){return $this->writable;}
 public function addUser($u){if($this->throw)throw new \RuntimeException('Insufficient access');$this->m[]=$u->getUID();}
 public function removeUser($u){$this->m=array_values(array_diff($this->m,[$u->getUID()]));}}
class Groups implements \OCP\IGroupManager {public string $role='admin';public array $g=[];public array $backends=[];public array $member=[];public array $roles=[];public function getBackends(){return $this->backends;}public function groupExists($g){return isset($this->g[$g]);}public function createGroup($g){return $this->g[$g]=new Group($g,[]);}public function isAdmin($u){return false;}public function isInGroup($u,$g){if(isset($this->g[$g])&&str_starts_with($g,'crm_profil_'))return in_array($u,$this->g[$g]->m,true);return str_ends_with($g,'_'.($this->roles[$u]??$this->role));}public function get($g){return $this->g[$g]??null;}}
class MemBackend {public array $skip=[];public function __construct(public Groups $gs){}public function groupExists($g){return isset($this->gs->g[$g])&&!in_array($g,$this->skip,true);}public function implementsActions($a){return in_array($a,[256,512],true);}
 public function addToGroup($u,$g){$x=$this->gs->g[$g];if($x->throw)throw new \RuntimeException('Insufficient access');$x->m[]=$u;return true;}public function removeFromGroup($u,$g){$x=$this->gs->g[$g];$x->m=array_values(array_diff($x->m,[$u]));return true;}}
class Users implements \OCP\IUserManager {public function get($u){return new User($u);}public function searchDisplayName($q,$l){return [new User('zoe')];}}
class Config implements \OCP\IConfig {public array $v=[];public function getAppValue($a,$k,$d=''){return $this->v[$k]??$d;}public function setAppValue($a,$k,$x){$this->v[$k]=$x;}}
class Req implements \OCP\IRequest {public array $p=[];public function getParam($k,$d=null){return $this->p[$k]??$d;}}
class FakeStore extends Store {public array $audits=[];public array $rows=[];public array $updates=[];public function __construct(){}public function get(int $id):?array{return $this->rows[$id]??null;}public function update(int $id,array $v,?string $actor=null,?array $expected=null):void{$this->updates[]=[$id,$v];$this->rows[$id]=array_merge($this->rows[$id],$v);}public function audit(?string $uid,string $action,?int $pid=null,string $detail=''):void{$this->audits[]=[$action,$detail];}}
class Cache {public array $v=[];public function get($k){return $this->v[$k]??null;}public function set($k,$x,$t){$this->v[$k]=$x;}public function remove($k){unset($this->v[$k]);}}
class CacheFactory {public Cache $c;public function __construct(){$this->c=new Cache;}public function createDistributed($p){return $this->c;}}
$cfg=new Config;$cf=new CacheFactory;
\OCP\Server::$map=[\OCP\IConfig::class=>$cfg,'OCP\ICacheFactory'=>$cf,'OCA\CallCrm\Service\Sms'=>new class{public function runtime(){return ['enabled'=>false];}},'OCA\CallCrm\Service\ClientActivity'=>new class{public function event(...$a){}}];
$groups=new Groups;$session=new Session;$store=new FakeStore;$req=new Req;
$groups->g=['callcrm_commercial'=>new Group('callcrm_commercial',['alice','bob']),'callcrm_motos'=>new Group('callcrm_motos',['alice']),
 'callcrm_electro_media'=>new Group('callcrm_electro_media',['bob']),'callcrm_amenagement'=>new Group('callcrm_amenagement',[])];
$groups->backends=[new MemBackend($groups)];
$ctl=fn()=>new \OCA\CallCrm\Controller\ApiController($req,new Access($session,$groups),$store,$cfg,$groups,new Users,
 new class implements \OCP\ITempManager{},new class implements \OCP\Files\IAppData{},new class implements \OCP\Mail\IMailer{},new class implements \OCP\IURLGenerator{});

$groups->role='superviseur';$req->p=['uid'=>'alice','to'=>'callcrm_amenagement'];ok($ctl()->teamMove()->status===403,'superviseur : refusé');
$groups->role='admin';
$req->p=['uid'=>'mallory','to'=>'callcrm_amenagement'];ok($ctl()->teamMove()->status===400,'non commercial : refusé');
$req->p=['uid'=>'alice','to'=>'callcrm_inconnu'];ok($ctl()->teamMove()->status===400,'groupe inconnu : refusé');
$req->p=['uid'=>'alice','to'=>'callcrm_motos'];ok($ctl()->teamMove()->status===400,'déjà dans le groupe');
$cf->c->v['teams']='{"x":[]}';
$req->p=['uid'=>'alice','to'=>'callcrm_amenagement'];$r=$ctl()->teamMove();
ok($r->status===200,'déplacement accepté');
ok($groups->g['callcrm_amenagement']->m===['alice']&&$groups->g['callcrm_motos']->m===[],'ajouté au nouveau, retiré de l’ancien');
ok(($r->data['teams']['callcrm_amenagement']??[])===['alice'],'équipes renvoyées à jour');
ok(!isset($cf->c->v['teams']),'cache des équipes vidé');
ok(end($store->audits)[0]==='team_move'&&str_contains(end($store->audits)[1],'alice'),'audit');
$req->p=['uid'=>'bob','to'=>''];$r=$ctl()->teamMove();ok($r->status===200&&$groups->g['callcrm_electro_media']->m===[],'retrait de tous les groupes');
$groups->g['callcrm_motos']->throw=true;$req->p=['uid'=>'alice','to'=>'callcrm_motos'];$r=$ctl()->teamMove();
ok($r->status===502&&str_contains($r->data['error'],'AD')&&$groups->g['callcrm_amenagement']->m===['alice'],'refus AD : signalé, ancien groupe conservé');
// Moteur LDAP qui refuse sans exception (ldap_write_support renvoie false) : refus signalé
$groups->g['callcrm_electro_media']=new Group('callcrm_electro_media',[]);$mem=$groups->backends[0];$mem->skip=['callcrm_electro_media'];$groups->backends=[$mem,new class{public function groupExists($g){return $g==='callcrm_electro_media';}public function implementsActions($a){return true;}public function addToGroup($u,$g){return false;}public function removeFromGroup($u,$g){return false;}}];
$req->p=['uid'=>'alice','to'=>'callcrm_electro_media'];$r=$ctl()->teamMove();
ok($r->status===502&&str_contains($r->data['error'],'refusé la modification')&&$groups->g['callcrm_amenagement']->m===['alice'],'refus silencieux de l’AD détecté');
$req->p=['uid'=>'alice','to'=>'callcrm_motos'];
$groups->g['callcrm_motos']->throw=false;$groups->g['callcrm_motos']->writable=false;$r=$ctl()->teamMove();ok($r->status===400&&$groups->g['callcrm_amenagement']->m===['alice'],'groupe en lecture seule');

// ---- Superviseurs limités à leurs groupes ----
use OCA\CallCrm\Service\TeamScope;
$groups->g['callcrm_superviseur']=new Group('callcrm_superviseur',['sup']);
$req->p=['uid'=>'sup','teams'=>['callcrm_motos','callcrm_inconnu']];$groups->role='superviseur';ok($ctl()->supervisorTeams()->status===403,'superviseur : ne peut pas se donner des groupes');
$groups->role='admin';$r=$ctl()->supervisorTeams();
ok($r->status===200&&TeamScope::config($cfg)===['sup'=>['callcrm_motos']],'admin : groupes du superviseur enregistrés (groupe inconnu ignoré)');
ok(end($store->audits)[0]==='supervisor_teams','audit des groupes du superviseur');
$req->p=['uid'=>'alice','teams'=>['callcrm_motos']];ok($ctl()->supervisorTeams()->status===400,'non superviseur refusé');
$me=$ctl()->me()->data;ok(array_column($me['supervisors'],'uid')===['sup']&&$me['supervisors'][0]['teams']===['callcrm_motos'],'/me : superviseurs pour l’admin');
// Périmètre forcé (superviseur de Motos : alice) ; bob est dans une autre équipe
TeamScope::force(['sup','alice']);$groups->role='superviseur';$session->uid='sup';
$m=new ReflectionMethod(\OCA\CallCrm\Controller\ApiController::class,'visible');
$store->rows=[1=>['id'=>1,'assigned_to'=>'alice'],2=>['id'=>2,'assigned_to'=>'bob'],3=>['id'=>3,'assigned_to'=>null]];
ok($m->invoke($ctl(),1)!==null&&$m->invoke($ctl(),2)===null&&$m->invoke($ctl(),3)!==null,'fiche visible : son équipe et non attribuées, pas les autres');
$req->p=['uid'=>'alice'];ok($ctl()->assign(2)->status===404,'ne réattribue pas une fiche hors de ses groupes');
$req->p=['uid'=>'bob'];ok($ctl()->assign(3)->status===400,'n’attribue pas à un commercial hors de ses groupes');
$req->p=['uid'=>'alice'];ok($ctl()->assign(3)->status===200&&$store->rows[3]['assigned_to']==='alice','attribue une fiche non attribuée à son équipe');
$sid=new ReflectionMethod(\OCA\CallCrm\Controller\ApiController::class,'scopedIds');ok($sid->invoke($ctl(),[1,2,3])===[1,3],'sélection filtrée sur son périmètre');
$me=$ctl()->me()->data;ok(array_column($me['commerciaux'],'uid')===['alice']&&$me['scope_teams']===true&&$me['supervisors']===[],'/me : seulement ses commerciaux');
ok(TeamScope::canTarget('alice')&&!TeamScope::canTarget('bob')&&TeamScope::canSee(null),'règles canSee / canTarget');
TeamScope::force(null);ok($m->invoke($ctl(),2)!==null,'sans groupe attribué : voit tout');
TeamScope::reset();

// ---- Droits des profils ----
$session->uid='boss';$groups->role='commercial';$req->p=['rights'=>['delete'=>['commercial']]];ok($ctl()->saveRights()->status===403,'commercial : ne modifie pas les droits');
$groups->role='admin';$acc=fn()=>new Access($session,$groups);
ok(!in_array('commercial',Access::rights()['delete'],true),'par défaut : commercial ne supprime pas');
$req->p=['rights'=>['delete'=>['commercial','intrus'],'export'=>[],'teams'=>['commercial'],'mask'=>['lecture']]];$r=$ctl()->saveRights();
$m=Access::rights();
ok($r->status===200&&in_array('commercial',$m['delete'],true)&&in_array('admin',$m['delete'],true)&&!in_array('intrus',$m['delete'],true),'droit accordé au commercial, admin conservé, rôle inconnu ignoré');
ok($m['export']===['admin'],'droit retiré à tous les profils sauf admin');
ok($m['teams']===['admin'],'gestion des groupes réservée à l’admin');
ok($m['mask']===['lecture']&&!in_array('admin',$m['mask'],true),'masquage : jamais pour l’admin');
$groups->role='commercial';ok($acc()->can('delete')&&!$acc()->can('export'),'droits appliqués au profil');
ok(str_contains(end($store->audits)[1],'+commercial:delete'),'audit des droits');
$groups->role='admin';$req->p=['reset'=>1];$ctl()->saveRights();ok(!in_array('commercial',Access::rights()['delete'],true)&&in_array('commercial',Access::rights()['export'],true),'retour aux droits d’origine');

// ---- Création de profils et de groupes ----
$session->uid='boss';$groups->role='admin';TeamScope::reset();
$req->p=['name'=>'Chef d’équipe','base'=>'superviseur'];$r=$ctl()->profileCreate();
$cp=Access::customProfiles();ok($r->status===200&&count($cp)===1&&$cp[0]['base']==='superviseur'&&isset($groups->g[$cp[0]['group']]),'profil créé avec son groupe');
$pid=$cp[0]['id'];ok(in_array($pid,Access::rights()['assign'],true)&&!in_array($pid,Access::rights()['delete'],true),'le profil hérite des droits du modèle');
$req->p=['name'=>'chef d’équipe','base'=>'commercial'];ok($ctl()->profileCreate()->status===400,'nom en double refusé');
$req->p=['name'=>'X','base'=>'admin'];ok($ctl()->profileCreate()->status===400,'modèle administrateur interdit');
$groups->roles['zoe']='commercial';$req->p=['id'=>$pid,'uid'=>'zoe','add'=>true];$r=$ctl()->profileMember();ok($r->status===200&&$groups->g[$cp[0]['group']]->m===['zoe'],'membre ajouté au profil');
$groups->role='commercial';$session->uid='zoe';$a2=new Access($session,$groups);ok($a2->profile()===$pid&&$a2->role()==='superviseur'&&$a2->can('assign'),'le membre a le profil et fonctionne comme son modèle');
$session->uid='boss';$groups->role='admin';
$req->p=['rights'=>['delete'=>[$pid],'assign'=>['superviseur']]];$ctl()->saveRights();$m=Access::rights();
ok(in_array($pid,$m['delete'],true)&&!in_array($pid,$m['assign'],true)&&in_array('superviseur',$m['assign'],true),'droits propres au profil créé');
$req->p=['name'=>'Contrôle','base'=>'lecture'];$ctl()->profileCreate();$p2=Access::customProfiles()[1]['id'];
ok(in_array($p2,Access::rights()['all'],true)&&!in_array($p2,Access::rights()['delete'],true),'profil créé après réglage : hérite toujours de son modèle');
$req->p=['id'=>$pid];ok($ctl()->profileDelete()->status===400,'suppression refusée tant qu’il a des membres');
$req->p=['id'=>$pid,'uid'=>'zoe','add'=>false];$ctl()->profileMember();$req->p=['id'=>$pid];$r=$ctl()->profileDelete();
ok($r->status===200&&count(Access::customProfiles())===1&&!in_array($pid,Access::rights()['delete'],true),'profil vide supprimé et retiré des droits');
$req->p=['id'=>'superviseur'];ok($ctl()->profileDelete()->status===400,'profil d’origine non supprimable');
$req->p=['q'=>'zo'];ok($ctl()->userSearch()->data===[['uid'=>'zoe','name'=>'Zoe']],'recherche d’utilisateurs');
$req->p=['name'=>'Grands comptes'];$r=$ctl()->teamCreate();$gid=array_key_last(Access::customTeams());
ok($r->status===200&&$gid==='crm_equipe_grands_comptes'&&isset(Access::teams()[$gid])&&isset($groups->g[$gid]),'groupe d’équipe créé');
$req->p=['name'=>'motos'];ok($ctl()->teamCreate()->status===400,'nom de groupe en double refusé');
$req->p=['gid'=>'callcrm_motos'];ok($ctl()->teamDelete()->status===400,'groupe d’origine non supprimable');
$groups->g[$gid]->m=['alice'];$req->p=['gid'=>$gid];ok($ctl()->teamDelete()->status===400,'groupe non vide non supprimable');
$groups->g[$gid]->m=[];$r=$ctl()->teamDelete();ok($r->status===200&&!isset(Access::teams()[$gid])&&!isset($groups->g[$gid]),'groupe vide supprimé');
$groups->role='superviseur';$req->p=['name'=>'Pirate','base'=>'superviseur'];ok($ctl()->profileCreate()->status===403&&$ctl()->teamCreate()->status===403,'réservé à l’admin');

// ---- Demande de documents : envoi par e-mail ----
class Mailer implements \OCP\Mail\IMailer {public array $sent=[];public bool $fail=false;public function validateMailAddress($m){return (bool)filter_var($m,FILTER_VALIDATE_EMAIL);}
 public function createMessage(){return new class{public array $d=[];public function setTo($t){$this->d['to']=$t;return $this;}public function setSubject($x){$this->d['subject']=$x;return $this;}public function setPlainBody($x){$this->d['body']=$x;return $this;}};}
 public function send($m){if($this->fail)return ['x'];$this->sent[]=$m->d;return [];}}
class Url implements \OCP\IURLGenerator {public function getAbsoluteURL($u){return 'https://cloud.csmtaksit.dz'.$u;}}
$mailer=new Mailer;$mctl=fn()=>new \OCA\CallCrm\Controller\ApiController($req,new Access($session,$groups),$store,$cfg,$groups,new Users,new class implements \OCP\ITempManager{},new class implements \OCP\Files\IAppData{},$mailer,new Url);
TeamScope::reset();$cfg->v["role_rights"]="";$session->uid="alice";$groups->role="commercial";
$store->rows[7]=['id'=>7,'assigned_to'=>'alice','firstname'=>'Karim','lastname'=>'B','email'=>'karim@example.com'];
$store->rows[8]=['id'=>8,'assigned_to'=>'alice','firstname'=>'Sans','lastname'=>'Mail','email'=>''];
$store->rows[9]=['id'=>9,'assigned_to'=>'bob','firstname'=>'Autre','lastname'=>'','email'=>'x@example.com'];
$good="Bonjour Karim, envoyez vos documents.\nأرسلوا وثائقكم.\nhttps://cloud.csmtaksit.dz/d/ABCDEFGHIJKLMNOPQRSTUVWXYZ012345";
$req->p=["text"=>$good];$r=$mctl()->collectMail(7);
ok($r->status===200&&$mailer->sent[0]['to']===['karim@example.com'=>'Karim B']&&str_contains($mailer->sent[0]['body'],'/d/ABCDEF'),'e-mail envoyé au client avec le message et le lien');
ok(end($store->audits)[0]==='collect_mail','envoi par e-mail journalisé');
ok($mctl()->collectMail(8)->status===400,'pas d’e-mail sur la fiche : refus');
ok($mctl()->collectMail(9)->status===404,'fiche d’un autre commercial : refus');
$req->p=['text'=>'Publicité sans lien'];ok($mctl()->collectMail(7)->status===400,'texte sans lien de dépôt refusé');
$req->p=['text'=>'https://evil.example/d/ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'];ok($mctl()->collectMail(7)->status===400,'lien d’un autre site refusé');
$mailer->fail=true;$req->p=['text'=>$good];ok($mctl()->collectMail(7)->status===502,'refus du serveur e-mail signalé');
$groups->role='lecture';ok($mctl()->collectMail(7)->status===403,'lecture seule : refus');
echo "callcrm: $n team move checks".($fail?", $fail FAILED":' passed')."\n";exit($fail?1:0);
}
