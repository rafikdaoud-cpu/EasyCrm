<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Fiches d'un commercial désactivé ou supprimé dans l'annuaire (AD) : remises en « non attribué ».
 * Garde-fous contre une panne de l'annuaire (tous les comptes paraîtraient absents) :
 * - un compte doit être absent ou désactivé à deux contrôles espacés d'au moins 50 minutes ;
 * - si plus du tiers des commerciaux (et plus de 2) disparaissent d'un coup, rien n'est fait.
 */
class InactiveOwners {
	public const GRACE = 3000;

	public function __construct(private IDBConnection $db, private IUserManager $users, private IConfig $config, private Store $store) {}

	/** Le compte peut-il encore travailler ? null = absent de l'annuaire. */
	protected function active(string $uid): bool {
		$u = $this->users->get($uid);
		return $u !== null && $u->isEnabled();
	}

	/** Comptes ayant au moins une fiche. */
	protected function owners(): array {
		$q = $this->db->getQueryBuilder();
		$q->selectDistinct('assigned_to')->from('callcrm_prospects')->where($q->expr()->isNotNull('assigned_to'))->andWhere($q->expr()->neq('assigned_to', $q->createNamedParameter('')));
		return array_values(array_filter(array_map(fn($r) => (string)$r['assigned_to'], $q->executeQuery()->fetchAll())));
	}
	protected function idsOf(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('callcrm_prospects')->where($q->expr()->eq('assigned_to', $q->createNamedParameter($uid)));
		return array_map('intval', array_column($q->executeQuery()->fetchAll(), 'id'));
	}

	/** @return array{owners:int,inactive:array,released:array,skipped:string} */
	public function run(?int $now = null): array {
		$now ??= time();
		$owners = $this->owners();
		$inactive = array_values(array_filter($owners, fn($uid) => !$this->active($uid)));
		$seen = json_decode($this->config->getAppValue('callcrm', 'inactive_owners_seen', '{}'), true) ?: [];
		$out = ['owners' => count($owners), 'inactive' => $inactive, 'released' => [], 'skipped' => ''];
		if (count($inactive) > 2 && count($inactive) > count($owners) / 3) {
			$out['skipped'] = 'Trop de comptes absents d’un coup (' . count($inactive) . ' sur ' . count($owners) . ') : annuaire probablement indisponible, rien n’est modifié.';
			$this->config->setAppValue('callcrm', 'inactive_owners_last', json_encode(['at' => $now] + $out, JSON_UNESCAPED_UNICODE));
			return $out;
		}
		$next = [];
		foreach ($inactive as $uid) {
			$first = (int)($seen[$uid] ?? $now);
			if ($now - $first < self::GRACE) { $next[$uid] = $first; continue; }
			$ids = $this->idsOf($uid);
			if (!$ids) continue;
			$n = $this->store->reassign($ids, null, 'system');
			$this->store->audit('system', 'transfer', null, $n . ' fiche(s) remises en non attribué : compte ' . $uid . ' désactivé ou supprimé dans l’annuaire');
			$out['released'][$uid] = $n;
		}
		$this->config->setAppValue('callcrm', 'inactive_owners_seen', json_encode($next));
		$this->config->setAppValue('callcrm', 'inactive_owners_last', json_encode(['at' => $now] + $out, JSON_UNESCAPED_UNICODE));
		return $out;
	}
}
