<?php
namespace OCA\Reclamations\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** Service (département) responsable d'une réclamation. */
class Version1001Date20261021100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if ($s->hasTable('reclam_claims')) {
			$t = $s->getTable('reclam_claims');
			if (!$t->hasColumn('service')) { $t->addColumn('service', Types::STRING, ['notnull' => false, 'length' => 80]); }
		}
		return $s;
	}
}
