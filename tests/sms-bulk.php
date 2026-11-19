<?php
// SMS groupé : droits, plafond, aperçu sans envoi, confirmation du nombre, personnalisation, doublons et chronologie.
namespace OCP { interface IConfig {} interface IUserManager {} interface IUserSession {} interface IGroupManager {} interface IRequest {} interface IDBConnection {} interface ITempManager {} interface IURLGenerator {}
 class Server { public static array $map=[]; public static function get($c){return self::$map[$c];} } }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\Mail { interface IMailer {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\AppFramework { class App {} class Controller { protected $request;public function __construct($n,$r){$this->request=$r;} } class Http {const STATUS_BAD_REQUEST=400,STATUS_FORBIDDEN=403,STATUS_NOT_FOUND=404,STATUS_CONFLICT=409;} }
namespace OCP\AppFramework\Http { class JSONResponse {public function __construct(public $data,public $status=200){}} class DataDownloadResponse {} }
namespace OCP\AppFramework\Http\Attribute { #[\Attribute] class NoAdminRequired {} #[\Attribute] class NoCSRFRequired {} }
namespace {
$root=__DIR__.'/../callcrm';
foreach(['Access','TeamScope','Store','Sms','ClientActivity','Statuses','Lookup','Fields','Prefs','Sim'] as $f) if(is_file("$root/lib/Service/$f.php")) require_once "$root/lib/Service/$f.php";
require_once "$root/lib/AppInfo/Application.php";require_once "$root/lib/Controller/ApiController.php";
use OCA\CallCrm\Service\{Access,Store,Sms};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
class User {public function __construct(private string $u){}public function getUID(){return $this->u;}public function getDisplayName(){return ['alice'=>'Alice','sam'=>'Sam'][$this->u]??$this->u;}}
class Session implements \OCP\IUserSession {public string $uid='sam';public function getUser(){return new User($this->uid);}}
class Groups implements \OCP\IGroupManager {public string $role='admin';public function isAdmin($u){return $this->role==='admin';}public function isInGroup($u,$g){return str_ends_with($g,'_'.$this->role);}public function get($g){return null;}}
class Users implements \OCP\IUserManager {public function get($u){return new User($u);}}
class Config implements \OCP\IConfig {public array $v=[];public function getAppValue($a,$k,$d=''){return $this->v[$k]??$d;}public function setAppValue($a,$k,$x){$this->v[$k]=$x;}}
class Req implements \OCP\IRequest {public array $p=[];public function getParam($k,$d=null){return $this->p[$k]??$d;}}
class FakeStore extends Store {public array $rows=[];public array $audits=[];public array $filters=[];public function __construct(){}
 public function count(?string $uid,array $f):int{$this->filters[]=$f;return count($this->rows);}
 public function search(?string $uid,array $f,int $limit,int $off):array{return array_slice($this->rows,$off,$limit);}
 public function audit(?string $uid,string $action,?int $pid=null,string $detail=''):void{$this->audits[]=[$action,$detail];}}
class Activity {public array $events=[];public function event($pid,$uid,$kind,$body){$this->events[]=[$pid,$kind,$body];}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return 'e'.$s;}public function decrypt($s){return substr($s,1);}}
class Resp {public function __construct(private string $b){}public function getStatusCode(){return 200;}public function getBody(){return $this->b;}}
class Client {public array $q=[];public function get($u,$o){parse_str(parse_url($u,PHP_URL_QUERY),$q);$this->q[]=$q;return new Resp(implode(',',array_map(fn($d)=>($d==='213550000003'?'1706':'1701').'|'.$d.':id'.$d,explode(',',$q['destination']))));}}
class Http implements \OCP\Http\Client\IClientService {public Client $c;public function __construct(){$this->c=new Client;}public function newClient(){return $this->c;}}

$cfg=new Config;$http=new Http;$sms=new Sms($cfg,new Crypto,$http);$act=new Activity;
\OCP\Server::$map=[Sms::class=>$sms,\OCA\CallCrm\Service\ClientActivity::class=>$act,\OCP\IConfig::class=>$cfg];
$groups=new Groups;$session=new Session;$access=new Access($session,$groups);$store=new FakeStore;$req=new Req;
$store->rows=[
 ['id'=>1,'firstname'=>'Karim','lastname'=>'Benali','phone'=>'0550 00 00 01','assigned_to'=>'alice'],
 ['id'=>2,'firstname'=>'Sara','lastname'=>'Amri','phone'=>'+213550000002','assigned_to'=>null],
 ['id'=>3,'firstname'=>'Nadia','lastname'=>'X','phone'=>'0550000003','assigned_to'=>'alice'],
 ['id'=>4,'firstname'=>'Doublon','lastname'=>'','phone'=>'0550000001','assigned_to'=>'alice'],
 ['id'=>5,'firstname'=>'Fixe','lastname'=>'','phone'=>'021000000','assigned_to'=>null],
];
$ctl=fn()=>new \OCA\CallCrm\Controller\ApiController($req,new Access($session,$groups),$store,$cfg,$groups,new Users,
 (new ReflectionClass(\OCP\ITempManager::class))->isInterface()?new class implements \OCP\ITempManager{}:null,new class implements \OCP\Files\IAppData{},new class implements \OCP\Mail\IMailer{},new class implements \OCP\IURLGenerator{});

// Option désactivée : refus, et /me n'expose rien
$r=$ctl()->smsBulk();ok($r->status===403,'désactivé : refusé');
$m=new ReflectionMethod(\OCA\CallCrm\Controller\ApiController::class,'smsRuntime');
ok($m->invoke($ctl())===['enabled'=>false],'désactivé : aucun bouton');
$sms->save(['url'=>'https://sms.test','username'=>'u','password'=>'p','sender'=>'CSM','enabled'=>true,'max_bulk'=>10]);
$rt=$m->invoke($ctl());ok($rt['enabled']&&$rt['bulk'],'activé : admin a l’envoi groupé');
$groups->role='commercial';$rt=$m->invoke($ctl());ok($rt['enabled']&&!$rt['bulk'],'commercial : fiche seulement');
$r=$ctl()->smsBulk();ok($r->status===403,'commercial : envoi groupé refusé');
$sms->save(['commercial_send'=>false]);ok($m->invoke($ctl())===['enabled'=>false],'option commerciaux décochée : rien');
$groups->role='lecture';ok($m->invoke($ctl())===['enabled'=>false],'lecture : rien');
$groups->role='superviseur';

// Aperçu : rien n'est envoyé
$req->p=['preview'=>1,'text'=>'Bonjour {prenom}, de la part de {commercial}','status'=>'interesse','sort'=>'nom'];
$r=$ctl()->smsBulk();
ok($r->status===200&&$r->data==['clients'=>5,'recipients'=>3,'invalid'=>1,'duplicates'=>1,'billed'=>3],'aperçu : '.json_encode($r->data));
ok(!$http->c->q,'aperçu : aucun envoi');ok(($store->filters[0]['status']??'')==='interesse'&&!isset($store->filters[0]['sort']),'mêmes filtres que la liste');

// Nombre confirmé différent : refus
$req->p=['text'=>'Bonjour {prenom}','expected'=>2];$r=$ctl()->smsBulk();ok($r->status===409&&!$http->c->q,'sélection changée : refus');

// Envoi
$req->p=['text'=>'Bonjour {prenom}, de la part de {commercial}','expected'=>3];$r=$ctl()->smsBulk();
ok($r->status===200&&$r->data['sent']===2&&array_sum($r->data['failed'])===1,'envoi : 2 OK, 1 échec');
$texts=array_column($http->c->q,'message');sort($texts);
ok($texts===['Bonjour Karim, de la part de Alice','Bonjour Nadia, de la part de Alice','Bonjour Sara, de la part de Sam'],'personnalisé (commercial de la fiche, sinon l’expéditeur) : '.json_encode($texts));
ok(count($act->events)===3&&$act->events[0][1]==='sms'&&!empty($act->events[0][2]['batch']),'chronologie des 3 clients');
ok(end($store->audits)[0]==='sms_bulk','journal');

// Plafond
$store->rows=array_merge($store->rows,array_map(fn($i)=>['id'=>100+$i,'firstname'=>'','lastname'=>'','phone'=>'05510000'.sprintf('%02d',$i)],range(1,10)));
$req->p=['preview'=>1,'text'=>'x'];$r=$ctl()->smsBulk();ok($r->status===400&&str_contains($r->data['error'],'trop grande'),'plafond');

// Message trop long
$store->rows=array_slice($store->rows,0,2);$req->p=['preview'=>1,'text'=>str_repeat('a',800)];$r=$ctl()->smsBulk();ok($r->status===400,'trop long refusé');

echo $fail?"$fail/$n bulk SMS checks FAILED\n":"callcrm: $n bulk SMS checks passed\n";exit($fail?1:0);
}
