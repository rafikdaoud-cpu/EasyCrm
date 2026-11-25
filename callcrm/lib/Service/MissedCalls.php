<?php
namespace OCA\CallCrm\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Appels entrants manqués à rappeler, regroupés par numéro (un « épisode » par numéro tant qu'il reste ouvert).
 * Alimenté par les détails d'appel Yeastar (30012) déjà enregistrés ; clos automatiquement quand le client est
 * rappelé et répond, quand il rappelle et obtient une réponse, ou quand un résultat d'appel est saisi sur sa fiche.
 */
class MissedCalls {
    /** Délai laissé aux différentes jambes d'un même appel pour arriver avant de le classer. */
    public const SETTLE = 120;
    /** Seuls les appels manqués des 4 dernières heures sont confiés automatiquement à un commercial en poste. */
    public const AUTO_WINDOW = 14400;
    public function __construct(private IDBConnection $db, private IConfig $config, private IGroupManager $groups,
        private IUserManager $users, private Presence $presence, private ILockingProvider $locks) {}

    /** 0770… / +213770… / 00213770… / 213770… → 0770… ; autre format : chiffres seuls. */
    public static function phone(string $raw): string {
        $d = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '00213')) $d = '0' . substr($d, 5);
        elseif (str_starts_with($d, '213') && strlen($d) === 12) $d = '0' . substr($d, 3);
        elseif (preg_match('/^00[567]\d{8}$/D',$d)) $d = substr($d,1);
        return substr($d, 0, 40);
    }

    /** Commerciaux (groupe des commerciaux). */
    public function commercials(): array {
        $g = $this->groups->get(Access::groups()['commercial']);
        $out = [];
        foreach ($g ? $g->getUsers() : [] as $u) $out[$u->getUID()] = $u->getDisplayName();
        return $out;
    }

    /** Met à jour les épisodes depuis les nouveaux détails d'appel ; sans effet si une autre requête le fait déjà. */
    public function sync(?int $now = null): void {
        $now = $now ?? time();
        try { $this->locks->acquireLock('callcrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE); }
        catch (LockedException $e) { return; }
        try {
            $last = (int)$this->config->getAppValue('callcrm', 'missed_last_id', '0');
            if ($last === 0) {
                // Première mise en service : on ne reprend que les 3 derniers jours.
                $q = $this->db->getQueryBuilder();
                $last = (int)$q->select($q->func()->max('id'))->from('callcrm_pbx_recordings')
                    ->where($q->expr()->lt('created_at', $q->createNamedParameter($now - 3 * 86400, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
            }
            $q = $this->db->getQueryBuilder();
            $rows = $q->select('id', 'pbx_url', 'call_id', 'phone', 'direction', 'call_status', 'prospect_id', 'created_at', 'user_id','extension')->from('callcrm_pbx_recordings')
                ->where($q->expr()->gt('id', $q->createNamedParameter($last, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->lt('created_at', $q->createNamedParameter($now - self::SETTLE, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')->setMaxResults(3000)->executeQuery()->fetchAll();
            $calls = [];
            $closed = InboundOutcome::closedDestinations();
            foreach ($rows as $r) {
                $k = hash('sha256', json_encode([$r['pbx_url'], $r['call_id'], $r['direction'], $r['phone']]));
                $c = $calls[$k] ?? ['phone' => self::phone((string)$r['phone']), 'direction' => $r['direction'], 'call_id' => $r['call_id'], 'at' => (int)$r['created_at'], 'answered' => false, 'pid' => null];
                $c['at'] = min($c['at'], (int)$r['created_at']);
                // Le statut ANSWERED seul sur une ligne IVR ne prouve aucun décroché commercial.
                $ok = strtoupper(trim((string)$r['call_status'])) === 'ANSWERED';
                $c['answered'] = $c['answered'] || ($ok && ($r['direction'] === 'outbound' || (string)$r['user_id'] !== ''));
                $c['closed'] = !empty($c['closed']) || in_array($r['extension'],$closed,true);
                $c['agent'] = !empty($c['agent']) || $r['user_id']!=='';
                if ($r['prospect_id']) $c['pid'] = (int)$r['prospect_id'];
                $calls[$k] = $c; $last = max($last, (int)$r['id']);
            }
            uasort($calls, fn($a, $b) => $a['at'] <=> $b['at']);
            $evidence = (new InboundOutcome($this->db))->events(array_column($calls,'call_id'));
            [$legs, $owners] = CallDashboard::journal(array_column($calls,'call_id'));
            foreach ($calls as $c) {
                if (strlen($c['phone']) < 6) continue;
                if ($c['direction'] === 'inbound') {
                    $result = YeastarCdr::classify($legs[$c['call_id']] ?? [],$closed,$owners)
                        ?? InboundOutcome::classify($evidence[$c['call_id']] ?? [],$c['phone'],$c['answered'],$c['closed']&&!$c['agent']);
                    if (in_array($result['kind'],['lost','menu','unknown','closed'],true)) continue;
                    $c['answered'] = $result['kind'] === 'answered';
                }
                $this->apply($c);
            }
            $this->resolveByCrmResults();
            $this->assign($now);
            $this->config->setAppValue('callcrm', 'missed_last_id', (string)$last);
        } finally {
            $this->locks->releaseLock('callcrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function open(string $phone): ?array {
        $q = $this->db->getQueryBuilder();
        return $q->select('*')->from('callcrm_missed')->where($q->expr()->eq('phone', $q->createNamedParameter($phone)))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('open')))->setMaxResults(1)->executeQuery()->fetch() ?: null;
    }
    private function update(int $id, array $values): void {
        $q = $this->db->getQueryBuilder(); $q->update('callcrm_missed');
        foreach ($values as $k => $v) $q->set($k, $q->createNamedParameter($v));
        $q->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    private function owner(?int $pid): ?string {
        if (!$pid) return null;
        $q = $this->db->getQueryBuilder();
        $r = $q->select('assigned_to')->from('callcrm_prospects')->where($q->expr()->eq('id', $q->createNamedParameter($pid, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
        return $r ?: null;
    }

    /** Applique un appel (entrant ou sortant) aux épisodes. */
    private function apply(array $c): void {
        $ep = $this->open($c['phone']);
        if ($c['direction'] === 'outbound') {
            if (!$ep || $c['at'] < (int)$ep['first_at']) return;
            $v = ['attempts' => (int)$ep['attempts'] + 1, 'updated_at' => $c['at']];
            if (!(int)$ep['first_attempt_at']) $v['first_attempt_at'] = $c['at'];
            if ($c['answered']) $v += ['state' => 'done', 'done_at' => $c['at'], 'done_how' => 'callback'];
            $this->update((int)$ep['id'], $v);
            return;
        }
        if ($c['answered']) {
            if ($ep && $c['at'] >= (int)$ep['first_at']) $this->update((int)$ep['id'], ['state' => 'done', 'done_at' => $c['at'], 'done_how' => 'client_recalled', 'updated_at' => $c['at']]);
            return;
        }
        if ($ep) {
            $v = ['count' => (int)$ep['count'] + 1, 'last_at' => max((int)$ep['last_at'], $c['at']), 'updated_at' => $c['at']];
            if (!$ep['prospect_id'] && $c['pid']) $v['prospect_id'] = $c['pid'];
            $this->update((int)$ep['id'], $v);
            return;
        }
        $q = $this->db->getQueryBuilder(); $q->insert('callcrm_missed');
        $owner = $this->owner($c['pid']);
        foreach (['phone' => $c['phone'], 'first_at' => $c['at'], 'last_at' => $c['at'], 'count' => 1, 'prospect_id' => $c['pid'],
            'assigned_to' => $owner, 'assigned_at' => $owner ? $c['at'] : 0, 'state' => 'open', 'attempts' => 0, 'first_attempt_at' => 0,
            'done_at' => 0, 'done_by' => '', 'done_how' => '', 'updated_at' => $c['at']] as $k => $v) $q->setValue($k, $q->createNamedParameter($v));
        $q->executeStatement();
    }

    /** Un résultat d'appel saisi sur la fiche après l'appel manqué vaut traitement. */
    private function resolveByCrmResults(): void {
        $q = $this->db->getQueryBuilder();
        $open = $q->select('id', 'prospect_id', 'first_at', 'first_attempt_at')->from('callcrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
            ->andWhere($q->expr()->isNotNull('prospect_id'))->executeQuery()->fetchAll();
        foreach ($open as $ep) {
            $q = $this->db->getQueryBuilder();
            $r = $q->select('created_at', 'user_id')->from('callcrm_calls')->where($q->expr()->eq('prospect_id', $q->createNamedParameter((int)$ep['prospect_id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->gte('created_at', $q->createNamedParameter((int)$ep['first_at'], IQueryBuilder::PARAM_INT)))
                ->orderBy('created_at', 'ASC')->setMaxResults(1)->executeQuery()->fetch();
            if (!$r) continue;
            $v = ['state' => 'done', 'done_at' => (int)$r['created_at'], 'done_how' => 'crm_result', 'done_by' => (string)($r['user_id'] ?? ''), 'updated_at' => (int)$r['created_at']];
            if (!(int)$ep['first_attempt_at']) $v['first_attempt_at'] = (int)$r['created_at'];
            $this->update((int)$ep['id'], $v);
        }
    }

    /** Épisodes sans commercial : commercial du client, sinon commercial en poste le moins chargé. */
    private function assign(int $now): void {
        $q = $this->db->getQueryBuilder();
        $todo = $q->select('id', 'prospect_id', 'last_at')->from('callcrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
            ->andWhere($q->expr()->isNull('assigned_to'))->orderBy('first_at', 'ASC')->executeQuery()->fetchAll();
        if (!$todo) return;
        $online = [];
        foreach (array_keys($this->commercials()) as $uid) if (($this->presence->state($uid, $now)['status'] ?? '') === 'online') $online[$uid] = 0;
        if ($online) {
            $q = $this->db->getQueryBuilder();
            foreach ($q->select('assigned_to')->selectAlias($q->func()->count('*'), 'n')->from('callcrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
                ->andWhere($q->expr()->in('assigned_to', $q->createNamedParameter(array_keys($online), IQueryBuilder::PARAM_STR_ARRAY)))->groupBy('assigned_to')->executeQuery()->fetchAll() as $r) $online[$r['assigned_to']] = (int)$r['n'];
        }
        foreach ($todo as $ep) {
            $uid = $this->owner($ep['prospect_id'] ? (int)$ep['prospect_id'] : null);
            // Répartition automatique seulement pour les appels récents ; l'arriéré reste « à prendre » pour les responsables.
            if (!$uid && $online && (int)$ep['last_at'] >= $now - self::AUTO_WINDOW) { asort($online); $uid = array_key_first($online); }
            if (!$uid) continue;
            if (isset($online[$uid])) $online[$uid]++;
            $this->update((int)$ep['id'], ['assigned_to' => $uid, 'assigned_at' => $now]);
        }
    }

    public function find(int $id): ?array {
        $q = $this->db->getQueryBuilder();
        return $q->select('*')->from('callcrm_missed')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch() ?: null;
    }

    /** Épisodes ouverts. $uid : ceux du commercial + ceux sans commercial ; null : tous. */
    public function openList(?string $uid): array {
        $q = $this->db->getQueryBuilder();
        $q->select('m.*')->selectAlias('p.lastname', 'p_lastname')->selectAlias('p.firstname', 'p_firstname')->selectAlias('p.assigned_to', 'p_owner')
            ->from('callcrm_missed', 'm')->leftJoin('m', 'callcrm_prospects', 'p', $q->expr()->eq('m.prospect_id', 'p.id'))
            ->where($q->expr()->eq('m.state', $q->createNamedParameter('open')));
        if ($uid !== null) $q->andWhere($q->expr()->orX($q->expr()->eq('m.assigned_to', $q->createNamedParameter($uid)), $q->expr()->isNull('m.assigned_to')));
        else TeamScope::prospects($q, 'm.assigned_to');
        $rows = $q->orderBy('m.last_at', 'DESC')->setMaxResults(500)->executeQuery()->fetchAll();
        $names = $this->commercials();
        $durations = $this->lastDurations($rows);
        return array_map(function ($r) use ($uid, $names, $durations) {
            // Le nom n'est montré que pour une fiche que l'utilisateur peut ouvrir.
            $visible = $r['prospect_id'] && ($uid === null || $r['p_owner'] === $uid || $r['p_owner'] === null);
            return ['id' => (int)$r['id'], 'phone' => $r['phone'], 'count' => (int)$r['count'], 'first_at' => (int)$r['first_at'], 'last_at' => (int)$r['last_at'],
                'last_duration' => $durations[(int)$r['last_at']][$r['phone']] ?? null,
                'attempts' => (int)$r['attempts'], 'first_attempt_at' => (int)$r['first_attempt_at'],
                'prospect_id' => $visible ? (int)$r['prospect_id'] : null, 'name' => $visible ? trim(($r['p_lastname'] ?? '') . ' ' . ($r['p_firstname'] ?? '')) : '',
                'assigned_to' => $r['assigned_to'], 'assigned_name' => $r['assigned_to'] ? ($names[$r['assigned_to']] ?? $this->users->getDisplayName($r['assigned_to']) ?? $r['assigned_to']) : ''];
        }, $rows);
    }

    /** Look up the latest missed call in the stored CDRs, including episodes from older versions. */
    private function lastDurations(array $rows): array {
        $wanted = $calls = $durations = [];
        foreach ($rows as $row) $wanted[(int)$row['last_at']][$row['phone']] = true;
        foreach (array_chunk(array_keys($wanted), 100) as $timestamps) {
            $q = $this->db->getQueryBuilder();
            $records = $q->select('created_at', 'phone', 'call_id', 'pbx_url', 'call_duration')->from('callcrm_pbx_recordings')
                ->where($q->expr()->in('created_at', $q->createNamedParameter($timestamps, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($q->expr()->eq('direction', $q->createNamedParameter('inbound')))
                ->executeQuery()->fetchAll();
            foreach ($records as $record) {
                $at = (int)$record['created_at'];
                $phone = self::phone($record['phone']);
                if (!isset($wanted[$at][$phone])) continue;
                $key = json_encode([$record['pbx_url'], $record['call_id'], $record['phone']]);
                $calls[$at][$phone][$key] ??= null;
                if ($record['call_duration'] !== null) {
                    $calls[$at][$phone][$key] = max($calls[$at][$phone][$key] ?? 0, (int)$record['call_duration']);
                }
            }
        }
        foreach ($calls as $at => $numbers) {
            foreach ($numbers as $phone => $matches) {
                if (count($matches) === 1) $durations[$at][$phone] = reset($matches);
            }
        }
        return $durations;
    }

    public function take(int $id, string $uid): bool {
        $q = $this->db->getQueryBuilder();
        return $q->update('callcrm_missed')->set('assigned_to', $q->createNamedParameter($uid))->set('assigned_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->isNull('assigned_to'))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('open')))->executeStatement() === 1;
    }
    public function reassign(int $id, ?string $uid): void {
        $this->update($id, ['assigned_to' => $uid, 'assigned_at' => $uid ? time() : 0]);
    }
    public function close(int $id, string $uid, string $how): void {
        if (!in_array($how, ['manual_called', 'manual_wrong', 'manual_other'], true)) throw new \InvalidArgumentException('Motif de clôture invalide.');
        $ep = $this->find($id);
        if (!$ep || $ep['state'] !== 'open') throw new \InvalidArgumentException('Appel manqué déjà traité ou introuvable.');
        $this->update($id, ['state' => 'done', 'done_at' => time(), 'done_by' => $uid, 'done_how' => $how, 'updated_at' => time()]);
    }

    /** Indicateurs depuis $since : épisodes, rappelés, délai moyen avant première tentative, encore ouverts. */
    public function stats(int $since, ?string $uid = null): array {
        $q = $this->db->getQueryBuilder();
        $q->select('state', 'first_at', 'first_attempt_at', 'done_how', 'assigned_to')->from('callcrm_missed')->where($q->expr()->gte('first_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        if ($uid !== null) $q->andWhere($q->expr()->eq('assigned_to', $q->createNamedParameter($uid)));
        else TeamScope::prospects($q);
        $n = 0; $done = 0; $open = 0; $delays = []; $by = [];
        foreach ($q->executeQuery()->fetchAll() as $r) {
            $n++; $r['state'] === 'open' ? $open++ : $done++;
            $by[$r['done_how'] ?: 'open'] = ($by[$r['done_how'] ?: 'open'] ?? 0) + 1;
            if ((int)$r['first_attempt_at']) $delays[] = (int)$r['first_attempt_at'] - (int)$r['first_at'];
        }
        sort($delays);
        return ['missed' => $n, 'done' => $done, 'open' => $open, 'by' => $by, 'attempted' => count($delays),
            'avg_delay' => $delays ? (int)round(array_sum($delays) / count($delays)) : null, 'median_delay' => $delays ? $delays[intdiv(count($delays), 2)] : null];
    }

    public function openCount(string $uid): int {
        $q = $this->db->getQueryBuilder();
        return (int)$q->select($q->func()->count('*'))->from('callcrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
            ->andWhere($q->expr()->eq('assigned_to', $q->createNamedParameter($uid)))->executeQuery()->fetchOne();
    }
}
