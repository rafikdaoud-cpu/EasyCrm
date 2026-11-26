<?php
namespace OCA\Reclamations\Service;
use OCP\IGroupManager;
use OCP\IUserSession;
class Access {
	public const GROUPS = ['admin' => 'reclam_admin', 'superviseur' => 'reclam_superviseur', 'agent' => 'reclam_agent', 'lecture' => 'reclam_lecture'];
	private const CAN = [
		'import' => ['admin'], 'delete' => ['admin'], 'config' => ['admin'],
		'assign' => ['admin', 'superviseur'], 'create' => ['admin', 'superviseur', 'agent', 'saisie'],
		'handle' => ['admin', 'superviseur', 'agent', 'saisie'], 'close' => ['admin', 'superviseur'],
		'all' => ['admin', 'superviseur', 'lecture', 'saisie'],
	];
	public function __construct(private IUserSession $us, private IGroupManager $gm) {}
	public function uid(): string { return $this->us->getUser()->getUID(); }
	public function role(): string {
		$u = $this->us->getUser();
		if (!$u) { return 'none'; }
		$uid = $u->getUID();
		if ($this->gm->isAdmin($uid)) { return 'admin'; }
		foreach (self::GROUPS as $role => $g) { if ($this->gm->isInGroup($uid, $g)) { return $role; } }
		return 'saisie'; // tout autre utilisateur connecté : peut voir, saisir et modifier les réclamations
	}
	public function can(string $a): bool {
		$r = $this->role();
		if ($a === 'view') { return $r !== 'none'; }
		return in_array($r, self::CAN[$a] ?? [], true);
	}
}
