<?php
namespace OCA\Reclamations\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
class Store {
	public const STATUS = ['nouvelle' => 'Nouvelle', 'en_cours' => 'En cours', 'attente_client' => 'En attente client', 'attente_fournisseur' => 'En attente fournisseur', 'resolue' => 'Résolue', 'cloturee' => 'Clôturée', 'rejetee' => 'Rejetée'];
	public const OPEN = ['nouvelle', 'en_cours', 'attente_client', 'attente_fournisseur'];
	public const PROGRESS = ['en_cours', 'attente_client', 'attente_fournisseur'];
	public const TYPES = ['Panne / SAV', 'Retour produit', 'Retard de livraison', 'Produit non conforme', 'Service / accueil', 'Autre'];
	public const PRIORITIES = ['Normale', 'Haute', 'Urgente'];
	public const SLA_DAYS = ['Normale' => 7, 'Haute' => 4, 'Urgente' => 2];
	/** Services de l'utilisateur dont on filtre la visibilité (renseigné par le contrôleur). */
	public array $svc = [];
	public function __construct(private IDBConnection $db) {}

	private function in(IQueryBuilder $qb, string $col, array $vals) { return $qb->expr()->in($col, $qb->createNamedParameter($vals ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY)); }
	private function where(IQueryBuilder $qb, ?string $uid, array $f): void {
		if ($uid !== null) {
			$or = $qb->expr()->orX(
				$qb->expr()->eq('assigned_to', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('created_by', $qb->createNamedParameter($uid))
			);
			if ($this->svc) { $or->add($this->in($qb, 'service', $this->svc)); }
			$qb->andWhere($or);
		}
		if (!empty($f['status'])) { $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($f['status']))); }
		if (!empty($f['statuses'])) { $qb->andWhere($this->in($qb, 'status', (array)$f['statuses'])); }
		if (!empty($f['late'])) {
			$qb->andWhere($this->in($qb, 'status', self::OPEN));
			$qb->andWhere($qb->expr()->isNotNull('due_at'));
			$qb->andWhere($qb->expr()->lt('due_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		}
		if (!empty($f['assigned'])) { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($f['assigned']))); }
		if (!empty($f['unassigned'])) { $qb->andWhere($qb->expr()->isNull('assigned_to')); }
		if (!empty($f['service'])) { $qb->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($f['service']))); }
		if (isset($f['services'])) { $qb->andWhere($this->in($qb, 'service', (array)$f['services'])); }
		if (!empty($f['open'])) { $qb->andWhere($this->in($qb, 'status', self::OPEN)); }
		if (!empty($f['type'])) { $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($f['type']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($f['cat']))); }
		if (!empty($f['from'])) { $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter((int)$f['from'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['to'])) { $qb->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter((int)$f['to'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['q'])) {
			$l = '%' . $this->db->escapeLikeParameter($f['q']) . '%';
			$or = $qb->expr()->orX();
			foreach (['ref', 'client_name', 'phone', 'invoice_no', 'product'] as $c) { $or->add($qb->expr()->like($c, $qb->createNamedParameter($l))); }
			$qb->andWhere($or);
		}
	}
	public function search(?string $uid, array $f, int $limit, int $off): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('reclam_claims');
		$this->where($qb, $uid, $f);
		$qb->orderBy('id', !empty($f['asc']) ? 'ASC' : 'DESC')->setMaxResults($limit)->setFirstResult($off);
		return $qb->executeQuery()->fetchAll();
	}
	public function get(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('reclam_claims')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $qb->executeQuery()->fetch() ?: null;
	}
	public function insert(string $table, array $v): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($v as $k => $x) { $qb->setValue($k, $qb->createNamedParameter($x)); }
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}
	public function create(array $v): int {
		$id = $this->insert('reclam_claims', $v);
		$this->update($id, ['ref' => 'REC-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT)]);
		return $id;
	}
	public function update(int $id, array $v): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('reclam_claims');
		foreach ($v as $k => $x) { $qb->set($k, $qb->createNamedParameter($x)); }
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}
	/** Prise en charge : n'aboutit que si personne ne l'a prise entre-temps (retourne false sinon). */
	public function take(int $id, string $uid, bool $start): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('reclam_claims')->set('assigned_to', $qb->createNamedParameter($uid))->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
		if ($start) { $qb->set('status', $qb->createNamedParameter('en_cours')); }
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->isNull('assigned_to'));
		return $qb->executeStatement() === 1;
	}
	public function delete(int $id): void {
		foreach (['reclam_events' => 'claim_id', 'reclam_claims' => 'id'] as $t => $c) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($t)->where($qb->expr()->eq($c, $qb->createNamedParameter($id)))->executeStatement();
		}
	}
	public function event(int $claim, string $uid, string $kind, ?string $status, ?string $note): void {
		$this->insert('reclam_events', ['claim_id' => $claim, 'user_id' => $uid, 'kind' => $kind, 'status' => $status, 'note' => $note, 'created_at' => time()]);
	}
	public function events(int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('reclam_events')->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($id)))->orderBy('id', 'DESC');
		return $qb->executeQuery()->fetchAll();
	}
	public function counts(?string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')->selectAlias($qb->func()->count('*'), 'n')->from('reclam_claims')->groupBy('status');
		$this->where($qb, $uid, []);
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) { $out[$r['status']] = (int)$r['n']; }
		return $out;
	}
	public function lateCount(?string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('reclam_claims');
		$this->where($qb, $uid, ['late' => 1]);
		return (int)$qb->executeQuery()->fetchOne();
	}
}
