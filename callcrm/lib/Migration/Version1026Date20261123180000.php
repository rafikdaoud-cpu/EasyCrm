<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

class Version1026Date20261123180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('callcrm_calls')) {
			$table = $schema->getTable('callcrm_calls');
			if (!$table->hasColumn('call_direction')) {
				$table->addColumn('call_direction',Types::STRING,['notnull'=>false,'length'=>8]);
			}
		}
		return $schema;
	}
}
