<?php
namespace OCP {interface ICacheFactory{} interface IDBConnection{}}
namespace OCP\Http\Client {interface IClientService{}}
namespace OCP\Lock {interface ILockingProvider{const LOCK_EXCLUSIVE=2;}}
namespace OCA\CallCrm\Service {class Store {public array $rows=[];public function get($id){return $this->rows[$id]??null;}}}
namespace {
require __DIR__.'/yeastar-settings.php';
require __DIR__.'/../callcrm/lib/Service/YeastarClient.php';
require __DIR__.'/../callcrm/lib/Service/YeastarEvents.php';
require __DIR__.'/../callcrm/lib/Controller/TelephonyController.php';
class CacheFactory implements \OCP\ICacheFactory {public array $rows=[];public function createDistributed($name){return $this;}public function get($k){return $this->rows[$k]??null;}public function set($k,$v,$ttl){$this->rows[$k]=$v;}}
class Locks implements \OCP\Lock\ILockingProvider {public int $held=0;public function acquireLock($r,$type){$this->held++;}public function releaseLock($r,$type){$this->held--;}}
class HttpClient implements \OCP\Http\Client\IClientService {public array $requests=[];public bool $fail=false;public function newClient(){return $this;}public function post($url,$options){$this->requests[]=[$url,$options];if($this->fail)throw new RuntimeException('SECRET_MUST_NOT_LEAK');$data=str_contains($url,'get_token')?['errcode'=>0,'access_token'=>'test-token','access_token_expire_time'=>1800]:['errcode'=>0,'call_id'=>'call-1'];return new class($data){public function __construct(private $data){}public function getStatusCode(){return 200;}public function getBody(){return json_encode($this->data);}};}}
$settings->save(['enabled'=>true,'client_id'=>'test','secret'=>'dummy','allow_self_signed'=>false,'extensions'=>[['uid'=>'alice','extension'=>'1029']]]);
$http=new HttpClient();$cache=new CacheFactory();$locks=new Locks();$pbx=new \OCA\CallCrm\Service\YeastarClient($settings,$http,$cache,new Crypto(),$locks);
$pbx->test();$pbx->test();check(count($http->requests)===1,'shared encrypted token reused instead of exhausting PBX token quota');check(!str_contains(json_encode($cache->rows),'test-token'),'cached token encrypted');check($locks->held===0,'token lock released');
$cache->rows=[];$pbx->test();check(count($http->requests)===1,'persistent encrypted token fallback prevents quota exhaustion without cache');
$options=$http->requests[0][1];check($options['verify']===true&&$options['allow_redirects']===false,'TLS verification enabled and redirects refused');check(isset($options['headers']['User-Agent']),'required Yeastar User-Agent supplied');
check($pbx->dial('1029','0770322647')==='call-1','valid call is sent to PBX');$payload=json_decode(end($http->requests)[1]['body'],true);check($payload['caller']==='1029'&&$payload['auto_answer']==='no'&&!isset($payload['dial_permission']),'call never overrides extension permissions');
$settings->save(['allow_self_signed'=>true]);$pbx->test();check(end($http->requests)[1]['verify']===false,'explicit TLS exception applies to Yeastar request');$http->fail=true;try{$pbx->dial('1029','0770322647');throw new LogicException('Expected error');}catch(RuntimeException $e){check(!str_contains($e->getMessage(),'SECRET_MUST_NOT_LEAK'),'network errors redact internal request details');}
$event=['type'=>30011,'msg'=>json_encode(['call_id'=>'c1','members'=>[['extension'=>['number'=>'1029','member_status'=>'RING']],['inbound'=>['from'=>'+213770322647']]]])];
$rows=\OCA\CallCrm\Service\YeastarEvents::parse($event);check(count($rows)===1&&$rows[0]['extension']==='1029'&&$rows[0]['direction']==='inbound','documented 30011 JSON-string payload parsed');
$event['msg']=json_decode($event['msg'],true);check(\OCA\CallCrm\Service\YeastarEvents::parse($event)===$rows,'object payload also supported');check(\OCA\CallCrm\Service\YeastarEvents::parse(['type'=>30009])===[],'unrelated events ignored');check(\OCA\CallCrm\Service\YeastarEvents::number('00213 770-322-647')==='+213770322647','international Algerian number normalized');
class FakeDb implements \OCP\IDBConnection{}
$events=new \OCA\CallCrm\Service\YeastarEvents(new FakeDb(),$settings);
$settings->save(['webhook_secret'=>str_repeat('a',32)]);$raw=json_encode(['event'=>'test']);$signature=base64_encode(hash_hmac('sha256',$raw,str_repeat('a',32),true));$events->receive($raw,$signature);check(true,'official base64 HMAC-SHA256 webhook signature accepted');
try{$events->receive($raw,'forged');throw new LogicException('Expected refusal');}catch(DomainException $e){check(true,'forged webhook refused before database access');}
$settings->save(['enabled'=>false]);try{$events->receive($raw,$signature);throw new LogicException('Expected refusal');}catch(DomainException $e){check(true,'disabled integration refuses webhook');}
class FakeEvents extends \OCA\CallCrm\Service\YeastarEvents {public ?array $prospect=null;public array $records=[];public function __construct(){}public function match(string $number):?array{return $this->prospect;}public function record(string $hash,string $call,string $uid,string $phone,string $direction,string $state,?int $pid):void{$this->records[]=func_get_args();}}
class UserAccess extends \OCA\CallCrm\Service\Access {public function __construct(private string $role){}public function uid(){return 'alice';}public function can($action){return $this->role==='admin'||($this->role==='commercial'&&in_array($action,['view','call'],true));}}
class FakeClient extends \OCA\CallCrm\Service\YeastarClient {public array $calls=[];public function __construct(){}public function dial(string $extension,string $number):string{$this->calls[]=[$extension,$number];return 'call-2';}}
$settings->save(['enabled'=>true]);$fe=new FakeEvents();$fc=new FakeClient();$store=new \OCA\CallCrm\Service\Store();$request->params=['phone'=>'0770322647'];
$controller=new \OCA\CallCrm\Controller\TelephonyController($request,new UserAccess('commercial'),$store,$settings,$fc,$fe);
$fe->prospect=['id'=>1,'phone'=>'0770322647','assigned_to'=>'bob'];check($controller->dial()->status===404&&!$fc->calls,'commercial cannot call another commercial client');
$fe->prospect['assigned_to']='alice';check($controller->dial()->status===200&&$fc->calls===[['1029','0770322647']],'dial uses server-owned extension and stored client number');
check($controller->test()->status===403,'commercial cannot run admin API test');$settings->save(['enabled'=>false]);check($controller->dial()->status===400,'disabled integration cannot place call');
check(!(new ReflectionMethod($controller,'dial'))->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired'),'dial endpoint retains CSRF protection');
}
