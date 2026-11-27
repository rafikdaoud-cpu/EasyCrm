<?php
// Tableau de bord qualité (agrégats) et fiches en double (groupes, valeurs fusionnées) : fonctions pures.
namespace OCP { interface IDBConnection {} interface IConfig {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_INT=1; const PARAM_INT_ARRAY=101; } }
namespace {
require_once __DIR__.'/../callcrm/lib/Service/Quality.php';
require_once __DIR__.'/../callcrm/lib/Service/Duplicates.php';
use OCA\CallCrm\Service\{Quality, Duplicates};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
$sc=fn($a,$b,$e)=>json_encode([['label'=>'Accueil','max'=>2,'eliminatory'=>false,'value'=>$a],['label'=>'Objections','max'=>2,'eliminatory'=>false,'value'=>$b],['label'=>'Impoli','max'=>0,'eliminatory'=>true,'value'=>$e]]);
$t=strtotime('2026-11-23 10:00');
$r=Quality::aggregate([
 ['agent_uid'=>'a','score'=>100,'eliminated'=>0,'scores'=>$sc(2,2,0),'ack'=>1,'updated_at'=>$t],
 ['agent_uid'=>'a','score'=>50,'eliminated'=>0,'scores'=>$sc(2,0,0),'ack'=>2,'updated_at'=>$t+3600],
 ['agent_uid'=>'b','score'=>75,'eliminated'=>1,'scores'=>$sc(2,1,1),'ack'=>0,'updated_at'=>$t-7*86400],
]);
ok($r['count']===3&&$r['avg']===50,'moyenne globale, éliminée = 0');
ok($r['agents'][0]['uid']==='b'&&$r['agents'][0]['avg']===0&&$r['agents'][0]['eliminated']===1,'commercial le plus faible en premier');
ok($r['agents'][1]['avg']===75&&$r['agents'][1]['contested']===1&&$r['agents'][1]['count']===2,'moyenne et contestations du commercial');
ok($r['criteria'][0]['label']==='Objections'&&$r['criteria'][0]['pct']===50,'critère le plus raté en premier');
ok(end($r['criteria'])['label']==='Impoli'&&end($r['criteria'])['pct']===33,'éliminatoire : fréquence, en dernier');
ok(count($r['weeks'])===2&&$r['weeks'][1]['avg']===75,'moyenne par semaine');
ok(Quality::aggregate([])['avg']===0,'aucune évaluation');

ok(Duplicates::nameKey('Benali','Mohamed')===Duplicates::nameKey(' mohamed ','BENALI'),'nom dans un autre ordre');
ok(Duplicates::nameKey('محمد','')===''&&Duplicates::nameKey('Ali','B')==='','prénom seul ou trop court ignoré');
$p=fn($id,$l,$f,$ph,$x=[])=>$x+['id'=>$id,'lastname'=>$l,'firstname'=>$f,'phone'=>$ph,'email'=>'','assigned_to'=>null];
$g=Duplicates::group([$p(1,'Benali','Mohamed','0663967416'),$p(2,'mohamed','benali','0770400172'),$p(3,'Mohamed','','0550000000'),$p(4,'Ali','Kaci','0551',['email'=>'a@b.dz']),$p(5,'Omar','Z','0552',['email'=>'A@b.dz '])]);
ok(count($g)===2&&$g[0]['ids']===[1,2]&&$g[1]['reason']==='email'&&$g[1]['ids']===[4,5],'groupes par nom et par e-mail');
ok(count(Duplicates::group([$p(1,'Benali','Mohamed','1'),$p(2,'Benali','Mohamed','2')],['1,2']))===0,'groupe écarté');
$keep=['id'=>1,'lastname'=>'Benali','firstname'=>'Mohamed','phone'=>'0663967416','email'=>'','wilaya'=>'','remarque'=>'A rappeler','comment'=>'','extra'=>'{"x":"1"}','created_at'=>200,'last_call_at'=>100,'status'=>'Intéressé','assigned_to'=>'u1','salary'=>'50000'];
$drop=['id'=>2,'lastname'=>'Benali','firstname'=>'Mohamed','phone'=>'0770400172','email'=>'m@b.dz','wilaya'=>'16','remarque'=>'Veut une moto','comment'=>'CCP','extra'=>'{"x":"2","y":"3"}','created_at'=>100,'last_call_at'=>300,'status'=>'Nouveau','assigned_to'=>'u2','salary'=>'60000'];
$v=Duplicates::mergedValues($keep,$drop);
ok(($v['email']??'')==='m@b.dz'&&($v['wilaya']??'')==='16'&&!isset($v['salary'])&&!isset($v['status'])&&!isset($v['assigned_to']),'seuls les champs vides sont complétés');
ok($v['remarque']==="A rappeler\nVeut une moto"&&$v['comment']==="CCP\nAutre numéro (fiche fusionnée) : 0770400172",'notes réunies, autre numéro noté');
ok(json_decode($v['extra'],true)===['x'=>'1','y'=>'3']&&$v['created_at']===100&&$v['last_call_at']===300,'extra et dates fusionnés');
ok(Duplicates::mergedValues($keep,$keep)===[],'fusion d’une fiche identique : rien à changer');
echo "$n vérifications, $fail échec(s)\n";exit($fail?1:0);
}
