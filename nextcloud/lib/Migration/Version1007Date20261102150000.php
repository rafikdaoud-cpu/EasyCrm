<?php
namespace OCA\EasyCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** Le déjeuner reste une pause ; son type est conservé pour les reprises et l'historique. */
class Version1007Date20261102150000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s = $schemaClosure();
  foreach (['easycrm_presence','easycrm_presence_log'] as $name) {
   if ($s->hasTable($name)) {
    $t = $s->getTable($name);
    if (!$t->hasColumn('pause_kind')) { $t->addColumn('pause_kind',Types::STRING,['length'=>16,'notnull'=>true,'default'=>'pause']); }
   }
  }
  return $s;
 }
}
