<?php
namespace OCA\CallCrm\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Périmètre d'un superviseur : s'il a des groupes d'équipe attribués (Administration), il ne voit et ne gère
 * que les commerciaux de ces groupes (et lui-même), plus les fiches non attribuées à répartir.
 * Sans groupe attribué : aucune restriction (fonctionnement d'avant). Admin, commercial, lecture : inchangés.
 * Hors requête utilisateur (tâches de fond, CLI) : aucune restriction.
 */
class TeamScope {
	public const CONFIG = 'supervisor_teams';
	/** Tests : forcer le périmètre (tableau d'uids) ou l'absence de restriction (null). */
	public static bool $forced = false;
	public static ?array $forcedUsers = null;
	private static bool $done = false;
	private static ?array $memo = null;

	public static function force(?array $users): void { self::$forced = true; self::$forcedUsers = $users; }
	public static function reset(): void { self::$forced = false; self::$forcedUsers = null; self::$done = false; self::$memo = null; }

	/** uid superviseur => [groupes d'équipe valides] */
	public static function config(?\OCP\IConfig $cfg = null): array {
		try { $cfg ??= \OCP\Server::get(\OCP\IConfig::class); } catch (\Throwable $e) { return []; }
		$raw = json_decode((string)$cfg->getAppValue('callcrm', self::CONFIG, '{}'), true);
		$teams = array_keys(Access::teams()); $out = [];
		foreach (is_array($raw) ? $raw : [] as $uid => $gids) {
			$g = array_values(array_intersect(array_map('strval', (array)$gids), $teams));
			if ($g) { $out[(string)$uid] = $g; }
		}
		return $out;
	}

	/** Uids visibles (commerciaux de ses groupes + lui-même) ou null = pas de restriction. */
	public static function users(): ?array {
		if (self::$forced) { return self::$forcedUsers; }
		if (self::$done) { return self::$memo; }
		self::$done = true;
		if (!class_exists(\OC::class, false)) { return null; }
		try {
			$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
			if (!$user) { return null; }
			if (\OCP\Server::get(Access::class)->role() !== 'superviseur') { return null; }
			$gids = self::config()[$user->getUID()] ?? [];
			if (!$gids) { return null; }
			$gm = \OCP\Server::get(\OCP\IGroupManager::class);
			$uids = [$user->getUID()];
			foreach ($gids as $gid) { foreach ($gm->get($gid)?->getUsers() ?? [] as $u) { $uids[] = $u->getUID(); } }
			return self::$memo = array_values(array_unique($uids));
		} catch (\Throwable $e) { return null; }
	}

	/** Une fiche attribuée à $assigned est-elle visible ? (non attribuée : oui) */
	public static function canSee(?string $assigned): bool {
		$u = self::users();
		return $u === null || $assigned === null || $assigned === '' || in_array($assigned, $u, true);
	}

	/** Peut-on attribuer à ce commercial / filtrer sur lui ? */
	public static function canTarget(string $uid): bool { $u = self::users(); return $u === null || in_array($uid, $u, true); }

	/** Fiches : attribuées à son périmètre ou non attribuées. */
	public static function prospects(IQueryBuilder $qb, string $col = 'assigned_to'): void {
		$u = self::users();
		if ($u === null) { return; }
		$qb->andWhere($qb->expr()->orX($qb->expr()->in($col, $qb->createNamedParameter($u ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY)), $qb->expr()->isNull($col)));
	}

	/** Appels / actions : faits par une personne de son périmètre. */
	public static function actors(IQueryBuilder $qb, string $col = 'user_id'): void {
		$u = self::users();
		if ($u === null) { return; }
		$qb->andWhere($qb->expr()->in($col, $qb->createNamedParameter($u ?: ['-'], IQueryBuilder::PARAM_STR_ARRAY)));
	}
}
