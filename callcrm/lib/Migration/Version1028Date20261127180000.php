<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Évaluations qualité des appels enregistrés : une par enregistrement (grille, note sur 100, commentaire, lecture par le commercial). */
class Version1028Date20261127180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('callcrm_quality')) {
			$t = $schema->createTable('callcrm_quality');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('recording_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('agent_uid', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$t->addColumn('evaluator_uid', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$t->addColumn('scores', Types::TEXT, ['notnull' => false]);
			$t->addColumn('score', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('eliminated', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('read_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['recording_id'], 'callcrm_quality_rec');
			$t->addIndex(['agent_uid', 'created_at'], 'callcrm_quality_agent');
		}
		return $schema;
	}
}
