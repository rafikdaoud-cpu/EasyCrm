<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};

/** Appels entrants manqués à rappeler, un épisode par numéro tant qu'il est ouvert. */
class Version1019Date20261118090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('callcrm_missed')) {
            $t=$schema->createTable('callcrm_missed');
            $t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true,'unsigned'=>true]);
            $t->addColumn('phone',Types::STRING,['length'=>40,'notnull'=>true]);
            foreach (['first_at','last_at','assigned_at','first_attempt_at','done_at','updated_at'] as $c) $t->addColumn($c,Types::BIGINT,['notnull'=>true,'default'=>0]);
            foreach (['count','attempts'] as $c) $t->addColumn($c,Types::INTEGER,['notnull'=>true,'default'=>0]);
            $t->addColumn('prospect_id',Types::BIGINT,['notnull'=>false]);
            $t->addColumn('assigned_to',Types::STRING,['length'=>64,'notnull'=>false]);
            $t->addColumn('state',Types::STRING,['length'=>16,'notnull'=>true,'default'=>'open']);
            $t->addColumn('done_by',Types::STRING,['length'=>64,'notnull'=>true,'default'=>'']);
            $t->addColumn('done_how',Types::STRING,['length'=>24,'notnull'=>true,'default'=>'']);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['phone','state'],'ccrm_missed_phone');
            $t->addIndex(['state','assigned_to'],'ccrm_missed_owner');
            $t->addIndex(['first_at'],'ccrm_missed_first');
        }
        return $schema;
    }
}
