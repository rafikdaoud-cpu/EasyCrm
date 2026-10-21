<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** WhatsApp : conversations (une par numéro client) et messages. */
class Version1007Date20261021200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if (!$s->hasTable('callcrm_wa_conv')) {
			$t = $s->createTable('callcrm_wa_conv');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('wa_id', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('prospect_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('assigned_to', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ouvert']);
			$t->addColumn('unread', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_in_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_out_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_msg_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_preview', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['wa_id'], 'cc_wc_waid');
			$t->addIndex(['prospect_id'], 'cc_wc_prosp');
			$t->addIndex(['assigned_to'], 'cc_wc_assign');
			$t->addIndex(['last_msg_at'], 'cc_wc_last');
		}
		if (!$s->hasTable('callcrm_wa_msg')) {
			$t = $s->createTable('callcrm_wa_msg');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('conv_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('wamid', Types::STRING, ['notnull' => false, 'length' => 191]);
			$t->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 4]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 20]);
			$t->addColumn('body', Types::TEXT, ['notnull' => false]);
			$t->addColumn('file_path', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('mime', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['conv_id', 'created_at'], 'cc_wm_conv');
			$t->addIndex(['wamid'], 'cc_wm_wamid');
		}
		return $s;
	}
}
