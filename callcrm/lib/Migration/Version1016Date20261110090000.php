<?php
namespace OCA\CallCrm\Migration;
use Closure;use OCP\DB\ISchemaWrapper;use OCP\DB\Types;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
class Version1016Date20261110090000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper{
  $s=$schemaClosure();if(!$s->hasTable('callcrm_pbx_events')){
   $t=$s->createTable('callcrm_pbx_events');
   $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
   foreach(['event_hash'=>64,'call_id'=>128,'user_id'=>255,'phone'=>40,'direction'=>16,'state'=>24] as $k=>$length)$t->addColumn($k,Types::STRING,['length'=>$length,'notnull'=>true]);
   $t->addColumn('prospect_id',Types::BIGINT,['notnull'=>false]);
   $t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
   $t->setPrimaryKey(['id']);$t->addUniqueIndex(['event_hash'],'ccrm_pbx_event_hash');$t->addIndex(['user_id','id'],'ccrm_pbx_user_event');$t->addIndex(['prospect_id','id'],'ccrm_pbx_client_event');
  }return $s;
 }
}
