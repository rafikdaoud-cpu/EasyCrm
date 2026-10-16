<?php
namespace OCA\EasyCrm\Service;

use OCP\IConfig;

/** Réglages généraux (entreprise, pays / format de téléphone), modifiables par l'administrateur. */
class Settings {
	public const COUNTRIES = [
		'DZ' => 'Algérie', 'MA' => 'Maroc', 'TN' => 'Tunisie', 'FR' => 'France', 'OTHER' => 'Autre pays',
	];
	private static function stored(): array {
		$raw = (string)\OCP\Server::get(IConfig::class)->getAppValue('easycrm', 'settings', '');
		$a = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($a) ? $a : [];
	}
	public static function get(): array {
		$s = self::stored();
		$country = isset(self::COUNTRIES[$s['country'] ?? '']) ? $s['country'] : 'DZ';
		$company = mb_substr(trim((string)($s['company'] ?? '')), 0, 80);
		return [
			'company' => $company !== '' ? $company : 'Mon entreprise',
			'country' => $country,
			'cc' => preg_replace('/\D+/', '', (string)($s['cc'] ?? '')),
			'trunk' => preg_replace('/\D+/', '', (string)($s['trunk'] ?? '')),
		];
	}
	public static function save(array $in): array {
		$country = isset(self::COUNTRIES[$in['country'] ?? '']) ? $in['country'] : 'DZ';
		$clean = [
			'company' => mb_substr(trim((string)($in['company'] ?? '')), 0, 80),
			'country' => $country,
			'cc' => mb_substr(preg_replace('/\D+/', '', (string)($in['cc'] ?? '')), 0, 4),
			'trunk' => mb_substr(preg_replace('/\D+/', '', (string)($in['trunk'] ?? '')), 0, 2),
		];
		\OCP\Server::get(IConfig::class)->setAppValue('easycrm', 'settings', json_encode($clean, JSON_UNESCAPED_UNICODE));
		return self::get();
	}
}
