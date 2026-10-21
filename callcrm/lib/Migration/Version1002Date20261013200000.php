<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1002Date20261013200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		$t = $s->getTable('callcrm_calls');
		if (!$t->hasColumn('recording')) {
			$t->addColumn('recording', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		return $s;
	}
}
