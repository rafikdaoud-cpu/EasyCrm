<?php
namespace OCA\CallCrm\Migration;
use Closure;use OCP\DB\ISchemaWrapper;use OCP\DB\Types;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
/** Liens de dépôt de documents envoyés aux clients (page publique) et fichiers déposés en attente d'envoi. */
class Version1015Date20261105100000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper{
  $s=$schemaClosure();
  if(!$s->hasTable('callcrm_collect_links')){$t=$s->createTable('callcrm_collect_links');
   $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$t->addColumn('prospect_id',Types::BIGINT,['notnull'=>true]);
   foreach(['token_hash'=>64,'created_by'=>64,'owner'=>64,'state'=>16,'folder_path'=>512]as $key=>$size)$t->addColumn($key,Types::STRING,['length'=>$size,'notnull'=>false]);
   $t->addColumn('documents',Types::TEXT,['notnull'=>true]);
   foreach(['created_at','expires_at','submitted_at']as $key)$t->addColumn($key,Types::BIGINT,['notnull'=>true,'default'=>0]);
   $t->setPrimaryKey(['id']);$t->addUniqueIndex(['token_hash'],'ccrm_collect_token_unique');$t->addIndex(['prospect_id','created_at'],'ccrm_collect_client_idx');$t->addIndex(['state','expires_at'],'ccrm_collect_state_idx');
  }
  if(!$s->hasTable('callcrm_collect_files')){$t=$s->createTable('callcrm_collect_files');
   $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$t->addColumn('link_id',Types::BIGINT,['notnull'=>true]);$t->addColumn('doc_index',Types::SMALLINT,['notnull'=>true]);
   foreach(['file_token'=>64,'name'=>255,'mime'=>127]as $key=>$size)$t->addColumn($key,Types::STRING,['length'=>$size,'notnull'=>false]);
   foreach(['size','created_at']as $key)$t->addColumn($key,Types::BIGINT,['notnull'=>true,'default'=>0]);
   $t->setPrimaryKey(['id']);$t->addUniqueIndex(['link_id','doc_index'],'ccrm_collect_file_unique');
  }
  return $s;
 }
}
