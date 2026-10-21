<?php
namespace OCA\EasyCrm\Service;
use OCP\IGroupManager;
use OCP\IUserSession;
class Access {
	// Ordre = priorité
	public const DEF_GROUPS = ['admin' => 'easycrm_admin', 'superviseur' => 'easycrm_superviseur', 'commercial' => 'easycrm_commercial', 'lecture' => 'easycrm_lecture'];
	// Les noms de groupes peuvent être remplacés par des groupes de l'AD (occ config:app:set easycrm group_commercial --value="…")
	private static function cfg(string $k, string $def): string {
		static $c = null;
		$c ??= \OCP\Server::get(\OCP\IConfig::class);
		$v = trim((string)$c->getAppValue('easycrm', $k, ''));
		return $v !== '' ? $v : $def;
	}
	public static function groups(): array {
		$o = [];
		foreach (self::DEF_GROUPS as $role => $def) { $o[$role] = self::cfg('group_' . $role, $def); }
		return $o;
	}
	public static function teams(): array { return Teams::teams(); }
	public static function cats(): array { return Teams::cats(); }
	private const CAN = [
		'import' => ['admin', 'superviseur'], 'invite' => ['admin'], 'fields' => ['admin'], 'labels' => ['admin'], 'delete' => ['admin'],
		'assign' => ['admin', 'superviseur'], 'create' => ['admin', 'superviseur', 'commercial'], 'call' => ['admin', 'commercial'],
		'all' => ['admin', 'superviseur', 'lecture'], 'audit' => ['admin', 'superviseur'],
		'export' => ['admin', 'superviseur', 'commercial'], 'export_phone' => ['admin', 'superviseur'], 'mask' => ['lecture'],
	];
	public function __construct(private IUserSession $us, private IGroupManager $gm) {}
	public function uid(): string { return $this->us->getUser()->getUID(); }
	private ?string $roleMemo = null;
	public function role(): string {
		if ($this->roleMemo !== null) { return $this->roleMemo; }
		$u = $this->us->getUser();
		if (!$u) { return 'none'; }
		$uid = $u->getUID();
		if ($this->gm->isAdmin($uid)) { return $this->roleMemo = 'admin'; }
		foreach (self::groups() as $role => $g) {
			if ($this->gm->isInGroup($uid, $g)) { return $this->roleMemo = $role; }
		}
		return $this->roleMemo = 'none';
	}
	public function can(string $a): bool {
		$r = $this->role();
		if ($a === 'view') { return $r !== 'none'; }
		return in_array($r, self::CAN[$a] ?? [], true);
	}
}
