<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

class Version1023Date20261123100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('callcrm_shared_clients')) {
			$table = $schema->createTable('callcrm_shared_clients');
			$table->addColumn('prospect_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('grantee', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['prospect_id', 'grantee']);
			$table->addIndex(['grantee', 'prospect_id'], 'cc_shared_grantee');
		}
		return $schema;
	}
}
