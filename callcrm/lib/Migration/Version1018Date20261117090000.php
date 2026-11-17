<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};

class Version1018Date20261117090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('callcrm_pbx_followups')) {
            $t=$schema->createTable('callcrm_pbx_followups');
            foreach (['call_key'=>64,'state'=>16,'updated_by'=>255] as $key=>$length) $t->addColumn($key,Types::STRING,['length'=>$length,'notnull'=>true]);
            $t->addColumn('updated_at',Types::BIGINT,['notnull'=>true,'default'=>0]);
            $t->setPrimaryKey(['call_key']);
        }
        $t=$schema->getTable('callcrm_pbx_recordings');
        if (!$t->hasIndex('ccrm_pbx_rec_received')) $t->addIndex(['created_at'],'ccrm_pbx_rec_received');
        return $schema;
    }
}
