<?php
namespace OCA\EasyCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** Date de remise à zéro des tentatives (changement de statut en masse). */
class Version1002Date20261021100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if ($s->hasTable('easycrm_prospects')) {
			$t = $s->getTable('easycrm_prospects');
			if (!$t->hasColumn('reset_at')) { $t->addColumn('reset_at', Types::BIGINT, ['notnull' => false]); }
		}
		return $s;
	}
}
