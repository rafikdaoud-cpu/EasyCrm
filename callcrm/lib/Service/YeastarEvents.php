<?php
namespace OCA\CallCrm\Service;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class YeastarEvents {
 public function __construct(private IDBConnection $db,private YeastarSettings $settings){}
 public static function number(string $number):string {
  $n=preg_replace('/[\s().-]+/','',trim($number));
  if(str_starts_with($n,'00213'))$n='+213'.substr($n,5);
  if(preg_match('/^213\d{9}$/D',$n))$n='+'.$n;
  return $n;
 }
 public function match(string $number):?array {
  $n=self::number($number);$variants=[$n];
  if(preg_match('/^0\d{9}$/D',$n))$variants=array_merge($variants,['+213'.substr($n,1),'213'.substr($n,1),'00213'.substr($n,1)]);
  if(preg_match('/^\+213\d{9}$/D',$n))$variants=array_merge($variants,['0'.substr($n,4),substr($n,1),'00'.substr($n,1)]);
  $q=$this->db->getQueryBuilder();$q->select('*')->from('callcrm_prospects')->where($q->expr()->in('phone',$q->createNamedParameter(array_unique($variants),IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(2);
  $rows=$q->executeQuery()->fetchAll();return count($rows)===1?$rows[0]:null;
 }
 /** Vrai si au moins une fiche porte ce numéro, quel que soit son commercial (évite de proposer un doublon). */
 public function known(string $number):bool {
  $n=self::number($number);$variants=[$n];
  if(preg_match('/^0\d{9}$/D',$n))$variants=array_merge($variants,['+213'.substr($n,1),'213'.substr($n,1),'00213'.substr($n,1)]);
  if(preg_match('/^\+213\d{9}$/D',$n))$variants=array_merge($variants,['0'.substr($n,4),substr($n,1),'00'.substr($n,1)]);
  $q=$this->db->getQueryBuilder();$q->select('id')->from('callcrm_prospects')->where($q->expr()->in('phone',$q->createNamedParameter(array_unique($variants),IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1);
  return (bool)$q->executeQuery()->fetchOne();
 }
 public function record(string $hash,string $call,string $uid,string $phone,string $direction,string $state,?int $pid):void {
  if(strlen($call)>128||strlen($phone)>40||strlen($state)>24)return;
  $q=$this->db->getQueryBuilder();$q->insert('callcrm_pbx_events');
  foreach(['event_hash'=>$hash,'call_id'=>$call,'user_id'=>$uid,'phone'=>$phone,'direction'=>$direction,'state'=>$state,'prospect_id'=>$pid,'created_at'=>time()] as $k=>$v)$q->setValue($k,$q->createNamedParameter($v));
  try{$q->executeStatement();}catch(\Throwable $e){$check=$this->db->getQueryBuilder();$check->select('id')->from('callcrm_pbx_events')->where($check->expr()->eq('event_hash',$check->createNamedParameter($hash)));if(!$check->executeQuery()->fetchOne())throw $e;}
 }
 public static function parse(array $event):array {
  if((int)($event['type']??0)!==30011)return [];
  $msg=$event['msg']??[];if(is_string($msg))$msg=json_decode($msg,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($msg)||empty($msg['call_id'])||!is_array($msg['members']??null))throw new \InvalidArgumentException('Événement d’appel invalide.');
  $external=null;$direction='';$extensions=[];
  foreach($msg['members'] as $member){
   if(!is_array($member))continue;
   foreach(['inbound'=>'from','outbound'=>'to'] as $kind=>$field)if(isset($member[$kind][$field])){$external=self::number((string)$member[$kind][$field]);$direction=$kind;}
   if(isset($member['extension']['number']))$extensions[]=$member['extension'];
  }
  // Événement réduit aux postes (le numéro du client était dans un événement précédent du même appel) : phone vide, complété par receive().
  if($external!==null&&!preg_match('/^\+?\d{3,20}$/D',$external))return [];
  $external??='';
  $out=[];foreach($extensions as $ext){$state=(string)($ext['member_status']??'');if(!in_array($state,['ALERT','RING','ANSWERED','ANSWER','HOLD','BYE'],true))continue;
   $out[]=['call_id'=>(string)$msg['call_id'],'extension'=>(string)$ext['number'],'phone'=>$external,'direction'=>$direction,'state'=>$state];
  }return $out;
 }
 public function receive(string $raw,string $signature):void {
  $s=$this->settings->credentials();
  if(!$s['enabled']||$s['webhook_secret']===''||!hash_equals(base64_encode(hash_hmac('sha256',$raw,$s['webhook_secret'],true)),$signature))throw new \DomainException('Signature Webhook refusée.');
  $event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($event))throw new \InvalidArgumentException('Événement invalide.');
  if(($event['event']??'')==='test')return;
  // Diagnostic temporaire (occ config:app:set callcrm yeastar_debug_until --value=<horodatage>) : 20 derniers événements d'appel.
  try{$cfg=\OCP\Server::get(\OCP\IConfig::class);if((int)$cfg->getAppValue('callcrm','yeastar_debug_until','0')>time()&&(int)($event['type']??0)!==30012){
   $log=json_decode($cfg->getAppValue('callcrm','yeastar_debug_log','[]'),true)?:[];$log[]=['at'=>time(),'type'=>$event['type']??null,'msg'=>is_string($event['msg']??null)?mb_substr($event['msg'],0,3000):$event['msg']??null];
   $cfg->setAppValue('callcrm','yeastar_debug_log',json_encode(array_slice($log,-20),JSON_UNESCAPED_UNICODE));}}catch(\Throwable $e){}
  if((int)($event['type']??0)===30012){$this->receiveCdr($event);return;}
  foreach(self::parse($event) as $row){
   if($row['phone']===''){$known=$this->byCall($row['call_id']);if(!$known)continue;$row['phone']=$known['phone'];$row['direction']=$known['direction'];}
   foreach($s['extensions'] as $mapping)if($mapping['extension']===$row['extension']){
    $p=$this->match($row['phone']);
    $this->record(hash('sha256',$raw.'|'.$mapping['uid']),$row['call_id'],$mapping['uid'],$row['phone'],$row['direction'],$row['state'],$p?(int)$p['id']:null);
   }
  }
 }
 private function receiveCdr(array $event):void {
  $row=YeastarRecordings::parse($event);if($row===null)return;
  $q=$this->db->getQueryBuilder();$q->select('id')->from('callcrm_pbx_recordings')->where($q->expr()->eq('source_key',$q->createNamedParameter($row['source_key'])));
  if($q->executeQuery()->fetchOne())return;
  $p=$this->match($row['phone']);$uid='';
  $config=$this->settings->settings();
  foreach($config['extensions'] as $mapping)if($mapping['extension']===$row['extension'])$uid=$mapping['uid'];
  $state=$row['recording']===''?'no_recording':(YeastarRecordings::shortWithoutAgent($row,array_column($config['extensions'],'extension'))?'skipped_short':'queued');
  $row+=['storage_folder'=>$config['recordings_folder'],'pbx_url'=>$config['url'],'prospect_id'=>$p?(int)$p['id']:null,'user_id'=>$uid,'state'=>$state,
   'error'=>'','mime'=>'','size'=>0,'attempts'=>0,'next_attempt'=>0,'lease_until'=>0,'created_at'=>time()];
  $q=$this->db->getQueryBuilder();$q->insert('callcrm_pbx_recordings');foreach($row as $k=>$v)$q->setValue($k,$q->createNamedParameter($v));
  try{$q->executeStatement();}catch(\Throwable $e){
   $check=$this->db->getQueryBuilder();$check->select('id')->from('callcrm_pbx_recordings')->where($check->expr()->eq('source_key',$check->createNamedParameter($row['source_key'])));
   if(!$check->executeQuery()->fetchOne())throw $e;
  }
 }
 /** Numéro et sens d'un appel déjà vus (même call_id) dans les 2 dernières heures. */
 private function byCall(string $call):?array{
  $q=$this->db->getQueryBuilder();$q->select('phone','direction')->from('callcrm_pbx_events')->where($q->expr()->eq('call_id',$q->createNamedParameter($call)))->andWhere($q->expr()->neq('phone',$q->createNamedParameter('')))
   ->andWhere($q->expr()->gte('created_at',$q->createNamedParameter(time()-7200,IQueryBuilder::PARAM_INT)))->orderBy('id','DESC')->setMaxResults(1);
  $r=$q->executeQuery()->fetch();return $r?:null;
 }
 public function recent(string $uid,int $after):array {
  $q=$this->db->getQueryBuilder();$q->select('*')->from('callcrm_pbx_events')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->gt('id',$q->createNamedParameter(max(0,$after),IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->gte('created_at',$q->createNamedParameter(time()-120,IQueryBuilder::PARAM_INT)))->orderBy('id','ASC')->setMaxResults(50);
  return $q->executeQuery()->fetchAll();
 }
 public function history(int $pid):array {
  $q=$this->db->getQueryBuilder();$q->select('call_id','direction','state','created_at')->from('callcrm_pbx_events')->where($q->expr()->eq('prospect_id',$q->createNamedParameter($pid,IQueryBuilder::PARAM_INT)))->orderBy('id','DESC')->setMaxResults(50);return $q->executeQuery()->fetchAll();
 }
}
