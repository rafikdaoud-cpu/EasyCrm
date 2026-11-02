<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1012Date20261102160000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s = $schemaClosure();
  if ($s->hasTable('callcrm_presence')) {
   $t = $s->getTable('callcrm_presence');
   if (!$t->hasColumn('pause_started_at')) { $t->addColumn('pause_started_at',Types::BIGINT,['notnull'=>true,'default'=>0]); }
   if (!$t->hasColumn('lunch_day')) { $t->addColumn('lunch_day',Types::STRING,['length'=>10,'notnull'=>false,'default'=>null]); }
  }
  return $s;
 }
}
