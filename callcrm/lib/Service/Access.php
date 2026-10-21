<?php
namespace OCA\CallCrm\Service;
use OCP\IGroupManager;
use OCP\IUserSession;
class Access {
	// Groupes par catégorie de produit (les commerciaux y sont rattachés)
	// catégorie de produit => groupe de commerciaux
	public const DEF_CATS = ['Motos' => 'callcrm_motos', 'Électroménager' => 'callcrm_electro_media', 'Média' => 'callcrm_electro_media', 'Aménagement' => 'callcrm_amenagement', 'Cuisine équipée' => 'callcrm_amenagement', 'Meuble' => 'callcrm_amenagement'];
	// groupe => nom affiché
	public const DEF_TEAMS = ['callcrm_motos' => 'Motos', 'callcrm_electro_media' => 'Électroménager & Média', 'callcrm_amenagement' => 'Aménagement, Cuisine & Meuble'];
	// Ordre = priorité
	public const DEF_GROUPS = ['admin' => 'callcrm_admin', 'superviseur' => 'callcrm_superviseur', 'commercial' => 'callcrm_commercial', 'lecture' => 'callcrm_lecture'];
	// Les noms de groupes peuvent être remplacés par des groupes de l'AD (occ config:app:set callcrm group_commercial --value="…")
	private static function cfg(string $k, string $def): string {
		static $c = null;
		$c ??= \OCP\Server::get(\OCP\IConfig::class);
		$v = trim((string)$c->getAppValue('callcrm', $k, ''));
		return $v !== '' ? $v : $def;
	}
	public static function groups(): array {
		$o = [];
		foreach (self::DEF_GROUPS as $role => $def) { $o[$role] = self::cfg('group_' . $role, $def); }
		return $o;
	}
	public static function teams(): array {
		$o = [];
		foreach (self::DEF_TEAMS as $def => $name) { $o[self::cfg('team_' . substr($def, 8), $def)] = $name; }
		return $o;
	}
	public static function cats(): array {
		$o = [];
		foreach (self::DEF_CATS as $cat => $def) { $o[$cat] = self::cfg('team_' . substr($def, 8), $def); }
		return $o;
	}
	private const CAN = [
		'import' => ['admin', 'superviseur'], 'invite' => ['admin'], 'fields' => ['admin'], 'labels' => ['admin'], 'delete' => ['admin'],
		'assign' => ['admin', 'superviseur'], 'create' => ['admin', 'superviseur', 'commercial'], 'call' => ['admin', 'commercial'],
		'all' => ['admin', 'superviseur', 'lecture'], 'audit' => ['admin', 'superviseur'],
		'export' => ['admin', 'superviseur', 'commercial', 'lecture'], 'export_phone' => ['admin', 'superviseur'], 'mask' => [],
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
