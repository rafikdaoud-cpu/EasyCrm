<?php
namespace OCA\EasyCrm\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Appels entrants manqués à rappeler, regroupés par numéro (un « épisode » par numéro tant qu'il reste ouvert).
 * Alimenté par les événements de fin d'appel du standard (webhook /api/hook/pbx, tout PBX) ; clos automatiquement
 * quand le client est rappelé et répond, quand il rappelle et obtient une réponse, ou quand un résultat est saisi sur sa fiche.
 */
class MissedCalls {
    /** Seuls les appels manqués des 4 dernières heures sont confiés automatiquement à un commercial en poste. */
    public const AUTO_WINDOW = 14400;
    public function __construct(private IDBConnection $db, private IConfig $config, private IGroupManager $groups,
        private IUserManager $users, private Presence $presence, private ILockingProvider $locks) {}

    /** 0770… / +213770… / 00213770… / 213770… → 0770… ; autre format : chiffres seuls. */
    public static function phone(string $raw): string {
        $d = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '00213')) $d = '0' . substr($d, 5);
        elseif (str_starts_with($d, '213') && strlen($d) === 12) $d = '0' . substr($d, 3);
        return substr($d, 0, 40);
    }

    /** Commerciaux (groupe des commerciaux). */
    public function commercials(): array {
        $g = $this->groups->get(Access::groups()['commercial']);
        $out = [];
        foreach ($g ? $g->getUsers() : [] as $u) $out[$u->getUID()] = $u->getDisplayName();
        return $out;
    }

    /**
     * Événement de fin d'appel du standard : ['number'=>chiffres, 'inbound'=>bool, 'answered'=>bool, 'pid'=>?int, 'at'=>?int].
     * Les numéros internes (moins de 6 chiffres) sont ignorés.
     */
    public function event(array $e): void {
        $phone = self::phone((string)($e['number'] ?? ''));
        if (strlen($phone) < 6) return;
        try { $this->locks->acquireLock('easycrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE); }
        catch (LockedException $x) { usleep(200000); try { $this->locks->acquireLock('easycrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE); } catch (LockedException $y) { return; } }
        try {
            $this->apply(['phone' => $phone, 'direction' => !empty($e['inbound']) ? 'inbound' : 'outbound', 'at' => (int)($e['at'] ?? time()),
                'answered' => !empty($e['answered']), 'pid' => isset($e['pid']) && $e['pid'] ? (int)$e['pid'] : null]);
        } finally { $this->locks->releaseLock('easycrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE); }
    }
    /** Clôture par résultat saisi et attribution des appels sans commercial (à chaque consultation et par la tâche de fond). */
    public function sync(?int $now = null): void {
        $now = $now ?? time();
        try { $this->locks->acquireLock('easycrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE); }
        catch (LockedException $e) { return; }
        try { $this->resolveByCrmResults(); $this->assign($now); }
        finally { $this->locks->releaseLock('easycrm/missed-sync', ILockingProvider::LOCK_EXCLUSIVE); }
    }

    private function open(string $phone): ?array {
        $q = $this->db->getQueryBuilder();
        return $q->select('*')->from('easycrm_missed')->where($q->expr()->eq('phone', $q->createNamedParameter($phone)))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('open')))->setMaxResults(1)->executeQuery()->fetch() ?: null;
    }
    private function update(int $id, array $values): void {
        $q = $this->db->getQueryBuilder(); $q->update('easycrm_missed');
        foreach ($values as $k => $v) $q->set($k, $q->createNamedParameter($v));
        $q->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    private function owner(?int $pid): ?string {
        if (!$pid) return null;
        $q = $this->db->getQueryBuilder();
        $r = $q->select('assigned_to')->from('easycrm_prospects')->where($q->expr()->eq('id', $q->createNamedParameter($pid, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
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
        $q = $this->db->getQueryBuilder(); $q->insert('easycrm_missed');
        $owner = $this->owner($c['pid']);
        foreach (['phone' => $c['phone'], 'first_at' => $c['at'], 'last_at' => $c['at'], 'count' => 1, 'prospect_id' => $c['pid'],
            'assigned_to' => $owner, 'assigned_at' => $owner ? $c['at'] : 0, 'state' => 'open', 'attempts' => 0, 'first_attempt_at' => 0,
            'done_at' => 0, 'done_by' => '', 'done_how' => '', 'updated_at' => $c['at']] as $k => $v) $q->setValue($k, $q->createNamedParameter($v));
        $q->executeStatement();
    }

    /** Un résultat d'appel saisi sur la fiche après l'appel manqué vaut traitement. */
    private function resolveByCrmResults(): void {
        $q = $this->db->getQueryBuilder();
        $open = $q->select('id', 'prospect_id', 'first_at', 'first_attempt_at')->from('easycrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
            ->andWhere($q->expr()->isNotNull('prospect_id'))->executeQuery()->fetchAll();
        foreach ($open as $ep) {
            $q = $this->db->getQueryBuilder();
            $r = $q->select('created_at', 'user_id')->from('easycrm_calls')->where($q->expr()->eq('prospect_id', $q->createNamedParameter((int)$ep['prospect_id'], IQueryBuilder::PARAM_INT)))
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
        $todo = $q->select('id', 'prospect_id', 'last_at')->from('easycrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
            ->andWhere($q->expr()->isNull('assigned_to'))->orderBy('first_at', 'ASC')->executeQuery()->fetchAll();
        if (!$todo) return;
        $online = [];
        foreach (array_keys($this->commercials()) as $uid) if (($this->presence->state($uid, $now)['status'] ?? '') === 'online') $online[$uid] = 0;
        if ($online) {
            $q = $this->db->getQueryBuilder();
            foreach ($q->select('assigned_to')->selectAlias($q->func()->count('*'), 'n')->from('easycrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
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
        return $q->select('*')->from('easycrm_missed')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch() ?: null;
    }

    /** Épisodes ouverts. $uid : ceux du commercial + ceux sans commercial ; null : tous. */
    public function openList(?string $uid): array {
        $q = $this->db->getQueryBuilder();
        $q->select('m.*')->selectAlias('p.lastname', 'p_lastname')->selectAlias('p.firstname', 'p_firstname')->selectAlias('p.assigned_to', 'p_owner')
            ->from('easycrm_missed', 'm')->leftJoin('m', 'easycrm_prospects', 'p', $q->expr()->eq('m.prospect_id', 'p.id'))
            ->where($q->expr()->eq('m.state', $q->createNamedParameter('open')));
        if ($uid !== null) $q->andWhere($q->expr()->orX($q->expr()->eq('m.assigned_to', $q->createNamedParameter($uid)), $q->expr()->isNull('m.assigned_to')));
        $rows = $q->orderBy('m.last_at', 'DESC')->setMaxResults(500)->executeQuery()->fetchAll();
        $names = $this->commercials();
        return array_map(function ($r) use ($uid, $names) {
            // Le nom n'est montré que pour une fiche que l'utilisateur peut ouvrir.
            $visible = $r['prospect_id'] && ($uid === null || $r['p_owner'] === $uid || $r['p_owner'] === null);
            return ['id' => (int)$r['id'], 'phone' => $r['phone'], 'count' => (int)$r['count'], 'first_at' => (int)$r['first_at'], 'last_at' => (int)$r['last_at'],
                'attempts' => (int)$r['attempts'], 'first_attempt_at' => (int)$r['first_attempt_at'],
                'prospect_id' => $visible ? (int)$r['prospect_id'] : null, 'name' => $visible ? trim(($r['p_lastname'] ?? '') . ' ' . ($r['p_firstname'] ?? '')) : '',
                'assigned_to' => $r['assigned_to'], 'assigned_name' => $r['assigned_to'] ? ($names[$r['assigned_to']] ?? $this->users->getDisplayName($r['assigned_to']) ?? $r['assigned_to']) : ''];
        }, $rows);
    }

    public function take(int $id, string $uid): bool {
        $q = $this->db->getQueryBuilder();
        return $q->update('easycrm_missed')->set('assigned_to', $q->createNamedParameter($uid))->set('assigned_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
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
        $q->select('state', 'first_at', 'first_attempt_at', 'done_how', 'assigned_to')->from('easycrm_missed')->where($q->expr()->gte('first_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        if ($uid !== null) $q->andWhere($q->expr()->eq('assigned_to', $q->createNamedParameter($uid)));
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
        return (int)$q->select($q->func()->count('*'))->from('easycrm_missed')->where($q->expr()->eq('state', $q->createNamedParameter('open')))
            ->andWhere($q->expr()->eq('assigned_to', $q->createNamedParameter($uid)))->executeQuery()->fetchOne();
    }
}
