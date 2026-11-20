<?php
namespace OCA\CallCrm\Migration;
use Closure;
use OCA\CallCrm\Service\YeastarRecordings;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\{IOutput,SimpleMigrationStep};

/** Appels courts pris par un commercial via une file : audio écarté à tort (« skipped_short ») remis en téléchargement. */
class Version1022Date20261120090000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db, private IConfig $config) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $settings = json_decode($this->config->getAppValue('callcrm', 'yeastar_settings', '{}'), true) ?: [];
        $agents = array_values(array_filter(array_map(fn($r) => (string)($r['extension'] ?? ''), $settings['extensions'] ?? [])));
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('id', 'extension', 'recording', 'call_status', 'talk_duration', 'call_duration')->from('callcrm_pbx_recordings')
            ->where($q->expr()->eq('state', $q->createNamedParameter('skipped_short')))->executeQuery()->fetchAll();
        $n = 0;
        foreach ($rows as $r) {
            if (YeastarRecordings::shortWithoutAgent($r, $agents)) continue;
            $u = $this->db->getQueryBuilder();
            $n += $u->update('callcrm_pbx_recordings')->set('state', $u->createNamedParameter('queued'))->set('attempts', $u->createNamedParameter(0))
                ->set('next_attempt', $u->createNamedParameter(0))->set('lease_until', $u->createNamedParameter(0))->set('error', $u->createNamedParameter(''))
                ->where($u->expr()->eq('id', $u->createNamedParameter((int)$r['id'])))->andWhere($u->expr()->eq('state', $u->createNamedParameter('skipped_short')))->executeStatement();
        }
        $output->info("Audios d'appels courts pris par un commercial remis en téléchargement : $n");
    }
}
