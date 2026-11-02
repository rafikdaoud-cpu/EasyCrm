<?php
/** Executes the actual migration callbacks against a schema API double. */
namespace OCP\DB {
 interface ISchemaWrapper {}
 class Types { const BIGINT='bigint', STRING='string', TEXT='text', SMALLINT='smallint'; }
}
namespace OCP\Migration { interface IOutput {} class SimpleMigrationStep {} }
namespace {
 class Schema implements \OCP\DB\ISchemaWrapper {
  public array $tables = ['existing_table'=>['untouched'=>true]];
  public function hasTable($name) { return isset($this->tables[$name]); }
  public function getTable($name) { return $this->tables[$name]; }
  public function createTable($name) { return $this->tables[$name] = new Table(); }
 }
 class Table {
  public array $columns = [], $indexes = [], $primary = [];
  public function hasColumn($name) { return isset($this->columns[$name]); }
  public function addColumn($name,$type,$options) {
   // Nextcloud rejects explicit empty/null defaults on NOT NULL fields.
   if (($options['notnull'] ?? false) && array_key_exists('default',$options) && ($options['default'] === '' || $options['default'] === null)) {
    throw new \RuntimeException('NotNull column with empty/null default: '.$name);
   }
   $this->columns[$name] = [$type,$options];
  }
  public function setPrimaryKey($columns) { $this->primary = $columns; }
  public function addIndex($columns,$name) { $this->indexes[$name] = $columns; }
 }
 $allIndexes = [];
 foreach ([['callcrm','CallCrm','Version1009Date20261102090000','Version1010Date20261102120000','Version1011Date20261102150000','Version1012Date20261102160000'],['nextcloud','EasyCrm','Version1005Date20261102090000','Version1006Date20261102120000','Version1007Date20261102150000','Version1008Date20261102160000']] as [$folder,$ns,$ann,$presence,$lunch,$policy]) {
  $schema = new Schema(); $output = new class implements \OCP\Migration\IOutput {};
  foreach ([$ann,$presence,$lunch,$policy] as $version) {
   require __DIR__.'/../'.$folder.'/lib/Migration/'.$version.'.php';
   $class = 'OCA\\'.$ns.'\\Migration\\'.$version; $migration = new $class();
   $migration->changeSchema($output,fn()=>$schema,[]); $first = serialize($schema);
   $migration->changeSchema($output,fn()=>$schema,[]);
   if (serialize($schema) !== $first) throw new \RuntimeException('Migration not repeatable: '.$class);
  }
  $id = $folder === 'callcrm' ? 'callcrm' : 'easycrm';
  foreach (['announcements'=>['id','sender_id','sender_name','recipient_id','body','created_at','dismissed_at'], 'presence'=>['user_id','paused','segment_start','last_seen','revision','pause_kind','pause_started_at','lunch_day'], 'presence_log'=>['id','user_id','paused','started_at','ended_at','pause_kind']] as $table=>$columns) {
   $t = $schema->tables[$id.'_'.$table] ?? null;
   if (!$t || array_diff($columns,array_keys($t->columns))) throw new \RuntimeException('Missing migration fields: '.$table);
   foreach ($t->indexes as $name=>$index) {
    if (isset($allIndexes[$name])) throw new \RuntimeException('Index collision: '.$name);
    $allIndexes[$name] = true;
   }
  }
  $lunch = $schema->tables[$id.'_presence']->columns['lunch_day'][1];
  if (($lunch['notnull'] ?? true) !== false || $lunch['default'] !== null) throw new \RuntimeException('lunch_day must allow NULL before first lunch');
  if ($schema->tables['existing_table'] !== ['untouched'=>true]) throw new \RuntimeException('Existing schema modified');
  echo "PASS $folder migration callbacks, repeatability, columns and isolated index names\n";
 }
}
