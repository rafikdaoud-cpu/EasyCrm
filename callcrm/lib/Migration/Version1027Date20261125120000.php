<?php
namespace OCA\CallCrm\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Journal d'appels complet du standard (une ligne par étape : accueil, poste, file, message), relu par l'API Yeastar. */
class Version1027Date20261125120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('callcrm_pbx_cdr')) {
			$t = $schema->createTable('callcrm_pbx_cdr');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('leg_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('call_id', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('call_type', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => '']);
			$t->addColumn('at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('from_number', Types::STRING, ['length' => 40, 'notnull' => true, 'default' => '']);
			$t->addColumn('to_number', Types::STRING, ['length' => 40, 'notnull' => true, 'default' => '']);
			$t->addColumn('to_label', Types::STRING, ['length' => 128, 'notnull' => true, 'default' => '']);
			$t->addColumn('disposition', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$t->addColumn('ring_duration', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('talk_duration', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('duration', Types::INTEGER, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['leg_key'], 'callcrm_cdr_leg');
			$t->addIndex(['call_id'], 'callcrm_cdr_call');
			$t->addIndex(['at'], 'callcrm_cdr_at');
		}
		return $schema;
	}
}
