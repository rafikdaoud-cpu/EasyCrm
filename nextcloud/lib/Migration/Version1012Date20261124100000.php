<?php
namespace OCA\EasyCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

class Version1012Date20261124100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('easycrm_calls')) {
			$table = $schema->getTable('easycrm_calls');
			if (!$table->hasColumn('call_direction')) {
				$table->addColumn('call_direction',Types::STRING,['notnull'=>false,'length'=>8]);
			}
		}
		return $schema;
	}
}
