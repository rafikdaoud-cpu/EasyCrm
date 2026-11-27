<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Fiches en double : même nom complet (au moins deux mots, dans n'importe quel ordre) ou même e-mail. Le téléphone est déjà
 * unique à la saisie ; les doublons restants sont surtout un même client saisi avec un autre numéro (ou une faute de frappe).
 * La fusion rattache tout l'historique à la fiche gardée, complète ses champs vides, puis supprime l'autre fiche.
 */
class Duplicates {
	/** Tables qui pointent vers une fiche (colonne prospect_id). */
	public const LINKED = ['callcrm_documents', 'callcrm_pbx_events', 'callcrm_missed', 'callcrm_collect_links', 'callcrm_calls', 'callcrm_client_events', 'callcrm_recordings',
		'callcrm_appointments', 'callcrm_audit', 'callcrm_shared_clients', 'callcrm_pbx_recordings', 'callcrm_wa_conv'];
	private const COLS = ['id', 'lastname', 'firstname', 'email', 'phone', 'status', 'assigned_to', 'wilaya', 'product_category', 'created_at', 'last_call_at'];

	public function __construct(private IDBConnection $db, private IConfig $config) {}

	public static function nameKey(string $last, string $first): string {
		$w = preg_split('/\s+/u', trim(mb_strtolower($last . ' ' . $first)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (count($w) < 2 || mb_strlen(implode('', $w)) < 5) return '';
		sort($w);
		return implode(' ', $w);
	}

	public function ignored(): array {
		$v = json_decode($this->config->getAppValue('callcrm', 'duplicates_ignored', '[]'), true);
		return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
	}

	/** Écarte un groupe (« ce ne sont pas des doublons ») : il réapparaît seulement si une nouvelle fiche s'y ajoute. */
	public function ignore(array $ids): void {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
		if (count($ids) < 2) throw new \InvalidArgumentException('Groupe invalide.');
		sort($ids);
		$all = array_slice(array_values(array_unique(array_merge($this->ignored(), [implode(',', $ids)]))), -2000);
		$this->config->setAppValue('callcrm', 'duplicates_ignored', json_encode($all));
	}

	public function groups(): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select(...self::COLS)->from('callcrm_prospects')->orderBy('id')->executeQuery()->fetchAll();
		return self::group($rows, $this->ignored());
	}

	public static function group(array $rows, array $ignored = []): array {
		$by = [];
		foreach ($rows as $r) {
			$k = self::nameKey((string)$r['lastname'], (string)$r['firstname']);
			if ($k !== '') $by['name:' . $k][] = $r;
			$e = mb_strtolower(trim((string)($r['email'] ?? '')));
			if (str_contains($e, '@')) $by['email:' . $e][] = $r;
		}
		$out = []; $seen = [];
		foreach ($by as $key => $list) {
			if (count($list) < 2) continue;
			$ids = array_map(fn($r) => (int)$r['id'], $list); sort($ids); $sig = implode(',', $ids);
			if (isset($seen[$sig]) || in_array($sig, $ignored, true)) continue;
			$seen[$sig] = true;
			$out[] = ['reason' => str_starts_with($key, 'email:') ? 'email' : 'name', 'key' => substr($key, strpos($key, ':') + 1), 'ids' => $ids, 'rows' => $list];
		}
		return $out;
	}

	/** Nombre d'appels saisis par fiche. */
	public function callCounts(array $ids): array {
		if (!$ids) return [];
		$q = $this->db->getQueryBuilder(); $out = [];
		foreach ($q->select('prospect_id')->selectAlias($q->func()->count('*'), 'n')->from('callcrm_calls')
			->where($q->expr()->in('prospect_id', $q->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)))->groupBy('prospect_id')->executeQuery()->fetchAll() as $r) $out[(int)$r['prospect_id']] = (int)$r['n'];
		return $out;
	}

	private function get(int $id): ?array {
		$q = $this->db->getQueryBuilder();
		return $q->select('*')->from('callcrm_prospects')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch() ?: null;
	}

	/** Valeurs de la fiche gardée après fusion : champs vides complétés, notes et commentaires réunis, autre numéro noté. */
	public static function mergedValues(array $keep, array $drop): array {
		$v = [];
		foreach ($drop as $k => $x) {
			if (in_array($k, ['id', 'phone', 'remarque', 'comment', 'extra', 'created_at', 'last_call_at', 'status', 'assigned_to', 'reset_at'], true)) continue;
			if (trim((string)($keep[$k] ?? '')) === '' && trim((string)$x) !== '') $v[$k] = $x;
		}
		if (!$keep['assigned_to'] && $drop['assigned_to']) $v['assigned_to'] = $drop['assigned_to'];
		$join = fn($a, $b) => trim(implode("\n", array_unique(array_filter([trim((string)$a), trim((string)$b)], 'strlen'))));
		$r = $join($keep['remarque'] ?? '', $drop['remarque'] ?? '');
		if ($r !== trim((string)($keep['remarque'] ?? ''))) $v['remarque'] = $r;
		$other = trim((string)$drop['phone']) !== '' && $drop['phone'] !== $keep['phone'] ? 'Autre numéro (fiche fusionnée) : ' . $drop['phone'] : '';
		$c = $join($join($keep['comment'] ?? '', $drop['comment'] ?? ''), $other);
		if ($c !== trim((string)($keep['comment'] ?? ''))) $v['comment'] = $c;
		$ek = json_decode((string)($keep['extra'] ?? ''), true); $ed = json_decode((string)($drop['extra'] ?? ''), true);
		if (is_array($ed) && $ed) { $m = (is_array($ek) ? $ek : []) + $ed; if ($m !== $ek) $v['extra'] = json_encode($m, JSON_UNESCAPED_UNICODE); }
		foreach (['created_at' => 'min', 'last_call_at' => 'max'] as $k => $f) {
			$a = (int)($keep[$k] ?? 0); $b = (int)($drop[$k] ?? 0);
			$n = $f === 'min' ? ($a && $b ? min($a, $b) : ($a ?: $b)) : max($a, $b);
			if ($n !== $a && $n) $v[$k] = $n;
		}
		return $v;
	}

	/** Fusionne $drop dans $keep. Renvoie [fiche gardée, fiche supprimée]. */
	public function merge(int $keep, int $drop): array {
		if ($keep === $drop) throw new \InvalidArgumentException('Choisissez deux fiches différentes.');
		$this->db->beginTransaction();
		try {
			$k = $this->get($keep); $d = $this->get($drop);
			if (!$k || !$d) throw new \InvalidArgumentException('Fiche introuvable (déjà fusionnée ou supprimée ?).');
			foreach (self::LINKED as $t) {
				$q = $this->db->getQueryBuilder();
				$q->update($t)->set('prospect_id', $q->createNamedParameter($keep, IQueryBuilder::PARAM_INT))
					->where($q->expr()->eq('prospect_id', $q->createNamedParameter($drop, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$v = self::mergedValues($k, $d);
			if ($v) {
				$q = $this->db->getQueryBuilder(); $q->update('callcrm_prospects');
				foreach ($v as $c => $x) $q->set($c, $q->createNamedParameter($x));
				$q->where($q->expr()->eq('id', $q->createNamedParameter($keep, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$q = $this->db->getQueryBuilder();
			$q->delete('callcrm_prospects')->where($q->expr()->eq('id', $q->createNamedParameter($drop, IQueryBuilder::PARAM_INT)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
		return [$this->get($keep), $d];
	}
}
