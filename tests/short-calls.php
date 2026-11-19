<?php
// Appels de moins de 30 s : repérés dans les statistiques, audio non récupéré s'ils ne sont passés chez aucun commercial.
namespace OCP { interface IDBConnection {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_STR_ARRAY=102; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/YeastarEvents.php';
require_once __DIR__.'/../callcrm/lib/Service/YeastarRecordings.php';
require_once __DIR__.'/../callcrm/lib/Service/CallDashboard.php';
use OCA\CallCrm\Service\{YeastarRecordings,CallDashboard};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
$cdr=fn(array $m)=>['type'=>30012,'sn'=>'S1','msg'=>json_encode($m+['call_id'=>'1.1','call_from'=>'0550123456','call_to'=>'6200','type'=>'Inbound','status'=>'ANSWERED','time_start'=>'2026-11-19 10:00:00','talk_duration'=>12,'recording'=>'a.wav'])];
$r=YeastarRecordings::parse($cdr(['call_duration'=>18]));
ok($r['call_duration']===18&&$r['talk_duration']===12,'durée totale lue');
ok(YeastarRecordings::parse($cdr([]))['call_duration']===null,'durée totale absente : null');
ok(YeastarRecordings::parse($cdr(['call_duration'=>'abc']))['call_duration']===null,'durée totale invalide ignorée');
$ext=['1005','1008'];
ok(YeastarRecordings::shortWithoutAgent($r,$ext),'SVI 18 s : audio non récupéré');
ok(!YeastarRecordings::shortWithoutAgent(['extension'=>'1008','call_duration'=>10,'talk_duration'=>8],$ext),'poste commercial : toujours récupéré, même court');
ok(!YeastarRecordings::shortWithoutAgent(['extension'=>'6400','call_duration'=>95,'talk_duration'=>60],$ext),'file, appel long : récupéré');
ok(YeastarRecordings::shortWithoutAgent(['extension'=>'6400','call_duration'=>null,'talk_duration'=>20],$ext),'ancien détail sans durée totale : temps de conversation utilisé');
ok(!YeastarRecordings::shortWithoutAgent(['extension'=>'6200','call_duration'=>30,'talk_duration'=>30],$ext),'30 s pile : pas court');
$row=fn($id,$call,$ext,$st,$talk,$dur,$uid='')=>['id'=>$id,'pbx_url'=>'p','call_id'=>$call,'phone'=>'0550','direction'=>'inbound','created_at'=>100+$id,'extension'=>$ext,'prospect_id'=>null,'user_id'=>$uid,'call_status'=>$st,'talk_duration'=>$talk,'call_duration'=>$dur];
$c=CallDashboard::aggregate([$row(1,'A','6200','ANSWERED',9,12),$row(2,'A','6400','NO ANSWER',0,12)]);
ok(count($c)===1&&$c[0]['duration']===12&&!$c[0]['answered'],'appel A : 12 s, sans réponse');
$c=CallDashboard::aggregate([$row(3,'B','6200','ANSWERED',14,null),$row(4,'B','6400','NO ANSWER',0,null)]);
ok($c[0]['duration']===14,'ancien appel : durée = temps SVI');
$c=CallDashboard::aggregate([$row(5,'C','6200','ANSWERED',10,75),$row(6,'C','1008','ANSWERED',50,75,'u8')]);
ok($c[0]['duration']===75&&$c[0]['answered'],'appel C : décroché par un commercial');
echo $fail?"$fail/$n short-call checks FAILED\n":"callcrm: $n short-call checks passed\n";exit($fail?1:0);
}
