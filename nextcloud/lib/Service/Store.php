<?php
namespace OCA\EasyCrm\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
class Store {
	public const RESULTS = ['interesse', 'injoignable', 'rappel', 'pas_interesse', 'non_eligible', 'faux_numero'];
	public function __construct(private IDBConnection $db) {}

	private function where(IQueryBuilder $qb, ?string $uid, array $f): void {
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($uid))); }
		if (!empty($f['status'])) { $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($f['status']))); }
		if (!empty($f['assigned'])) { $qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($f['assigned']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->in('c2', $qb->createNamedParameter((array)$f['cat'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (isset($f['users']) && is_array($f['users'])) { $qb->andWhere($qb->expr()->in('assigned_to', $qb->createNamedParameter($f['users'] ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (!empty($f['rappels'])) {
			$qb->andWhere($qb->expr()->isNotNull('callback_at'));
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter(\OCA\EasyCrm\Service\Statuses::callbackKeys() ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if (!empty($f['q'])) {
			$l = '%' . $this->db->escapeLikeParameter($f['q']) . '%';
			$or = $qb->expr()->orX();
			foreach (['lastname', 'firstname', 'email', 'phone'] as $c) { $or->add($qb->expr()->like($c, $qb->createNamedParameter($l))); }
			$qb->andWhere($or);
		}
	}
	public function search(?string $uid, array $f, int $limit, int $off): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_prospects');
		$this->where($qb, $uid, $f);
		$cols = ['nom' => 'lastname', 'date' => 'created_at', 'statut' => 'status', 'wilaya' => 'c5', 'categorie' => 'c2', 'appel' => 'last_call_at', 'rappel' => 'callback_at'];
		if (!empty($f['sort']) && isset($cols[$f['sort']])) {
			$qb->orderBy($cols[$f['sort']], ($f['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC')->addOrderBy('id', 'DESC');
		} elseif (!empty($f['rappels'])) { $qb->orderBy('callback_at', 'ASC'); } else { $qb->orderBy('id', !empty($f['asc']) ? 'ASC' : 'DESC'); }
		$qb->setMaxResults($limit)->setFirstResult($off);
		return $qb->executeQuery()->fetchAll();
	}
	public function count(?string $uid, array $f): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('easycrm_prospects');
		$this->where($qb, $uid, $f);
		return (int)$qb->executeQuery()->fetchOne();
	}
	public function get(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_prospects')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	public function calls(int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_calls')->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($id)))->orderBy('id', 'DESC');
		return $qb->executeQuery()->fetchAll();
	}
	public function insert(string $table, array $v): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($v as $k => $x) { $qb->setValue($k, $qb->createNamedParameter($x)); }
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}
	public function update(int $id, array $v): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('easycrm_prospects');
		foreach ($v as $k => $x) { $qb->set($k, $qb->createNamedParameter($x)); }
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}
	public function delete(int $id): void {
		foreach (['easycrm_recordings' => 'prospect_id', 'easycrm_calls' => 'prospect_id', 'easycrm_prospects' => 'id'] as $t => $c) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($t)->where($qb->expr()->eq($c, $qb->createNamedParameter($id)))->executeStatement();
		}
	}
	public function prospectByPhone(string $p): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_prospects')->where($qb->expr()->eq('phone', $qb->createNamedParameter($p)))->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	/** Dernier appel saisi par ce commercial pour ce prospect, entre 10 min avant et 6 h après le début de l'enregistrement */
	public function nearestCall(string $uid, int $prospectId, int $ts): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('easycrm_calls')
			->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($prospectId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($ts - 600, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($ts + 21600, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')->setMaxResults(1);
		$r = $qb->executeQuery()->fetch();
		return $r ? (int)$r['id'] : null;
	}
	public function recordingExists(string $uid, string $title, int $size): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('easycrm_recordings')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->andWhere($qb->expr()->eq('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT)))->setMaxResults(1);
		return (bool)$qb->executeQuery()->fetch();
	}
	public function getRecording(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_recordings')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$r = $qb->executeQuery()->fetch();
		return $r ?: null;
	}
	public function setRecordingFile(int $id, string $file): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('easycrm_recordings')->set('file', $qb->createNamedParameter($file))->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}
	public function recordingsFor(int $prospectId, ?string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_recordings')->where($qb->expr()->eq('prospect_id', $qb->createNamedParameter($prospectId)))->orderBy('recorded_at', 'DESC');
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid))); }
		return $qb->executeQuery()->fetchAll();
	}
	public function unmatchedRecordings(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('easycrm_recordings')->where($qb->expr()->isNull('prospect_id'))->orderBy('recorded_at', 'DESC')->setMaxResults($limit);
		return $qb->executeQuery()->fetchAll();
	}
	public function phoneExists(string $p): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('easycrm_prospects')->where($qb->expr()->eq('phone', $qb->createNamedParameter($p)))->setMaxResults(1);
		return (bool)$qb->executeQuery()->fetch();
	}
	private function logWhere(IQueryBuilder $qb, ?string $uid, array $f): void {
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid))); }
		if (!empty($f['user'])) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($f['user']))); }
		if (!empty($f['result'])) { $qb->andWhere($qb->expr()->eq('c.result', $qb->createNamedParameter($f['result']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->in('p.c2', $qb->createNamedParameter((array)$f['cat'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (isset($f['users']) && is_array($f['users'])) { $qb->andWhere($qb->expr()->in('c.user_id', $qb->createNamedParameter($f['users'] ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (!empty($f['from'])) { $qb->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter((int)$f['from'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['to'])) { $qb->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter((int)$f['to'], IQueryBuilder::PARAM_INT))); }
		$q = trim((string)($f['q'] ?? ''));
		if ($q !== '') {
			$l = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($q) . '%');
			$or = [];
			foreach (['p.lastname', 'p.firstname', 'p.phone', 'c.note', 'c.user_id'] as $col) { $or[] = $qb->expr()->like($col, $l); }
			if (!empty($f['qusers'])) { $or[] = $qb->expr()->in('c.user_id', $qb->createNamedParameter($f['qusers'], IQueryBuilder::PARAM_STR_ARRAY)); }
			$qb->andWhere($qb->expr()->orX(...$or));
		}
	}
	public function callLog(?string $uid, array $f, int $limit, int $off): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.user_id', 'c.result', 'c.note', 'c.callback_at', 'c.created_at', 'c.prospect_id', 'p.lastname', 'p.firstname', 'p.phone', 'p.c2')
			->from('easycrm_calls', 'c')->innerJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		$cols = ['date' => 'c.created_at', 'user' => 'c.user_id', 'client' => 'p.lastname', 'phone' => 'p.phone', 'cat' => 'p.c2', 'result' => 'c.result', 'note' => 'c.note'];
		$dir = ($f['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
		if (!empty($f['sort']) && isset($cols[$f['sort']])) { $qb->orderBy($cols[$f['sort']], $dir)->addOrderBy('c.id', 'DESC'); }
		else { $qb->orderBy('c.id', 'DESC'); }
		$qb->setMaxResults($limit)->setFirstResult($off);
		$this->logWhere($qb, $uid, $f);
		return $qb->executeQuery()->fetchAll();
	}
	public function callLogCount(?string $uid, array $f): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('easycrm_calls', 'c')->innerJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'));
		$this->logWhere($qb, $uid, $f);
		return (int)$qb->executeQuery()->fetchOne();
	}
	/** Prospects assignés à quelqu'un qui n'est plus commercial (id, c2, status). */
	public function orphans(array $valid, bool $onlyNew): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'c2', 'status')->from('easycrm_prospects')
			->where($qb->expr()->isNotNull('assigned_to'))
			->andWhere($qb->expr()->notIn('assigned_to', $qb->createNamedParameter($valid, IQueryBuilder::PARAM_STR_ARRAY)));
		if ($onlyNew) { $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('nouveau'))); }
		return $qb->orderBy('id', 'ASC')->executeQuery()->fetchAll();
	}
	public function unassigned(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'c2')->from('easycrm_prospects')->where($qb->expr()->isNull('assigned_to'))->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('nouveau')))->orderBy('id', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}
	public function statusCounts(?string $uid, array $f = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')->selectAlias($qb->func()->count('*'), 'n')->from('easycrm_prospects')->groupBy('status');
		$this->where($qb, $uid, $f);
		return $qb->executeQuery()->fetchAll();
	}
	public function callCounts(?string $uid, array $f = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.user_id', 'c.result')->selectAlias($qb->func()->count('*'), 'n')->from('easycrm_calls', 'c')
			->innerJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'))->groupBy('c.user_id', 'c.result');
		if ($uid !== null) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid))); }
		if (!empty($f['user'])) { $qb->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($f['user']))); }
		if (!empty($f['result'])) { $qb->andWhere($qb->expr()->eq('c.result', $qb->createNamedParameter($f['result']))); }
		if (!empty($f['cat'])) { $qb->andWhere($qb->expr()->in('p.c2', $qb->createNamedParameter((array)$f['cat'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (isset($f['users']) && is_array($f['users'])) { $qb->andWhere($qb->expr()->in('c.user_id', $qb->createNamedParameter($f['users'] ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY))); }
		if (!empty($f['from'])) { $qb->andWhere($qb->expr()->gte('c.created_at', $qb->createNamedParameter((int)$f['from'], IQueryBuilder::PARAM_INT))); }
		if (!empty($f['to'])) { $qb->andWhere($qb->expr()->lt('c.created_at', $qb->createNamedParameter((int)$f['to'], IQueryBuilder::PARAM_INT))); }
		return $qb->executeQuery()->fetchAll();
	}
	/** Horodatages des appels (pour le graphique par heure). */
	public function callTimes(?string $uid, array $f): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.created_at')->from('easycrm_calls', 'c')->innerJoin('c', 'easycrm_prospects', 'p', $qb->expr()->eq('c.prospect_id', 'p.id'))->setMaxResults(200000);
		$this->logWhere($qb, $uid, $f);
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}
	public function callCountsOld(?string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'result')->selectAlias($qb->func()->count('*'), 'n')->from('easycrm_calls')->groupBy('user_id', 'result');
		if ($uid !== null) { $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid))); }
		return $qb->executeQuery()->fetchAll();
	}
}
