<?php
namespace OCA\CallCrm\Service;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Un seul compteur par compte, même avec plusieurs onglets ou appareils. */
class Presence {
 public const TIMEOUT = 120;
 public function __construct(private IConfig $config, private IDBConnection $db) {}
 private function current(string $uid): ?array {
  $q = $this->db->getQueryBuilder();
  $r = $q->select('*')->from('callcrm_presence')->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))->executeQuery()->fetch();
  return $r ?: null;
 }
 private function localDay(int $now): string {
  $tz = (string)$this->config->getAppValue('callcrm','tz','Africa/Algiers');
  try { $zone = new \DateTimeZone($tz); } catch (\Throwable $e) { $zone = new \DateTimeZone('Africa/Algiers'); }
  return (new \DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
 }
 private function historicalLunchDay(string $uid, int $now): string {
  $day = $this->localDay($now);
  $tz = (string)$this->config->getAppValue('callcrm','tz','Africa/Algiers');
  try { $zone = new \DateTimeZone($tz); } catch (\Throwable $e) { $zone = new \DateTimeZone('Africa/Algiers'); }
  $from = (new \DateTimeImmutable($day,$zone))->getTimestamp();
  $q = $this->db->getQueryBuilder();
  $found = $q->select('started_at')->from('callcrm_presence_log')
   ->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))
   ->andWhere($q->expr()->eq('pause_kind',$q->createNamedParameter('lunch')))
   ->andWhere($q->expr()->gte('started_at',$q->createNamedParameter($from)))
   ->andWhere($q->expr()->lt('started_at',$q->createNamedParameter($now+1)))->setMaxResults(1)->executeQuery()->fetch();
  return $found ? $day : '';
 }
 public function heartbeat(string $uid): array { return $this->touch($uid, null, time()); }
 public function pause(string $uid, bool $paused, string $kind = 'pause'): array { return $this->touch($uid, $paused, time(), $kind); }

 /** Compare-and-swap transactionnel : une seule transition peut gagner par révision. */
 public function touch(string $uid, ?bool $paused, int $now, ?string $kind = null): array {
  if ($kind !== null && !in_array($kind,['pause','lunch'],true)) { throw new \InvalidArgumentException('Type de pause invalide.'); }
  for ($attempt = 0; $attempt < 3; $attempt++) {
   $this->db->beginTransaction();
   try {
    $old = $this->current($uid);
    if (!$old) {
     $p = $paused ?? ($this->config->getUserValue($uid, 'callcrm', 'presence_pause', '0') === '1');
     $q = $this->db->getQueryBuilder(); $q->insert('callcrm_presence');
     foreach (['user_id'=>$uid,'paused'=>(int)$p,'pause_kind'=>$p ? ($kind ?? 'pause') : 'pause','pause_started_at'=>$p ? $now : 0,'lunch_day'=>$p && $kind === 'lunch' ? $this->localDay($now) : '', 'last_seen'=>$now,'segment_start'=>$now,'revision'=>1] as $k=>$v) { $q->setValue($k,$q->createNamedParameter($v)); }
     $q->executeStatement();
    } else {
     $now = max($now, (int)$old['last_seen']);
     $p = $paused ?? (bool)$old['paused'];
     $expired = $now >= (int)$old['last_seen'] + self::TIMEOUT;
     $nextKind = $p ? ($kind ?? ($old['pause_kind'] ?? 'pause')) : 'pause';
     $changed = $p !== (bool)$old['paused'] || ($p && $nextKind !== ($old['pause_kind'] ?? 'pause'));
     $lunchDay = $old['lunch_day'] ?? '';
     if ($lunchDay === '') { $lunchDay = $this->historicalLunchDay($uid,$now); }
     $day = $this->localDay($now);
     $startingLunch = $p && $nextKind === 'lunch' && (!(bool)$old['paused'] || ($old['pause_kind'] ?? 'pause') !== 'lunch');
     if ($startingLunch && $lunchDay === $day) { throw new \DomainException('La pause déjeuner a déjà été utilisée aujourd’hui. Elle est autorisée une seule fois par jour.'); }
     if ($startingLunch) { $lunchDay = $day; }
     // Recover an ongoing lunch begun before this additive migration.
     if ($p && $nextKind === 'lunch' && $lunchDay === '') { $lunchDay = $this->localDay((int)$old['segment_start']); }
     $pauseStart = $p ? ($changed ? $now : ((int)($old['pause_started_at'] ?? 0) ?: (int)$old['segment_start'])) : 0;
     $start = ($expired || $changed) ? $now : (int)$old['segment_start'];
     $q = $this->db->getQueryBuilder();
     $affected = $q->update('callcrm_presence')->set('paused',$q->createNamedParameter((int)$p))
      ->set('lunch_day',$q->createNamedParameter($lunchDay))->set('pause_started_at',$q->createNamedParameter($pauseStart))->set('pause_kind',$q->createNamedParameter($nextKind))->set('last_seen',$q->createNamedParameter($now))->set('segment_start',$q->createNamedParameter($start))
      ->set('revision',$q->createNamedParameter((int)$old['revision']+1))
      ->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))
      ->andWhere($q->expr()->eq('revision',$q->createNamedParameter((int)$old['revision'])))->executeStatement();
     if ($affected !== 1) { throw new \RuntimeException('Concurrent presence update'); }
     if ($expired || $changed) {
      $end = min($now, (int)$old['last_seen'] + self::TIMEOUT);
      if ($end > (int)$old['segment_start']) {
       $q = $this->db->getQueryBuilder(); $q->insert('callcrm_presence_log');
       foreach (['user_id'=>$uid,'paused'=>(int)$old['paused'],'pause_kind'=>$old['pause_kind'] ?? 'pause','started_at'=>(int)$old['segment_start'],'ended_at'=>$end] as $k=>$v) { $q->setValue($k,$q->createNamedParameter($v)); }
       $q->executeStatement();
      }
     }
    }
    $result = $this->state($uid, $now);
    $this->db->commit(); return $result;
   } catch (\Throwable $e) {
    $this->db->rollBack();
    if ($e instanceof \DomainException || $attempt === 2) { throw $e; }
   }
  }
  throw new \RuntimeException('Presence update failed');
 }
 private function describe(?array $r, int $now): array {
  $seen = (int)($r['last_seen'] ?? 0); $p = (bool)($r['paused'] ?? false);
  $online = $r !== null && $now - $seen < self::TIMEOUT;
  $kind = $r['pause_kind'] ?? 'pause';
  $since = $p ? ((int)($r['pause_started_at'] ?? 0) ?: (int)$r['segment_start']) : null;
  $usedDay = $r['lunch_day'] ?? '';
  if ($usedDay === '' && $p && $kind === 'lunch') { $usedDay = $this->localDay($since); }
  $used = $usedDay === $this->localDay($now);
  $elapsed = $since !== null ? max(0,$now-$since) : 0;
  $alert = 'normal';
  if ($online && $p) {
   if ($elapsed > ($kind === 'lunch' ? 4200 : 600)) { $alert = 'overdue'; }
   elseif ($kind === 'lunch' && $elapsed > 3600) { $alert = 'tolerance'; }
  }
  return ['status'=>!$online ? 'absent' : ($p ? 'pause' : 'online'), 'paused'=>$p, 'pause_kind'=>$kind, 'last_seen'=>$seen,
   'pause_since'=>$online && $p ? $since : null, 'server_now'=>$now, 'pause_alert'=>$alert,
   'lunch_used_today'=>$used, 'lunch_available'=>!$used && !$p];
 }
 public function state(string $uid, ?int $now = null): array { return $this->describe($this->current($uid), $now ?? time()); }

 public function report(array $uids, ?int $now = null, array $filter = []): array {
  $now ??= time();
  $tz = (string)$this->config->getAppValue('callcrm','tz','Africa/Algiers');
  try { $zone = new \DateTimeZone($tz); } catch (\Throwable $e) { $tz = 'Africa/Algiers'; $zone = new \DateTimeZone($tz); }
  $date = (new \DateTimeImmutable('@'.$now))->setTimezone($zone);
  $day = $date->setTime(0,0);
  $starts = ['day'=>$day->getTimestamp(), 'week'=>$day->modify('-'.((int)$day->format('N')-1).' days')->getTimestamp(), 'month'=>$day->modify('first day of this month')->getTimestamp()];
  $range = PresenceRange::resolve($filter,$zone,$now);
  $starts['selected'] = $range['start'];
  $rows = []; $periods = [];
  foreach ($starts as $key=>$from) { $periods[$key] = ['from'=>$from,'to'=>$key === 'selected' ? min($now,$range['end']) : $now]; }
  if (!$uids) { return ['rows'=>[], 'periods'=>$periods, 'timezone'=>$tz, 'server_now'=>$now, 'range'=>$range]; }
  foreach ($uids as $uid) {
   $rows[$uid] = $this->describe(null,$now) + ['totals'=>array_fill_keys(array_keys($starts), ['online'=>0,'pause'=>0,'lunch'=>0])];
  }
  $q = $this->db->getQueryBuilder();
  $live = $q->select('*')->from('callcrm_presence')->where($q->expr()->in('user_id',$q->createNamedParameter($uids,IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetchAll();
  $q = $this->db->getQueryBuilder();
  $segments = $q->select('user_id','paused','pause_kind','started_at','ended_at')->from('callcrm_presence_log')
   ->where($q->expr()->in('user_id',$q->createNamedParameter($uids,IQueryBuilder::PARAM_STR_ARRAY)))
   ->andWhere($q->expr()->gt('ended_at',$q->createNamedParameter(min($starts))))
   ->andWhere($q->expr()->lt('started_at',$q->createNamedParameter($now)))->executeQuery()->fetchAll();
  foreach ($live as $r) {
   $uid = $r['user_id']; $rows[$uid] = $this->describe($r,$now) + ['totals'=>$rows[$uid]['totals']];
   $segments[] = ['user_id'=>$uid,'paused'=>$r['paused'],'pause_kind'=>$r['pause_kind'],'started_at'=>$r['segment_start'],'ended_at'=>min($now,(int)$r['last_seen']+self::TIMEOUT)];
  }
  foreach ($segments as $s) {
   $uid = $s['user_id']; $mode = (bool)$s['paused'] ? (($s['pause_kind'] ?? 'pause') === 'lunch' ? 'lunch' : 'pause') : 'online';
   foreach ($starts as $key=>$from) { $rows[$uid]['totals'][$key][$mode] += max(0,min($periods[$key]['to'],(int)$s['ended_at'])-max($from,(int)$s['started_at'])); }
  }
  foreach ($rows as &$r) { $r['processed'] = 0; $r['by_result'] = []; } unset($r);
  $q = $this->db->getQueryBuilder();
  $cases = $q->select('user_id','prospect_id','result')->from('callcrm_calls')
   ->where($q->expr()->in('user_id',$q->createNamedParameter($uids,IQueryBuilder::PARAM_STR_ARRAY)))
   ->andWhere($q->expr()->gte('created_at',$q->createNamedParameter($range['start'])))
   ->andWhere($q->expr()->lt('created_at',$q->createNamedParameter(min($now+1,$range['end']))))
   ->orderBy('created_at','ASC')->addOrderBy('id','ASC')->executeQuery()->fetchAll();
  $latest = [];
  foreach ($cases as $case) { $latest[$case['user_id']][(string)$case['prospect_id']] = $case['result']; }
  foreach ($latest as $uid=>$results) {
   $rows[$uid]['processed'] = count($results);
   foreach ($results as $result) { $rows[$uid]['by_result'][$result] = ($rows[$uid]['by_result'][$result] ?? 0) + 1; }
  }
  return ['rows'=>$rows,'periods'=>$periods,'timezone'=>$tz,'server_now'=>$now,'range'=>$range];
 }
}
