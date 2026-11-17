<?php
// Test du contrôleur WhatsApp dans le vrai Nextcloud ; aucun message réel (client HTTP factice), tout est annulé (rollback).
// Usage sur le VPS : sudo -u csmtak11 php tests/live/wa-controller.php (depuis la racine Nextcloud ou ce dépôt). Lit la base réelle, n’écrit rien.
require '/home/csmtak11/cloud.csmtaksit.dz/lib/base.php';\OC_App::loadApps();
use OCA\CallCrm\Controller\WaController;use OCA\CallCrm\Service\{Access,Store,Wa};use OCP\Http\Client\{IClient,IClientService,IResponse,IPromise};
final class Resp implements IResponse{public function __construct(private int $c,private array $b){}public function getBody(){return json_encode($this->b);}public function getStatusCode():int{return $this->c;}public function getHeader(string $k):string{return '';}public function getHeaders():array{return [];}}
final class Cli implements IClient{public array $sent=[];public function __construct(private array $answers){}
 private function r($u,$o){$this->sent[]=[$u,json_decode($o['body']??'null',true)];foreach($this->answers as $k=>$a)if(str_ends_with($u,$k))return new Resp($a[0],$a[1]);return new Resp(500,['error'=>'?']);}
 public function get(string $u,array $o=[]):IResponse{return $this->r($u,$o);}public function post(string $u,array $o=[]):IResponse{return $this->r($u,$o);}
 public function head(string $u,array $o=[]):IResponse{throw new \Exception;}public function put(string $u,array $o=[]):IResponse{throw new \Exception;}public function patch(string $u,array $o=[]):IResponse{throw new \Exception;}public function delete(string $u,array $o=[]):IResponse{throw new \Exception;}public function options(string $u,array $o=[]):IResponse{throw new \Exception;}
 public function getResponseFromThrowable(\Throwable $e):IResponse{throw $e;}public function request(string $m,string $u,array $o=[]):IResponse{return $this->r($u,$o);}public function sendRequest(\Psr\Http\Message\RequestInterface $q):\Psr\Http\Message\ResponseInterface{throw new \Exception;}
 public function getAsync(string $u,array $o=[]):IPromise{throw new \Exception;}public function headAsync(string $u,array $o=[]):IPromise{throw new \Exception;}public function postAsync(string $u,array $o=[]):IPromise{throw new \Exception;}public function putAsync(string $u,array $o=[]):IPromise{throw new \Exception;}public function deleteAsync(string $u,array $o=[]):IPromise{throw new \Exception;}public function optionsAsync(string $u,array $o=[]):IPromise{throw new \Exception;}}
final class Svc implements IClientService{public function __construct(public Cli $c){}public function newClient(?callable $h=null):IClient{return $this->c;}}
$fails=0;function ok($c,$m){global $fails;echo ($c?'PASS ':'FAIL ').$m."\n";if(!$c)$fails++;}
$s=\OC::$server;$db=$s->get(\OCP\IDBConnection::class);$us=$s->get(\OCP\IUserSession::class);$gm=$s->get(\OCP\IGroupManager::class);$um=$s->get(\OCP\IUserManager::class);
$q=$db->getQueryBuilder();$rows=$q->select('id','assigned_to','phone')->from('callcrm_prospects')->where($q->expr()->isNotNull('assigned_to'))->setMaxResults(3000)->executeQuery()->fetchAll();
$mine=null;$other=null;$roles=[];
foreach($rows as $r){
 if(!Wa::localPhone((string)$r['phone']))continue;
 if(!isset($roles[$r['assigned_to']])){$u=$um->get($r['assigned_to']);$roles[$r['assigned_to']]='none';if($u){$us->setUser($u);$roles[$r['assigned_to']]=(new Access($us,$gm))->role();}}
 if($roles[$r['assigned_to']]!=='commercial')continue;
 if(!$mine)$mine=$r;elseif($r['assigned_to']!==$mine['assigned_to']){$other=$r;break;}
}
if(!$mine||!$other){echo "pas de données de test\n";exit(1);}
$us->setUser($um->get($mine['assigned_to']));$acc=new Access($us,$gm);echo 'rôle testé : '.$acc->role()."\n";
$make=function(array $params,array $answers)use($s,$acc,$gm,$um){$req=new \OC\AppFramework\Http\Request(['post'=>$params],new \OC\AppFramework\Http\RequestId('t',$s->get(\OCP\Security\ISecureRandom::class)),$s->get(\OCP\IConfig::class));
 $cli=new Cli($answers);return [new WaController($req,$acc,$s->get(Store::class),$s->get(Wa::class),$gm,$um,$s->get(\OCP\Files\IRootFolder::class),new Svc($cli)),$cli];};
$db->beginTransaction();
try{
 $wa=$s->get(Wa::class);$before=$wa->convForProspect((int)$mine['id'],Wa::localPhone($mine['phone']));
 [$c,$cli]=$make(['template'=>'relance','language'=>'fr','values'=>['body'=>['1'=>'Ali']]],['/send'=>[200,['wamid'=>'wamid.TEST1','text'=>'Bonjour Ali']]]);
 $r=$c->sendProspect((int)$mine['id']);$d=$r->getData();
 ok($r->getStatus()===200&&!empty($d['conv']),'modèle envoyé depuis la fiche du commercial ('.$r->getStatus().' '.json_encode($d,JSON_UNESCAPED_UNICODE).')');
 ok(($cli->sent[0][1]['template']['name']??'')==='relance'&&($cli->sent[0][1]['to']??'')===Wa::waId(Wa::localPhone($mine['phone']))&&($cli->sent[0][1]['values']['body']['1']??'')==='Ali','service appelé avec le modèle, les variables et le bon numéro');
 $conv=!empty($d['conv'])?$wa->conv((int)$d['conv']):null;ok($conv&&(int)$conv['prospect_id']===(int)$mine['id']&&$conv['owner']===$mine['assigned_to'],'conversation rattachée à la fiche et au commercial'.($before?' (existante)':' (créée)'));
 $m=$conv?$wa->messages((int)$d['conv']):[];$last=$m?end($m):null;ok($last&&$last['direction']==='out'&&str_contains($last['body'],'Modèle « relance »')&&$last['wamid']==='wamid.TEST1','message sortant enregistré dans la conversation');
 [$c,$cli]=$make(['template'=>'relance','language'=>'fr'],['/send'=>[200,['wamid'=>'x']]]);$r=$c->sendProspect((int)$other['id']);
 ok(in_array($r->getStatus(),[403,404],true)&&!$cli->sent,'refus pour la fiche d’un autre commercial ('.$r->getStatus().')');
 [$c,$cli]=$make(['template'=>'Bad Name!','language'=>'fr'],[]);$r=$c->sendProspect((int)$mine['id']);ok($r->getStatus()===400&&!$cli->sent,'nom de modèle invalide refusé avant envoi');
 [$c,$cli]=$make(['template'=>'relance','language'=>'fr','values'=>['body'=>['1'=>['x']]]],[]);$r=$c->sendProspect((int)$mine['id']);ok($r->getStatus()===400&&!$cli->sent,'variable non texte refusée');
 [$c,$cli]=$make(['template'=>'relance','language'=>'fr'],['/send'=>[400,['error'=>'Variable {{1}} du modèle à remplir']]]);$r=$c->sendProspect((int)$mine['id']);
 ok($r->getStatus()===400&&str_contains($r->getData()['error']??'','Variable'),'erreur du service relayée au commercial');
 $win=$conv?$wa->conv((int)$d['conv'])['window_open']:false;
 [$c,$cli]=$make(['text'=>'Bonjour'],['/send'=>[200,['wamid'=>'wamid.TEST2']]]);$r=$c->sendProspect((int)$mine['id']);
 ok($win?($r->getStatus()===200):($r->getStatus()===400&&!$cli->sent),'message libre '.($win?'accepté (fenêtre ouverte)':'refusé hors fenêtre de 24 h'));
 [$c,$cli]=$make([],['/templates'=>[200,['templates'=>[['name'=>'relance']]]]]);$r=$c->templates();ok($r->getStatus()===200&&count($r->getData()['templates'])===1,'liste des modèles relayée');
}finally{$db->rollBack();}
$q=$db->getQueryBuilder();$n=$q->select($q->func()->count('*'))->from('callcrm_wa_msg')->where($q->expr()->like('wamid',$q->createNamedParameter('wamid.TEST%')))->executeQuery()->fetchOne();ok((int)$n===0,'rien de conservé en base après le test');
echo $fails?"ÉCHECS: $fails\n":"tout est passé\n";exit($fails?1:0);
