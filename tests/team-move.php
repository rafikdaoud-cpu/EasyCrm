<?php
// Changement de groupe d'équipe d'un commercial : droits, validation, ajout puis retrait, audit, cache vidé.
namespace OCP { interface IConfig {} interface IUserManager {} interface IUserSession {} interface IGroupManager {} interface IRequest {} interface IDBConnection {} interface ITempManager {} interface IURLGenerator {}
 class Server { public static array $map=[]; public static function get($c){return self::$map[$c];} } }
namespace OCP\Group\Backend { interface IAddToGroupBackend {} interface IRemoveFromGroupBackend {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\Mail { interface IMailer {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework { class App {} class Controller { protected $request;public function __construct($n,$r){$this->request=$r;} } class Http {const STATUS_BAD_REQUEST=400,STATUS_FORBIDDEN=403,STATUS_NOT_FOUND=404,STATUS_CONFLICT=409,STATUS_BAD_GATEWAY=502;} }
namespace OCP\AppFramework\Http { class JSONResponse {public function __construct(public $data,public $status=200){}} class DataDownloadResponse {} }
namespace OCP\AppFramework\Http\Attribute { #[\Attribute] class NoAdminRequired {} #[\Attribute] class NoCSRFRequired {} }
namespace {
$root=__DIR__.'/../callcrm';
foreach(['Access','Store','Sms','ClientActivity','Statuses','Lookup','Fields','Prefs','Sim'] as $f) if(is_file("$root/lib/Service/$f.php")) require_once "$root/lib/Service/$f.php";
require_once "$root/lib/AppInfo/Application.php";require_once "$root/lib/Controller/ApiController.php";
use OCA\CallCrm\Service\{Access,Store};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
class User {public function __construct(private string $u){}public function getUID(){return $this->u;}public function getDisplayName(){return ucfirst($this->u);}}
class Session implements \OCP\IUserSession {public string $uid='boss';public function getUser(){return new User($this->uid);}}
class Group {public bool $writable=true;public bool $throw=false;public function __construct(public string $id,public array $m){}public function getGID(){return $this->id;}
 public function getUsers(){return array_map(fn($u)=>new User($u),$this->m);}public function inGroup($u){return in_array($u->getUID(),$this->m,true);}
 public function canAddUser(){return $this->writable;}public function canRemoveUser(){return $this->writable;}
 public function addUser($u){if($this->throw)throw new \RuntimeException('Insufficient access');$this->m[]=$u->getUID();}
 public function removeUser($u){$this->m=array_values(array_diff($this->m,[$u->getUID()]));}}
class Groups implements \OCP\IGroupManager {public string $role='admin';public array $g=[];public array $backends=[];public function getBackends(){return $this->backends;}public function isAdmin($u){return false;}public function isInGroup($u,$g){return str_ends_with($g,'_'.$this->role);}public function get($g){return $this->g[$g]??null;}}
class MemBackend {public array $skip=[];public function __construct(public Groups $gs){}public function groupExists($g){return isset($this->gs->g[$g])&&!in_array($g,$this->skip,true);}public function implementsActions($a){return in_array($a,[256,512],true);}
 public function addToGroup($u,$g){$x=$this->gs->g[$g];if($x->throw)throw new \RuntimeException('Insufficient access');$x->m[]=$u;return true;}public function removeFromGroup($u,$g){$x=$this->gs->g[$g];$x->m=array_values(array_diff($x->m,[$u]));return true;}}
class Users implements \OCP\IUserManager {public function get($u){return new User($u);}}
class Config implements \OCP\IConfig {public array $v=[];public function getAppValue($a,$k,$d=''){return $this->v[$k]??$d;}public function setAppValue($a,$k,$x){$this->v[$k]=$x;}}
class Req implements \OCP\IRequest {public array $p=[];public function getParam($k,$d=null){return $this->p[$k]??$d;}}
class FakeStore extends Store {public array $audits=[];public function __construct(){}public function audit(?string $uid,string $action,?int $pid=null,string $detail=''):void{$this->audits[]=[$action,$detail];}}
class Cache {public array $v=[];public function get($k){return $this->v[$k]??null;}public function set($k,$x,$t){$this->v[$k]=$x;}public function remove($k){unset($this->v[$k]);}}
class CacheFactory {public Cache $c;public function __construct(){$this->c=new Cache;}public function createDistributed($p){return $this->c;}}
$cfg=new Config;$cf=new CacheFactory;
\OCP\Server::$map=[\OCP\IConfig::class=>$cfg,'OCP\ICacheFactory'=>$cf];
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
echo "callcrm: $n team move checks".($fail?", $fail FAILED":' passed')."\n";exit($fail?1:0);
}
