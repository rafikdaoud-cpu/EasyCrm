<?php
namespace OCA\EasyCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1008Date20261102160000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s = $schemaClosure();
  if ($s->hasTable('easycrm_presence')) {
   $t = $s->getTable('easycrm_presence');
   if (!$t->hasColumn('pause_started_at')) { $t->addColumn('pause_started_at',Types::BIGINT,['notnull'=>true,'default'=>0]); }
   if (!$t->hasColumn('lunch_day')) { $t->addColumn('lunch_day',Types::STRING,['length'=>10,'notnull'=>true,'default'=>'']); }
  }
  return $s;
 }
}
