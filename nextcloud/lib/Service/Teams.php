<?php
namespace OCA\EasyCrm\Service;

use OCP\IConfig;
use OCP\IGroupManager;

/** Équipes de commerciaux (groupes Nextcloud / AD) et catégories de produits rattachées. */
class Teams {
	private static function stored(): array {
		$raw = (string)\OCP\Server::get(IConfig::class)->getAppValue('easycrm', 'teams_cfg', '');
		$a = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($a) ? $a : [];
	}
	private static function cleanList($v, int $max = 60): array {
		if (is_string($v)) { $v = preg_split('/[\r\n,;]+/', $v); }
		$o = array_map(fn($x) => str_replace('|', '/', mb_substr(trim((string)$x), 0, 60)), (array)$v);
		return array_slice(array_values(array_unique(array_filter($o, fn($x) => $x !== ''))), 0, $max);
	}
	/** [{gid, name, cats[]}] */
	public static function all(): array {
		$out = [];
		foreach (self::stored() as $t) {
			if (!is_array($t) || empty($t['gid'])) { continue; }
			$out[] = ['gid' => (string)$t['gid'], 'name' => mb_substr(trim((string)($t['name'] ?? $t['gid'])), 0, 60) ?: (string)$t['gid'], 'cats' => self::cleanList($t['cats'] ?? [])];
		}
		return $out;
	}
	/** Catégories sans équipe */
	public static function extra(): array {
		$raw = (string)\OCP\Server::get(IConfig::class)->getAppValue('easycrm', 'cats_extra', '');
		$a = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($a) ? self::cleanList($a) : [];
	}
	public static function teams(): array { return array_column(self::all(), 'name', 'gid'); }
	/** catégorie => groupe */
	public static function cats(): array {
		$o = [];
		foreach (self::all() as $t) { foreach ($t['cats'] as $c) { $o[$c] ??= $t['gid']; } }
		return $o;
	}
	public static function categories(): array {
		return array_values(array_unique(array_merge(array_keys(self::cats()), self::extra())));
	}
	public static function slug(string $s): string {
		$s = mb_strtolower(trim($s));
		if (class_exists('Normalizer')) { $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s; }
		$s = preg_replace('/[^a-z0-9]+/', '_', preg_replace('/\p{Mn}/u', '', $s));
		return trim($s, '_') ?: 'equipe';
	}
	/** Enregistre ; crée le groupe Nextcloud local si l'identifiant donné n'existe pas. */
	public static function save(array $teams, $extra, IGroupManager $gm): array {
		$clean = []; $seen = [];
		foreach (array_slice($teams, 0, 30) as $t) {
			if (!is_array($t)) { continue; }
			$name = mb_substr(trim((string)($t['name'] ?? '')), 0, 60);
			if ($name === '') { continue; }
			$gid = mb_substr(trim((string)($t['gid'] ?? '')), 0, 64);
			if ($gid === '') { $gid = 'easycrm_' . self::slug($name); }
			if (isset($seen[$gid])) { continue; }
			if (!$gm->groupExists($gid)) {
				$g = $gm->createGroup($gid);
				if ($g) { $g->setDisplayName('EasyCrm – ' . $name); }
			}
			$clean[] = ['gid' => $gid, 'name' => $name, 'cats' => self::cleanList($t['cats'] ?? [])];
			$seen[$gid] = true;
		}
		$cfg = \OCP\Server::get(IConfig::class);
		$cfg->setAppValue('easycrm', 'teams_cfg', json_encode($clean, JSON_UNESCAPED_UNICODE));
		$cfg->setAppValue('easycrm', 'cats_extra', json_encode(self::cleanList($extra), JSON_UNESCAPED_UNICODE));
		return self::all();
	}
}
