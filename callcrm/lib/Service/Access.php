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
		return $o + self::customTeams();
	}
	/** Groupes d'équipe créés dans l'administration : gid => nom. */
	public static function customTeams(): array {
		$raw = json_decode(self::cfg('custom_teams', '{}'), true); $o = [];
		foreach (is_array($raw) ? $raw : [] as $gid => $name) { if (is_string($name) && preg_match('/^crm_equipe_[a-z0-9_]{1,40}$/', (string)$gid)) { $o[(string)$gid] = $name; } }
		return $o;
	}
	public const BASES = ['superviseur' => 'Superviseur', 'commercial' => 'Commercial', 'lecture' => 'Lecture seule'];
	/** Profils créés dans l'administration : [{id, name, base, group}], par ordre de priorité. */
	public static function customProfiles(): array {
		$raw = json_decode(self::cfg('custom_profiles', '[]'), true); $o = [];
		foreach (is_array($raw) ? $raw : [] as $r) {
			if (!is_array($r) || !preg_match('/^p_[a-z0-9_]{1,40}$/', (string)($r['id'] ?? '')) || !isset(self::BASES[$r['base'] ?? '']) || !is_string($r['name'] ?? null) || !is_string($r['group'] ?? null)) { continue; }
			$o[] = ['id' => $r['id'], 'name' => $r['name'], 'base' => $r['base'], 'group' => $r['group']];
		}
		return $o;
	}
	/** Profils dont les droits se règlent : modèles + profils créés (id => nom). */
	public static function editableProfiles(): array {
		$o = self::BASES;
		foreach (self::customProfiles() as $p) { $o[$p['id']] = $p['name']; }
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
		'quality' => ['admin', 'superviseur'],
	];
	public function __construct(private IUserSession $us, private IGroupManager $gm) {}
	public function uid(): string { return $this->us->getUser()->getUID(); }
	private ?string $roleMemo = null;
	private ?string $profileMemo = null;
	/** Profil effectif : admin, un profil créé (prioritaire sur les modèles), puis superviseur / commercial / lecture. */
	public function profile(): string {
		if ($this->profileMemo !== null) { return $this->profileMemo; }
		$u = $this->us->getUser();
		if (!$u) { return 'none'; }
		$uid = $u->getUID();
		if ($this->gm->isAdmin($uid) || $this->gm->isInGroup($uid, self::groups()['admin'])) { return $this->profileMemo = 'admin'; }
		foreach (self::customProfiles() as $p) { if ($this->gm->isInGroup($uid, $p['group'])) { return $this->profileMemo = $p['id']; } }
		foreach (self::groups() as $role => $g) {
			if ($this->gm->isInGroup($uid, $g)) { return $this->profileMemo = $role; }
		}
		return $this->profileMemo = 'none';
	}
	/** Rôle de fonctionnement : un profil créé se comporte comme son modèle (écrans, périmètre). */
	public function role(): string {
		if ($this->roleMemo !== null) { return $this->roleMemo; }
		$p = $this->profile();
		foreach (self::customProfiles() as $c) { if ($c['id'] === $p) { return $this->roleMemo = $c['base']; } }
		return $this->roleMemo = $p;
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
		'quality' => ['Évaluer la qualité des appels', 'Onglet Qualité : écouter les enregistrements de tous les commerciaux et les noter avec la grille.'],
	];

	/** Droits par défaut, éventuellement remplacés par le réglage « role_rights » (l'administrateur garde toujours tout). */
	public static function rights(): array {
		$map = self::CAN;
		// Un profil créé hérite des droits de son modèle tant qu'aucun réglage ne le précise.
		foreach (self::customProfiles() as $p) { foreach ($map as $k => $roles) { if (in_array($p['base'], $roles, true)) { $map[$k][] = $p['id']; } } }
		// Profil « Qualiticien » (ou tout profil dont l'identifiant contient « qualit ») : évaluation qualité par défaut.
		foreach (self::customProfiles() as $p) { if (str_contains($p['id'], 'qualit')) { $map['quality'][] = $p['id']; } }
		try { $raw = json_decode((string)\OCP\Server::get(\OCP\IConfig::class)->getAppValue('callcrm', 'role_rights', ''), true); } catch (\Throwable $e) { $raw = null; }
		if (is_array($raw)) {
			$editable = array_keys(self::editableProfiles());
			$covered = array_map('strval', (array)($raw['_profiles'] ?? array_keys(self::BASES)));
			foreach (self::RIGHTS as $k => $_) {
				if (!isset($raw[$k]) || !is_array($raw[$k])) { continue; }
				$set = array_values(array_intersect($raw[$k], $editable));
				// Profils absents du réglage enregistré (créés depuis) : on garde leur héritage.
				$keep = array_values(array_filter($map[$k] ?? [], fn($r) => $r !== 'admin' && !in_array($r, $covered, true)));
				$roles = array_values(array_unique(array_merge($set, $keep)));
				$map[$k] = $k === 'mask' ? $roles : array_merge(['admin'], $roles);
			}
		}
		$map['teams'] = ['admin'];
		return $map;
	}
	public function can(string $a): bool {
		$r = $this->profile();
		if ($a === 'view') { return $r !== 'none'; }
		return in_array($r, self::rights()[$a] ?? [], true);
	}
}
