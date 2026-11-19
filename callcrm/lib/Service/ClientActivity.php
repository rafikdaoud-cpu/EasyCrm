<?php
namespace OCA\CallCrm\Service;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Client-scoped agenda and chronicle; dates are interpreted in the CRM timezone. */
class ClientActivity {
 public function __construct(private IDBConnection $db, private IUserManager $users) {}
 public function userName(?string $uid): string {
  if (!$uid) return 'Non assigné';
  return $this->users->get($uid)?->getDisplayName() ?: 'Ancien utilisateur';
 }
 public static function dayRanges(int $now, string $zone): array {
  $d=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($zone))->setTime(0,0);
  return ['today'=>[$d->getTimestamp(),$d->modify('+1 day')->getTimestamp()], 'tomorrow'=>[$d->modify('+1 day')->getTimestamp(),$d->modify('+2 days')->getTimestamp()], 'late'=>[0,$now]];
 }
 public static function parseDate(string $date, string $zone): int {
  $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new \DateTimeZone($zone));
  if (!$d || $d->format('Y-m-d\TH:i')!==$date) throw new \InvalidArgumentException('Date et heure de rendez-vous invalides.');
  return $d->getTimestamp();
 }
 private function scoped($q, ?string $scope, string $assigned='', string $search=''): void {
  if ($scope!==null) $q->andWhere($q->expr()->eq('p.assigned_to',$q->createNamedParameter($scope)));
  else TeamScope::prospects($q,'p.assigned_to');
  if ($scope===null && $assigned!=='') $q->andWhere($q->expr()->eq('p.assigned_to',$q->createNamedParameter($assigned)));
  if ($search!=='') {
   $term='%'.$this->db->escapeLikeParameter(mb_substr($search,0,120)).'%';
   $or=$q->expr()->orX();foreach (['p.lastname','p.firstname','p.phone'] as $col) $or->add($q->expr()->iLike($col,$q->createNamedParameter($term)));
   $q->andWhere($or);
  }
 }
 private function agendaQuery(string $type, ?string $scope, string $assigned, string $search, array $range, bool $count=false) {
  $q=$this->db->getQueryBuilder();
  if ($type==='callback') {
   $q->select($count ? $q->func()->count('*','n') : 'p.*')->from('callcrm_prospects','p');
   $due='p.callback_at';
   $q->where($q->expr()->in('p.status',$q->createNamedParameter(Statuses::callbackKeys() ?: ['-'],IQueryBuilder::PARAM_STR_ARRAY)));
  } else {
   $q->select($count ? $q->func()->count('*','n') : 'p.id','p.lastname','p.firstname','p.phone','p.assigned_to','p.status','p.last_note','p.c5','a.id AS appointment_id','a.starts_at','a.title','a.notes','a.state','a.revision')
     ->from('callcrm_appointments','a')->innerJoin('a','callcrm_prospects','p',$q->expr()->eq('p.id','a.prospect_id'));
   // Some query builders retain additional select arguments: use a distinct count query.
   if ($count) $q->select($q->func()->count('*','n'));
   $due='a.starts_at';$q->where($q->expr()->eq('a.state',$q->createNamedParameter('open')));
  }
  $q->andWhere($q->expr()->gte($due,$q->createNamedParameter($range[0],IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->lt($due,$q->createNamedParameter($range[1],IQueryBuilder::PARAM_INT)));
  $this->scoped($q,$scope,$assigned,$search);
  if (!$count) $q->orderBy($due,'ASC')->addOrderBy($type==='callback'?'p.id':'a.id','ASC');
  return $q;
 }
 public function agenda(?string $scope, string $period, string $type, string $assigned, string $search, int $offset, int $limit, ?int $now=null): array {
  $now??=time();$zone=Prefs::all()['tz'];$ranges=self::dayRanges($now,$zone);
  if (!isset($ranges[$period]) || !in_array($type,['all','callback','appointment'],true)) throw new \InvalidArgumentException('Filtre d’agenda invalide.');
  $offset=max(0,min(10000,$offset));$limit=max(1,min(30,$limit));$counts=['today'=>0,'tomorrow'=>0,'late'=>0];$rows=[];
  foreach (['callback','appointment'] as $kind) {
   if ($type!=='all' && $kind!==$type) continue;
   foreach ($ranges as $key=>$range) $counts[$key]+=(int)$this->agendaQuery($kind,$scope,$assigned,$search,$range,true)->executeQuery()->fetchOne();
   $q=$this->agendaQuery($kind,$scope,$assigned,$search,$ranges[$period]);$q->setMaxResults($offset+$limit+1);
   foreach ($q->executeQuery()->fetchAll() as $r) {
    $at=(int)($kind==='callback'?$r['callback_at']:$r['starts_at']);
    $rows[]=['id'=>$kind.':'.($r['appointment_id']??$r['id']), 'prospect_id'=>(int)$r['id'], 'type'=>$kind,'starts_at'=>$at,'late'=>$at<$now,
     'client_name'=>trim($r['lastname'].' '.($r['firstname']??'')), 'phone'=>$r['phone']??'', 'wilaya'=>$r['c5']??'', 'owner'=>$this->userName($r['assigned_to']), 'status'=>$r['status'],
     'title'=>$kind==='callback'?'Rappel client':$r['title'],'notes'=>$kind==='callback'?($r['last_note']??''):($r['notes']??''), 'appointment_id'=>(int)($r['appointment_id']??0),'revision'=>(int)($r['revision']??0)];
   }
  }
  usort($rows,fn($a,$b)=>[$a['starts_at'],$a['id']]<=>[$b['starts_at'],$b['id']]);
  return ['rows'=>array_slice($rows,$offset,$limit),'counts'=>$counts,'has_more'=>count($rows)>$offset+$limit,'next_offset'=>$offset+$limit,'timezone'=>$zone,'server_now'=>$now];
 }
 public function appointments(int $pid): array {
  $q=$this->db->getQueryBuilder();$q->select('*')->from('callcrm_appointments')->where($q->expr()->eq('prospect_id',$q->createNamedParameter($pid)))
   ->andWhere($q->expr()->eq('state',$q->createNamedParameter('open')))->orderBy('starts_at','ASC')->setMaxResults(100);
  return $q->executeQuery()->fetchAll();
 }
 private function client(int $id, ?string $scope): array {
  $q=$this->db->getQueryBuilder();$q->select('*')->from('callcrm_prospects')->where($q->expr()->eq('id',$q->createNamedParameter($id)));
  if ($scope!==null) $q->andWhere($q->expr()->eq('assigned_to',$q->createNamedParameter($scope)));
  else TeamScope::prospects($q);
  $p=$q->executeQuery()->fetch();if (!$p) throw new \OutOfBoundsException('Dossier introuvable.');return $p;
 }
 public function saveAppointment(int $pid, ?string $scope, string $actor, array $in): array {
  $id=(int)($in['id']??0);$state=$in['state']??'open';
  if (!is_string($state) || !in_array($state,['open','done','cancelled'],true)) throw new \InvalidArgumentException('État du rendez-vous invalide.');
  $zone=Prefs::all()['tz'];$title=$in['title']??'Rendez-vous client';$notes=$in['notes']??'';
  if (!is_string($title) || trim($title)==='' || mb_strlen($title)>120 || !is_string($notes) || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Titre ou commentaire de rendez-vous invalide.');
  if (!is_string($in['date']??null)) throw new \InvalidArgumentException('Renseignez la date du rendez-vous.');
  $at=self::parseDate($in['date'],$zone);
  if ($state==='open' && $at<time()-60) throw new \InvalidArgumentException('Choisissez un rendez-vous à venir.');
  $this->db->beginTransaction();
  try {
   $this->client($pid,$scope);$q=$this->db->getQueryBuilder();
   $values=['starts_at'=>$at,'title'=>trim($title),'notes'=>$notes,'state'=>$state,'updated_at'=>time()];
   if ($id>0) {
    $q->update('callcrm_appointments');foreach ($values as $key=>$v) $q->set($key,$q->createNamedParameter($v));
    $revision=(int)($in['revision']??0);$q->set('revision',$q->createNamedParameter($revision+1));
    $q->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('prospect_id',$q->createNamedParameter($pid)))->andWhere($q->expr()->eq('revision',$q->createNamedParameter($revision)));
    if ($q->executeStatement()!==1) throw new \DomainException('Ce rendez-vous a changé. Rechargez la fiche.');
   } else {
    if ($state!=='open') throw new \InvalidArgumentException('Un nouveau rendez-vous doit être ouvert.');
    $values+=['prospect_id'=>$pid,'created_by'=>$actor,'revision'=>1];$q->insert('callcrm_appointments');foreach ($values as $key=>$v) $q->setValue($key,$q->createNamedParameter($v));$q->executeStatement();$id=(int)$q->getLastInsertId();
   }
   $this->event($pid,$actor,'appointment',['id'=>$id,'title'=>trim($title),'starts_at'=>$at,'state'=>$state,'notes'=>$notes]);
   $appointments=$this->appointments($pid);$this->db->commit();return ['id'=>$id,'appointments'=>$appointments];
  } catch (\Throwable $e) { $this->db->rollBack();throw $e; }
 }
 public function event(int $pid, string $actor, string $kind, array $body): void {
  $q=$this->db->getQueryBuilder();$q->insert('callcrm_client_events');foreach (['prospect_id'=>$pid,'user_id'=>$actor,'kind'=>$kind,'body'=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>time()] as $key=>$v) $q->setValue($key,$q->createNamedParameter($v));$q->executeStatement();
 }
 /** Stable cursor across the three sources, including equal-second events. */
 public function timeline(int $pid, string $filter, string $cursor='', int $limit=30): array {
  if (!in_array($filter,['all','call','note','sim','assign','appointment','sms'],true)) throw new \InvalidArgumentException('Filtre de chronologie invalide.');
  $limit=max(1,min(100,$limit));$boundary=null;
  if ($cursor!=='') { $boundary=json_decode(base64_decode($cursor,true) ?: '',true);if (!is_array($boundary) || count($boundary)!==3 || !is_int($boundary[0]) || !is_int($boundary[1]) || !is_int($boundary[2])) throw new \InvalidArgumentException('Page de chronologie invalide.'); }
  $events=[];
  foreach ([['callcrm_calls',3],['callcrm_client_events',2],['callcrm_audit',1]] as [$table,$origin]) {
   if ($origin===3 && !in_array($filter,['all','call'],true)) continue;
   if ($origin===1 && !in_array($filter,['all','sim'],true)) continue;
   $q=$this->db->getQueryBuilder();$q->select('*')->from($table)->where($q->expr()->eq('prospect_id',$q->createNamedParameter($pid)));
   if ($origin===1) $q->andWhere($q->expr()->eq('action',$q->createNamedParameter('sim')));
   if ($origin===2 && $filter!=='all') $q->andWhere($q->expr()->eq('kind',$q->createNamedParameter($filter)));
   if ($boundary) {
    [$at,$source,$id]=$boundary;$older=$q->expr()->lt('created_at',$q->createNamedParameter($at));
    if ($origin<=$source) {
     $same=$q->expr()->eq('created_at',$q->createNamedParameter($at));
     if ($origin===$source) $same=$q->expr()->andX($same,$q->expr()->lt('id',$q->createNamedParameter($id)));
     $older=$q->expr()->orX($older,$same);
    }
    $q->andWhere($older);
   }
   $q->orderBy('created_at','DESC')->addOrderBy('id','DESC')->setMaxResults($limit+1);
   foreach ($q->executeQuery()->fetchAll() as $r) {
    $body=$origin===3?['result'=>$r['result'],'reason'=>$r['reason']??'','note'=>$r['note']??'','callback_at'=>(int)($r['callback_at']??0)]:json_decode($r[$origin===2?'body':'detail']??'',true);
    if (!is_array($body)) $body=[];
    if ($origin===2 && ($r['kind']??'')==='assign') { $body=['from'=>$this->userName($body['from']??null),'to'=>$this->userName($body['to']??null)]; }
    $events[]=['id'=>$origin.':'.$r['id'],'kind'=>$origin===3?'call':($origin===1?'sim':$r['kind']),'created_at'=>(int)$r['created_at'],'author'=>$this->userName($r['user_id']??null),'body'=>$body,'sort'=>[(int)$r['created_at'],$origin,(int)$r['id']]];
   }
  }
  usort($events,fn($a,$b)=>$b['sort']<=>$a['sort']);$more=count($events)>$limit;$events=array_slice($events,0,$limit);$last=$events?end($events)['sort']:null;
  $this->attachRecordings($pid,$events);
  foreach ($events as &$event) unset($event['sort']);unset($event);
  return ['rows'=>$events,'has_more'=>$more,'cursor'=>$more&&$last?base64_encode(json_encode($last)):''];
 }
 /** Associe à chaque résultat d'appel l'audio Yeastar le plus proche (2 h avant à 10 min après la saisie), chacun une seule fois. */
 private function attachRecordings(int $pid,array &$events): void {
  $calls=array_filter(array_keys($events),fn($i)=>$events[$i]['kind']==='call');
  if (!$calls) return;
  $q=$this->db->getQueryBuilder();
  $recs=$q->select('id','created_at','talk_duration','direction')->from('callcrm_pbx_recordings')->where($q->expr()->eq('prospect_id',$q->createNamedParameter($pid)))
   ->andWhere($q->expr()->eq('state',$q->createNamedParameter('ready')))->orderBy('created_at','DESC')->setMaxResults(200)->executeQuery()->fetchAll();
  $used=[];
  foreach ($calls as $i) {
   $t=$events[$i]['created_at'];$best=null;
   foreach ($recs as $r) { $d=$t-(int)$r['created_at']; if (isset($used[$r['id']]) || $d<-600 || $d>7200) continue; if ($best===null || abs($d)<abs($t-(int)$best['created_at'])) $best=$r; }
   if ($best) { $used[$best['id']]=true; $events[$i]['recording']=['id'=>(int)$best['id'],'talk'=>(int)$best['talk_duration'],'direction'=>$best['direction']]; }
  }
 }
 /** Search all visible client fields, with an optional commercial-name match. */
 public function globalSearch(?string $scope, string $search, int $offset=0): array {
  $search=trim($search);if(mb_strlen($search)<2) return ['rows'=>[],'has_more'=>false,'next_offset'=>0];
  $search=mb_substr($search,0,120);$q=$this->db->getQueryBuilder();$q->select('p.id','p.lastname','p.firstname','p.phone','p.assigned_to','p.status','p.remarque')->from('callcrm_prospects','p');
  $term='%'.$this->db->escapeLikeParameter($search).'%';$or=$q->expr()->orX();
  foreach(['lastname','firstname','phone','remarque','last_note','email'] as $col)$or->add($q->expr()->iLike('p.'.$col,$q->createNamedParameter($term)));
  if($scope===null){$uids=[];foreach($this->users->search($search,30,0) as $u)$uids[]=$u->getUID();if($uids)$or->add($q->expr()->in('p.assigned_to',$q->createNamedParameter($uids,IQueryBuilder::PARAM_STR_ARRAY)));}
  $q->where($or);$this->scoped($q,$scope);$offset=max(0,min(10000,$offset));$q->orderBy('p.id','DESC')->setMaxResults(21)->setFirstResult($offset);$rows=$q->executeQuery()->fetchAll();
  foreach($rows as &$r){$r['owner']=$this->userName($r['assigned_to']);unset($r['remarque']);}unset($r);
  return ['rows'=>array_slice($rows,0,20),'has_more'=>count($rows)>20,'next_offset'=>$offset+20];
 }
 /**
  * Prospects à relancer, avec le dernier commercial qui a appelé et le commercial actuel.
  * $f : assigned (uid), uids (liste d'uids : groupe de commerciaux), product (catégorie c2), changed ('yes'|'no'),
  *      q (nom/téléphone), sort (name|status|product|last_call|days|owner|caller|changed|created), dir (asc|desc).
  * Le tri et le filtre « changement de commercial » portent sur toute la liste (5 000 dossiers au plus), pas sur la page.
  */
 public const NEGLECTED_SORTS=['name','status','product','source','last_call','days','owner','caller','changed','created'];
 public const SOURCES=['formulaire','appel','import','manuel','whatsapp'];
 /** Origine d'une fiche ; celles du formulaire public créées depuis la dernière mise à jour sont reconnues à leur trace. */
 public static function sourceOf(array $r): string { $s=(string)($r['source']??''); if($s!=='')return $s; return ($r['c1']??'')==='Facebook /prospects'?'formulaire':''; }
 public function neglected(?string $scope, string $type='idle', int $days=7, int $offset=0, array $f=[]): array {
  if(!in_array($type,['idle','callback','estimate'],true))throw new \InvalidArgumentException('Type de suivi invalide.');
  $sort=(string)($f['sort']??'');if($sort!==''&&!in_array($sort,self::NEGLECTED_SORTS,true))throw new \InvalidArgumentException('Tri invalide.');
  $dir=strtolower((string)($f['dir']??''))==='desc'?-1:1;$changed=(string)($f['changed']??'');
  if(!in_array($changed,['','yes','no'],true))throw new \InvalidArgumentException('Filtre de changement invalide.');
  $source=(string)($f['source']??'');if($source!==''&&!in_array($source,self::SOURCES,true))throw new \InvalidArgumentException('Source invalide.');
  $days=max(1,min(365,$days));$now=time();$cutoff=$now-$days*86400;$q=$this->db->getQueryBuilder();
  $cols=['p.id','p.lastname','p.firstname','p.phone','p.assigned_to','p.status','p.last_call_at','p.created_at','p.callback_at','p.c2','p.c5','p.c1','p.source'];
  $q->select(...$cols)->from('callcrm_prospects','p');
  if($type==='callback')$q->where($q->expr()->lt('p.callback_at',$q->createNamedParameter($now)))->andWhere($q->expr()->in('p.status',$q->createNamedParameter(Statuses::callbackKeys()?:['-'],IQueryBuilder::PARAM_STR_ARRAY)));
  elseif($type==='estimate'){
   $q->innerJoin('p','callcrm_audit','a',$q->expr()->eq('a.prospect_id','p.id'))->where($q->expr()->eq('a.action',$q->createNamedParameter('sim')))
    ->andWhere($q->expr()->orX($q->expr()->isNull('p.last_call_at'),$q->expr()->lt('p.last_call_at','a.created_at')));
   $q->groupBy(...$cols)->having('MAX(a.created_at) < '.$q->createNamedParameter($cutoff,IQueryBuilder::PARAM_INT));
  }else $q->where($q->expr()->lt('p.created_at',$q->createNamedParameter($cutoff)))->andWhere($q->expr()->orX($q->expr()->isNull('p.last_call_at'),$q->expr()->lt('p.last_call_at',$q->createNamedParameter($cutoff))));
  if($type!=='callback')$q->andWhere($q->expr()->in('p.status',$q->createNamedParameter(array_unique(array_merge(['nouveau','interesse'],Statuses::callbackKeys())),IQueryBuilder::PARAM_STR_ARRAY)));
  $this->scoped($q,$scope,(string)($f['assigned']??''),(string)($f['q']??''));
  $uids=array_values(array_filter(array_map('strval',(array)($f['uids']??[])),fn($u)=>$u!==''));
  if($uids)$q->andWhere($q->expr()->in('p.assigned_to',$q->createNamedParameter(array_slice($uids,0,200),IQueryBuilder::PARAM_STR_ARRAY)));
  if(($f['product']??'')!=='')$q->andWhere($q->expr()->eq('p.c2',$q->createNamedParameter(mb_substr((string)$f['product'],0,120))));
  $q->orderBy('p.created_at','ASC')->addOrderBy('p.id','ASC')->setMaxResults(5001);
  $rows=$q->executeQuery()->fetchAll();$truncated=count($rows)>5000;$rows=array_slice($rows,0,5000);
  $last=$this->lastCallers(array_map(fn($r)=>(int)$r['id'],$rows));$names=[];
  $name=function(?string $uid)use(&$names){return $names[$uid??'']??=$this->userName($uid);};
  foreach($rows as &$r){
   $c=$last[(int)$r['id']]??null;$r['owner']=$name($r['assigned_to']);
   $r['last_caller']=$c['user_id']??null;$r['last_caller_name']=$c?$name($c['user_id']):'';$r['last_result']=$c['result']??'';
   $r['changed']=$c!==null&&(string)$c['user_id']!==(string)($r['assigned_to']??'');
   $r['source']=self::sourceOf($r);unset($r['c1']);
   $ref=(int)($r['last_call_at']?:$r['created_at']);$r['days_idle']=$ref?intdiv(max(0,$now-$ref),86400):null;
  }unset($r);
  // « Même commercial » suppose un appel : un client jamais appelé n'est ni « changé » ni « inchangé ».
  if($source!=='')$rows=array_values(array_filter($rows,fn($r)=>$r['source']===$source));
  if($changed!=='')$rows=array_values(array_filter($rows,fn($r)=>$changed==='yes'?$r['changed']:($r['last_caller']!==null&&!$r['changed'])));
  if($sort!==''){
   $key=fn($r)=>match($sort){'name'=>mb_strtolower(trim($r['lastname'].' '.$r['firstname'])),'status'=>(string)$r['status'],'product'=>mb_strtolower((string)$r['c2']),'source'=>(string)$r['source'],
    'last_call'=>(int)$r['last_call_at'],'days'=>(int)($r['days_idle']??-1),'owner'=>mb_strtolower($r['owner']),'caller'=>mb_strtolower($r['last_caller_name']),
    'changed'=>$r['changed']?1:0,'created'=>(int)$r['created_at']};
   usort($rows,fn($a,$b)=>($dir*($key($a)<=>$key($b)))?:((int)$a['id']<=>(int)$b['id']));
  }
  $offset=max(0,min(10000,$offset));$page=array_slice($rows,$offset,50);
  return ['rows'=>$page,'total'=>count($rows),'truncated'=>$truncated,'has_more'=>count($rows)>$offset+50,'next_offset'=>$offset+50,'days'=>$days];
 }
 /** Dernier appel saisi par prospect : [id => ['user_id','result','created_at']]. */
 private function lastCallers(array $ids): array {
  $out=[];
  foreach(array_chunk(array_values(array_unique($ids)),500) as $chunk){
   $q=$this->db->getQueryBuilder();
   $q->select('prospect_id','user_id','result','created_at','id')->from('callcrm_calls')->where($q->expr()->in('prospect_id',$q->createNamedParameter($chunk,IQueryBuilder::PARAM_INT_ARRAY)))
     ->orderBy('created_at','DESC')->addOrderBy('id','DESC');
   foreach($q->executeQuery()->fetchAll() as $c)$out[(int)$c['prospect_id']]??=$c;
  }
  return $out;
 }
}
