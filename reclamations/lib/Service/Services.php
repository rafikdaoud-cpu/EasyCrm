<?php
namespace OCA\Reclamations\Service;
use OCP\IConfig;
use OCP\IGroupManager;

/**
 * Groupes d'affectation : des groupes de l'annuaire (AD/LDAP) choisis par l'administrateur.
 * Les membres sont lus dans l'annuaire à chaque fois : un départ ou une arrivée dans l'AD s'applique tout seul.
 */
class Services {
	public const DEFAULT = ['GRP-LIVRAISON', 'GRP-MOTO', 'GRP-COORDINATION'];
	private static function cfg(): IConfig { return \OCP\Server::get(IConfig::class); }
	private static function gm(): IGroupManager { return \OCP\Server::get(IGroupManager::class); }
	/** @return string[] identifiants des groupes retenus */
	public static function groupIds(): array {
		$l = json_decode((string)self::cfg()->getAppValue('reclamations', 'service_groups', ''), true);
		return is_array($l) ? array_values(array_filter($l, 'is_string')) : self::DEFAULT;
	}
	/** @return array<int,array{name:string,label:string,members:string[]}> */
	public static function all(): array {
		$out = [];
		foreach (self::groupIds() as $gid) {
			$g = self::gm()->get($gid);
			if (!$g) { continue; }
			$out[] = ['name' => $gid, 'label' => $g->getDisplayName() ?: $gid, 'members' => array_values(array_map(fn($u) => $u->getUID(), $g->getUsers()))];
		}
		return $out;
	}
	public static function names(): array { return array_column(self::all(), 'name'); }
	public static function forUser(string $uid): array {
		return array_values(array_filter(self::groupIds(), fn($gid) => self::gm()->isInGroup($uid, $gid)));
	}
	/** Enregistre la liste des groupes ; un groupe absent de l'annuaire est refusé. */
	public static function save(array $in, callable $exists): array {
		$ids = [];
		foreach (array_slice($in, 0, 50) as $s) {
			$gid = trim((string)(is_array($s) ? ($s['name'] ?? '') : $s));
			if ($gid === '' || in_array($gid, $ids, true)) { continue; }
			if (!self::gm()->groupExists($gid)) { throw new \InvalidArgumentException('Groupe introuvable dans l’annuaire : ' . $gid); }
			$ids[] = $gid;
		}
		self::cfg()->setAppValue('reclamations', 'service_groups', json_encode($ids, JSON_UNESCAPED_UNICODE));
		return self::all();
	}
}
