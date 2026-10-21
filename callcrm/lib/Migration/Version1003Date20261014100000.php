<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1003Date20261014100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if (!$s->hasTable('callcrm_recordings')) {
			$t = $s->createTable('callcrm_recordings');
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
			$t->addIndex(['prospect_id'], 'cc_r_prosp');
			$t->addIndex(['user_id'], 'cc_r_user');
		}
		return $s;
	}
}
