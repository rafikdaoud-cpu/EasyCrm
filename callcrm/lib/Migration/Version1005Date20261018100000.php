<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** Motif de refus sur les appels + journal d'activité (transferts, clôtures automatiques, exports…). */
class Version1005Date20261018100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if ($s->hasTable('callcrm_calls')) {
			$t = $s->getTable('callcrm_calls');
			if (!$t->hasColumn('reason')) { $t->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 80]); }
		}
		if (!$s->hasTable('callcrm_audit')) {
			$t = $s->createTable('callcrm_audit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('prospect_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['created_at'], 'cc_a_date');
			$t->addIndex(['action'], 'cc_a_action');
		}
		return $s;
	}
}
