<?php
namespace OCA\EasyCrm\Service;

/** Normalisation et validation des numéros selon le pays choisi par l'administrateur. */
class Phone {
	/** pays => [indicatif, préfixe national (0), expressions de validité du numéro national] */
	private const PRESETS = [
		'DZ' => ['213', '0', ['/^0[567]\d{8}$/', '/^0[2349]\d{7,8}$/']],
		'MA' => ['212', '0', ['/^0[5-7]\d{8}$/']],
		'TN' => ['216', '', ['/^[2-9]\d{7}$/']],
		'FR' => ['33', '0', ['/^0[1-9]\d{8}$/']],
	];

	/** [indicatif, préfixe national, expressions] pour les réglages actuels */
	public static function rules(): array {
		$s = Settings::get();
		if (isset(self::PRESETS[$s['country']])) { return self::PRESETS[$s['country']]; }
		return [$s['cc'], $s['trunk'], ['/^\+?\d{6,15}$/']];
	}
	/** Informations utiles au navigateur (WhatsApp, import Excel) */
	public static function info(): array {
		[$cc, $trunk] = self::rules();
		return ['cc' => $cc, 'trunk' => $trunk];
	}
	public static function normalize(string $raw): ?string {
		[$cc, $trunk, $res] = self::rules();
		$plus = str_starts_with(trim($raw), '+');
		$d = preg_replace('/\D+/', '', Lookup::latin($raw));
		if ($d === '') { return null; }
		if ($cc !== '') {
			if (str_starts_with($d, '00' . $cc)) { $d = substr($d, 2 + strlen($cc)); }
			elseif (($plus || strlen($d) >= strlen($cc) + 8) && str_starts_with($d, $cc)) { $d = substr($d, strlen($cc)); }
			if ($trunk !== '' && !str_starts_with($d, $trunk)) { $d = $trunk . $d; }
		} elseif ($plus) { $d = '+' . $d; }
		foreach ($res as $re) { if (preg_match($re, $d)) { return $d; } }
		return null;
	}
	/** Numéro international (chiffres seuls) pour WhatsApp */
	public static function whatsapp(string $national): string {
		[$cc, $trunk] = self::rules();
		$d = preg_replace('/\D+/', '', $national);
		if ($cc === '') { return $d; }
		if ($trunk !== '' && str_starts_with($d, $trunk)) { $d = substr($d, strlen($trunk)); }
		return $cc . $d;
	}
}
