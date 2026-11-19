<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};

/** Durée totale de l'appel (SVI et attente compris), pour repérer les appels raccrochés en moins de 30 s. */
class Version1021Date20261119160000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if ($schema->hasTable('callcrm_pbx_recordings')) {
            $t=$schema->getTable('callcrm_pbx_recordings');
            if (!$t->hasColumn('call_duration')) $t->addColumn('call_duration',Types::BIGINT,['notnull'=>false]);
        }
        return $schema;
    }
}
