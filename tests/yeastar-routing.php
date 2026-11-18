<?php
// Routage des appels entrants vers le commercial : routes « CRM_<poste>_<n> », jamais vides, découpées par 250,
// route générale toujours dernière (recopiée seulement si la copie est identique), désactivation propre.
namespace OCP { interface IConfig {} interface IDBConnection {} interface ICacheFactory {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Lock { interface ILockingProvider { const LOCK_EXCLUSIVE=2; } class LockedException extends \Exception {} }
namespace {
foreach(['YeastarSettings','YeastarClient','Presence','YeastarRouting'] as $f) require_once __DIR__."/../callcrm/lib/Service/$f.php";
use OCA\CallCrm\Service\{YeastarRouting,YeastarClient,YeastarSettings,Presence};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
class Config implements \OCP\IConfig {public array $v=[];public function getAppValue($a,$k,$d=''){return $this->v[$k]??$d;}public function setAppValue($a,$k,$x){$this->v[$k]=$x;}}
/** Faux standard : routes en mémoire, ordre de création = ordre de priorité (comme le Yeastar). */
class FakePbx extends YeastarClient {
 public array $routes=[];public array $ext=[];public int $extUpdates=0;public int $next=10;
 public static function presence(){return [['status'=>'available','enb_ex_no_answer_forward'=>1,'ex_no_answer_forward_dest'=>'ext_vm','ex_no_answer_forward_value'=>'','enb_ex_busy_forward'=>1,'ex_busy_forward_dest'=>'ext_vm','ex_busy_forward_value'=>'','enb_in_no_answer_forward'=>1,'in_no_answer_forward_dest'=>'ext_vm','ring_timeout'=>30],['status'=>'away','ex_no_answer_forward_dest'=>'ext_vm']];}public array $log=[];public bool $badCopy=false;
 public function __construct(){}
 public function call(string $verb,string $path,array $query=[],?array $body=null):array{
  $this->log[]=$path;
  switch($path){
   case 'extension/list': return ['errcode'=>0,'data'=>[
     ['id'=>20,'number'=>'1029','presence_status'=>'available','online_status'=>['linkus_web'=>['status'=>1]]],
     ['id'=>7,'number'=>'1004','presence_status'=>'available','online_status'=>['linkus_web'=>['status'=>1]]],
     ['id'=>8,'number'=>'1000','presence_status'=>'available','online_status'=>['linkus_web'=>['status'=>0]]]]];
   case 'inbound_route/list': $d=array_values($this->routes);foreach($d as $i=>&$r)$r=['id'=>$r['id'],'pos'=>$i+1,'name'=>$r['name']];return ['errcode'=>0,'data'=>$d];
   case 'inbound_route/get': $r=$this->routes[$query['id']]??null;if(!$r)return ['errcode'=>40002];
     $r['cid_pattern_list']=array_map(fn($x,$i)=>['id'=>$i]+$x,$r['cid_pattern_list']??[],array_keys($r['cid_pattern_list']??[]));return ['errcode'=>0,'data'=>$r];
   case 'inbound_route/create': if(count($body['cid_pattern_list']??[])>300)return ['errcode'=>60002];
     $id=$this->next++;$r=['id'=>$id]+$body;if($this->badCopy&&str_ends_with($body['name'],'_tmp'))$r['outside_business_hours_destination_value']='9';$this->routes[$id]=$r;return ['errcode'=>0,'id'=>$id];
   case 'inbound_route/update': $id=$body['id'];if(!isset($this->routes[$id]))return ['errcode'=>40002];if(count($body['cid_pattern_list']??[])>300)return ['errcode'=>60002];
     $this->routes[$id]=array_merge($this->routes[$id],$body);return ['errcode'=>0];
   case 'inbound_route/delete': unset($this->routes[$query['id']]);return ['errcode'=>0];
   case 'queue/list': return ['errcode'=>0,'queue_list'=>[['id'=>1,'number'=>'6400','name'=>'CSM_AR'],['id'=>2,'number'=>'6401','name'=>'CSM_FR']]];
   case 'extension/get': return ['errcode'=>0,'data'=>['id'=>$query['id'],'number'=>'x','presence_list'=>$this->ext[$query['id']]??($this->ext[$query['id']]=self::presence())]];
   case 'extension/update': foreach($body['presence_list'] as $u){if(isset($u['mobile_prefix']))return ['errcode'=>40002];foreach($this->ext[$body['id']] as &$e)if($e['status']===$u['status'])$e=array_merge($e,$u);unset($e);}$this->extUpdates++;return ['errcode'=>0];
  }
  return ['errcode'=>10001];
 }
}
class TestRouting extends YeastarRouting {
 public array $want=[];
 public function desired(array $extensions):array{return $this->want;}
}
$general=['name'=>'Inbound_Route','trunk_list'=>[['id'=>1]],'did_option'=>'patterns','cid_option'=>'patterns','def_dest'=>'queue','def_dest_value'=>'1',
 'enb_time_condition'=>1,'timezone_id'=>1,'time_condition'=>'route_scope','time_condition_value'=>[['days_of_week'=>'sun mon','times'=>['08:30-16:30'],'months'=>'','days'=>'','dest'=>'','dest_prefix'=>'','dest_value'=>'','time_condition_switch'=>0]],
 'business_hours_destination'=>'ivr','business_hours_destination_value'=>'1','outside_business_hours_destination'=>'ivr','outside_business_hours_destination_value'=>'2','holidays_hours_destination'=>'end_call'];
$setup=function() use($general){
 $pbx=new FakePbx;$pbx->routes[3]=['id'=>3]+$general;$cfg=new Config;
 $settings=(new ReflectionClass(YeastarSettings::class))->newInstanceWithoutConstructor();$presence=(new ReflectionClass(Presence::class))->newInstanceWithoutConstructor();
 $db=new class implements \OCP\IDBConnection{};$r=new TestRouting($pbx,$settings,$cfg,$db,$presence);return [$pbx,$cfg,$r];};

// Numéros : format reçu de l'opérateur
ok(YeastarRouting::callerId('+213 550 12 34 56')==='0550123456','+213 → 0');
ok(YeastarRouting::callerId('00213770112233')==='0770112233','00213');
ok(YeastarRouting::callerId('0661 00 00 00')==='0661000000','mobile');
ok(YeastarRouting::callerId('021 23 45 67')==='021234567','fixe');
ok(YeastarRouting::callerId('12345')===null&&YeastarRouting::callerId('')===null,'invalide');

// Désactivé : rien n'est touché
[$pbx,$cfg,$r]=$setup();$r->sync();ok($pbx->log===[],'désactivé : aucun appel au standard');

// Activation : 600 numéros pour 1004 → 3 routes (250/250/100), 1000 hors ligne : rien ; route générale remise en dernier
[$pbx,$cfg,$r]=$setup();
$nums=[];for($i=0;$i<600;$i++)$nums[]='0550'.str_pad((string)$i,6,'0',STR_PAD_LEFT);
$r->want=['1004'=>['uid'=>'u4','ext_id'=>7,'reachable'=>true,'why'=>'','numbers'=>$nums],'1000'=>['uid'=>'u0','ext_id'=>8,'reachable'=>false,'why'=>'hors ligne','numbers'=>['0661000000']]];
$st=$r->setEnabled(true);
$names=array_map(fn($x)=>$x['name'],array_values($pbx->routes));
ok($names===['CRM_1004_1','CRM_1004_2','CRM_1004_3','Inbound_Route'],'3 routes puis route générale en dernier : '.json_encode($names));
$g=end($pbx->routes);ok($g['outside_business_hours_destination_value']==='2'&&$g['business_hours_destination']==='ivr'&&!isset($pbx->routes[3]),'route générale recopiée à l’identique, ancienne supprimée');
$first=reset($pbx->routes);ok(count($first['cid_pattern_list'])===250&&$first['business_hours_destination']==='extension'&&$first['business_hours_destination_value']==='7'&&$first['outside_business_hours_destination_value']==='2'&&$first['holidays_hours_destination']==='end_call','route commercial : poste aux heures d’ouverture, horaires copiés');
ok($st['summary']['commercials']===1&&$st['summary']['numbers']===600&&$st['summary']['not_routed']===['1000 (hors ligne)'],'résumé : '.json_encode($st['summary']));

// Renvois du poste : appels externes non répondus / occupé → file choisie ; internes et autres statuts inchangés
$p=$pbx->ext[7][0];ok($p['ex_no_answer_forward_dest']==='queue'&&$p['ex_no_answer_forward_value']==='1'&&$p['ex_busy_forward_dest']==='queue'&&$p['in_no_answer_forward_dest']==='ext_vm'&&$pbx->ext[7][1]['ex_no_answer_forward_dest']==='ext_vm','renvoi externe vers la file, interne et autres statuts intacts');
ok($p['ring_timeout']===15,'sonnerie du poste réduite à 15 s');
ok(isset($pbx->ext[8])&&$pbx->ext[8][0]['ex_no_answer_forward_dest']==='queue','renvoi aussi pour un commercial hors ligne (prêt quand il revient)');
// Deuxième passage sans changement : aucune écriture
$pbx->log=[];$r->sync();ok(!array_intersect($pbx->log,['inbound_route/update','inbound_route/create','inbound_route/delete','extension/update']),'rien de changé : aucune écriture');

// Commercial passe hors ligne : routes neutralisées par un numéro impossible, jamais vides ni supprimées
$r->want['1004']['reachable']=false;$r->want['1004']['why']='en pause';$r->sync();
$pats=array_map(fn($x)=>array_column($x['cid_pattern_list'],'cid_pattern'),array_values(array_filter($pbx->routes,fn($x)=>str_starts_with($x['name'],'CRM_'))));
ok(count($pats)===3&&$pats===[['0000000000'],['0000000000'],['0000000000']],'en pause : routes neutralisées');
ok(end($pbx->routes)['name']==='Inbound_Route','route générale toujours dernière');
// Retour : routes réutilisées, pas recréées
$before=array_keys($pbx->routes);$r->want['1004']['reachable']=true;$r->want['1004']['numbers']=array_slice($nums,0,300);$r->sync();
ok(array_keys($pbx->routes)===$before,'retour en ligne : mêmes routes, ordre conservé');
ok(count($pbx->routes[$before[0]]['cid_pattern_list'])===250&&count($pbx->routes[$before[1]]['cid_pattern_list'])===50&&$pbx->routes[$before[2]]['cid_pattern_list']===[['cid_pattern'=>'0000000000']],'moins de clients : route en trop neutralisée');

// Copie de la route générale différente : on n'y touche pas
[$pbx,$cfg,$r]=$setup();$pbx->badCopy=true;$r->want=['1029'=>['uid'=>'u','ext_id'=>20,'reachable'=>true,'why'=>'','numbers'=>['0782240723']]];
try{$r->setEnabled(true);ok(false,'copie différente doit échouer');}catch(\RuntimeException $e){ok(str_contains($e->getMessage(),'ne correspond pas'),'copie différente : arrêt');}
ok(isset($pbx->routes[3])&&count(array_filter($pbx->routes,fn($x)=>str_ends_with($x['name'],'_tmp')))===0,'route générale originale conservée, copie supprimée');

// Deux routes générales : arrêt par précaution
[$pbx,$cfg,$r]=$setup();$pbx->routes[4]=['id'=>4]+$general;$r->want=['1029'=>['uid'=>'u','ext_id'=>20,'reachable'=>true,'why'=>'','numbers'=>['0782240723']]];
try{$r->setEnabled(true);ok(false,'deux générales');}catch(\RuntimeException $e){ok(str_contains($e->getMessage(),'plusieurs routes générales'),'deux routes générales : arrêt');}
ok(count($pbx->routes)===2,'rien créé');

// Désactivation : routes gérées supprimées, autres routes intactes
[$pbx,$cfg,$r]=$setup();$pbx->routes[2]=['id'=>2,'name'=>'CRM_TEST_DSI','cid_pattern_list'=>[['cid_pattern'=>'0782240723']]]+$general;
$r->want=['1029'=>['uid'=>'u','ext_id'=>20,'reachable'=>true,'why'=>'','numbers'=>['0782240723','0550000001']]];$r->setEnabled(true);
ok(count(array_filter($pbx->routes,fn($x)=>preg_match(YeastarRouting::NAME,$x['name'])))===1,'activé : une route gérée');
ok($pbx->ext[20][0]['ex_no_answer_forward_dest']==='queue','activé : renvoi posé');
$r->setEnabled(false);
ok($pbx->ext[20][0]['ex_no_answer_forward_dest']==='ext_vm'&&$pbx->ext[20][0]['ex_busy_forward_dest']==='ext_vm','désactivé : renvoi d’origine (messagerie) restauré');
ok($pbx->ext[20][0]['ring_timeout']===30,'désactivé : sonnerie d’origine (30 s) restaurée');
ok(count(array_filter($pbx->routes,fn($x)=>preg_match(YeastarRouting::NAME,$x['name'])))===0&&isset($pbx->routes[2])&&in_array('Inbound_Route',array_column($pbx->routes,'name'),true),'désactivé : routes gérées supprimées, le reste intact');

echo $fail?"$fail/$n routing checks FAILED\n":"callcrm: $n Yeastar routing checks passed\n";exit($fail?1:0);
}
