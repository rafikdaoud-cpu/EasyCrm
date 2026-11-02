<?php
/** Standalone contract tests: real controller/service, SQLite storage, Nextcloud API doubles. */
namespace OCP {
 interface IRequest { public function getParam($key, $default = null); }
 interface IGroupManager { public function isInGroup($uid, $group); }
 interface IUserManager { public function get($uid); }
 interface IUserSession { public function getUser(); }
 interface IDBConnection {}
 class Server { public static function get($type) { return new class { public function getAppValue(...$args) { return ''; } }; } }
}
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { public const PARAM_STR_ARRAY = 102; } }
namespace OCP\AppFramework {
 class App {}
 class Controller { protected $request; public function __construct($id, $r) { $this->request = $r; } }
}
namespace OCP\AppFramework\Http {
 class JSONResponse { public function __construct(public $data, public $status = 200) {} }
}
namespace {
 require __DIR__ . '/../lib/AppInfo/Application.php';
 require __DIR__ . '/../lib/Service/Access.php';
 require __DIR__ . '/../lib/Service/Announcements.php';
 require __DIR__ . '/../lib/Controller/AnnouncementController.php';

}
namespace {
 class Database implements \OCP\IDBConnection {
  public \PDO $pdo;
  public function __construct() {
   $this->pdo = new \PDO('sqlite::memory:');
   $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
   $this->pdo->exec('CREATE TABLE callcrm_announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_id TEXT, sender_name TEXT, recipient_id TEXT, body TEXT, created_at INTEGER, dismissed_at INTEGER)');
  }
  public function getQueryBuilder() { return new Query($this->pdo); }
  public function beginTransaction() { $this->pdo->beginTransaction(); }
  public function commit() { $this->pdo->commit(); }
  public function rollBack() { $this->pdo->rollBack(); }
 }
 class Query {
  private string $kind = '', $table = '', $columns = '*', $order = '';
  private array $values = [], $where = [], $params = [];
  private int $limit = 0; private string $group = '';
  public function __construct(private \PDO $pdo) {}
  public function createNamedParameter($value, $type = null) { if (is_array($value)) { return '(' . implode(',', array_map(fn($x) => $this->createNamedParameter($x), $value)) . ')'; } $key = ':p' . count($this->params); $this->params[$key] = $value; return $key; }
  public function expr() { return $this; }
  public function gte($left, $right) { return "$left >= $right"; }
  public function groupBy(...$keys) { $this->group = implode(',', $keys); return $this; }
  public function gt($left, $right) { return "$left > $right"; }
  public function lt($left, $right) { return "$left < $right"; }
  public function in($left, $right) { return "$left IN $right"; }
  public function eq($left, $right) { return "$left = $right"; }
  public function isNull($key) { return "$key IS NULL"; }
  public function insert($table) { $this->kind = 'INSERT'; $this->table = $table; return $this; }
  public function update($table) { $this->kind = 'UPDATE'; $this->table = $table; return $this; }
  public function setValue($key, $value) { $this->values[$key] = $value; return $this; }
  public function set($key, $value) { return $this->setValue($key, $value); }
  public function select(...$keys) { $this->kind = 'SELECT'; $this->columns = implode(',', $keys); return $this; }
  public function from($table) { $this->table = $table; return $this; }
  public function where($clause) { $this->where = [$clause]; return $this; }
  public function andWhere($clause) { $this->where[] = $clause; return $this; }
  public function addOrderBy($key, $direction) { $this->order .= ", $key $direction"; return $this; }
  public function orderBy($key, $direction) { $this->order = "$key $direction"; return $this; }
  public function setMaxResults($limit) { $this->limit = $limit; return $this; }
  private function execute() {
   $sql = match ($this->kind) {
    'INSERT' => 'INSERT INTO ' . $this->table . ' (' . implode(',', array_keys($this->values)) . ') VALUES (' . implode(',', $this->values) . ')',
    'UPDATE' => 'UPDATE ' . $this->table . ' SET ' . implode(',', array_map(fn($k, $v) => "$k=$v", array_keys($this->values), $this->values)),
    default => 'SELECT ' . $this->columns . ' FROM ' . $this->table,
   };
   if ($this->where) { $sql .= ' WHERE ' . implode(' AND ', $this->where); }
   if ($this->group) { $sql .= ' GROUP BY ' . $this->group; }
   if ($this->order) { $sql .= ' ORDER BY ' . $this->order; }
   if ($this->limit) { $sql .= ' LIMIT ' . $this->limit; }
   $q = $this->pdo->prepare($sql); $q->execute($this->params); return $q;
  }
  public function executeStatement() { return $this->execute()->rowCount(); }
  public function executeQuery() { return $this; }
  public function fetch() { return $this->execute()->fetch(\PDO::FETCH_ASSOC); }
  public function fetchAll() { return $this->execute()->fetchAll(\PDO::FETCH_ASSOC); }
 }
 class Request implements \OCP\IRequest {
  public array $params = [];
  public function getParam($key, $default = null) { return $this->params[$key] ?? $default; }
 }
 class User {
  public function __construct(private string $uid) {}
  public function getUID() { return $this->uid; }
  public function getDisplayName() { return 'Nom ' . $this->uid; }
 }
 class Users implements \OCP\IUserManager { public function get($uid) { return new User($uid); } }
 class Session implements \OCP\IUserSession {
  public string $uid = 'admin';
  public function getUser() { return new User($this->uid); }
 }
 class Groups implements \OCP\IGroupManager {
  public function get($group) { return new class { public function getUsers() { return [new User('alice'),new User('bob')]; } }; }

  public function isAdmin($uid) { return $uid === 'admin'; }
  public function isInGroup($uid, $group) {
   return in_array($uid, match ($group) {
    'callcrm_superviseur' => ['supervisor'], 'callcrm_commercial' => ['alice', 'bob'], 'callcrm_lecture' => ['reader'], default => [],
   }, true);
  }
 }
 $db = new Database(); $request = new Request(); $session = new Session(); $groups = new Groups();
 $service = new \OCA\CallCrm\Service\Announcements($db);
 $as = function ($uid) use ($request, $session, $groups, $service) {
  $session->uid = $uid;
  return new \OCA\CallCrm\Controller\AnnouncementController($request, new \OCA\CallCrm\Service\Access($session, $groups), $service, $groups, new Users());
 };
 $count = 0;
 function check($ok, $label) { global $count; if (!$ok) { throw new \RuntimeException($label); } $count++; echo "PASS $label\n"; }
 $request->params = ['body' => 'Bonjour', 'uids' => ['alice']];
 foreach (['alice', 'reader', 'outsider'] as $uid) { check($as($uid)->send()->status === 403, "$uid cannot send"); }
 check($as('admin')->send()->data['sent'] === 1, 'admin sends individual announcement');
 check(count($as('alice')->pending()->data) === 1, 'recipient receives announcement');
 check($as('bob')->pending()->data === [], 'other commercial cannot read announcement');
 $request->params = ['body' => 'Réunion à 14 h', 'uids' => ['alice', 'bob', 'alice']];
 check($as('supervisor')->send()->data['sent'] === 2, 'supervisor broadcasts with deduplicated recipients');
 $alice = $as('alice')->pending()->data;
 check(count($alice) === 2 && $alice[1]['sender_name'] === 'Nom supervisor', 'ordered pending messages include sender');
 $id = (int)$alice[0]['id'];
 $as('bob')->dismiss($id);
 check(count($as('alice')->pending()->data) === 2, 'cannot dismiss another recipient message');
 $as('alice')->dismiss($id); $as('alice')->dismiss($id);
 check(count($as('alice')->pending()->data) === 1, 'dismissal persists and is idempotent');
 foreach (['admin', 'supervisor', 'reader', 'outsider'] as $uid) {
  check($as($uid)->pending()->status === 403 && $as($uid)->dismiss($id)->status === 403, "$uid cannot access recipient endpoints");
 }
 foreach ([['body'=>' ', 'uids'=>['alice']], ['body'=>str_repeat('é', 2001), 'uids'=>['alice']], ['body'=>[], 'uids'=>['alice']], ['body'=>'x', 'uids'=>[]], ['body'=>'x', 'uids'=>['alice','reader']], ['body'=>'x', 'uids'=>[[]]]] as $invalid) {
  $request->params = $invalid;
  $before = $db->pdo->query('SELECT count(*) FROM callcrm_announcements')->fetchColumn();
  check($as('admin')->send()->status === 400, 'invalid message or recipients rejected');
  check($db->pdo->query('SELECT count(*) FROM callcrm_announcements')->fetchColumn() === $before, 'invalid send creates no partial deliveries');
 }
 $db->pdo->exec("CREATE TRIGGER fail_bob BEFORE INSERT ON callcrm_announcements WHEN NEW.recipient_id = 'bob' BEGIN SELECT RAISE(ABORT, 'storage failed'); END");
 $request->params = ['body'=>'transaction', 'uids'=>['alice','bob']];
 $before = count($service->pending('alice'));
 try { $as('admin')->send(); throw new \RuntimeException('Expected storage failure'); } catch (\PDOException $e) {}
 check(count($service->pending('alice')) === $before, 'storage failure rolls back all recipients');
 $routes = require __DIR__ . '/../appinfo/routes.php';
 check(count(array_filter($routes['routes'], fn($r) => str_starts_with($r['name'], 'announcement#'))) === 3, 'three announcement routes registered');
 foreach (['send','dismiss'] as $method) {
  $reflection = new \ReflectionMethod(\OCA\CallCrm\Controller\AnnouncementController::class, $method);
  check(!$reflection->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired'), "$method keeps Nextcloud CSRF protection");
 }
 echo "$count checks passed\n";
}
