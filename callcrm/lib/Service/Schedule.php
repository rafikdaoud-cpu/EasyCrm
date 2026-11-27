<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

/**
 * Jours et horaires de travail, jours fériés (Administration → Jours et horaires de travail).
 * Chaque jour : « open » (tout le monde travaille), « brigade » (ouvert, mais le jour ne compte dans l'objectif d'un
 * commercial que s'il a travaillé ce jour-là) ou « closed ». Un jour férié est fermé. Sert au calcul « hors horaires »
 * du pilotage, aux objectifs sur une période et au début de « Cette semaine ». Le standard Yeastar garde ses propres horaires.
 */
class Schedule {
	public const DAYS = [7 => 'Dimanche', 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'];
	public const KINDS = ['open', 'brigade', 'closed'];
	public const DEFAULT = ['days' => [7 => 'open', 1 => 'open', 2 => 'open', 3 => 'open', 4 => 'open', 5 => 'closed', 6 => 'brigade'],
		'open' => '08:30', 'close' => '16:30', 'week_start' => 7, 'holidays' => []];
	private static ?array $memo = null;

	/** Réglage enregistré (ou par défaut hors Nextcloud, par exemple dans les tests). */
	public static function current(): array {
		if (self::$memo !== null) return self::$memo;
		$raw = null;
		if (class_exists(\OCP\Server::class)) {
			try { $raw = json_decode((string)\OCP\Server::get(\OCP\IConfig::class)->getAppValue('callcrm', 'work_schedule', ''), true); } catch (\Throwable $e) { $raw = null; }
		}
		try { return self::$memo = is_array($raw) ? self::normalize($raw) : self::DEFAULT; } catch (\InvalidArgumentException $e) { return self::$memo = self::DEFAULT; }
	}
	public static function set(?array $s): void { self::$memo = $s; }

	public static function save(array $in): array {
		$s = self::normalize($in);
		\OCP\Server::get(\OCP\IConfig::class)->setAppValue('callcrm', 'work_schedule', json_encode($s, JSON_UNESCAPED_UNICODE));
		return self::$memo = $s;
	}

	/** Minutes depuis minuit d'une heure « HH:MM », ou null. */
	public static function minutes(string $v): ?int {
		if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $v, $m)) return null;
		return (int)$m[1] * 60 + (int)$m[2];
	}

	public static function normalize(array $in): array {
		$days = [];
		foreach (array_keys(self::DAYS) as $n) {
			$k = (string)(($in['days'] ?? [])[$n] ?? ($in['days'] ?? [])[(string)$n] ?? self::DEFAULT['days'][$n]);
			if (!in_array($k, self::KINDS, true)) throw new \InvalidArgumentException('Type de jour invalide.');
			$days[$n] = $k;
		}
		if (!array_filter($days, fn($k) => $k !== 'closed')) throw new \InvalidArgumentException('Gardez au moins un jour travaillé.');
		$open = (string)($in['open'] ?? self::DEFAULT['open']); $close = (string)($in['close'] ?? self::DEFAULT['close']);
		$a = self::minutes($open); $b = self::minutes($close);
		if ($a === null || $b === null) throw new \InvalidArgumentException('Heures au format HH:MM.');
		if ($a >= $b) throw new \InvalidArgumentException('L’heure de fermeture doit suivre l’heure d’ouverture.');
		$ws = (int)($in['week_start'] ?? 7);
		if (!isset(self::DAYS[$ws])) throw new \InvalidArgumentException('Premier jour de la semaine invalide.');
		$hol = [];
		foreach (array_slice((array)($in['holidays'] ?? []), 0, 200) as $h) {
			if (!is_array($h)) continue;
			$date = trim((string)($h['date'] ?? ''));
			if ($date === '') continue;
			$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
			if (!$d || $d->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Date de jour férié invalide : ' . $date);
			$yearly = !empty($h['yearly']);
			$key = $yearly ? substr($date, 5) : $date;
			$hol[$key] = ['date' => $date, 'label' => mb_substr(trim((string)($h['label'] ?? '')), 0, 80), 'yearly' => $yearly];
		}
		usort($hol, fn($x, $y) => strcmp(substr($x['date'], 5), substr($y['date'], 5)) ?: strcmp($x['date'], $y['date']));
		return ['days' => $days, 'open' => $open, 'close' => $close, 'week_start' => $ws, 'holidays' => array_values($hol)];
	}

	public static function holiday(array $s, \DateTimeInterface $d): ?array {
		$ymd = $d->format('Y-m-d'); $md = $d->format('m-d');
		foreach ($s['holidays'] as $h) if ($h['yearly'] ? substr($h['date'], 5) === $md : $h['date'] === $ymd) return $h;
		return null;
	}
	/** Le centre est-il ouvert ce jour-là (jour travaillé ou de brigade, hors jour férié) ? */
	public static function isOpen(array $s, \DateTimeInterface $d): bool {
		return $s['days'][(int)$d->format('N')] !== 'closed' && !self::holiday($s, $d);
	}
	/** Le jour compte-t-il dans l'objectif d'un commercial ($worked : il a saisi des appels ce jour-là) ? */
	public static function counts(array $s, \DateTimeInterface $d, bool $worked): bool {
		if (self::holiday($s, $d)) return false;
		$k = $s['days'][(int)$d->format('N')];
		return $k === 'open' || ($k === 'brigade' && $worked);
	}
}
