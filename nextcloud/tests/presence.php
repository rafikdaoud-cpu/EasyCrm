<?php
namespace OCP { interface IConfig {} }
namespace {
 require __DIR__ . '/announcements.php';
 require __DIR__ . '/../lib/Service/PresenceRange.php';
 require __DIR__ . '/../lib/Service/Presence.php';
 require __DIR__ . '/../lib/Controller/PresenceController.php';
 class PresenceConfig implements \OCP\IConfig {
  public array $values = [];
  public function getAppValue($app, $key, $default) { return $default; }
  public function getUserValue($uid, $app, $key, $default) { return $this->values[$uid][$key] ?? $default; }
  public function setUserValue($uid, $app, $key, $value) { $this->values[$uid][$key] = $value; }
 }
 $db->pdo->exec('CREATE TABLE easycrm_calls (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL, prospect_id INTEGER NOT NULL, created_at INTEGER NOT NULL, result TEXT NOT NULL DEFAULT \'interesse\')');
 $config = new PresenceConfig();
 $db->pdo->exec('CREATE TABLE easycrm_presence (user_id TEXT PRIMARY KEY, paused INTEGER NOT NULL, pause_kind TEXT NOT NULL DEFAULT \'pause\', pause_started_at INTEGER NOT NULL DEFAULT 0, lunch_day TEXT NOT NULL DEFAULT \'\', segment_start INTEGER NOT NULL, last_seen INTEGER NOT NULL, revision INTEGER NOT NULL)');
 $db->pdo->exec('CREATE TABLE easycrm_presence_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL, paused INTEGER NOT NULL, pause_kind TEXT NOT NULL DEFAULT \'pause\', started_at INTEGER NOT NULL, ended_at INTEGER NOT NULL)');
 $presence = new \OCA\EasyCrm\Service\Presence($config, $db);
 check($presence->state('alice')['status'] === 'absent', 'never-connected commercial is absent');
 $state = $presence->heartbeat('alice');
 check($state['status'] === 'online' && !$state['paused'], 'heartbeat confirms connection');
 $seen = $state['last_seen'];
 check($presence->state('alice', $seen + 119)['status'] === 'online', 'connection survives short gap');
 check($presence->state('alice', $seen + 120)['status'] === 'absent', 'connection expires after two minutes');
 check($presence->pause('alice', true)['status'] === 'pause', 'commercial can pause');
 check($presence->heartbeat('alice')['status'] === 'pause', 'heartbeat from another tab preserves pause');
 check($presence->state('bob')['status'] === 'absent', 'pause does not affect another account');
 check($presence->state('alice', time() + 121)['status'] === 'absent', 'expired paused commercial becomes absent');
 check($presence->heartbeat('alice')['status'] === 'pause', 'pause survives reconnect until resumed');
 check($presence->pause('alice', false)['status'] === 'online', 'resume restores connected state');
 $asPresence = function ($uid) use ($request, $session, $groups, $presence) {
  $session->uid = $uid;
  return new \OCA\EasyCrm\Controller\PresenceController($request, new \OCA\EasyCrm\Service\Access($session, $groups), $presence, $groups);
 };
 foreach (['admin','supervisor','reader','outsider'] as $uid) {
  check($asPresence($uid)->heartbeat()->status === 403 && $asPresence($uid)->pause()->status === 403, "$uid cannot change commercial presence");
 }
 foreach (['alice','reader','outsider'] as $uid) { check($asPresence($uid)->roster()->status === 403, "$uid cannot monitor roster"); }
 check(count($asPresence('admin')->roster()->data['rows']) === 2, 'admin sees commercial roster');
 check(count($asPresence('supervisor')->roster()->data['rows']) === 2, 'supervisor sees commercial roster');
 foreach (['true', 1, null, []] as $invalid) {
  $request->params = ['paused' => $invalid];
  check($asPresence('alice')->pause()->status === 400, 'non-boolean pause rejected');
 }
 $request->params = ['paused' => true, 'uid'=>'bob'];
 check($asPresence('alice')->pause()->data['status'] === 'pause', 'pause endpoint changes authenticated account');
 check($presence->state('bob')['status'] === 'absent', 'supplied uid cannot change another account');
 $request->params = ['paused' => false];
 check($asPresence('alice')->pause()->data['status'] === 'online', 'resume endpoint works');
 foreach (['heartbeat','pause'] as $method) {
  $r = new \ReflectionMethod(\OCA\EasyCrm\Controller\PresenceController::class, $method);
  check(!$r->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired'), "$method retains CSRF protection");
 }
 // Deterministic timeline spanning midnight, week/month boundaries and an absence.
 $stamp = fn($s) => (new \DateTimeImmutable($s, new \DateTimeZone('Africa/Algiers')))->getTimestamp();
 $t = $stamp('2026-11-01 23:59:00'); // Sunday, last minute before Monday.
 $presence->touch('timeline', false, $t);
 $presence->touch('timeline', null, $t+60);
 $presence->touch('timeline', true, $t+90);
 $presence->touch('timeline', null, $t+120);
 $r = $presence->report(['timeline'], $t+150)['rows']['timeline'];
 check($r['totals']['day'] === ['online'=>30,'pause'=>60,'lunch'=>0], 'day totals split work and pause at midnight');
 check($r['totals']['week'] === ['online'=>30,'pause'=>60,'lunch'=>0], 'new Monday week excludes Sunday work');
 check($r['totals']['month'] === ['online'=>90,'pause'=>60,'lunch'=>0], 'month includes Sunday work');
 $r = $presence->report(['timeline'], $t+1000)['rows']['timeline'];
 check($r['status'] === 'absent' && $r['totals']['day']['pause'] === 150, 'absence caps accumulated pause at last signal plus timeout');
 $presence->touch('timeline', null, $t+1000);
 $presence->touch('timeline', null, $t+1030); // Another tab must not double count.
 $r = $presence->report(['timeline'], $t+1060)['rows']['timeline'];
 check($r['pause_since'] === $t+90 && $r['totals']['day']['pause'] === 210, 'reconnect preserves pause deadline while accounting excludes the absence');
 $presence->touch('timeline', false, $t+1060);
 $r = $presence->report(['timeline'], $t+1090)['rows']['timeline'];
 check($r['totals']['day'] === ['online'=>60,'pause'=>210,'lunch'=>0], 'resume adds only elapsed work time');
 $before = $db->pdo->query('SELECT count(*) FROM easycrm_presence_log')->fetchColumn();
 $presence->touch('timeline', false, $t+1090);
 check($db->pdo->query('SELECT count(*) FROM easycrm_presence_log')->fetchColumn() === $before, 'repeated resume does not split or duplicate interval');
 $r = $presence->report(['never'], $t+1090)['rows']['never'];
 check($r['totals']['month'] === ['online'=>0,'pause'=>0,'lunch'=>0], 'no history is shown as zero, not invented');
 $old = $stamp('2026-10-31 23:59:30');
 $presence->touch('boundary', false, $old); $presence->touch('boundary', null, $old+60);
 $r = $presence->report(['boundary'], $old+90)['rows']['boundary'];
 check($r['totals']['month']['online'] === 60 && $r['totals']['week']['online'] === 90, 'month and week boundaries are clipped independently');
 $db->pdo->exec("CREATE TRIGGER fail_presence BEFORE INSERT ON easycrm_presence_log WHEN NEW.user_id = 'timeline' BEGIN SELECT RAISE(ABORT, 'failed log'); END");
 $oldState = $presence->state('timeline',$t+1100);
 try { $presence->touch('timeline',true,$t+1100); throw new \RuntimeException('Expected failed transition'); } catch (\PDOException $e) {}
 check($presence->state('timeline',$t+1100) === $oldState, 'failed transition rolls back both state and accounting');
 foreach (['admin','supervisor','reader','outsider'] as $uid) { check($asPresence($uid)->mine()->status === 403, "$uid cannot access commercial personal counters"); }
 $request->params = ['uid'=>'bob', 'user'=>'bob'];
 $mine = $asPresence('alice')->mine()->data;
 check(isset($mine['totals']['day']['online'], $mine['totals']['week']['pause'], $mine['totals']['month']['online']), 'commercial receives all three personal periods');
 check(!isset($mine['rows']), 'personal endpoint does not expose roster');
 $spy = new class($config,$db) extends \OCA\EasyCrm\Service\Presence {
  public array $queried = [];
  public function report(array $uids, ?int $now = null, array $filter = []): array { $this->queried = $uids; return parent::report($uids,$now,$filter); }
 };
 $session->uid = 'alice';
 $controller = new \OCA\EasyCrm\Controller\PresenceController($request, new \OCA\EasyCrm\Service\Access($session,$groups), $spy, $groups);
 $controller->mine();
 check($spy->queried === ['alice'], 'personal endpoint ignores supplied uid and scopes accounting to authenticated account');
 $insert = $db->pdo->prepare('INSERT INTO easycrm_calls (user_id,prospect_id,created_at,result) VALUES (?,?,?,?)');
 foreach ([
  ['timeline',10,'2026-11-01 23:59:45','interesse'], ['timeline',10,'2026-11-02 00:00:15','rappel'],
  ['timeline',11,'2026-11-02 00:00:20','pas_interesse'], ['timeline',11,'2026-11-02 00:00:30','interesse'],
  ['timeline',12,'2026-11-03 00:00:00','interesse'],
  ['timeline',13,'2026-11-02 00:00:35','rappel'], ['timeline',13,'2026-11-02 00:00:35','interesse'],
  ['timeline',14,'2026-11-02 12:00:00','pas_interesse'], ['timeline',14,'2026-11-02 11:00:00','rappel'],
  ['timeline',15,'2026-11-02 23:59:59','rappel'], ['timeline',16,'2026-11-02 00:00:00','interesse'],
  ['alice',99,'2026-11-02 00:00:10','interesse'],
 ] as [$uid,$id,$at,$result]) { $insert->execute([$uid,$id,$stamp($at),$result]); }
 $filter = ['period'=>'custom','from'=>'2026-11-02','to'=>'2026-11-02'];
 $report = $presence->report(['timeline'],$stamp('2026-11-03 12:00:00'),$filter);
 $r = $report['rows']['timeline'];
 check($r['processed'] === 6, 'processed counts distinct dossiers, includes both day edges and excludes next midnight');
 check($r['by_result']['rappel'] === 2 && $r['by_result']['interesse'] === 3 && $r['by_result']['pas_interesse'] === 1, 'result breakdown uses last chronological outcome, id breaks same-time ties');
 check(array_sum($r['by_result']) === $r['processed'], 'result counts add up to distinct dossier count');
 check($r['totals']['selected'] === ['online'=>180,'pause'=>210,'lunch'=>0], 'custom past-day accounting excludes neighboring days and absences');
 check($report['range']['end'] - $report['range']['start'] === 86400, 'Au date includes full day in CRM timezone');
 $oldDay = $presence->report(['timeline'],$stamp('2026-11-03 12:00:00'),['period'=>'custom','from'=>'2026-11-01','to'=>'2026-11-01'])['rows']['timeline'];
 check($oldDay['processed'] === 1 && $oldDay['by_result']['interesse'] === 1 && $oldDay['totals']['selected']['online'] === 60, 'different selected day changes counts, outcomes and time together');
 $future = $presence->report(['timeline'],$stamp('2026-11-03 12:00:00'),['period'=>'custom','from'=>'2026-12-01','to'=>'2026-12-02'])['rows']['timeline'];
 check($future['processed'] === 0 && $future['totals']['selected'] === ['online'=>0,'pause'=>0,'lunch'=>0], 'future dates do not invent work or calls');
 foreach (['day','week','month'] as $key) {
  $r = $presence->report(['timeline'],$t+1090,['period'=>$key])['rows']['timeline'];
  check($r['totals']['selected'] === $r['totals'][$key], "$key preset matches its calendar accounting");
 }
 foreach ([['period'=>'invalid'], ['period'=>'custom','from'=>'2026-02-30','to'=>'2026-03-01'], ['period'=>'custom','from'=>'2026-11-03','to'=>'2026-11-02'], ['period'=>'custom'], ['period'=>[]]] as $invalid) {
  $request->params = $invalid;
  check($asPresence('alice')->mine()->status === 400 && $asPresence('supervisor')->roster()->status === 400, 'both endpoints reject invalid or reversed dates');
 }
 $dst = \OCA\EasyCrm\Service\PresenceRange::resolve(['period'=>'custom','from'=>'2026-03-29','to'=>'2026-03-29'],new \DateTimeZone('Europe/Paris'),$stamp('2026-04-01 00:00:00'));
 check($dst['end'] - $dst['start'] === 23*3600, 'calendar end uses local next midnight across DST');
 $request->params = ['paused'=>true,'kind'=>'lunch'];
 $meal = $asPresence('alice')->pause()->data;
 check($meal['paused'] && $meal['pause_kind'] === 'lunch', 'commercial starts a lunch break');
 check($asPresence('alice')->heartbeat()->data['pause_kind'] === 'lunch', 'lunch label survives reload and heartbeat');
 $roster = $asPresence('supervisor')->roster()->data['rows'];
 check($roster[0]['pause_kind'] === 'lunch', 'supervisor receives lunch state');
 foreach (['invalid',[]] as $invalid) {
  $request->params = ['paused'=>true,'kind'=>$invalid];
  check($asPresence('alice')->pause()->status === 400, 'invalid pause kind is rejected');
 }
 $request->params = ['paused'=>false];
 check($asPresence('alice')->pause()->data['pause_kind'] === 'pause', 'resume clears lunch kind');
 $presence->touch('meal',true,$t,'lunch'); $presence->touch('meal',null,$t+30);
 $presence->touch('meal',false,$t+90);
 $mealTotals = $presence->report(['meal'],$t+120)['rows']['meal']['totals']['month'];
 check($mealTotals === ['online'=>30,'pause'=>0,'lunch'=>90], 'lunch time is separate from ordinary pause totals');
 check($db->pdo->query("SELECT pause_kind FROM easycrm_presence_log WHERE user_id = 'meal'")->fetchColumn() === 'lunch', 'lunch kind retained in interval history');
 $base = $stamp('2026-11-02 12:00:00');
 $presence->touch('shortpause',true,$base);
 check($presence->touch('shortpause',null,$base+600)['pause_alert'] === 'normal', 'ordinary pause exactly ten minutes stays within limit');
 check($presence->touch('shortpause',null,$base+601)['pause_alert'] === 'overdue', 'ordinary pause exceeding ten minutes is overdue');
 check($presence->touch('shortpause',null,$base+1000)['pause_since'] === $base, 'reconnect cannot reset pause deadline');
 $presence->touch('quota',true,$base,'lunch');
 check($presence->touch('quota',true,$base+30,'lunch')['pause_since'] === $base, 'duplicate lunch activation is idempotent, not cumulative');
 check($presence->touch('quota',null,$base+3600)['pause_alert'] === 'normal', 'lunch exactly one hour has no delay alert');
 check($presence->touch('quota',null,$base+3601)['pause_alert'] === 'tolerance', 'lunch enters tolerance after one hour');
 check($presence->touch('quota',null,$base+4200)['pause_alert'] === 'tolerance', 'ten minutes of lunch tolerance are accepted');
 check($presence->touch('quota',null,$base+4201)['pause_alert'] === 'overdue', 'lunch over one hour ten is overdue');
 $finished = $presence->touch('quota',false,$base+4300);
 check($finished['lunch_used_today'] && !$finished['lunch_available'], 'lunch button remains unavailable after resuming');
 try { $presence->touch('quota',true,$base+4400,'lunch'); throw new \RuntimeException('Expected daily lunch limit'); }
 catch (\DomainException $e) { check(true,'second lunch in same CRM civil day rejected by server'); }
 $tomorrow = $presence->touch('quota',true,$stamp('2026-11-03 08:00:00'),'lunch');
 check($tomorrow['pause_kind'] === 'lunch' && $tomorrow['lunch_used_today'], 'new civil day allows one new lunch without carryover');
 $presence->touch('legacymeal',true,$base,'lunch'); $presence->touch('legacymeal',false,$base+60);
 $db->pdo->exec("UPDATE easycrm_presence SET lunch_day = '' WHERE user_id = 'legacymeal'");
 try { $presence->touch('legacymeal',true,$base+100,'lunch'); throw new \RuntimeException('Expected recovered daily lunch limit'); }
 catch (\DomainException $e) { check(true,'closed lunch history prevents a second meal after additive upgrade'); }
 $request->params = ['paused'=>true,'kind'=>'lunch'];
 check($asPresence('alice')->pause()->status === 409, 'controller reports already-used lunch as conflict');
 echo "Presence and announcement checks: $count passed\n";
}
