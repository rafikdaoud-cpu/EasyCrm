<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1001Date20261013100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		$t = $s->getTable('callcrm_prospects');
		if (!$t->hasColumn('remarque')) {
			$t->addColumn('remarque', Types::TEXT, ['notnull' => false]);
		}
		return $s;
	}
}
