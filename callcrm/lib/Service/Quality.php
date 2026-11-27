<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Qualité des appels : le service qualité écoute les appels enregistrés et les note avec une grille (critères notés de 0 au
 * maximum, critères éliminatoires qui mettent la note à 0), note sur 100, commentaire ; le commercial voit ses évaluations.
 * Une évaluation par enregistrement ; la grille utilisée est recopiée dans l'évaluation (une grille modifiée ne la fausse pas).
 */
class Quality {
	private const MAX_CRITERIA = 30;

	public function __construct(private IDBConnection $db, private IConfig $config) {}

	public static function defaultGrid(): array {
		$c = fn(string $label, int $max = 2) => ['label' => $label, 'max' => $max, 'eliminatory' => false];
		return ['criteria' => [
			$c('Accueil et présentation (nom, CSM Taksit)'),
			$c('Annonce de l’enregistrement de l’appel', 1),
			$c('Écoute et qualification du besoin (produit, wilaya)'),
			$c('Vérification de l’éligibilité (âge, salaire, mensualités en cours)'),
			$c('Explication claire de la mensualité, sans donner le prix'),
			$c('Réponse aux objections'),
			$c('Envoi ou explication de la liste des documents'),
			$c('Ton, politesse et patience'),
			$c('Conclusion et prochaine étape (rappel, rendez-vous, documents)'),
			$c('Respect du guide d’appel'),
			['label' => 'Fausse information donnée au client', 'max' => 0, 'eliminatory' => true],
			['label' => 'Comportement impoli ou agressif', 'max' => 0, 'eliminatory' => true],
		]];
	}

	public function grid(): array {
		$v = json_decode($this->config->getAppValue('callcrm', 'quality_grid', ''), true);
		return is_array($v) && !empty($v['criteria']) ? $v : self::defaultGrid();
	}

	public function saveGrid(array $in): array {
		$out = [];
		foreach (array_slice((array)($in['criteria'] ?? []), 0, self::MAX_CRITERIA) as $c) {
			if (!is_array($c)) continue;
			$label = mb_substr(trim((string)($c['label'] ?? '')), 0, 160);
			if ($label === '') continue;
			$elim = !empty($c['eliminatory']);
			$out[] = ['label' => $label, 'max' => $elim ? 0 : max(1, min(10, (int)($c['max'] ?? 2))), 'eliminatory' => $elim];
		}
		if (!array_filter($out, fn($c) => !$c['eliminatory'])) throw new \InvalidArgumentException('La grille doit contenir au moins un critère noté.');
		$v = ['criteria' => $out];
		$this->config->setAppValue('callcrm', 'quality_grid', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $v;
	}

	/** Appels enregistrés d'un commercial (audio disponible), avec leur évaluation éventuelle. */
	public function calls(array $f, int $offset): array {
		$q = $this->db->getQueryBuilder();
		$q->select('r.id', 'r.phone', 'r.user_id', 'r.direction', 'r.call_status', 'r.started_at', 'r.talk_duration', 'r.prospect_id', 'p.firstname', 'p.lastname',
				'q.score', 'q.eliminated', 'q.evaluator_uid')
			->selectAlias('q.id', 'eval_id')->selectAlias('q.updated_at', 'evaluated_at')
			->from('callcrm_pbx_recordings', 'r')
			->leftJoin('r', 'callcrm_prospects', 'p', $q->expr()->eq('p.id', 'r.prospect_id'))
			->leftJoin('r', 'callcrm_quality', 'q', $q->expr()->eq('q.recording_id', 'r.id'))
			->where($q->expr()->eq('r.state', $q->createNamedParameter('ready')))
			->andWhere($q->expr()->neq('r.user_id', $q->createNamedParameter('')));
		if (($f['agent'] ?? '') !== '') $q->andWhere($q->expr()->eq('r.user_id', $q->createNamedParameter($f['agent'])));
		if (($f['direction'] ?? '') !== '') $q->andWhere($q->expr()->eq('r.direction', $q->createNamedParameter($f['direction'])));
		if (($f['from'] ?? '') !== '') $q->andWhere($q->expr()->gte('r.started_at', $q->createNamedParameter($f['from'] . ' 00:00:00')));
		if (($f['to'] ?? '') !== '') $q->andWhere($q->expr()->lte('r.started_at', $q->createNamedParameter($f['to'] . ' 23:59:59')));
		if (($f['min'] ?? 0) > 0) $q->andWhere($q->expr()->gte('r.talk_duration', $q->createNamedParameter((int)$f['min'], IQueryBuilder::PARAM_INT)));
		if (($f['state'] ?? '') === 'todo') $q->andWhere($q->expr()->isNull('q.id'));
		if (($f['state'] ?? '') === 'done') $q->andWhere($q->expr()->isNotNull('q.id'));
		$rows = $q->orderBy('r.id', 'DESC')->setFirstResult(max(0, $offset))->setMaxResults(51)->executeQuery()->fetchAll();
		return ['rows' => array_slice($rows, 0, 50), 'has_more' => count($rows) > 50];
	}

	/** Tirage au hasard : jusqu'à $n appels non évalués par commercial, parmi les 7 derniers jours (contrôle équitable). */
	public function sample(int $n): array {
		$n = max(1, min(20, $n));
		$since = date('Y-m-d H:i:s', time() - 7 * 86400);
		$agents = $this->db->executeQuery("SELECT DISTINCT user_id FROM *PREFIX*callcrm_pbx_recordings WHERE state = 'ready' AND user_id <> '' AND started_at >= ?", [$since])->fetchAll(\PDO::FETCH_COLUMN);
		$ids = [];
		foreach ($agents as $agent) {
			$ids = array_merge($ids, $this->db->executeQuery("SELECT r.id FROM *PREFIX*callcrm_pbx_recordings r LEFT JOIN *PREFIX*callcrm_quality q ON q.recording_id = r.id
				WHERE r.state = 'ready' AND r.user_id = ? AND r.started_at >= ? AND r.talk_duration >= 30 AND q.id IS NULL ORDER BY RAND() LIMIT " . $n, [$agent, $since])->fetchAll(\PDO::FETCH_COLUMN));
		}
		if (!$ids) return ['rows' => [], 'has_more' => false];
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('r.id', 'r.phone', 'r.user_id', 'r.direction', 'r.call_status', 'r.started_at', 'r.talk_duration', 'r.prospect_id', 'p.firstname', 'p.lastname')
			->from('callcrm_pbx_recordings', 'r')->leftJoin('r', 'callcrm_prospects', 'p', $q->expr()->eq('p.id', 'r.prospect_id'))
			->where($q->expr()->in('r.id', $q->createNamedParameter(array_map('intval', $ids), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('r.user_id')->addOrderBy('r.id', 'DESC')->executeQuery()->fetchAll();
		return ['rows' => $rows, 'has_more' => false];
	}

	public function recording(int $id): ?array {
		$q = $this->db->getQueryBuilder();
		return $q->select('*')->from('callcrm_pbx_recordings')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch() ?: null;
	}

	public function forRecording(int $rid): ?array {
		$q = $this->db->getQueryBuilder();
		$r = $q->select('*')->from('callcrm_quality')->where($q->expr()->eq('recording_id', $q->createNamedParameter($rid, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
		return $r ? self::decode($r) : null;
	}

	public function find(int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$r = $q->select('*')->from('callcrm_quality')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
		return $r ? self::decode($r) : null;
	}

	private static function decode(array $r): array {
		$r['scores'] = json_decode((string)($r['scores'] ?? '[]'), true) ?: [];
		return $r;
	}

	/** Note un appel : $values = [index du critère => points ou 0/1 pour un critère éliminatoire]. Renvoie l'évaluation. */
	public function evaluate(int $rid, string $evaluator, array $values, string $comment): array {
		$rec = $this->recording($rid);
		if (!$rec || $rec['state'] !== 'ready' || (string)$rec['user_id'] === '') throw new \InvalidArgumentException('Enregistrement introuvable ou sans commercial.');
		$scores = []; $total = 0; $max = 0; $elim = false;
		foreach ($this->grid()['criteria'] as $i => $c) {
			$v = (int)($values[$i] ?? $values[(string)$i] ?? 0);
			if ($c['eliminatory']) { $v = $v ? 1 : 0; $elim = $elim || $v === 1; }
			else { $v = max(0, min((int)$c['max'], $v)); $total += $v; $max += (int)$c['max']; }
			$scores[] = $c + ['value' => $v];
		}
		$score = $elim || !$max ? 0 : (int)round(100 * $total / $max);
		$now = time(); $old = $this->forRecording($rid);
		$row = ['agent_uid' => (string)$rec['user_id'], 'evaluator_uid' => $evaluator, 'scores' => json_encode($scores, JSON_UNESCAPED_UNICODE),
			'score' => $score, 'eliminated' => $elim ? 1 : 0, 'comment' => mb_substr(trim($comment), 0, 4000), 'updated_at' => $now, 'read_at' => 0];
		$q = $this->db->getQueryBuilder();
		if ($old) {
			$q->update('callcrm_quality');
			foreach ($row as $k => $v) $q->set($k, $q->createNamedParameter($v));
			$q->where($q->expr()->eq('id', $q->createNamedParameter((int)$old['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
			$id = (int)$old['id'];
		} else {
			$q->insert('callcrm_quality');
			foreach ($row + ['recording_id' => $rid, 'created_at' => $now] as $k => $v) $q->setValue($k, $q->createNamedParameter($v));
			$q->executeStatement();
			$id = (int)$q->getLastInsertId();
		}
		return $this->find($id);
	}

	/** Évaluations d'un commercial (les plus récentes d'abord), avec l'appel concerné. */
	public function mine(string $uid, int $offset): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('q.id', 'q.recording_id', 'q.score', 'q.eliminated', 'q.comment', 'q.scores', 'q.updated_at', 'q.read_at', 'q.evaluator_uid',
				'r.phone', 'r.direction', 'r.started_at', 'r.talk_duration', 'p.firstname', 'p.lastname')
			->from('callcrm_quality', 'q')->innerJoin('q', 'callcrm_pbx_recordings', 'r', $q->expr()->eq('r.id', 'q.recording_id'))
			->leftJoin('r', 'callcrm_prospects', 'p', $q->expr()->eq('p.id', 'r.prospect_id'))
			->where($q->expr()->eq('q.agent_uid', $q->createNamedParameter($uid)))
			->orderBy('q.updated_at', 'DESC')->setFirstResult(max(0, $offset))->setMaxResults(51)->executeQuery()->fetchAll();
		return ['rows' => array_map(fn($r) => self::decode($r), array_slice($rows, 0, 50)), 'has_more' => count($rows) > 50];
	}

	public function markRead(int $id, string $uid): void {
		$q = $this->db->getQueryBuilder();
		$q->update('callcrm_quality')->set('read_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('agent_uid', $q->createNamedParameter($uid)))->executeStatement();
	}
}
