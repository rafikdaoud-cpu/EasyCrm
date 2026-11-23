<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

class Version1024Date20261123145000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('callcrm_pbx_events')) {
            $table = $schema->getTable('callcrm_pbx_events');
            if (!$table->hasIndex('ccrm_pbx_call_event')) {
                $table->addIndex(['call_id','direction'],'ccrm_pbx_call_event');
            }
        }
        return $schema;
    }
}
