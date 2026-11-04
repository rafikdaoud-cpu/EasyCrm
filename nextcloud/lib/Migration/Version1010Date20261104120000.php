<?php
namespace OCA\EasyCrm\Migration;
use Closure;use OCP\DB\ISchemaWrapper;use OCP\DB\Types;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
class Version1010Date20261104120000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper{
  $s=$schemaClosure();if(!$s->hasTable('easycrm_documents')){$t=$s->createTable('easycrm_documents');
   $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$t->addColumn('prospect_id',Types::BIGINT,['notnull'=>false]);
   foreach(['source'=>20,'source_key'=>64,'name'=>255,'mime'=>127,'original_file'=>64,'pdf_file'=>64,'state'=>24,'error'=>500,'sender'=>255,'subject'=>255]as $key=>$size)$t->addColumn($key,Types::STRING,['length'=>$size,'notnull'=>false]);
   $t->addColumn('source_ref',Types::TEXT,['notnull'=>false]);foreach(['size','created_at','lease_until']as $key)$t->addColumn($key,Types::BIGINT,['notnull'=>true,'default'=>0]);
   $t->setPrimaryKey(['id']);$t->addUniqueIndex(['source_key'],'ecrm_document_source_unique');$t->addIndex(['prospect_id','created_at'],'ecrm_document_client_idx');$t->addIndex(['state','lease_until'],'ecrm_document_queue_idx');
  }return $s;
 }
}
