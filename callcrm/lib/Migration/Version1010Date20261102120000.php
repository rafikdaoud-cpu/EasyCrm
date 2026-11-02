<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1010Date20261102120000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s = $schemaClosure();
  if (!$s->hasTable('callcrm_presence')) {
   $t = $s->createTable('callcrm_presence');
   $t->addColumn('user_id', Types::STRING, ['length'=>64, 'notnull'=>true]);
   $t->addColumn('paused', Types::SMALLINT, ['notnull'=>true, 'default'=>0]);
   foreach (['segment_start','last_seen','revision'] as $c) { $t->addColumn($c, Types::BIGINT, ['notnull'=>true]); }
   $t->setPrimaryKey(['user_id']);
  }
  if (!$s->hasTable('callcrm_presence_log')) {
   $t = $s->createTable('callcrm_presence_log');
   $t->addColumn('id', Types::BIGINT, ['autoincrement'=>true, 'notnull'=>true]);
   $t->addColumn('user_id', Types::STRING, ['length'=>64, 'notnull'=>true]);
   $t->addColumn('paused', Types::SMALLINT, ['notnull'=>true]);
   foreach (['started_at','ended_at'] as $c) { $t->addColumn($c, Types::BIGINT, ['notnull'=>true]); }
   $t->setPrimaryKey(['id']);
   $t->addIndex(['user_id','ended_at'], 'cc_presence_period');
  }
  return $s;
 }
}
