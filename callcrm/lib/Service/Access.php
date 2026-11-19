<?php
namespace OCA\CallCrm\Service;
use OCP\IGroupManager;
use OCP\IUserSession;
class Access {
	// Groupes par catégorie de produit (les commerciaux y sont rattachés)
	// catégorie de produit => groupe de commerciaux
	public const DEF_CATS = ['Motos' => 'callcrm_motos', 'Électroménager' => 'callcrm_electro_media', 'Média' => 'callcrm_electro_media', 'Aménagement' => 'callcrm_amenagement', 'Cuisine équipée' => 'callcrm_amenagement', 'Meuble' => 'callcrm_amenagement'];
	// groupe => nom affiché
	public const DEF_TEAMS = ['callcrm_motos' => 'Motos', 'callcrm_electro_media' => 'Électroménager & Média', 'callcrm_amenagement' => 'Aménagement, Cuisine & Meuble', 'callcrm_reclamation' => 'Réclamation'];
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
		'import' => ['admin', 'superviseur'], 'invite' => ['admin'], 'teams' => ['admin'], 'fields' => ['admin'], 'labels' => ['admin'], 'delete' => ['admin'],
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
	/** Droits réglables dans Administration → Droits des profils (libellé, explication). */
	public const RIGHTS = [
		'call' => ['Appeler et saisir des résultats', 'Bouton d’appel et qualification des appels.'],
		'create' => ['Créer des prospects', 'Ajouter une fiche à la main.'],
		'all' => ['Voir toutes les fiches', 'Sinon, seulement ses propres fiches (superviseur : limité à ses groupes s’il en a).'],
		'assign' => ['Attribuer et transférer', 'Réattribuer des fiches, transferts et changements de statut en masse.'],
		'import' => ['Importer des prospects', 'Import Excel / CSV.'],
		'export' => ['Exporter en Excel', 'Téléchargement des listes et du journal.'],
		'export_phone' => ['Exporter les numéros', 'Sinon l’export se fait sans numéro de téléphone.'],
		'audit' => ['Consulter le journal d’audit', 'Historique des actions sensibles.'],
		'delete' => ['Supprimer des fiches', 'Suppression définitive d’un prospect.'],
		'labels' => ['Modifier les libellés', 'Libellés des champs.'],
		'fields' => ['Configurer le CRM', 'Champs, statuts, réglages, simulateur.'],
		'invite' => ['Inviter les commerciaux', 'Envoi des accès par e-mail.'],
		'teams' => ['Gérer les groupes', 'Changer un commercial de groupe, groupes des superviseurs, droits des profils.'],
		'mask' => ['Masquer téléphones et e-mails', 'Restriction : le profil voit les fiches sans coordonnées.'],
	];
	public const EDITABLE_ROLES = ['superviseur', 'commercial', 'lecture'];
	/** Droits par défaut, éventuellement remplacés par le réglage « role_rights » (l'administrateur garde toujours tout). */
	public static function rights(): array {
		$map = self::CAN;
		try { $raw = json_decode((string)\OCP\Server::get(\OCP\IConfig::class)->getAppValue('callcrm', 'role_rights', ''), true); } catch (\Throwable $e) { $raw = null; }
		if (is_array($raw)) {
			foreach (self::RIGHTS as $k => $_) {
				if (!isset($raw[$k]) || !is_array($raw[$k])) { continue; }
				$roles = array_values(array_intersect($raw[$k], self::EDITABLE_ROLES));
				$map[$k] = $k === 'mask' ? $roles : array_merge(['admin'], $roles);
			}
		}
		$map['teams'] = ['admin'];
		return $map;
	}
	public function can(string $a): bool {
		$r = $this->role();
		if ($a === 'view') { return $r !== 'none'; }
		return in_array($r, self::rights()[$a] ?? [], true);
	}
}
