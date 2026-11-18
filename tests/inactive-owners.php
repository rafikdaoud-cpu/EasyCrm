<?php
// Fiches des commerciaux désactivés/supprimés dans l'AD : remises en non attribué après deux contrôles, jamais en cas de panne de l'annuaire.
namespace OCP { interface IConfig {} interface IDBConnection {} interface IUserManager {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_INT=1,PARAM_STR=2,PARAM_NULL=0,PARAM_STR_ARRAY=102,PARAM_INT_ARRAY=101; } }
namespace {
require __DIR__.'/../callcrm/lib/Service/Store.php';require __DIR__.'/../callcrm/lib/Service/InactiveOwners.php';
use OCA\CallCrm\Service\{InactiveOwners,Store};
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
class Config implements \OCP\IConfig {public array $v=[];public function getAppValue($a,$k,$d=''){return $this->v[$k]??$d;}public function setAppValue($a,$k,$x){$this->v[$k]=$x;}}
class FakeStore extends Store {public array $cards;public array $audits=[];public function __construct(array $c){$this->cards=$c;}
 public function reassign(array $ids,?string $to,?string $actor=null):int{$k=0;foreach($ids as $i){$this->cards[$i]=$to;$k++;}return $k;}
 public function audit(?string $uid,string $action,?int $pid=null,string $detail=''):void{$this->audits[]=[$uid,$action,$detail];}}
class T extends InactiveOwners {public array $active=[];public FakeStore $s;
 public function __construct(FakeStore $s,Config $c){$this->s=$s;parent::__construct(new class implements \OCP\IDBConnection{},new class implements \OCP\IUserManager{},$c,$s);}
 protected function active(string $uid):bool{return $this->active[$uid]??false;}
 protected function owners():array{return array_values(array_unique(array_filter($this->s->cards)));}
 protected function idsOf(string $uid):array{return array_keys(array_filter($this->s->cards,fn($o)=>$o===$uid));}}
$cards=[];foreach(['a','b','c','d','e','f'] as $i=>$u)for($k=0;$k<5;$k++)$cards[$i*10+$k]=$u;
$st=new FakeStore($cards);$cfg=new Config;$t=new T($st,$cfg);$t->active=['a'=>true,'b'=>true,'c'=>true,'d'=>true,'e'=>true,'f'=>false];
$r=$t->run(1000);ok($r['released']===[]&&$r['inactive']===['f'],'1er contrôle : compte repéré, rien libéré');
$r=$t->run(1000+1800);ok($r['released']===[],'30 min plus tard : toujours en attente');
$r=$t->run(1000+3600);ok($r['released']===['f'=>5]&&count(array_filter($st->cards,fn($o)=>$o===null))===5,'1 h plus tard : 5 fiches libérées');
ok(str_contains($st->audits[0][2],'désactivé ou supprimé'),'journal');
// compte revenu entre-temps : le compteur repart de zéro
$st2=new FakeStore($cards);$c2=new Config;$t2=new T($st2,$c2);$t2->active=['a'=>true,'b'=>true,'c'=>true,'d'=>true,'e'=>false,'f'=>true];
$t2->run(0);$t2->active['e']=true;$t2->run(3600);$t2->active['e']=false;$r=$t2->run(7200);ok($r['released']===[],'compte réactivé puis désactivé : nouvelle attente');
// panne de l'annuaire : la moitié des comptes disparaissent → rien
$st3=new FakeStore($cards);$c3=new Config;$t3=new T($st3,$c3);$t3->active=['a'=>true,'b'=>true,'c'=>true];
$t3->run(0);$r=$t3->run(7200);ok($r['released']===[]&&str_contains($r['skipped'],'annuaire')&&!in_array(null,$st3->cards,true),'panne annuaire : aucune fiche touchée');
echo $fail?"$fail/$n inactive owner checks FAILED\n":"callcrm: $n inactive owner checks passed\n";exit($fail?1:0);
}
