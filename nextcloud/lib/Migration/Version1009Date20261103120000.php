<?php
namespace OCA\EasyCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1009Date20261103120000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s=$schemaClosure();
  if (!$s->hasTable('easycrm_client_events')) {
   $t=$s->createTable('easycrm_client_events');
   $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
   $t->addColumn('prospect_id',Types::BIGINT,['notnull'=>true]);
   $t->addColumn('user_id',Types::STRING,['length'=>255,'notnull'=>true]);
   $t->addColumn('kind',Types::STRING,['length'=>24,'notnull'=>true]);
   $t->addColumn('body',Types::TEXT,['notnull'=>true]);
   $t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
   $t->setPrimaryKey(['id']);$t->addIndex(['prospect_id','created_at','id'],'ecrm_client_event_idx');
  }
  if (!$s->hasTable('easycrm_appointments')) {
   $t=$s->createTable('easycrm_appointments');
   $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
   $t->addColumn('prospect_id',Types::BIGINT,['notnull'=>true]);
   $t->addColumn('starts_at',Types::BIGINT,['notnull'=>true]);
   $t->addColumn('title',Types::STRING,['length'=>120,'notnull'=>true]);
   $t->addColumn('notes',Types::TEXT,['notnull'=>false]);
   $t->addColumn('state',Types::STRING,['length'=>12,'notnull'=>true,'default'=>'open']);
   $t->addColumn('revision',Types::BIGINT,['notnull'=>true,'default'=>1]);
   $t->addColumn('created_by',Types::STRING,['length'=>255,'notnull'=>true]);
   $t->addColumn('updated_at',Types::BIGINT,['notnull'=>true]);
   $t->setPrimaryKey(['id']);$t->addIndex(['state','starts_at'],'ecrm_agenda_due_idx');$t->addIndex(['prospect_id'],'ecrm_appt_client_idx');
  }
  return $s;
 }
}
