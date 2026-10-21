<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1000Date20261012100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if (!$s->hasTable('callcrm_prospects')) {
			$t = $s->createTable('callcrm_prospects');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			foreach (['lastname', 'firstname', 'email', 'phone', 'c1', 'c2', 'c3', 'c4', 'c5'] as $c) {
				$t->addColumn($c, Types::STRING, ['notnull' => false, 'length' => 255]);
			}
			$t->addColumn('assigned_to', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'nouveau']);
			$t->addColumn('callback_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_call_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['assigned_to'], 'cc_p_assign');
			$t->addIndex(['status'], 'cc_p_status');
			$t->addIndex(['phone'], 'cc_p_phone');
		}
		if (!$s->hasTable('callcrm_calls')) {
			$t = $s->createTable('callcrm_calls');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('prospect_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 20]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('callback_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['prospect_id'], 'cc_c_prosp');
		}
		return $s;
	}
}
