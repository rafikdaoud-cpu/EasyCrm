<?php
// Statistiques d'un superviseur : un appel décroché ne doit pas faire planter le rapport (fonction answeredBySelection).
namespace OCP { interface IDBConnection {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_STR_ARRAY=102; const PARAM_INT=1; const PARAM_INT_ARRAY=101; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/CallDashboard.php';
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
$d=(new ReflectionClass(OCA\CallCrm\Service\CallDashboard::class))->newInstanceWithoutConstructor();
$f=new ReflectionMethod($d,'answeredBySelection');
$call=['agents'=>['sara'=>['answered'=>false],'nawel'=>['answered'=>true]]];
ok($f->invoke($d,$call,[],['nawel']),'décroché par un commercial de l’équipe (détail du standard)');
ok(!$f->invoke($d,$call,[],['sara']),'sonné chez un commercial de l’équipe mais décroché ailleurs');
ok($f->invoke($d,['agents'=>[]],[['state'=>'ANSWER','user_id'=>'sara']],['sara']),'décroché d’après les événements de sonnerie');
ok(!$f->invoke($d,['agents'=>[]],[['state'=>'RING','user_id'=>'sara']],['sara']),'sonnerie seule : pas décroché');
echo "call-dashboard-selection: ".($n-$fail)."/$n\n"; exit($fail?1:0);
}
