<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1017Date20261116090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('callcrm_pbx_recordings')) {
            $t = $schema->createTable('callcrm_pbx_recordings');
            $t->addColumn('id', Types::BIGINT, ['autoincrement'=>true, 'notnull'=>true]);
            foreach (['source_key'=>64, 'call_id'=>128, 'phone'=>40, 'extension'=>32, 'user_id'=>255,
                'direction'=>16, 'call_status'=>32, 'started_at'=>32, 'recording'=>255,
                'state'=>24, 'error'=>255, 'mime'=>32, 'storage_folder'=>64, 'pbx_url'=>2048] as $key=>$length) {
                $t->addColumn($key, Types::STRING, ['length'=>$length, 'notnull'=>true]);
            }
            $t->addColumn('prospect_id', Types::BIGINT, ['notnull'=>false]);
            foreach (['talk_duration','size','attempts','next_attempt','lease_until','created_at'] as $key) {
                $t->addColumn($key, Types::BIGINT, ['notnull'=>true, 'default'=>0]);
            }
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['source_key'], 'ccrm_pbx_rec_source');
            $t->addIndex(['prospect_id','id'], 'ccrm_pbx_rec_client');
            $t->addIndex(['state','next_attempt'], 'ccrm_pbx_rec_queue');
        }
        return $schema;
    }
}
