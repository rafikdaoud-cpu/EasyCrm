<?php
namespace OCA\Reclamations\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version1000Date20261015100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if (!$s->hasTable('reclam_claims')) {
			$t = $s->createTable('reclam_claims');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ref', Types::STRING, ['notnull' => false, 'length' => 32]);
			foreach (['client_name', 'phone', 'wilaya', 'invoice_no', 'product', 'category', 'type', 'priority'] as $c) {
				$t->addColumn($c, Types::STRING, ['notnull' => false, 'length' => 255]);
			}
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('resolution', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 24, 'default' => 'nouvelle']);
			$t->addColumn('assigned_to', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('due_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('closed_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status'], 'rc_status');
			$t->addIndex(['assigned_to'], 'rc_assign');
			$t->addIndex(['created_by'], 'rc_creator');
		}
		if (!$s->hasTable('reclam_events')) {
			$t = $s->createTable('reclam_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 24]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['claim_id'], 're_claim');
		}
		return $s;
	}
}
