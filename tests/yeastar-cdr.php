<?php
// Classement des appels entrants à partir du journal complet du standard (étapes réelles du 24/11/2026).
namespace OCP { interface IDBConnection {} interface IConfig {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_STR_ARRAY=102; const PARAM_INT=1; const PARAM_STR=2; const PARAM_NULL=0; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/YeastarEvents.php';
require_once __DIR__.'/../callcrm/lib/Service/InboundOutcome.php';
require_once __DIR__.'/../callcrm/lib/Service/YeastarCdr.php';
use OCA\CallCrm\Service\YeastarCdr;
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
$leg=fn($label,$number,$disp,$ring=null,$talk=null,$dur=null)=>['call_type'=>'inbound','to_label'=>$label,'to_number'=>$number,'disposition'=>$disp,'ring_duration'=>$ring,'talk_duration'=>$talk,'duration'=>$dur];
$closed=['6201','IVR CLOSED<6201>','IVR CLOSED <6201>'];
$owners=['1014'=>'nawel','1017'=>'sara'];
$c=fn(array $legs)=>YeastarCdr::classify($legs,$closed,$owners);
ok($c([$leg('IVR OPEN<6200>','6200','ANSWERED',null,7,7)])['kind']==='menu','raccroché dans l’accueil : abandon au menu');
ok($c([$leg('IVR CLOSED<6201>','6201','ANSWERED',null,6,6)])['kind']==='closed','message de fermeture : hors horaires');
ok($c([$leg('IVR OPEN<6200>','6200','ANSWERED',null,12,12),$leg('Queue CSM_AR<6400>','6400','NO ANSWER',3,null,3)])['kind']==='lost','file 3 s sans réponse : perdu');
$r=$c([$leg('IVR OPEN<6200>','6200','ANSWERED',null,12,12),$leg('Queue CSM_AR<6400>','6400','NO ANSWER',68,null,68)]);
ok($r['kind']==='missed' && $r['seconds']===68,'file 68 s sans réponse : manqué (et non perdu)');
$r=$c([$leg('IVR CRM_1014<6257>','6257','ANSWERED',null,12,12),$leg('Nawel BELAIFA<1014>','1014','NO ANSWER',15,null,15),$leg('Play Prompt','','ANSWERED',null,3,3),$leg('Queue CSM_FR<6401>','6401','NO ANSWER',43,null,43)]);
ok($r['kind']==='missed' && $r['agents']===['nawel'],'commercial puis file sans réponse : manqué, poste sonné retenu');
$r=$c([$leg('IVR OPEN<6200>','6200','ANSWERED',null,9,9),$leg('Queue CSM_AR<6400>','6400','ANSWERED',15,8,23),$leg('Nawel BELAIFA<1014>','1014','ANSWERED',9,8,17)]);
ok($r['kind']==='answered' && $r['responders']===['nawel'],'décroché via la file par un commercial');
$r=$c([$leg('IVR OPEN<6200>','6200','ANSWERED',null,9,9),$leg('1019','1019','ANSWERED',4,30,34)]);
ok($r['kind']==='answered' && $r['responders']===[],'poste non associé à un compte : décroché quand même');
ok($c([$leg('IVR OPEN<6200>','6200','ANSWERED',null,9,9),$leg('Voicemail AMIRA<1016>','1016','ANSWERED',null,20,20)])['kind']==='menu','messagerie seule : aucun commercial atteint');
ok($c([])===null && $c([['call_type'=>'outbound']+$leg('x','0550','ANSWERED')])===null,'aucune étape entrante : classement par événements');
echo $fail?"$fail/$n journal checks FAILED\n":"callcrm: $n journal checks passed\n";exit($fail?1:0);
}
