<?php

declare(strict_types=1);

namespace OCA\Reclamations\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261013000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('recl_items')) {
            $t = $schema->createTable('recl_items');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('ref', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('client_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('client_phone', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('dossier_no', Types::STRING, ['notnull' => false, 'length' => 128]);
            $t->addColumn('product', Types::STRING, ['notnull' => false, 'length' => 255]);
            $t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('urgency', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('channel', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('description', Types::TEXT, ['notnull' => false]);
            $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('assigned_to', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('service', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('qualified_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('resolved_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('closed_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('take_due_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('due_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('paused_since', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('wait_reason', Types::STRING, ['notnull' => false, 'length' => 255]);
            $t->addColumn('solution', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('solution_detail', Types::TEXT, ['notnull' => false]);
            $t->addColumn('root_cause', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('cost', Types::FLOAT, ['notnull' => false]);
            $t->addColumn('satisfaction', Types::SMALLINT, ['notnull' => false]);
            $t->addColumn('reject_reason', Types::TEXT, ['notnull' => false]);
            $t->addColumn('reopen_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->addColumn('sla_notified', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['status'], 'recl_items_status');
            $t->addIndex(['assigned_to'], 'recl_items_assignee');
            $t->addIndex(['created_at'], 'recl_items_created');
        }

        if (!$schema->hasTable('recl_history')) {
            $t = $schema->createTable('recl_history');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('item_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('from_status', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('to_status', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('message', Types::TEXT, ['notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['item_id'], 'recl_hist_item');
        }

        if (!$schema->hasTable('recl_files')) {
            $t = $schema->createTable('recl_files');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('item_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('filename', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('mimetype', Types::STRING, ['notnull' => false, 'length' => 128]);
            $t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['item_id'], 'recl_files_item');
        }

        return $schema;
    }
}
