<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;

/** Définition configurable des champs de la fiche client (réglée par l'admin). */
class Fields {
	public const TYPES = ['text' => 'Texte libre', 'textarea' => 'Texte long', 'number' => 'Nombre', 'date' => 'Date', 'email' => 'E-mail',
		'yesno' => 'Oui / Non', 'select' => 'Liste (un seul choix)', 'multiselect' => 'Liste (plusieurs choix)',
		'whatsapp' => 'Numéro WhatsApp (algérien ou international)'];
	public const MAX_CUSTOM = 30;
	/** Champs intégrés : clé => [libellé, type par défaut, type verrouillé ?] */
	private const BUILTIN = [
		'lastname' => ['Nom et prénom', 'text', true],
		'phone' => ['Téléphone', 'tel', true],
		'email' => ['E-mail du client', 'email', true],
		'c5' => ['Wilaya', 'select', false],
		'c2' => ['Catégorie produit', 'select', true],
		'c3' => ['Type d’emploi', 'select', false],
		'c4' => ['Salaire', 'text', false],
		'remarque' => ['Commentaire', 'textarea', false],
	];

	public static function builtinOptions(string $k): array {
		return match ($k) { 'c5' => array_keys(Lookup::WILAYAS), 'c2' => Lookup::CATEGORIES, 'c3' => Lookup::EMPLOIS, default => [] };
	}

	private static function stored(): array {
		$raw = (string)\OCP\Server::get(IConfig::class)->getAppValue('callcrm', 'fields_cfg', '');
		$a = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($a) ? $a : [];
	}
	private static function opts($o): array {
		$o = array_map(fn($x) => str_replace('|', '/', mb_substr(trim((string)$x), 0, 100)), (array)$o);
		return array_slice(array_values(array_unique(array_filter($o, fn($x) => $x !== ''))), 0, 100);
	}

	/** Liste ordonnée complète (champs intégrés + personnalisés), prête à être envoyée au navigateur. */
	public static function all(): array {
		$out = []; $seen = [];
		foreach (self::stored() as $f) {
			if (!is_array($f) || empty($f['key'])) { continue; }
			$k = (string)$f['key'];
			if (isset($seen[$k])) { continue; }
			if (isset(self::BUILTIN[$k])) {
				$out[] = self::builtin($k, $f);
			} elseif (preg_match('/^x[0-9a-f]{6}$/', $k) && isset(self::TYPES[$f['type'] ?? ''])) {
				$out[] = ['key' => $k, 'label' => mb_substr(trim((string)($f['label'] ?? $k)), 0, 60), 'type' => $f['type'], 'required' => !empty($f['required']),
					'hidden' => false, 'builtin' => false, 'locked' => false, 'options' => self::opts($f['options'] ?? [])];
			} else { continue; }
			$seen[$k] = true;
		}
		foreach (array_keys(self::BUILTIN) as $k) {
			if (!isset($seen[$k])) { $out[] = self::builtin($k, []); }
		}
		return $out;
	}

	private static function builtin(string $k, array $f): array {
		[$label, $deftype, $lockedType] = self::BUILTIN[$k];
		$l = mb_substr(trim((string)($f['label'] ?? '')), 0, 60);
		$type = (!$lockedType && isset(self::TYPES[$f['type'] ?? ''])) ? $f['type'] : $deftype;
		$opts = in_array($type, ['select', 'multiselect'], true) ? (self::opts($f['options'] ?? []) ?: self::builtinOptions($k)) : [];
		return ['key' => $k, 'label' => $l !== '' ? $l : $label, 'type' => $type,
			'required' => $k === 'lastname' ? true : (array_key_exists('required', $f) ? !empty($f['required']) : $k === 'phone'),
			'hidden' => in_array($k, ['lastname', 'phone'], true) ? false : !empty($f['hidden']),
			'builtin' => true, 'locked' => $lockedType, 'options' => $opts, 'defaultOptions' => self::builtinOptions($k)];
	}

	/** Valide et enregistre la liste envoyée par l'admin ; renvoie la liste finale. */
	public static function save(array $in): array {
		$clean = []; $seen = []; $custom = 0;
		foreach ($in as $f) {
			if (!is_array($f)) { continue; }
			$k = (string)($f['key'] ?? '');
			$label = mb_substr(trim((string)($f['label'] ?? '')), 0, 60);
			$type = isset(self::TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
			if (isset(self::BUILTIN[$k])) {
				if (isset($seen[$k])) { continue; }
				$e = ['key' => $k, 'label' => $label !== '' ? $label : self::BUILTIN[$k][0], 'required' => $k === 'lastname' ? true : !empty($f['required']),
					'hidden' => in_array($k, ['lastname', 'phone'], true) ? false : !empty($f['hidden'])];
				if (!self::BUILTIN[$k][2]) {
					$e['type'] = $type;
					if (in_array($type, ['select', 'multiselect'], true)) { $e['options'] = self::opts($f['options'] ?? []); }
				}
				$clean[] = $e;
			} else {
				if ($label === '' || ++$custom > self::MAX_CUSTOM) { continue; }
				if (!preg_match('/^x[0-9a-f]{6}$/', $k) || isset($seen[$k])) { $k = 'x' . bin2hex(random_bytes(3)); }
				$clean[] = ['key' => $k, 'label' => $label, 'type' => $type, 'required' => !empty($f['required']),
					'options' => in_array($type, ['select', 'multiselect'], true) ? self::opts($f['options'] ?? []) : []];
			}
			$seen[$k] = true;
		}
		\OCP\Server::get(IConfig::class)->setAppValue('callcrm', 'fields_cfg', json_encode($clean, JSON_UNESCAPED_UNICODE));
		return self::all();
	}

	/** Normalise une valeur selon le type du champ ; '' si invalide. */
	/**
	 * Numéro WhatsApp au format international « +<indicatif><numéro> », ou null.
	 * Algérien : 05/06/07 + 8 chiffres ou fixe 02/03/04/09 (avec ou sans 0, 213 ou 00213) ;
	 * international : préfixe + ou 00, puis 8 à 15 chiffres (indicatif compris).
	 */
	public static function whatsapp(string $raw): ?string {
		$t = trim(Lookup::latin($raw));
		if ($t === '') { return null; }
		$intl = str_starts_with($t, '+') || str_starts_with(preg_replace('/\s+/', '', $t), '00');
		$d = preg_replace('/\D+/', '', $t);
		if (str_starts_with($d, '00')) { $d = substr($d, 2); }
		if (!$intl || str_starts_with($d, '213')) {
			$local = str_starts_with($d, '213') ? '0' . substr($d, 3) : ($d !== '' && $d[0] !== '0' ? '0' . $d : $d);
			if (preg_match('/^0[567]\d{8}$/', $local) || preg_match('/^0[2349]\d{7,8}$/', $local)) { return '+213' . substr($local, 1); }
			if (!$intl) { return null; }
		}
		return preg_match('/^[1-9]\d{7,14}$/', $d) ? '+' . $d : null;
	}
	/** Numéro WhatsApp renseigné sur la fiche (champ de type « whatsapp »), chiffres seuls (ex. 33612345678), sinon null. */
	public static function whatsappOf(array $prospect): ?string {
		$extra = is_array($prospect['extra'] ?? null) ? $prospect['extra'] : (json_decode((string)($prospect['extra'] ?? ''), true) ?: []);
		foreach (self::all() as $f) {
			if (($f['type'] ?? '') === 'whatsapp' && empty($f['builtin']) && !empty($extra[$f['key']])) {
				$n = self::whatsapp((string)$extra[$f['key']]);
				if ($n) { return substr($n, 1); }
			}
		}
		return null;
	}
	public static function norm(string $val, array $f): string {
		$val = trim($val);
		switch ($f['type']) {
			case 'number': $v = str_replace([' ', ','], ['', '.'], $val); return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : '';
			case 'date': return preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : '';
			case 'email': return filter_var($val, FILTER_VALIDATE_EMAIL) ? mb_substr($val, 0, 255) : '';
			case 'yesno': return in_array($val, ['Oui', 'Non'], true) ? $val : '';
			case 'select':
				if ($f['key'] === 'c5' && $f['options'] === self::builtinOptions('c5')) { return Lookup::wilaya($val); }
				if ($f['key'] === 'c3' && $f['options'] === Lookup::EMPLOIS) { return Lookup::emploi($val); }
				return in_array($val, $f['options'], true) ? $val : '';
			case 'multiselect':
				$sel = array_values(array_unique(array_filter(array_map('trim', explode('|', $val)), fn($x) => in_array($x, $f['options'], true))));
				return implode(' | ', $sel);
			case 'whatsapp': return self::whatsapp($val) ?? '';
			case 'textarea': return mb_substr($val, 0, 4000);
			default: return mb_substr($val, 0, 255);
		}
	}
}
