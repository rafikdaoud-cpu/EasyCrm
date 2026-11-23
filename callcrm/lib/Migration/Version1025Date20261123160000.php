<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

class Version1025Date20261123160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('callcrm_wa_conv')) {
			$table = $schema->getTable('callcrm_wa_conv');
			if (!$table->hasColumn('routed_to')) {
				$table->addColumn('routed_to', Types::STRING, ['notnull'=>false,'length'=>64]);
				$table->addIndex(['routed_to'], 'cc_wc_route');
			}
		}
		return $schema;
	}
}
