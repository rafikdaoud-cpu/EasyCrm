<?php
namespace OCA\CallCrm\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * Envoi de SMS par l'API HTTP ICOSNET (Notif+) : GET /bulksms/bulksms, crédit par GET /CreditCheck/checkcredits.
 * Le mot de passe est chiffré ; il ne quitte jamais le serveur et n'apparaît dans aucun message d'erreur.
 */
class Sms {
	public const MAX_SEGMENTS = 5;
	public const MAX_TEMPLATES = 30;
	public const HARD_BULK = 2000;
	public const DEFAULT_URL = 'https://smsapi.icosnet.com:8443';
	private const CHUNK = 50;
	// Alphabet GSM 03.38 (table de base + extension comptée double).
	private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	private const GSM_EXT = "^{}\\[~]|€";
	private const ERRORS = [
		'1702' => 'Requête incomplète (paramètre manquant).', '1703' => 'Identifiant ou mot de passe ICOSNET refusé.',
		'1704' => 'Type de message refusé.', '1705' => 'Message refusé par ICOSNET.', '1706' => 'Numéro de destination invalide.',
		'1707' => 'Nom d’expéditeur refusé par ICOSNET.', '1708' => 'Paramètre d’accusé de réception refusé.',
		'1709' => 'Validation du compte ICOSNET échouée (réessayez).', '1710' => 'Erreur interne ICOSNET.',
		'1725' => 'Crédit SMS insuffisant.', '1025' => 'Crédit SMS insuffisant.', '1715' => 'ICOSNET n’a pas répondu à temps.',
		'1732' => 'Numéro inscrit en liste d’opposition (DND).', '1032' => 'Numéro inscrit en liste d’opposition (DND).',
		'1042' => 'Numéro inscrit en liste d’opposition (DND).', '1028' => 'Message classé comme spam.', '1041' => 'Message en double.',
		'1033' => 'Message non conforme au modèle validé par ICOSNET.', '16' => 'Adresse IP du serveur bloquée par ICOSNET.',
		'18' => 'Adresse IP du serveur non autorisée chez ICOSNET.', '8' => 'Erreur interne ICOSNET.', '69' => 'Erreur interne ICOSNET.',
	];

	public function __construct(private IConfig $config, private ICrypto $crypto, private IClientService $http) {}

	private function stored(): array {
		$v = json_decode($this->config->getAppValue('callcrm', 'sms_settings', '{}'), true);
		return is_array($v) ? $v : [];
	}

	/** Réglages lisibles par le navigateur de l'administrateur (sans le mot de passe). */
	public function settings(): array {
		$s = $this->stored();
		return [
			'enabled' => ($s['enabled'] ?? false) === true,
			'url' => (string)($s['url'] ?? self::DEFAULT_URL),
			'username' => (string)($s['username'] ?? ''),
			'has_password' => !empty($s['password']),
			'sender' => (string)($s['sender'] ?? ''),
			'dlr' => ($s['dlr'] ?? false) === true,
			'max_bulk' => (int)($s['max_bulk'] ?? 300),
			'commercial_send' => ($s['commercial_send'] ?? true) === true,
			'templates' => array_values($s['templates'] ?? []),
		];
	}

	/** Ce que chaque utilisateur doit savoir pour proposer l'envoi. */
	public function runtime(): array {
		$s = $this->settings();
		return ['enabled' => $s['enabled'] && $this->configured(), 'templates' => $s['templates'], 'max_bulk' => $s['max_bulk'], 'commercial_send' => $s['commercial_send']];
	}

	public function configured(): bool {
		$s = $this->stored();
		return ($s['url'] ?? self::DEFAULT_URL) !== '' && !empty($s['username']) && !empty($s['password']) && !empty($s['sender']);
	}

	public function save(array $in): array {
		$s = $this->stored();
		if (array_key_exists('url', $in)) {
			$url = rtrim(trim((string)$in['url']), '/');
			if ($url !== '') {
				$p = parse_url($url);
				if (strlen($url) > 300 || !$p || !in_array($p['scheme'] ?? '', ['https', 'http'], true) || empty($p['host'])
					|| isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '', ['', '/'], true)
					|| preg_match('/[\s\x00-\x1f\\\\]/', $url)) {
					throw new \InvalidArgumentException('Adresse ICOSNET invalide : indiquez seulement https://serveur:port (sans /bulksms).');
				}
			}
			$s['url'] = $url;
		}
		if (array_key_exists('username', $in)) {
			$u = trim((string)$in['username']);
			if (strlen($u) > 100 || preg_match('/[\x00-\x1f]/', $u)) throw new \InvalidArgumentException('Identifiant ICOSNET invalide.');
			$s['username'] = $u;
		}
		if (!empty($in['clear_password'])) unset($s['password']);
		if (isset($in['password']) && (string)$in['password'] !== '') {
			if (strlen((string)$in['password']) > 200) throw new \InvalidArgumentException('Mot de passe trop long.');
			$s['password'] = $this->crypto->encrypt((string)$in['password']);
		}
		if (array_key_exists('sender', $in)) {
			$sender = trim((string)$in['sender']);
			if ($sender !== '' && !preg_match('/^(\+?\d{3,18}|[A-Za-z0-9 .\-]{1,11})$/D', $sender)) {
				throw new \InvalidArgumentException('Expéditeur invalide : 11 caractères maximum (lettres, chiffres, espace, point, tiret) ou un numéro.');
			}
			$s['sender'] = $sender;
		}
		if (array_key_exists('max_bulk', $in)) $s['max_bulk'] = max(1, min(self::HARD_BULK, (int)$in['max_bulk']));
		foreach (['enabled', 'dlr', 'commercial_send'] as $k) if (array_key_exists($k, $in)) $s[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
		if (array_key_exists('templates', $in)) {
			if (!is_array($in['templates'])) throw new \InvalidArgumentException('Modèles invalides.');
			$t = [];
			foreach ($in['templates'] as $row) {
				if (!is_array($row)) continue;
				$name = trim((string)($row['name'] ?? '')); $text = trim(str_replace("\r", '', (string)($row['text'] ?? '')));
				if ($name === '' && $text === '') continue;
				if ($name === '' || $text === '') throw new \InvalidArgumentException('Chaque modèle doit avoir un nom et un texte.');
				if (mb_strlen($name) > 60) throw new \InvalidArgumentException('Nom de modèle trop long (60 caractères).');
				if (self::segments($text)['segments'] > self::MAX_SEGMENTS) throw new \InvalidArgumentException('Le modèle « ' . $name . ' » est trop long (' . self::MAX_SEGMENTS . ' SMS maximum).');
				$t[] = ['name' => $name, 'text' => $text];
			}
			if (count($t) > self::MAX_TEMPLATES) throw new \InvalidArgumentException(self::MAX_TEMPLATES . ' modèles au maximum.');
			$s['templates'] = $t;
		}
		if (($s['enabled'] ?? false) === true && !(($s['url'] ?? self::DEFAULT_URL) !== '' && !empty($s['username']) && !empty($s['password']) && !empty($s['sender']))) {
			throw new \InvalidArgumentException('Pour activer l’envoi, renseignez l’adresse, l’identifiant, le mot de passe et l’expéditeur ICOSNET.');
		}
		$this->config->setAppValue('callcrm', 'sms_settings', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $this->settings();
	}

	/** Numéro mobile algérien au format international sans « + » (213XXXXXXXXX), sinon null. */
	public static function number(string $raw): ?string {
		$d = preg_replace('/\D+/', '', $raw);
		if ($d === null || $d === '') return null;
		if (str_starts_with($d, '00')) $d = substr($d, 2);
		if (preg_match('/^0([567]\d{8})$/D', $d, $m)) $d = '213' . $m[1];
		elseif (preg_match('/^([567]\d{8})$/D', $d, $m)) $d = '213' . $m[1];
		return preg_match('/^213[567]\d{8}$/D', $d) ? $d : null;
	}

	public static function isGsm(string $text): bool {
		foreach (mb_str_split($text) as $c) if (mb_strpos(self::GSM, $c) === false && mb_strpos(self::GSM_EXT, $c) === false) return false;
		return true;
	}

	/** Nombre de SMS facturés : 160/153 caractères en GSM, 70/67 en Unicode (arabe, ê, ç…). */
	public static function segments(string $text): array {
		$gsm = self::isGsm($text);
		if ($gsm) { $n = 0; foreach (mb_str_split($text) as $c) $n += mb_strpos(self::GSM_EXT, $c) !== false ? 2 : 1; }
		else { $n = mb_strlen($text); }
		[$one, $part] = $gsm ? [160, 153] : [70, 67];
		$seg = $n === 0 ? 0 : ($n <= $one ? 1 : (int)ceil($n / $part));
		return ['chars' => $n, 'segments' => $seg, 'unicode' => !$gsm];
	}

	/** Remplace {prenom}, {nom}, {commercial} ; le reste du texte est conservé tel quel. */
	public static function render(string $text, array $p, string $commercial): string {
		$out = strtr($text, ['{prenom}' => trim((string)($p['firstname'] ?? '')), '{nom}' => trim((string)($p['lastname'] ?? '')), '{commercial}' => $commercial]);
		return trim(preg_replace('/[ \t]{2,}/', ' ', $out) ?? $out);
	}

	public static function check(string $text): void {
		if (trim($text) === '') throw new \InvalidArgumentException('Le message est vide.');
		if (preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $text)) throw new \InvalidArgumentException('Le message contient des caractères invalides.');
		if (self::segments($text)['segments'] > self::MAX_SEGMENTS) throw new \InvalidArgumentException('Message trop long : ' . self::MAX_SEGMENTS . ' SMS au maximum.');
	}

	private function credentials(): array {
		if (!$this->configured()) throw new \RuntimeException('L’envoi de SMS n’est pas configuré (Administration → SMS ICOSNET).');
		$s = $this->stored();
		return ['url' => $s['url'] ?? self::DEFAULT_URL, 'username' => $s['username'], 'password' => $this->crypto->decrypt($s['password']), 'sender' => $s['sender'], 'dlr' => ($s['dlr'] ?? false) === true];
	}

	private function get(string $url): string {
		try {
			$r = $this->http->newClient()->get($url, ['timeout' => 30, 'connect_timeout' => 8, 'allow_redirects' => false, 'http_errors' => false,
				'headers' => ['User-Agent' => 'CallCRM-SMS']]);
			$body = (string)$r->getBody();
			if ($r->getStatusCode() !== 200) throw new \RuntimeException('HTTP ' . $r->getStatusCode());
			return substr($body, 0, 200000);
		} catch (\Throwable $e) {
			// L'exception d'origine contient l'URL complète, donc le mot de passe : ne jamais la relayer.
			throw new \RuntimeException('Connexion à ICOSNET impossible : vérifiez l’adresse du serveur et que l’IP du serveur est autorisée.');
		}
	}

	public static function error(string $code): string {
		return self::ERRORS[$code] ?? ('Refusé par ICOSNET (code ' . $code . ').');
	}

	/**
	 * Envoie un même texte à plusieurs numéros (déjà normalisés), par paquets de 50.
	 * @return array<string,array{ok:bool,code:string,id:string,error:string}> résultat par numéro
	 */
	public function send(array $numbers, string $text): array {
		self::check($text);
		$c = $this->credentials();
		$gsm = self::isGsm($text);
		// Spécification ICOSNET : type 0 = texte GSM 03.38 ; type 2 = Unicode, message en UTF-16BE écrit en hexadécimal.
		$message = $gsm ? $text : strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
		$out = [];
		foreach (array_chunk(array_values(array_unique($numbers)), self::CHUNK) as $chunk) {
			// Seul le code 1709 peut être retenté (une fois) ; jamais les autres, ni 1715.
			for ($attempt = 0, $todo = $chunk; $todo && $attempt < 2; $attempt++) {
				$query = http_build_query(['username' => $c['username'], 'password' => $c['password'], 'type' => $gsm ? 0 : 2, 'dlr' => $c['dlr'] ? 1 : 0,
					'destination' => implode(',', $todo), 'source' => $c['sender'], 'message' => $message], '', '&', PHP_QUERY_RFC3986);
				try { $body = $this->get($c['url'] . '/bulksms/bulksms?' . $query); }
				catch (\RuntimeException $e) { foreach ($todo as $n) $out[$n] = ['ok' => false, 'code' => 'net', 'id' => '', 'error' => $e->getMessage()]; break; }
				$retry = [];
				foreach (self::parse($body, $todo) as $n => $r) { $out[$n] = $r; if ($r['code'] === '1709') $retry[] = $n; }
				$todo = $retry;
			}
		}
		return $out;
	}

	/**
	 * Réponses « 1701|213XXXXXXXXX|idmessage » séparées par des virgules. Un lot interrompu (crédit épuisé, serveur SMPP
	 * indisponible) se termine par « 1025 | numéro » ou « 1709 | numéro » : ce numéro et les suivants ne sont pas envoyés.
	 * Un numéro invalide est simplement sauté par ICOSNET.
	 */
	public static function parse(string $body, array $numbers): array {
		$out = []; $generic = null; $abort = null;
		foreach (preg_split('/[,\r\n]+/', trim($body)) ?: [] as $part) {
			$part = preg_replace('/\s*\|\s*/', '|', trim($part)) ?? '';
			if ($part === '' || !preg_match('/^(\d{1,5})(?:\|(.*))?$/sD', $part, $m)) continue;
			$code = $m[1]; $rest = $m[2] ?? '';
			$bits = preg_split('/[|:]/', $rest) ?: [];
			$num = self::number((string)($bits[0] ?? '')) ?? '';
			$id = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($bits[1] ?? '')) ?? '', 0, 80);
			$r = ['ok' => $code === '1701', 'code' => $code, 'id' => $id, 'error' => $code === '1701' ? '' : self::error($code)];
			if (in_array($code, ['1025', '1725', '1709'], true) && $id === '' && $num !== '') { $abort = ['num' => $num, 'r' => $r]; continue; }
			if ($num !== '' && in_array($num, $numbers, true)) $out[$num] = $r; else $generic ??= $r;
		}
		if ($abort) {
			$i = array_search($abort['num'], $numbers, true);
			foreach ($numbers as $k => $n) if (!isset($out[$n]) && ($i === false || $k >= $i)) $out[$n] = ['ok' => false, 'code' => $abort['r']['code'], 'id' => '', 'error' => 'Envoi interrompu : ' . $abort['r']['error']];
		}
		$several = count($numbers) > 1 && count($out) > 0;
		foreach ($numbers as $n) {
			if (isset($out[$n])) continue;
			if ($several) { $out[$n] = ['ok' => false, 'code' => '1706', 'id' => '', 'error' => self::error('1706')]; continue; }
			// Réponse unique sans numéro (erreur globale, ou un seul destinataire) : s'applique à chacun.
			$out[$n] = $generic && ($generic['ok'] === false || count($numbers) === 1)
				? $generic : ['ok' => false, 'code' => '?', 'id' => '', 'error' => 'Réponse ICOSNET illisible : vérifiez l’envoi dans votre espace ICOSNET.'];
		}
		return $out;
	}

	/** Crédit restant (en dinars selon ICOSNET). */
	public function credit(): string {
		$c = $this->credentials();
		$body = trim($this->get($c['url'] . '/CreditCheck/checkcredits?' . http_build_query(['username' => $c['username'], 'password' => $c['password']], '', '&', PHP_QUERY_RFC3986)));
		if (preg_match('/^BALANCE\s*:\s*(-?[\d.,]+)/i', $body, $m)) return $m[1];
		$known = ['AUTHORIZATION_FAILED' => 'Identifiant ou mot de passe ICOSNET refusé (ou compte inactif).', 'INVALID_URL' => 'Identifiant ou mot de passe ICOSNET incorrect.',
			'INTERNAL_ERROR' => 'ICOSNET n’a pas pu lire le crédit (erreur interne).', 'PERMISSION_DENIED' => 'Trop de consultations du crédit : réessayez plus tard.'];
		foreach ($known as $k => $msg) if (stripos($body, $k) !== false) throw new \RuntimeException($msg);
		throw new \RuntimeException('Réponse ICOSNET inattendue pour le crédit.');
	}
}
