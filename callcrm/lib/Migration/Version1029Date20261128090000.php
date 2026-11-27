<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Réponse du commercial à une évaluation qualité : 1 = compris, 2 = contesté, avec commentaire. */
class Version1029Date20261128090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$t = $schema->getTable('callcrm_quality');
		if (!$t->hasColumn('ack')) $t->addColumn('ack', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		if (!$t->hasColumn('ack_comment')) $t->addColumn('ack_comment', Types::TEXT, ['notnull' => false]);
		if (!$t->hasColumn('ack_at')) $t->addColumn('ack_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		return $schema;
	}
}
