<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1004Date20261015100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if ($s->hasTable('callcrm_prospects')) {
			$t = $s->getTable('callcrm_prospects');
			if (!$t->hasColumn('extra')) { $t->addColumn('extra', Types::TEXT, ['notnull' => false]); }
		}
		return $s;
	}
}
