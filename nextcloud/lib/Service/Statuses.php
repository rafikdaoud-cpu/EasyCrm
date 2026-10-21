<?php
namespace OCA\EasyCrm\Service;

use OCP\IConfig;

/** Statuts / réponses d'appel personnalisables (libellé, couleur, règles de saisie). */
class Statuses {
	public const MAX_CUSTOM = 20;
	/** clé => [libellé, couleur, rappel obligatoire, note obligatoire, motif obligatoire] */
	private const BUILTIN = [
		'nouveau' => ['Nouveau', '#3b82f6', false, false, false],
		'interesse' => ['Intéressé', '#2e9e55', false, false, false],
		'injoignable' => ['Ne répond pas', '#e9a100', true, false, false],
		'rappel' => ['À rappeler (occupé)', '#f0c040', true, false, false],
		'pas_interesse' => ['Pas intéressé', '#888888', false, true, true],
		'non_eligible' => ['Non éligible', '#8a4b9d', false, false, true],
		'faux_numero' => ['Injoignable / faux numéro', '#c0392b', false, false, false],
	];

	private static function stored(): array {
		$raw = (string)\OCP\Server::get(IConfig::class)->getAppValue('easycrm', 'statuses_cfg', '');
		$a = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($a) ? $a : [];
	}
	private static function color($c, string $def): string { return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $def; }

	public static function all(): array {
		$out = []; $seen = [];
		foreach (self::stored() as $f) {
			if (!is_array($f) || empty($f['key'])) { continue; }
			$k = (string)$f['key'];
			if (isset($seen[$k])) { continue; }
			if (isset(self::BUILTIN[$k])) { $out[] = self::builtin($k, $f); }
			elseif (preg_match('/^s[0-9a-f]{6}$/', $k)) {
				$l = mb_substr(trim((string)($f['label'] ?? '')), 0, 40);
				$out[] = ['key' => $k, 'label' => $l !== '' ? $l : $k, 'color' => self::color($f['color'] ?? '', '#607d8b'), 'callback' => !empty($f['callback']),
					'note' => !empty($f['note']), 'reason' => !empty($f['reason']), 'active' => !array_key_exists('active', $f) || !empty($f['active']), 'builtin' => false, 'result' => true];
			} else { continue; }
			$seen[$k] = true;
		}
		foreach (array_keys(self::BUILTIN) as $k) { if (!isset($seen[$k])) { $out[] = self::builtin($k, []); } }
		return $out;
	}
	private static function builtin(string $k, array $f): array {
		[$label, $color, $cb, $note, $rs] = self::BUILTIN[$k];
		$l = mb_substr(trim((string)($f['label'] ?? '')), 0, 40);
		$isNew = $k === 'nouveau';
		return ['key' => $k, 'label' => $l !== '' ? $l : $label, 'color' => self::color($f['color'] ?? '', $color),
			'callback' => $isNew ? false : (array_key_exists('callback', $f) ? !empty($f['callback']) : $cb),
			'note' => $isNew ? false : (array_key_exists('note', $f) ? !empty($f['note']) : $note),
			'reason' => $isNew ? false : (array_key_exists('reason', $f) ? !empty($f['reason']) : $rs),
			'active' => $isNew ? true : (!array_key_exists('active', $f) || !empty($f['active'])), 'builtin' => true, 'result' => !$isNew];
	}

	public static function keys(): array { return array_column(self::all(), 'key'); }
	/** Réponses proposables lors d'un appel (actives, hors « Nouveau »). */
	public static function results(): array { return array_column(array_filter(self::all(), fn($s) => $s['result'] && $s['active']), 'key'); }
	public static function callbackKeys(): array { return array_values(array_column(array_filter(self::all(), fn($s) => $s['callback']), 'key')); }
	public static function labels(): array { return array_column(self::all(), 'label', 'key'); }
	public static function get(string $k): ?array { foreach (self::all() as $s) { if ($s['key'] === $k) { return $s; } } return null; }

	/** @param array $inUse clé => nombre de prospects utilisant ce statut */
	public static function save(array $in, array $inUse): array {
		$clean = []; $seen = []; $custom = 0;
		foreach ($in as $f) {
			if (!is_array($f)) { continue; }
			$k = (string)($f['key'] ?? '');
			$label = mb_substr(trim((string)($f['label'] ?? '')), 0, 40);
			$color = self::color($f['color'] ?? '', '#607d8b');
			if (isset(self::BUILTIN[$k])) {
				if (isset($seen[$k])) { continue; }
				$clean[] = ['key' => $k, 'label' => $label !== '' ? $label : self::BUILTIN[$k][0], 'color' => $color, 'callback' => $k !== 'nouveau' && !empty($f['callback']),
					'note' => $k !== 'nouveau' && !empty($f['note']), 'reason' => $k !== 'nouveau' && !empty($f['reason']), 'active' => $k === 'nouveau' ? true : !empty($f['active'])];
			} else {
				if ($label === '' || ++$custom > self::MAX_CUSTOM) { continue; }
				if (!preg_match('/^s[0-9a-f]{6}$/', $k) || isset($seen[$k])) { $k = 's' . bin2hex(random_bytes(3)); }
				$clean[] = ['key' => $k, 'label' => $label, 'color' => $color, 'callback' => !empty($f['callback']), 'note' => !empty($f['note']), 'reason' => !empty($f['reason']), 'active' => !empty($f['active'])];
			}
			$seen[$k] = true;
		}
		// un statut personnalisé encore utilisé ne peut pas disparaître
		foreach (self::stored() as $old) {
			$k = (string)($old['key'] ?? '');
			if (!isset(self::BUILTIN[$k]) && !isset($seen[$k]) && preg_match('/^s[0-9a-f]{6}$/', $k) && !empty($inUse[$k])) {
				throw new \InvalidArgumentException('Le statut « ' . ($old['label'] ?? $k) . ' » est utilisé par ' . $inUse[$k] . ' prospect(s) : désactivez-le plutôt que de le supprimer.');
			}
		}
		\OCP\Server::get(IConfig::class)->setAppValue('easycrm', 'statuses_cfg', json_encode($clean, JSON_UNESCAPED_UNICODE));
		return self::all();
	}
}
