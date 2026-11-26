<?php

declare(strict_types=1);

namespace OCA\Reclamations\Service;

use OCA\Reclamations\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;

class ReclamationService {

    public const TYPES = [
        'autre' => 'Autre',
        'erreur_produit' => 'Erreur produit livré/commandé',
        'panne_sav' => 'Panne / SAV',
        'endommage' => 'Produit endommagé',
        'non_conforme' => 'Produit non conforme',
        'retard' => 'Retard de livraison',
    ];

    public const URGENCIES = ['faible' => 'Faible', 'moyenne' => 'Moyenne', 'haute' => 'Haute'];

    public const STATUSES = [
        'nouveau' => 'Nouveau',
        'qualifiee' => 'Qualifiée',
        'en_cours' => 'En cours de traitement',
        'en_attente' => 'En attente',
        'resolu' => 'Résolu',
        'cloture' => 'Clôturé',
        'rejetee' => 'Rejetée',
    ];

    /** Transitions autorisées : statut => [statuts cibles] */
    public const TRANSITIONS = [
        'nouveau' => ['qualifiee', 'rejetee'],
        'qualifiee' => ['en_cours', 'rejetee'],
        'en_cours' => ['en_attente', 'resolu'],
        'en_attente' => ['en_cours'],
        'resolu' => ['cloture', 'en_cours'],
        'rejetee' => ['nouveau'],
        'cloture' => [],
    ];

    public const CHANNELS = [
        'telephone' => 'Téléphone', 'magasin' => 'Magasin / showroom', 'email' => 'Email',
        'reseaux' => 'Réseaux sociaux', 'whatsapp' => 'WhatsApp', 'autre' => 'Autre',
    ];

    public const SERVICES = [
        'sav' => 'SAV / Technique', 'logistique' => 'Logistique / Livraison',
        'commercial' => 'Commercial', 'achats' => 'Achats / Fournisseur', 'qualite' => 'Qualité',
    ];

    public const SOLUTIONS = [
        'reparation' => 'Réparation', 'echange' => 'Échange produit', 'avoir' => 'Avoir',
        'remboursement' => 'Remboursement', 'livraison' => 'Livraison effectuée / relivraison',
        'geste' => 'Geste commercial', 'aucune' => 'Aucune action (explication client)',
    ];

    public const ROOT_CAUSES = [
        'fournisseur' => 'Défaut fournisseur / fabrication', 'transport' => 'Transporteur / livraison',
        'preparation' => 'Erreur de préparation / stock', 'saisie' => 'Erreur de saisie commande',
        'installation' => 'Installation / montage', 'usage' => 'Mauvaise utilisation client',
        'autre' => 'Autre',
    ];

    /** Délais en heures par urgence : [prise en charge, résolution] */
    public const SLA_HOURS = [
        'haute' => [4, 48],
        'moyenne' => [24, 120],
        'faible' => [48, 240],
    ];

    public const OPEN_STATUSES = ['nouveau', 'qualifiee', 'en_cours', 'en_attente', 'resolu'];

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private INotificationManager $notificationManager,
    ) {
    }

    public function meta(): array {
        return [
            'types' => self::TYPES,
            'urgencies' => self::URGENCIES,
            'statuses' => self::STATUSES,
            'transitions' => self::TRANSITIONS,
            'channels' => self::CHANNELS,
            'services' => self::SERVICES,
            'solutions' => self::SOLUTIONS,
            'rootCauses' => self::ROOT_CAUSES,
            'sla' => self::SLA_HOURS,
        ];
    }

    public function isManager(string $uid): bool {
        return $this->groupManager->isAdmin($uid) || $this->groupManager->isInGroup($uid, Application::MANAGER_GROUP);
    }

    /** Utilisateurs proposés à l'affectation */
    public function assignableUsers(): array {
        $users = [];
        $group = $this->groupManager->get(Application::ASSIGN_GROUP);
        $list = $group !== null ? $group->getUsers() : $this->userManager->search('', 500);
        foreach ($list as $u) {
            if ($u->isEnabled()) {
                $users[] = ['id' => $u->getUID(), 'name' => $u->getDisplayName()];
            }
        }
        usort($users, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $users;
    }

    // ------------------------------------------------------------------ lecture

    public function list(array $f, string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_items')->orderBy('created_at', 'DESC')->setMaxResults(2000);

        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($f['status'])));
        }
        if (!empty($f['open'])) {
            $qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter(self::OPEN_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
        }
        if (!empty($f['mine'])) {
            $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($uid)));
        }
        if (!empty($f['type']) && isset(self::TYPES[$f['type']])) {
            $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($f['type'])));
        }
        if (!empty($f['urgency']) && isset(self::URGENCIES[$f['urgency']])) {
            $qb->andWhere($qb->expr()->eq('urgency', $qb->createNamedParameter($f['urgency'])));
        }
        if (!empty($f['q'])) {
            $like = '%' . $this->db->escapeLikeParameter(trim((string)$f['q'])) . '%';
            $conds = [];
            foreach (['ref', 'client_name', 'client_phone', 'dossier_no', 'product'] as $col) {
                $conds[] = $qb->expr()->iLike($col, $qb->createNamedParameter($like));
            }
            $qb->andWhere($qb->expr()->orX(...$conds));
        }

        $res = $qb->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $this->decorate($row);
        }
        $res->closeCursor();

        if (!empty($f['late'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['late']));
        }
        return $rows;
    }

    public function get(int $id): array {
        $row = $this->fetchRow($id);
        $item = $this->decorate($row);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_history')
            ->where($qb->expr()->eq('item_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC');
        $res = $qb->executeQuery();
        $item['history'] = [];
        while ($h = $res->fetch()) {
            $h['user_name'] = $this->displayName($h['user_id']);
            $h['created_at'] = (int)$h['created_at'];
            $item['history'][] = $h;
        }
        $res->closeCursor();

        $item['attachments'] = $this->listFiles($id);
        return $item;
    }

    public function fetchRow(int $id): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_items')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if (!$row) {
            throw new NotFoundException('Réclamation introuvable');
        }
        return $row;
    }

    private function decorate(array $r): array {
        $now = time();
        foreach (['id', 'created_at', 'updated_at', 'qualified_at', 'resolved_at', 'closed_at', 'take_due_at', 'due_at', 'paused_since', 'satisfaction', 'reopen_count'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        }
        $r['cost'] = $r['cost'] === null ? null : (float)$r['cost'];
        $r['assigned_name'] = $r['assigned_to'] ? $this->displayName($r['assigned_to']) : null;
        $r['created_by_name'] = $this->displayName($r['created_by']);

        $late = false;
        $lateTake = false;
        if ($r['status'] === 'nouveau' && $r['take_due_at'] && $now > $r['take_due_at']) {
            $lateTake = true;
        }
        if (in_array($r['status'], ['nouveau', 'qualifiee', 'en_cours'], true) && $r['due_at'] && $now > $r['due_at']) {
            $late = true;
        }
        $r['late_take'] = $lateTake;
        $r['late'] = $late || $lateTake;
        // temps restant (secondes), figé pendant l'attente
        if ($r['due_at'] && in_array($r['status'], ['nouveau', 'qualifiee', 'en_cours', 'en_attente'], true)) {
            $ref = $r['status'] === 'en_attente' && $r['paused_since'] ? $r['paused_since'] : $now;
            $r['remaining'] = $r['due_at'] - $ref;
        } else {
            $r['remaining'] = null;
        }
        $r['allowed'] = self::TRANSITIONS[$r['status']] ?? [];
        return $r;
    }

    private function displayName(?string $uid): ?string {
        if ($uid === null || $uid === '') {
            return null;
        }
        return $this->userManager->getDisplayName($uid) ?? $uid;
    }

    // ------------------------------------------------------------------ écriture

    public function create(array $d, string $uid): array {
        $clean = $this->validate($d, true);
        $now = time();
        $sla = self::SLA_HOURS[$clean['urgency']];

        $qb = $this->db->getQueryBuilder();
        $qb->insert('recl_items')->values([
            'client_name' => $qb->createNamedParameter($clean['client_name']),
            'client_phone' => $qb->createNamedParameter($clean['client_phone'] ?? null),
            'dossier_no' => $qb->createNamedParameter($clean['dossier_no'] ?? null),
            'product' => $qb->createNamedParameter($clean['product'] ?? null),
            'type' => $qb->createNamedParameter($clean['type']),
            'urgency' => $qb->createNamedParameter($clean['urgency']),
            'channel' => $qb->createNamedParameter($clean['channel'] ?? null),
            'description' => $qb->createNamedParameter($clean['description'] ?? null),
            'service' => $qb->createNamedParameter($clean['service'] ?? null),
            'assigned_to' => $qb->createNamedParameter($clean['assigned_to'] ?? null),
            'status' => $qb->createNamedParameter('nouveau'),
            'created_by' => $qb->createNamedParameter($uid),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'take_due_at' => $qb->createNamedParameter($now + $sla[0] * 3600, IQueryBuilder::PARAM_INT),
            'due_at' => $qb->createNamedParameter($now + $sla[1] * 3600, IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();
        $id = $qb->getLastInsertId();

        $ref = sprintf('REC-%s-%04d', date('Y', $now), $id);
        $u = $this->db->getQueryBuilder();
        $u->update('recl_items')->set('ref', $u->createNamedParameter($ref))
            ->where($u->expr()->eq('id', $u->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $u->executeStatement();

        $this->log($id, $uid, 'create', null, 'nouveau', 'Réclamation ouverte');
        if (!empty($clean['assigned_to']) && $clean['assigned_to'] !== $uid) {
            $this->notify($clean['assigned_to'], 'assigned', $id, $ref, $uid);
        }
        return $this->get($id);
    }

    public function update(int $id, array $d, string $uid): array {
        $row = $this->fetchRow($id);
        if ($row['status'] === 'cloture' && !$this->isManager($uid)) {
            throw new ValidationException('Réclamation clôturée : seuls les responsables peuvent la modifier');
        }
        $clean = $this->validate($d, false);
        if (!$clean) {
            return $this->get($id);
        }

        // Changement d'urgence : recalcul des échéances depuis la création
        if (isset($clean['urgency']) && $clean['urgency'] !== $row['urgency']) {
            $sla = self::SLA_HOURS[$clean['urgency']];
            $clean['take_due_at'] = (int)$row['created_at'] + $sla[0] * 3600;
            $clean['due_at'] = (int)$row['created_at'] + $sla[1] * 3600;
            $clean['sla_notified'] = 0;
        }

        $changes = [];
        $labels = [
            'client_name' => 'Client', 'client_phone' => 'Téléphone', 'dossier_no' => 'N° dossier', 'product' => 'Produit',
            'type' => 'Type', 'urgency' => 'Urgence', 'channel' => 'Canal', 'description' => 'Description',
            'service' => 'Service', 'assigned_to' => 'Responsable', 'root_cause' => 'Cause racine', 'cost' => 'Coût',
            'solution' => 'Solution', 'solution_detail' => 'Détail solution', 'satisfaction' => 'Satisfaction',
        ];
        $qb = $this->db->getQueryBuilder();
        $qb->update('recl_items');
        foreach ($clean as $k => $v) {
            $type = is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
            $qb->set($k, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : $type));
            if (isset($labels[$k]) && (string)($row[$k] ?? '') !== (string)($v ?? '')) {
                $changes[] = $labels[$k];
            }
        }
        $qb->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        if ($changes) {
            $this->log($id, $uid, 'edit', null, null, 'Modifié : ' . implode(', ', $changes));
        }
        if (array_key_exists('assigned_to', $clean) && $clean['assigned_to'] && $clean['assigned_to'] !== $row['assigned_to'] && $clean['assigned_to'] !== $uid) {
            $this->notify($clean['assigned_to'], 'assigned', $id, (string)$row['ref'], $uid);
        }
        return $this->get($id);
    }

    public function transition(int $id, array $d, string $uid): array {
        $row = $this->fetchRow($id);
        $from = $row['status'];
        $to = (string)($d['to'] ?? '');
        $comment = trim((string)($d['comment'] ?? ''));

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ValidationException(sprintf('Passage de « %s » à « %s » non autorisé', self::STATUSES[$from] ?? $from, self::STATUSES[$to] ?? $to));
        }

        $now = time();
        $set = ['status' => $to];
        $msg = $comment;

        switch ($to) {
            case 'qualifiee':
                $assignee = trim((string)($d['assigned_to'] ?? $row['assigned_to'] ?? ''));
                if ($assignee === '' || !$this->userManager->userExists($assignee)) {
                    throw new ValidationException('Affectez un responsable pour qualifier la réclamation');
                }
                $set['assigned_to'] = $assignee;
                if (!empty($d['service']) && isset(self::SERVICES[$d['service']])) {
                    $set['service'] = $d['service'];
                }
                if (!empty($d['urgency']) && isset(self::URGENCIES[$d['urgency']]) && $d['urgency'] !== $row['urgency']) {
                    $sla = self::SLA_HOURS[$d['urgency']];
                    $set['urgency'] = $d['urgency'];
                    $set['take_due_at'] = (int)$row['created_at'] + $sla[0] * 3600;
                    $set['due_at'] = (int)$row['created_at'] + $sla[1] * 3600;
                }
                $set['qualified_at'] = $now;
                break;

            case 'rejetee':
                $reason = trim((string)($d['reject_reason'] ?? $comment));
                if ($reason === '') {
                    throw new ValidationException('Le motif de rejet est obligatoire');
                }
                $set['reject_reason'] = $reason;
                $set['closed_at'] = $now;
                $msg = 'Motif : ' . $reason;
                break;

            case 'en_attente':
                $wait = trim((string)($d['wait_reason'] ?? $comment));
                if ($wait === '') {
                    throw new ValidationException('Indiquez ce qui est attendu (pièce, client, fournisseur…)');
                }
                $set['wait_reason'] = $wait;
                $set['paused_since'] = $now;
                $msg = 'En attente : ' . $wait;
                break;

            case 'en_cours':
                if ($from === 'en_attente' && $row['paused_since']) {
                    $paused = $now - (int)$row['paused_since'];
                    $set['due_at'] = (int)$row['due_at'] + $paused;
                    $set['paused_since'] = null;
                    $set['wait_reason'] = null;
                    $msg = trim('Reprise après ' . $this->humanDuration($paused) . ' d\'attente. ' . $comment);
                } elseif ($from === 'resolu') {
                    if ($comment === '') {
                        throw new ValidationException('Indiquez pourquoi la réclamation est réouverte');
                    }
                    $set['reopen_count'] = (int)$row['reopen_count'] + 1;
                    $set['resolved_at'] = null;
                    $set['sla_notified'] = 0;
                    $msg = 'Réouverture : ' . $comment;
                }
                break;

            case 'resolu':
                $solution = (string)($d['solution'] ?? $row['solution'] ?? '');
                if (!isset(self::SOLUTIONS[$solution])) {
                    throw new ValidationException('Choisissez la solution apportée');
                }
                $set['solution'] = $solution;
                if (isset($d['solution_detail'])) {
                    $set['solution_detail'] = trim((string)$d['solution_detail']);
                }
                if (!empty($d['root_cause']) && isset(self::ROOT_CAUSES[$d['root_cause']])) {
                    $set['root_cause'] = $d['root_cause'];
                }
                if (isset($d['cost']) && $d['cost'] !== '') {
                    $set['cost'] = (float)str_replace(',', '.', (string)$d['cost']);
                }
                $set['resolved_at'] = $now;
                $msg = trim('Solution : ' . self::SOLUTIONS[$solution] . '. ' . ($set['solution_detail'] ?? '') . ' ' . $comment);
                break;

            case 'cloture':
                if (isset($d['satisfaction']) && $d['satisfaction'] !== '') {
                    $s = (int)$d['satisfaction'];
                    if ($s >= 1 && $s <= 5) {
                        $set['satisfaction'] = $s;
                    }
                }
                $set['closed_at'] = $now;
                break;

            case 'nouveau':
                $set['reject_reason'] = null;
                $set['closed_at'] = null;
                $msg = trim('Réexamen. ' . $comment);
                break;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('recl_items');
        foreach ($set as $k => $v) {
            if ($v === null) {
                $qb->set($k, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
            } elseif (is_int($v)) {
                $qb->set($k, $qb->createNamedParameter($v, IQueryBuilder::PARAM_INT));
            } else {
                $qb->set($k, $qb->createNamedParameter($v));
            }
        }
        $qb->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        $this->log($id, $uid, 'status', $from, $to, $msg !== '' ? $msg : null);

        // Notifications
        $ref = (string)$row['ref'];
        $assignee = $set['assigned_to'] ?? $row['assigned_to'];
        if ($to === 'qualifiee' && $assignee && $assignee !== $uid) {
            $this->notify($assignee, 'assigned', $id, $ref, $uid);
        }
        if (in_array($to, ['resolu', 'rejetee'], true) && $row['created_by'] !== $uid) {
            $this->notify($row['created_by'], 'status_' . $to, $id, $ref, $uid);
        }
        if ($to === 'en_cours' && $from === 'resolu' && $assignee && $assignee !== $uid) {
            $this->notify($assignee, 'reopened', $id, $ref, $uid);
        }

        return $this->get($id);
    }

    public function comment(int $id, string $message, string $uid): array {
        $row = $this->fetchRow($id);
        $message = trim($message);
        if ($message === '') {
            throw new ValidationException('Commentaire vide');
        }
        $this->log($id, $uid, 'comment', null, null, $message);
        $this->touch($id);
        foreach (array_unique(array_filter([$row['assigned_to'], $row['created_by']])) as $target) {
            if ($target !== $uid) {
                $this->notify($target, 'comment', $id, (string)$row['ref'], $uid);
            }
        }
        return $this->get($id);
    }

    public function delete(int $id, string $uid): void {
        if (!$this->isManager($uid)) {
            throw new ForbiddenException('Seuls les responsables peuvent supprimer une réclamation');
        }
        $this->fetchRow($id);
        foreach (['recl_history', 'recl_files'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('item_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('recl_items')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
        $this->notificationManager->markProcessed(
            $this->notificationManager->createNotification()->setApp(Application::APP_ID)->setObject('reclamation', (string)$id)
        );
    }

    // ------------------------------------------------------------------ pièces jointes (métadonnées)

    public function listFiles(int $id): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_files')
            ->where($qb->expr()->eq('item_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $res = $qb->executeQuery();
        $out = [];
        while ($f = $res->fetch()) {
            $f['id'] = (int)$f['id'];
            $f['size'] = (int)$f['size'];
            $f['created_at'] = (int)$f['created_at'];
            $f['user_name'] = $this->displayName($f['user_id']);
            $out[] = $f;
        }
        $res->closeCursor();
        return $out;
    }

    public function addFileRecord(int $id, string $name, string $mime, int $size, string $uid): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('recl_files')->values([
            'item_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
            'filename' => $qb->createNamedParameter($name),
            'mimetype' => $qb->createNamedParameter($mime),
            'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter($uid),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();
        $fid = $qb->getLastInsertId();
        $this->log($id, $uid, 'file', null, null, 'Pièce jointe ajoutée : ' . $name);
        $this->touch($id);
        return $fid;
    }

    public function getFileRecord(int $aid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_files')->where($qb->expr()->eq('id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)));
        $res = $qb->executeQuery();
        $f = $res->fetch();
        $res->closeCursor();
        if (!$f) {
            throw new NotFoundException('Pièce jointe introuvable');
        }
        return $f;
    }

    public function deleteFileRecord(int $aid, string $uid): array {
        $f = $this->getFileRecord($aid);
        if ($f['user_id'] !== $uid && !$this->isManager($uid)) {
            throw new ForbiddenException('Vous ne pouvez supprimer que vos propres pièces jointes');
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('recl_files')->where($qb->expr()->eq('id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
        $this->log((int)$f['item_id'], $uid, 'file', null, null, 'Pièce jointe supprimée : ' . $f['filename']);
        return $f;
    }

    // ------------------------------------------------------------------ statistiques

    public function stats(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_items');
        $res = $qb->executeQuery();
        $now = time();
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        $s = [
            'open' => 0, 'late' => 0, 'waiting' => 0, 'high_open' => 0,
            'month_created' => 0, 'month_closed' => 0,
            'by_status' => array_fill_keys(array_keys(self::STATUSES), 0),
            'by_type' => array_fill_keys(array_keys(self::TYPES), 0),
            'by_cause' => array_fill_keys(array_keys(self::ROOT_CAUSES), 0),
            'avg_resolution_h' => null, 'sla_ok_pct' => null, 'avg_satisfaction' => null,
            'total_cost' => 0.0, 'reopened' => 0,
        ];
        $resTimes = [];
        $slaOk = 0;
        $slaTotal = 0;
        $sat = [];
        while ($r = $res->fetch()) {
            $d = $this->decorate($r);
            $s['by_status'][$d['status']] = ($s['by_status'][$d['status']] ?? 0) + 1;
            $s['by_type'][$d['type']] = ($s['by_type'][$d['type']] ?? 0) + 1;
            if ($d['root_cause']) {
                $s['by_cause'][$d['root_cause']] = ($s['by_cause'][$d['root_cause']] ?? 0) + 1;
            }
            if (in_array($d['status'], self::OPEN_STATUSES, true)) {
                $s['open']++;
                if ($d['urgency'] === 'haute') {
                    $s['high_open']++;
                }
            }
            if ($d['late']) {
                $s['late']++;
            }
            if ($d['status'] === 'en_attente') {
                $s['waiting']++;
            }
            if ($d['created_at'] >= $monthStart) {
                $s['month_created']++;
            }
            if ($d['closed_at'] && $d['closed_at'] >= $monthStart && $d['status'] === 'cloture') {
                $s['month_closed']++;
            }
            if ($d['resolved_at']) {
                $resTimes[] = ($d['resolved_at'] - $d['created_at']) / 3600;
                $slaTotal++;
                if ($d['due_at'] && $d['resolved_at'] <= $d['due_at']) {
                    $slaOk++;
                }
            }
            if ($d['satisfaction']) {
                $sat[] = $d['satisfaction'];
            }
            if ($d['cost']) {
                $s['total_cost'] += $d['cost'];
            }
            if ($d['reopen_count'] > 0) {
                $s['reopened']++;
            }
        }
        $res->closeCursor();
        if ($resTimes) {
            $s['avg_resolution_h'] = round(array_sum($resTimes) / count($resTimes), 1);
        }
        if ($slaTotal) {
            $s['sla_ok_pct'] = (int)round(100 * $slaOk / $slaTotal);
        }
        if ($sat) {
            $s['avg_satisfaction'] = round(array_sum($sat) / count($sat), 1);
        }
        return $s;
    }

    // ------------------------------------------------------------------ SLA (tâche de fond)

    public function checkSla(): int {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('recl_items')
            ->where($qb->expr()->in('status', $qb->createNamedParameter(['nouveau', 'qualifiee', 'en_cours'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('sla_notified', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        $res = $qb->executeQuery();
        $count = 0;
        while ($r = $res->fetch()) {
            $targets = array_unique(array_filter([$r['assigned_to'], $r['created_by']]));
            $group = $this->groupManager->get(Application::MANAGER_GROUP);
            if ($group !== null) {
                foreach ($group->getUsers() as $u) {
                    $targets[] = $u->getUID();
                }
            }
            foreach (array_unique($targets) as $t) {
                $this->notify($t, 'sla', (int)$r['id'], (string)$r['ref'], '');
            }
            $u = $this->db->getQueryBuilder();
            $u->update('recl_items')->set('sla_notified', $u->createNamedParameter(1, IQueryBuilder::PARAM_INT))
                ->where($u->expr()->eq('id', $u->createNamedParameter((int)$r['id'], IQueryBuilder::PARAM_INT)));
            $u->executeStatement();
            $count++;
        }
        $res->closeCursor();
        return $count;
    }

    // ------------------------------------------------------------------ utilitaires

    private function validate(array $d, bool $creating): array {
        $out = [];
        $str = function (string $k, int $max) use ($d, &$out) {
            if (array_key_exists($k, $d)) {
                $v = trim((string)($d[$k] ?? ''));
                $out[$k] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        };
        $str('client_name', 255);
        $str('client_phone', 64);
        $str('dossier_no', 128);
        $str('product', 255);
        $str('description', 20000);
        $str('solution_detail', 20000);

        foreach (['type' => self::TYPES, 'urgency' => self::URGENCIES, 'channel' => self::CHANNELS,
                     'service' => self::SERVICES, 'solution' => self::SOLUTIONS, 'root_cause' => self::ROOT_CAUSES] as $k => $allowed) {
            if (array_key_exists($k, $d)) {
                $v = (string)($d[$k] ?? '');
                if ($v === '') {
                    $out[$k] = null;
                } elseif (!isset($allowed[$v])) {
                    throw new ValidationException('Valeur invalide pour ' . $k);
                } else {
                    $out[$k] = $v;
                }
            }
        }
        if (array_key_exists('assigned_to', $d)) {
            $v = trim((string)($d['assigned_to'] ?? ''));
            if ($v !== '' && !$this->userManager->userExists($v)) {
                throw new ValidationException('Utilisateur inconnu');
            }
            $out['assigned_to'] = $v === '' ? null : $v;
        }
        if (array_key_exists('cost', $d)) {
            $v = trim(str_replace(',', '.', (string)($d['cost'] ?? '')));
            $out['cost'] = $v === '' ? null : (float)$v;
        }
        if (array_key_exists('satisfaction', $d)) {
            $v = (int)($d['satisfaction'] ?? 0);
            $out['satisfaction'] = ($v >= 1 && $v <= 5) ? $v : null;
        }

        if ($creating) {
            foreach (['client_name' => 'Nom du client', 'type' => 'Type de réclamation', 'urgency' => 'Urgence'] as $k => $label) {
                if (empty($out[$k])) {
                    throw new ValidationException($label . ' est obligatoire');
                }
            }
            if (empty($out['description'])) {
                throw new ValidationException('La description est obligatoire');
            }
        } else {
            foreach (['client_name', 'type', 'urgency'] as $k) {
                if (array_key_exists($k, $out) && empty($out[$k])) {
                    throw new ValidationException('Champ obligatoire : ' . $k);
                }
            }
        }
        return $out;
    }

    private function touch(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('recl_items')->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function log(int $id, string $uid, string $action, ?string $from, ?string $to, ?string $message): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('recl_history')->values([
            'item_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter($uid),
            'action' => $qb->createNamedParameter($action),
            'from_status' => $qb->createNamedParameter($from),
            'to_status' => $qb->createNamedParameter($to),
            'message' => $qb->createNamedParameter($message),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();
    }

    private function notify(string $target, string $subject, int $id, string $ref, string $actor): void {
        try {
            $n = $this->notificationManager->createNotification();
            $n->setApp(Application::APP_ID)
                ->setUser($target)
                ->setDateTime(new \DateTime())
                ->setObject('reclamation', (string)$id)
                ->setSubject($subject, ['ref' => $ref, 'actor' => $actor === '' ? '' : ($this->displayName($actor) ?? $actor)]);
            $this->notificationManager->notify($n);
        } catch (\Throwable $e) {
            // une notification en échec ne doit jamais bloquer le traitement
        }
    }

    private function humanDuration(int $sec): string {
        if ($sec < 3600) {
            return max(1, intdiv($sec, 60)) . ' min';
        }
        if ($sec < 86400) {
            return round($sec / 3600, 1) . ' h';
        }
        return round($sec / 86400, 1) . ' j';
    }
}
