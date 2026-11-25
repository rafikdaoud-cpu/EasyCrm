<?php
// Menu du standard par équipe : postes des files et changements du SVI d'accueil.
namespace OCP { interface IConfig {} interface IGroupManager {} }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/YeastarTeams.php';
use OCA\CallCrm\Service\YeastarTeams as T;
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
$ext=['rofaida'=>'1002','fares'=>'1005','sup'=>'1000','yasmine'=>null];
$ids=['1002'=>'12','1005'=>'10','1000'=>'8'];
ok(T::agents(['fares','rofaida','sup','yasmine','inconnu'],array_filter($ext),$ids,['sup'=>0])===['10','12'],'postes des commerciaux, sans superviseur ni compte sans poste');
ok(T::agents([], $ext, $ids, [])===[],'équipe vide : file sans poste');
$open=['prompt'=>'Bienvenue-.wav','prompt_repeat'=>1,'press1_dest'=>'queue','press1_dest_value'=>'1','press2_dest'=>'queue','press2_dest_value'=>'2','press3_dest'=>'','press3_dest_value'=>'','timeout_dest'=>'queue','timeout_dest_value'=>'1','invalid_dest'=>'queue','invalid_dest_value'=>'1'];
$d=T::ivrChanges($open,['1'=>3,'2'=>4,'3'=>5],'1','Accueil-CSM.wav,Menu-CSM.wav',2);
ok($d==['prompt'=>'Accueil-CSM.wav,Menu-CSM.wav','prompt_repeat'=>2,'press1_dest_value'=>'3','press2_dest_value'=>'4','press3_dest'=>'queue','press3_dest_value'=>'5'],'menu actuel → touches 1, 2, 3 vers les files des équipes, secours inchangé');
ok(T::ivrChanges(array_merge($open,$d),['1'=>3,'2'=>4,'3'=>5],'1','Accueil-CSM.wav,Menu-CSM.wav',2)===[],'SVI déjà conforme : aucune mise à jour');
echo "yeastar-teams: ".($n-$fail)."/$n\n"; exit($fail?1:0);
}
