<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Une livraison par commercial, conservée jusqu'à sa fermeture dans le CRM. */
class Version1009Date20261102090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if (!$s->hasTable('callcrm_announcements')) {
			$t = $s->createTable('callcrm_announcements');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('sender_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('sender_name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('recipient_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('body', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('dismissed_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['recipient_id', 'dismissed_at', 'id'], 'cc_ann_pending');
		}
		return $s;
	}
}
