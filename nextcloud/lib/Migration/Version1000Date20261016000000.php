<?php
namespace OCA\EasyCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Schéma initial d'EasyCrm (installation propre). */
class Version1000Date20261016000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if (!$s->hasTable('easycrm_prospects')) {
			$t = $s->createTable('easycrm_prospects');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			foreach (['lastname', 'firstname', 'email', 'phone', 'c1', 'c2', 'c3', 'c4', 'c5'] as $c) {
				$t->addColumn($c, Types::STRING, ['notnull' => false, 'length' => 255]);
			}
			$t->addColumn('remarque', Types::TEXT, ['notnull' => false]);
			$t->addColumn('extra', Types::TEXT, ['notnull' => false]);
			$t->addColumn('assigned_to', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'nouveau']);
			$t->addColumn('callback_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_call_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['assigned_to'], 'ec_p_assign');
			$t->addIndex(['status'], 'ec_p_status');
			$t->addIndex(['phone'], 'ec_p_phone');
		}
		if (!$s->hasTable('easycrm_calls')) {
			$t = $s->createTable('easycrm_calls');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('prospect_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 20]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('callback_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('recording', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['prospect_id'], 'ec_c_prosp');
		}
		if (!$s->hasTable('easycrm_recordings')) {
			$t = $s->createTable('easycrm_recordings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('prospect_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('call_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('file', Types::STRING, ['notnull' => true, 'length' => 80]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('recorded_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['prospect_id'], 'ec_r_prosp');
			$t->addIndex(['user_id'], 'ec_r_user');
		}
		return $s;
	}
}
