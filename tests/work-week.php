<?php
// Semaine de travail : du dimanche au jeudi, samedi en brigades (compté seulement si le commercial a travaillé), vendredi fermé.
namespace OCP { interface IDBConnection {} }
namespace OCP\Files { interface IAppData {} class NotFoundException extends \Exception {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_STR_ARRAY=102; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/Schedule.php';
require_once __DIR__.'/../callcrm/lib/Service/PresenceRange.php';
require_once __DIR__.'/../callcrm/lib/Service/YeastarEvents.php';
require_once __DIR__.'/../callcrm/lib/Service/CallDashboard.php';
use OCA\CallCrm\Service\{PresenceRange, CallDashboard, Schedule};
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
// Réglage personnalisé : samedi fermé, 9 h – 17 h, semaine du samedi, 1er novembre férié chaque année, un férié ponctuel.
$s=Schedule::normalize(['days'=>[6=>'closed',5=>'brigade'],'open'=>'09:00','close'=>'17:00','week_start'=>6,
  'holidays'=>[['date'=>'2026-11-01','label'=>'Révolution','yearly'=>true],['date'=>'2026-11-24','label'=>'Aïd'],['date'=>'','label'=>'vide']]]);
ok(count($s['holidays'])===2&&$s['days'][5]==='brigade'&&$s['days'][7]==='open','réglage validé, ligne vide ignorée');
ok(PresenceRange::weekStart(new DateTimeImmutable('2026-11-26',$z),6)->format('Y-m-d')==='2026-11-21','semaine depuis samedi');
ok(CallDashboard::workDays($w[0],$w[1],$z,[$at('2026-11-27 10:00')],$s)===5,'férié non compté, brigade du vendredi comptée si travaillée');
ok(Schedule::holiday($s,new DateTimeImmutable('2027-11-01'))['label']==='Révolution'&&!Schedule::holiday($s,new DateTimeImmutable('2027-11-24')),'férié annuel / ponctuel');
$c=fn($t)=>CallDashboard::closureRange($at($t),$z,$s)['label'];
ok($c('2026-11-25 10:00')==='23/11/2026 17:00 → 25/11/2026 09:00','le lendemain d’un férié : fermé depuis la veille du férié');
ok($c('2026-11-26 10:00')==='25/11/2026 17:01 → 26/11/2026 09:00','nuit ordinaire avec les horaires réglés');
ok($c('2026-11-28 12:00')==='27/11/2026 17:00 → 29/11/2026 09:00','samedi fermé : reprise dimanche');
foreach ([['days'=>array_fill_keys([1,2,3,4,5,6,7],'closed')],['open'=>'17:00','close'=>'09:00'],['open'=>'8h'],['holidays'=>[['date'=>'2026-02-30']]],['week_start'=>9]] as $bad) {
  try { Schedule::normalize($bad); ok(false,'refus attendu '.json_encode($bad)); } catch (InvalidArgumentException $e) { ok(true,''); }
}
echo "$n vérifications, $fail échec(s)\n";exit($fail?1:0);
}
