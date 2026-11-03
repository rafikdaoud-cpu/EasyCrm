<?php
require __DIR__.'/announcements.php';
require __DIR__.'/../lib/Service/Sim.php';
require __DIR__.'/../lib/Service/Store.php';
class SimQuery extends Query {
 public function __construct(private PDO $connection) { parent::__construct($connection); }
 public function getLastInsertId() { return $this->connection->lastInsertId(); }
}
class SimDatabase extends Database { public function getQueryBuilder() { return new SimQuery($this->pdo); } }
class SimStore extends \OCA\CallCrm\Service\Store {
 public bool $auditFailure=false, $concurrentEdit=false;
 public function __construct(private SimDatabase $connection) { parent::__construct($connection); }
 public function get(int $id): ?array { $p=parent::get($id);if($this->concurrentEdit){$this->connection->pdo->exec("UPDATE callcrm_prospects SET remarque='Concurrent' WHERE id=1");}return $p; }
 public function insert(string $table,array $v): int { if($this->auditFailure) throw new RuntimeException('audit failed');return parent::insert($table,$v); }
}
$db=new SimDatabase();$db->pdo->exec("CREATE TABLE callcrm_prospects(id INTEGER PRIMARY KEY,remarque TEXT,assigned_to TEXT);CREATE TABLE callcrm_audit(id INTEGER PRIMARY KEY,created_at INTEGER,user_id TEXT,action TEXT,prospect_id INTEGER,detail TEXT);INSERT INTO callcrm_prospects VALUES(1,'Note existante','alice')");
$store=new SimStore($db);$cfg=\OCA\CallCrm\Service\Sim::DEF;$in=['w'=>4,'l'=>3,'hl'=>true,'model'=>'Classique','eq'=>['Cuisinière'=>true],'extras'=>['Tiroir'=>2]];
$r=\OCA\CallCrm\Service\Sim::compute($cfg,$in);$note=\OCA\CallCrm\Service\Sim::summary($cfg,$in,$r);
check($note[0]==='*'&&substr($note,-1)==='*'&&str_contains($note,'Cuisinière')&&str_contains($note,'2 × Tiroir'),'summary contains literal star delimiters and selected equipment');
$text=$store->saveSimulation(1,'alice',$in,$r,$note,'alice');
check($text==="Note existante\n\n".$note&&$db->pdo->query('SELECT remarque FROM callcrm_prospects')->fetchColumn()===$text,'simulation appends to existing client note');
check($db->pdo->query('SELECT count(*) FROM callcrm_audit')->fetchColumn()==1,'simulation history persists with note');
$store->auditFailure=true;
try{$store->saveSimulation(1,'alice',$in,$r,$note,'alice');throw new LogicException('Expected audit failure');}catch(RuntimeException $e){check($e->getMessage()==='audit failed','audit failure is propagated');}
check($db->pdo->query('SELECT remarque FROM callcrm_prospects')->fetchColumn()===$text,'audit failure rolls back note append');$store->auditFailure=false;
try{$store->saveSimulation(1,'alice',$in,$r,$note,'bob');throw new LogicException('Expected reassignment conflict');}catch(DomainException $e){}
check($db->pdo->query('SELECT count(*) FROM callcrm_audit')->fetchColumn()==1,'changed assignment cannot receive stale save');
$store->concurrentEdit=true;
try{$store->saveSimulation(1,'alice',$in,$r,$note,'alice');throw new LogicException('Expected note conflict');}catch(DomainException $e){}
check($db->pdo->query('SELECT count(*) FROM callcrm_audit')->fetchColumn()==1,'concurrent note change prevents save');
$store->concurrentEdit=false;$db->pdo->exec('UPDATE callcrm_prospects SET remarque=NULL,assigned_to=NULL');
check($store->saveSimulation(1,'admin',$in,$r,$note,null)===$note,'empty client note and unassigned prospect are supported');
echo "Simulator summary and atomic note append checks passed\n";
