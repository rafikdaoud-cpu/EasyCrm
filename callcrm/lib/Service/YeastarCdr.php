<?php
namespace OCA\CallCrm\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Journal d'appels complet du standard (cdr/search) : une ligne par étape (accueil, poste, message, file).
 * Le webhook ne transmet ni toutes les étapes ni la durée de sonnerie ; Pilotage, Statistiques et Appels manqués
 * classent d'abord les appels avec ce journal, puis avec les événements de sonnerie à défaut.
 */
class YeastarCdr {
    private const WINDOW = 86400;
    private const OVERLAP = 900;
    private const BACKFILL_DAYS = 92;

    public function __construct(private IDBConnection $db, private IConfig $config, private YeastarSettings $settings, private YeastarClient $client) {}

    /** Relit le standard par fenêtres d'un jour, du curseur jusqu'à maintenant (rattrapage de 92 jours au premier passage). */
    public function sync(?int $now = null, int $maxWindows = 4): int {
        $now ??= time();
        if (!$this->settings->settings()['enabled']) return 0;
        $zone = new \DateTimeZone(Prefs::all()['tz']);
        $cursor = (int)$this->config->getAppValue('callcrm', 'yeastar_cdr_cursor', '0') ?: $now - self::BACKFILL_DAYS * 86400;
        $saved = 0;
        for ($i = 0; $i < $maxWindows && $cursor < $now; $i++) {
            $end = min($cursor + self::WINDOW, $now);
            $saved += $this->fetch($cursor, $end, $zone);
            // Le standard ajoute une étape quand elle se termine : on repart un peu avant la fin de la fenêtre lue.
            $cursor = $end >= $now ? max($cursor, $end - self::OVERLAP) : $end;
            $this->config->setAppValue('callcrm', 'yeastar_cdr_cursor', (string)$cursor);
        }
        $this->config->setAppValue('callcrm', 'yeastar_cdr_error', '');
        return $saved;
    }

    private function fetch(int $from, int $to, \DateTimeZone $zone): int {
        // shortcut: dates au format système du standard (jj/mm/aaaa) et dans le fuseau du CRM ; à revoir si le standard change de format.
        $fmt = fn(int $t) => (new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('d/m/Y H:i:s');
        $saved = 0;
        for ($page = 1; $page <= 200; $page++) {
            $r = $this->client->call('GET', 'cdr/search', ['page' => $page, 'page_size' => 100, 'start_time' => $fmt($from), 'end_time' => $fmt($to)]);
            if ((string)($r['errcode'] ?? '') !== '0') {
                $this->config->setAppValue('callcrm', 'yeastar_cdr_error', 'Journal Yeastar : '.substr((string)($r['errmsg'] ?? '?'), 0, 80));
                throw new \RuntimeException('Journal Yeastar indisponible.');
            }
            $rows = is_array($r['data'] ?? null) ? $r['data'] : [];
            foreach ($rows as $row) $saved += $this->store($row);
            if (count($rows) < 100) break;
        }
        return $saved;
    }

    private function store(array $r): int {
        $int = static fn($v) => is_numeric($v) && (int)$v >= 0 && (int)$v <= 604800 ? (int)$v : null;
        $leg = (string)($r['new_id'] ?? '') ?: (string)($r['uid'] ?? '').'|'.(string)($r['call_to_number'] ?? '').'|'.(string)($r['timestamp'] ?? '');
        $call = (string)($r['call_id'] ?? '');
        if ($call === '' || strlen($call) > 128) return 0;
        $values = [
            'leg_key' => hash('sha256', $leg), 'call_id' => $call, 'call_type' => substr(strtolower((string)($r['call_type'] ?? '')), 0, 16),
            'at' => (int)($r['timestamp'] ?? 0), 'from_number' => substr(YeastarEvents::number((string)($r['call_from_number'] ?? '')), 0, 40),
            'to_number' => substr((string)($r['call_to_number'] ?? ''), 0, 40), 'to_label' => mb_substr((string)($r['call_to'] ?? ''), 0, 128),
            'disposition' => substr(strtoupper((string)($r['disposition'] ?? '')), 0, 32), 'ring_duration' => $int($r['ring_duration'] ?? null),
            'talk_duration' => $int($r['talk_duration'] ?? null), 'duration' => $int($r['duration'] ?? null),
        ];
        try {
            $q = $this->db->getQueryBuilder();
            $q->insert('callcrm_pbx_cdr');
            foreach ($values as $k => $v) $q->setValue($k, $q->createNamedParameter($v, is_int($v) ? IQueryBuilder::PARAM_INT : ($v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)));
            $q->executeStatement();
            return 1;
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) return 0;
            throw $e;
        }
    }

    /** Étapes connues pour ces appels, par identifiant d'appel. */
    public function legs(array $callIds): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($callIds))), 200) as $chunk) {
            $q = $this->db->getQueryBuilder();
            $rows = $q->select('call_id','call_type','at','from_number','to_number','to_label','disposition','ring_duration','talk_duration','duration')
                ->from('callcrm_pbx_cdr')->where($q->expr()->in('call_id', $q->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->orderBy('at','ASC')->addOrderBy('id','ASC')->executeQuery()->fetchAll();
            foreach ($rows as $row) $out[$row['call_id']][] = $row;
        }
        return $out;
    }

    /** Postes Yeastar → comptes CRM, d'après les réglages. */
    public function owners(): array {
        $map = [];
        foreach ($this->settings->settings()['extensions'] as $e) $map[(string)$e['extension']] = (string)$e['uid'];
        return $map;
    }

    /**
     * Classement d'un appel entrant à partir de ses étapes. Même forme que InboundOutcome::classify, avec un cas en plus :
     * « menu » = raccroché dans le message d'accueil, sans avoir atteint de file ni de poste.
     * Hors horaires = l'appel passe par le message de fermeture (entre 16 h 30 et 8 h 30, le standard y envoie les appels).
     * Retourne null s'il n'y a aucune étape entrante (le classement par événements prend alors le relais).
     */
    public static function classify(array $legs, array $closedNumbers, array $owners): ?array {
        $legs = array_values(array_filter($legs, fn($l) => ($l['call_type'] ?? '') === 'inbound'));
        if (!$legs) return null;
        $closed = $reached = false;
        $ring = null;
        $agents = $responders = [];
        foreach ($legs as $l) {
            $label = (string)$l['to_label'];
            $number = (string)$l['to_number'];
            $answered = $l['disposition'] === 'ANSWERED' && (int)($l['talk_duration'] ?? 0) > 0;
            if (str_starts_with($label, 'IVR')) {
                if (in_array($number, $closedNumbers, true) || in_array($label, $closedNumbers, true)) $closed = true;
                continue;
            }
            if ($label === 'Play Prompt' || str_starts_with($label, 'Voicemail')) continue;
            $isQueue = str_starts_with($label, 'Queue');
            if (!$isQueue && !preg_match('/^\d{1,10}$/D', $number)) continue;
            $reached = true;
            $seconds = $l['ring_duration'] ?? ($answered ? null : $l['duration']);
            if ($seconds !== null) $ring = max($ring ?? 0, (int)$seconds);
            if ($isQueue) continue;
            $uid = $owners[$number] ?? null;
            if ($uid !== null) $agents[$uid] = true;
            if ($answered) $responders[$uid ?? 'poste '.$number] = true;
        }
        $kind = match (true) {
            (bool)$responders => 'answered',
            $closed => 'closed',
            !$reached => 'menu',
            $ring !== null && $ring >= InboundOutcome::MIN_RING_SECONDS => 'missed',
            default => 'lost',
        };
        return ['kind' => $kind, 'agents' => array_keys($agents), 'responders' => array_values(array_filter(array_keys($responders), fn($u) => !str_starts_with((string)$u, 'poste '))),
            'seconds' => $ring, 'source' => 'cdr'];
    }
}
