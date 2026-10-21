<?php
namespace OCA\EasyCrm\Service;

use OCP\IConfig;

/** Réglages de la prospection : limite de tentatives, plage et espacement des rappels, objectif quotidien, motifs. */
class Prefs {
	public const DEF_REASONS = ['Prix trop élevé', 'Déjà client / déjà équipé', 'Dossier refusé par la banque', 'Pas de besoin', 'Mauvais moment', 'Autre'];
	private static function cfg(): IConfig { return \OCP\Server::get(IConfig::class); }

	/** Minutes depuis minuit ("08:00" → 480) ; '' ou invalide → null (non limité). */
	public static function hm(string $s): ?int {
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) { return null; }
		$v = (int)$m[1] * 60 + (int)$m[2];
		return ($m[1] > 23 || $m[2] > 59) ? null : $v;
	}
	public static function all(): array {
		$c = self::cfg();
		$from = (string)$c->getAppValue('easycrm', 'cb_from', '08:00');
		$to = (string)$c->getAppValue('easycrm', 'cb_to', '18:00');
		$tz = (string)$c->getAppValue('easycrm', 'tz', 'Africa/Algiers');
		try { new \DateTimeZone($tz); } catch (\Throwable $e) { $tz = 'Africa/Algiers'; }
		$reasons = json_decode((string)$c->getAppValue('easycrm', 'reasons', ''), true);
		if (!is_array($reasons) || !$reasons) { $reasons = self::DEF_REASONS; }
		return [
			'max_callbacks' => max(0, min(50, (int)$c->getAppValue('easycrm', 'max_callbacks', '0'))),
			'cb_from' => self::hm($from) !== null ? $from : '', 'cb_to' => self::hm($to) !== null ? $to : '',
			'cb_gap' => max(0, min(1440, (int)$c->getAppValue('easycrm', 'cb_gap', '60'))),
			'goal' => max(0, min(1000, (int)$c->getAppValue('easycrm', 'goal', '0'))),
			'tz' => $tz, 'reasons' => array_values($reasons),
		];
	}
	public static function save(array $in): array {
		$c = self::cfg(); $cur = self::all(); $err = null;
		$int = fn($k, $max) => max(0, min($max, (int)($in[$k] ?? $cur[$k])));
		$c->setAppValue('easycrm', 'max_callbacks', (string)$int('max_callbacks', 50));
		$c->setAppValue('easycrm', 'cb_gap', (string)$int('cb_gap', 1440));
		$c->setAppValue('easycrm', 'goal', (string)$int('goal', 1000));
		foreach (['cb_from', 'cb_to'] as $k) {
			$v = trim((string)($in[$k] ?? $cur[$k]));
			if ($v !== '' && self::hm($v) === null) { throw new \InvalidArgumentException('Heure invalide : ' . $v . ' (format HH:MM)'); }
			$c->setAppValue('easycrm', $k, $v);
		}
		$a = self::hm((string)$c->getAppValue('easycrm', 'cb_from', '')); $b = self::hm((string)$c->getAppValue('easycrm', 'cb_to', ''));
		if ($a !== null && $b !== null && $a >= $b) { throw new \InvalidArgumentException('La plage de rappel est incohérente : l’heure de début doit précéder l’heure de fin'); }
		if (isset($in['tz'])) {
			$tz = trim((string)$in['tz']);
			try { new \DateTimeZone($tz); $c->setAppValue('easycrm', 'tz', $tz); } catch (\Throwable $e) { throw new \InvalidArgumentException('Fuseau horaire invalide : ' . $tz); }
		}
		if (isset($in['reasons'])) {
			$l = is_array($in['reasons']) ? $in['reasons'] : preg_split('/\R/u', (string)$in['reasons']);
			$l = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), $l), fn($x) => $x !== '')));
			$c->setAppValue('easycrm', 'reasons', json_encode(array_slice($l, 0, 30), JSON_UNESCAPED_UNICODE));
		}
		return self::all();
	}
	/** Vérifie une date de rappel (timestamp). Retourne un message d'erreur ou null. $ref = instant de l'appel. */
	public static function checkCallback(int $cb, int $ref): ?string {
		$p = self::all();
		if ($p['cb_gap'] > 0 && $cb < $ref + $p['cb_gap'] * 60 - 120) {
			return 'Le rappel doit être programmé au moins ' . $p['cb_gap'] . ' min après l’appel (espacement des tentatives).';
		}
		$a = self::hm($p['cb_from']); $b = self::hm($p['cb_to']);
		if ($a !== null && $b !== null) {
			$d = (new \DateTime('@' . $cb))->setTimezone(new \DateTimeZone($p['tz']));
			$m = (int)$d->format('G') * 60 + (int)$d->format('i');
			if ($m < $a || $m > $b) { return 'Rappel hors de la plage autorisée (' . $p['cb_from'] . ' – ' . $p['cb_to'] . ').'; }
		}
		return null;
	}
}
