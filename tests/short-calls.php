<?php
// Appels de moins de 30 s : repérés dans les statistiques, audio non récupéré s'ils ne sont passés chez aucun commercial.
namespace OCP { interface IDBConnection {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_STR_ARRAY=102; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/YeastarEvents.php';
require_once __DIR__.'/../callcrm/lib/Service/YeastarRecordings.php';
require_once __DIR__.'/../callcrm/lib/Service/Schedule.php';
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
ok(!YeastarRecordings::shortWithoutAgent(['extension'=>'6400','call_status'=>'ANSWERED','call_duration'=>null,'talk_duration'=>20,'recording'=>'20261119133921-1795091755.7937-0553544976-1006-Inbound.wav'],$ext),'file, 20 s, pris par le poste 1006 (hors liste) : récupéré');
ok(!YeastarRecordings::shortWithoutAgent(['extension'=>'6401','call_status'=>'ANSWERED','call_duration'=>null,'talk_duration'=>1,'recording'=>'20261119152928-1795098506.9840-0797695124-1005-Inbound.wav'],$ext),'file, 1 s, pris par un commercial : récupéré');
ok(YeastarRecordings::shortWithoutAgent(['extension'=>'6200','call_status'=>'ANSWERED','call_duration'=>15,'talk_duration'=>15,'recording'=>'20261119100000-1.1-0550123456-6200-Inbound.wav'],$ext),'SVI seul, 15 s : toujours écarté');
ok(YeastarRecordings::answeredBy('x-0550-1005-Inbound.wav')==='1005'&&YeastarRecordings::answeredBy('a.wav')==='','poste lu dans le nom de l’audio');
$row=fn($id,$call,$ext,$st,$talk,$dur,$uid='')=>['id'=>$id,'pbx_url'=>'p','call_id'=>$call,'phone'=>'0550','direction'=>'inbound','created_at'=>100+$id,'extension'=>$ext,'prospect_id'=>null,'user_id'=>$uid,'call_status'=>$st,'talk_duration'=>$talk,'call_duration'=>$dur];
$c=CallDashboard::aggregate([$row(1,'A','6200','ANSWERED',9,12),$row(2,'A','6400','NO ANSWER',0,12)]);
ok(count($c)===1&&$c[0]['duration']===12&&!$c[0]['answered'],'appel A : 12 s, sans réponse');
$c=CallDashboard::aggregate([$row(3,'B','6200','ANSWERED',14,null),$row(4,'B','6400','NO ANSWER',0,null)]);
ok($c[0]['duration']===14,'ancien appel : durée = temps SVI');
$c=CallDashboard::aggregate([$row(5,'C','6200','ANSWERED',10,75),$row(6,'C','1008','ANSWERED',50,75,'u8')]);
ok($c[0]['duration']===75&&$c[0]['answered'],'appel C : décroché par un commercial');
require_once __DIR__.'/../callcrm/lib/Service/MissedCalls.php';
require_once __DIR__.'/../callcrm/lib/Service/InboundOutcome.php';
$ev=fn($at,$state,$uid='')=>['created_at'=>$at,'state'=>$state,'user_id'=>$uid,'phone'=>'+213550123456'];
$classify=fn($events,$answered=false)=>\OCA\CallCrm\Service\InboundOutcome::classify($events,'0550123456',$answered);
$start=$ev(1,'TRUNK_START');
$end=$ev(25,'TRUNK_END');
ok($classify([$start,$end])['kind']==='lost','closed IVR call without extension is lost');
$short=[$start,$ev(22,'RING','a'),$ev(22,'RING','b'),$end,$ev(26,'BYE','a'),$ev(27,'BYE','b')];
ok($classify($short)['kind']==='lost'&&$classify($short)['seconds']===3,'concurrent offers are not summed and caller hangup stops ringing');
$threshold=[$start,$ev(21,'RING','a'),$ev(21,'RING','b'),$end];
ok($classify($threshold)['kind']==='missed'&&$classify($threshold)['seconds']===4,'four seconds is missed');
ok($classify([$start,$ev(21,'RING','a'),$ev(22,'ANSWER','b'),$end])['responders']===['b'],'only the commercial who answered is a responder');
ok($classify($threshold)['responders']===[],'ringing alone does not identify a responder');
ok($classify([])['kind']==='unknown','a CDR alone without a mapped answer is indeterminate');
echo $fail?"$fail/$n short-call checks FAILED\n":"callcrm: $n short-call checks passed\n";exit($fail?1:0);
}
