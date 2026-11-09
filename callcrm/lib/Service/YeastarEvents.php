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
  if($external===null||!preg_match('/^\+?\d{3,20}$/D',$external))return [];
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
  foreach(self::parse($event) as $row){
   foreach($s['extensions'] as $mapping)if($mapping['extension']===$row['extension']){
    $p=$this->match($row['phone']);
    $this->record(hash('sha256',$raw.'|'.$mapping['uid']),$row['call_id'],$mapping['uid'],$row['phone'],$row['direction'],$row['state'],$p?(int)$p['id']:null);
   }
  }
 }
 public function recent(string $uid,int $after):array {
  $q=$this->db->getQueryBuilder();$q->select('*')->from('callcrm_pbx_events')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->gt('id',$q->createNamedParameter(max(0,$after),IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->gte('created_at',$q->createNamedParameter(time()-120,IQueryBuilder::PARAM_INT)))->orderBy('id','ASC')->setMaxResults(50);
  return $q->executeQuery()->fetchAll();
 }
 public function history(int $pid):array {
  $q=$this->db->getQueryBuilder();$q->select('call_id','direction','state','created_at')->from('callcrm_pbx_events')->where($q->expr()->eq('prospect_id',$q->createNamedParameter($pid,IQueryBuilder::PARAM_INT)))->orderBy('id','DESC')->setMaxResults(50);return $q->executeQuery()->fetchAll();
 }
}
