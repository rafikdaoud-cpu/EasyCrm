<?php
// Semaine de travail : du dimanche au jeudi, samedi en brigades (compté seulement si le commercial a travaillé), vendredi fermé.
namespace OCP { interface IDBConnection {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_STR_ARRAY=102; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/PresenceRange.php';
require_once __DIR__.'/../callcrm/lib/Service/YeastarEvents.php';
require_once __DIR__.'/../callcrm/lib/Service/CallDashboard.php';
use OCA\CallCrm\Service\{PresenceRange, CallDashboard};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
$z=new DateTimeZone('Africa/Algiers');$at=fn($s)=>(new DateTimeImmutable($s,$z))->getTimestamp();
foreach (['2026-11-22'=>'2026-11-22','2026-11-26'=>'2026-11-22','2026-11-27'=>'2026-11-22','2026-11-28'=>'2026-11-22','2026-11-29'=>'2026-11-29'] as $day=>$start)
  ok(PresenceRange::weekStart(new DateTimeImmutable($day.' 15:00',$z))->format('Y-m-d')===$start,"semaine de $day commence le $start");
ok(PresenceRange::weekStart(new DateTime('2026-11-25 09:00',$z))->format('Y-m-d H:i')==='2026-11-22 00:00','DateTime mutable accepté');
$r=PresenceRange::resolve(['period'=>'week'],$z,$at('2026-11-28 10:00'));
ok($r['from']==='2026-11-22','période « Cette semaine » depuis dimanche');
$w=[$at('2026-11-22 00:00'),$at('2026-11-29 00:00')];
ok(CallDashboard::workDays($w[0],$w[1],$z)===5,'dimanche au jeudi : 5 jours');
ok(CallDashboard::workDays($w[0],$w[1],$z,[$at('2026-11-28 10:00'),$at('2026-11-28 11:00')])===6,'samedi travaillé (brigade) : 6 jours');
ok(CallDashboard::workDays($w[0],$w[1],$z,[$at('2026-11-27 10:00')])===5,'vendredi jamais compté');
ok(CallDashboard::workDays($at('2026-11-27 00:00'),$at('2026-11-28 00:00'),$z)===0,'un vendredi seul : aucun objectif');
echo "$n vérifications, $fail échec(s)\n";exit($fail?1:0);
}
