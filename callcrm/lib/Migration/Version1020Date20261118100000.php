<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};

/** Origine du prospect : import, manuel, formulaire (cloud.csmtaksit.dz/prospects) ou whatsapp. */
class Version1020Date20261118100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if ($schema->hasTable('callcrm_prospects')) {
            $t=$schema->getTable('callcrm_prospects');
            if (!$t->hasColumn('source')) $t->addColumn('source',Types::STRING,['length'=>16,'notnull'=>false]);
        }
        return $schema;
    }
}
