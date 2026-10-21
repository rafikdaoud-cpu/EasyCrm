<?php
namespace OCA\EasyCrm\Service;

use OCP\IConfig;

/**
 * Connecteurs téléphonie (PBX / SIP) et WhatsApp. Chaque fonction d'envoi renvoie [bool ok, string message].
 * Aucun accès base de données ici : testable contre un serveur simulé.
 */
class Telephony {
	public const TIMEOUT = 10;
	public const MASK = '********';
	public const SIP = [
		'none' => ['label' => 'Désactivé (appel depuis le smartphone)', 'fields' => [], 'defaults' => []],
		'uri' => ['label' => 'Lien softphone (tel: / sip: / callto:)', 'fields' => ['dial_format', 'dial_prefix', 'uri_scheme'],
			'defaults' => ['uri_scheme' => 'tel', 'dial_format' => 'national', 'dial_prefix' => '']],
		'asterisk' => ['label' => 'Asterisk / FreePBX / Issabel (AMI)', 'fields' => ['host', 'port', 'user', 'secret', 'channel', 'context', 'dial_format', 'dial_prefix'],
			'defaults' => ['port' => '5038', 'channel' => 'PJSIP/{ext}', 'context' => 'from-internal', 'dial_format' => 'national', 'dial_prefix' => '']],
		'yeastar' => ['label' => 'Yeastar P-Series (OpenAPI)', 'fields' => ['url', 'user', 'secret', 'dial_format', 'dial_prefix', 'verify_ssl'],
			'defaults' => ['url' => 'https://pbx.example.com:8088', 'dial_format' => 'national', 'dial_prefix' => '', 'verify_ssl' => '1']],
		'3cx' => ['label' => '3CX (Call Control API)', 'fields' => ['url', 'user', 'secret', 'dial_format', 'dial_prefix', 'verify_ssl'],
			'defaults' => ['url' => 'https://pbx.example.com', 'dial_format' => 'e164', 'dial_prefix' => '', 'verify_ssl' => '1']],
		'http' => ['label' => 'API HTTP générique (tout standard)', 'fields' => ['url', 'http_method', 'http_headers', 'http_body', 'dial_format', 'dial_prefix', 'verify_ssl'],
			'defaults' => ['http_method' => 'POST', 'http_headers' => '{"Content-Type": "application/json"}', 'http_body' => '{"extension": "{ext}", "number": "{number}"}',
				'dial_format' => 'national', 'dial_prefix' => '', 'verify_ssl' => '1']],
	];
	public const WA = [
		'link' => ['label' => 'Lien WhatsApp (wa.me — message envoyé manuellement)', 'fields' => [], 'defaults' => []],
		'cloud' => ['label' => 'Meta WhatsApp Cloud API (officielle)', 'fields' => ['phone_id', 'token', 'template', 'language', 'api_version'],
			'defaults' => ['api_version' => 'v21.0', 'language' => 'fr']],
		'360dialog' => ['label' => '360dialog (WhatsApp Business API)', 'fields' => ['token', 'template', 'language'], 'defaults' => ['language' => 'fr']],
		'twilio' => ['label' => 'Twilio WhatsApp', 'fields' => ['account_sid', 'token', 'from_number', 'template'], 'defaults' => []],
		'http' => ['label' => 'API HTTP générique (toute passerelle)', 'fields' => ['url', 'http_method', 'http_headers', 'http_body'],
			'defaults' => ['http_method' => 'POST', 'http_headers' => '{"Content-Type": "application/json"}', 'http_body' => '{"to": "{number}", "text": "{text}"}']],
	];
	private const SECRETS = ['secret', 'token'];
	private const FIELDS = ['host', 'port', 'user', 'secret', 'url', 'channel', 'context', 'dial_format', 'dial_prefix', 'uri_scheme', 'verify_ssl',
		'http_method', 'http_headers', 'http_body', 'phone_id', 'token', 'template', 'language', 'api_version', 'account_sid', 'from_number'];

	// ------------------------------------------------------------------ configuration
	private static function cfgApp(): IConfig { return \OCP\Server::get(IConfig::class); }
	public static function stored(): array {
		$raw = (string)self::cfgApp()->getAppValue('easycrm', 'telephony', '');
		$a = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($a) ? $a : [];
	}
	/** Configuration complète (avec secrets) : ['sip' => [provider, ...], 'wa' => [...], 'secret' => webhook, ...] */
	public static function get(): array {
		$s = self::stored();
		$sip = is_array($s['sip'] ?? null) ? $s['sip'] : []; $wa = is_array($s['wa'] ?? null) ? $s['wa'] : [];
		$sp = isset(self::SIP[$sip['provider'] ?? '']) ? $sip['provider'] : 'none';
		$wp = isset(self::WA[$wa['provider'] ?? '']) ? $wa['provider'] : 'link';
		return ['sip' => ['provider' => $sp] + array_merge(self::SIP[$sp]['defaults'], array_intersect_key($sip, array_flip(self::FIELDS))),
			'wa' => ['provider' => $wp] + array_merge(self::WA[$wp]['defaults'], array_intersect_key($wa, array_flip(self::FIELDS))),
			'secret' => (string)($s['secret'] ?? ''), 'answered' => (string)($s['answered'] ?? ''), 'missed' => (string)($s['missed'] ?? '')];
	}
	/** Version envoyée au navigateur de l'administrateur : secrets masqués. */
	public static function forAdmin(): array {
		$g = self::get();
		foreach (['sip', 'wa'] as $k) { foreach (self::SECRETS as $f) { if (!empty($g[$k][$f])) { $g[$k][$f] = self::MASK; } } }
		return $g + ['sip_presets' => self::presets(self::SIP), 'wa_presets' => self::presets(self::WA)];
	}
	private static function presets(array $p): array {
		$o = []; foreach ($p as $k => $v) { $o[] = ['id' => $k] + $v; } return $o;
	}
	public static function save(array $in): array {
		$old = self::get();
		$clean = [];
		foreach (['sip' => self::SIP, 'wa' => self::WA] as $k => $presets) {
			$src = is_array($in[$k] ?? null) ? $in[$k] : [];
			$prov = isset($presets[$src['provider'] ?? '']) ? $src['provider'] : array_key_first($presets);
			$row = ['provider' => $prov];
			foreach ($presets[$prov]['fields'] as $f) {
				$v = mb_substr(trim((string)($src[$f] ?? '')), 0, 2000);
				if (in_array($f, self::SECRETS, true) && ($v === self::MASK || $v === '') && ($old[$k]['provider'] ?? '') === $prov) { $v = (string)($old[$k][$f] ?? ''); }
				if ($f === 'http_method' && !in_array(strtoupper($v), ['GET', 'POST', 'PUT'], true)) { $v = 'POST'; }
				if ($f === 'dial_format' && !in_array($v, ['national', 'e164', 'intl', 'intl00', 'raw'], true)) { $v = 'national'; }
				if ($f === 'uri_scheme' && !in_array($v, ['tel', 'sip', 'callto'], true)) { $v = 'tel'; }
				if ($f === 'verify_ssl') { $v = self::truthy($src[$f] ?? '1') ? '1' : '0'; }
				$row[$f] = $v;
			}
			$clean[$k] = $row;
		}
		$secret = (string)($in['secret'] ?? $old['secret']);
		$clean['secret'] = preg_match('/^[A-Za-z0-9_-]{16,64}$/', $secret) ? $secret : $old['secret'];
		foreach (['answered', 'missed'] as $k) { $v = (string)($in[$k] ?? ''); $clean[$k] = ($v !== '' && in_array($v, Statuses::results(), true)) ? $v : ''; }
		self::cfgApp()->setAppValue('easycrm', 'telephony', json_encode($clean, JSON_UNESCAPED_UNICODE));
		return self::get();
	}
	public static function newSecret(): string {
		$s = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
		$c = self::get(); $c['secret'] = $s;
		self::cfgApp()->setAppValue('easycrm', 'telephony', json_encode($c, JSON_UNESCAPED_UNICODE));
		return $s;
	}
	public static function mode(): array {
		$c = self::get();
		$sp = $c['sip']['provider']; $wp = $c['wa']['provider'];
		return ['pbx' => $sp === 'none' ? 'none' : ($sp === 'uri' ? 'uri' : 'api'), 'wa' => $wp === 'link' ? 'link' : 'api'];
	}
	public static function extension(string $uid): string { return (string)self::cfgApp()->getUserValue($uid, 'easycrm', 'ext', ''); }
	public static function setExtension(string $uid, string $ext): void {
		self::cfgApp()->setUserValue($uid, 'easycrm', 'ext', preg_replace('/[^0-9A-Za-z*#_.-]/', '', mb_substr($ext, 0, 20)));
	}

	// ------------------------------------------------------------------ helpers
	public static function truthy($v): bool { return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true); }
	public static function digits($s): string { return preg_replace('/\D+/', '', (string)$s); }

	/** Formate un numéro national normalisé pour le standard : national | e164 | intl | intl00 | raw */
	public static function dialNumber(string $national, string $cc, string $trunk, string $fmt = 'national', string $prefix = ''): string {
		$n = preg_replace('/[^\d+]/', '', $national);
		if ($fmt === 'raw' || $n === '') { return $prefix . $n; }
		$d = ltrim($n, '+');
		if ($trunk !== '' && str_starts_with($d, $trunk)) { $core = substr($d, strlen($trunk)); }
		elseif ($cc !== '' && str_starts_with($d, $cc)) { $core = substr($d, strlen($cc)); }
		else { $core = $d; }
		$out = match ($fmt) { 'national' => $trunk . $core, 'e164' => '+' . $cc . $core, 'intl' => $cc . $core, 'intl00' => '00' . $cc . $core, default => $d };
		return $prefix . $out;
	}
	private static function jsonEsc(string $s): string { return substr(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1); }
	/** Remplace {nom} sans toucher aux autres accolades (JSON) */
	public static function fill(string $tpl, array $values, bool $url = false): string {
		foreach ($values as $k => $v) { $tpl = str_replace('{' . $k . '}', $url ? rawurlencode((string)$v) : self::jsonEsc((string)$v), $tpl); }
		return $tpl;
	}
	private static function jsonArr(?string $t): array { $a = $t ? json_decode($t, true) : null; return is_array($a) ? $a : []; }

	/** @return array{0:?array,1:string} [ ['code'=>int,'body'=>string], erreur ] */
	public static function http(string $method, string $url, array $headers = [], ?string $body = null, bool $verify = true, ?array $basic = null): array {
		if (!preg_match('#^https?://#i', $url)) { return [null, "URL invalide (http:// ou https:// attendu)."]; }
		$ch = curl_init($url);
		$h = []; foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }
		curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_HTTPHEADER => $h, CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0, CURLOPT_FOLLOWLOCATION => false]);
		if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
		if ($basic) { curl_setopt($ch, CURLOPT_USERPWD, $basic[0] . ':' . $basic[1]); }
		$res = curl_exec($ch);
		if ($res === false) { $e = curl_error($ch); curl_close($ch); return [null, 'Erreur de connexion : ' . $e]; }
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
		return [['code' => $code, 'body' => (string)$res], ''];
	}
	private static function j(array $r): array { $a = json_decode($r['body'], true); return is_array($a) ? $a : []; }

	// ------------------------------------------------------------------ Asterisk AMI
	private static function amiRead($sock, ?string $until = null): string {
		$buf = ''; $end = microtime(true) + self::TIMEOUT;
		stream_set_timeout($sock, 1);
		while (microtime(true) < $end) {
			$c = fread($sock, 4096);
			if ($c === false || ($c === '' && feof($sock))) { break; }
			$buf .= $c;
			if ($until === null && str_contains($buf, "\r\n")) { return $buf; }
			if ($until !== null && str_contains($buf, $until) && str_ends_with($buf, "\r\n\r\n")) { return $buf; }
		}
		return $buf;
	}
	private static function amiLogin($sock, string $user, string $secret): array {
		$banner = self::amiRead($sock);
		if (!str_contains($banner, 'Asterisk Call Manager')) { return [false, 'Ce service n’est pas une interface Asterisk Manager (AMI).']; }
		fwrite($sock, "Action: Login\r\nUsername: $user\r\nSecret: $secret\r\nEvents: off\r\n\r\n");
		$r = self::amiRead($sock, 'Response:');
		if (!str_contains($r, 'Response: Success')) {
			preg_match('/Message: (.*)/', $r, $m);
			return [false, 'Connexion AMI refusée : ' . trim($m[1] ?? substr(trim($r), 0, 80))];
		}
		return [true, ''];
	}
	private static function amiOpen(string $host, $port) {
		$s = @stream_socket_client('tcp://' . $host . ':' . (int)($port ?: 5038), $en, $es, self::TIMEOUT);
		return $s ?: [null, "Erreur de connexion : $es"];
	}
	public static function amiTest(string $host, $port, string $user, string $secret): array {
		$s = self::amiOpen($host, $port); if (is_array($s)) { return [false, $s[1]]; }
		[$ok, $msg] = self::amiLogin($s, $user, $secret);
		if ($ok) { fwrite($s, "Action: Logoff\r\n\r\n"); }
		fclose($s);
		return $ok ? [true, 'Connexion AMI réussie.'] : [false, $msg];
	}
	public static function amiOriginate(array $c, string $channel, string $exten, string $callerid = ''): array {
		$s = self::amiOpen((string)$c['host'], $c['port'] ?? 5038); if (is_array($s)) { return [false, $s[1]]; }
		[$ok, $msg] = self::amiLogin($s, (string)$c['user'], (string)$c['secret']);
		if (!$ok) { fclose($s); return [false, $msg]; }
		$clean = fn($v) => str_replace(["\r", "\n"], '', $v);
		$lines = ['Action: Originate', 'Channel: ' . $clean($channel), 'Context: ' . $clean($c['context'] ?: 'from-internal'), 'Exten: ' . $clean($exten), 'Priority: 1', 'Async: true', 'Timeout: 30000'];
		if ($callerid !== '') { $lines[] = 'CallerID: ' . $clean($callerid); }
		fwrite($s, implode("\r\n", $lines) . "\r\n\r\n");
		$r = self::amiRead($s, 'Response:');
		fwrite($s, "Action: Logoff\r\n\r\n"); fclose($s);
		if (str_contains($r, 'Response: Success')) { return [true, 'Appel lancé.']; }
		preg_match('/Message: (.*)/', $r, $m);
		return [false, 'Appel refusé : ' . trim($m[1] ?? substr(trim($r), 0, 80))];
	}

	// ------------------------------------------------------------------ Yeastar / 3CX
	private static function yeastarToken(array $c): array {
		[$r, $e] = self::http('POST', rtrim((string)$c['url'], '/') . '/openapi/v1.0/get_token', ['Content-Type' => 'application/json'],
			json_encode(['username' => $c['user'], 'password' => $c['secret']]), self::truthy($c['verify_ssl'] ?? '1'));
		if (!$r) { return [null, $e]; }
		$j = self::j($r);
		if (!in_array($j['errcode'] ?? null, [0, '0', null], true) || empty($j['access_token'])) { return [null, 'Yeastar a refusé la connexion : ' . ($j['errmsg'] ?? ('HTTP ' . $r['code']))]; }
		return [$j['access_token'], ''];
	}
	private static function threecxToken(array $c): array {
		[$r, $e] = self::http('POST', rtrim((string)$c['url'], '/') . '/connect/token', ['Content-Type' => 'application/x-www-form-urlencoded'],
			http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['user'], 'client_secret' => $c['secret']]), self::truthy($c['verify_ssl'] ?? '1'));
		if (!$r) { return [null, $e]; }
		$j = self::j($r);
		if (empty($j['access_token'])) { return [null, '3CX a refusé la connexion : ' . ($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $r['code']))]; }
		return [$j['access_token'], ''];
	}

	// ------------------------------------------------------------------ SIP front-ends
	public static function sipTest(array $c): array {
		switch ($c['provider']) {
			case 'none': case '': return [true, 'Rien à tester (désactivé).'];
			case 'uri': return [true, 'Rien à tester : le navigateur ouvre le lien du softphone.'];
			case 'asterisk': return self::amiTest((string)$c['host'], $c['port'], (string)$c['user'], (string)$c['secret']);
			case 'yeastar': [$t, $e] = self::yeastarToken($c); return $t ? [true, 'Connexion à l’API Yeastar réussie.'] : [false, $e];
			case '3cx': [$t, $e] = self::threecxToken($c); return $t ? [true, 'Connexion à l’API 3CX réussie.'] : [false, $e];
			case 'http':
				[$r, $e] = self::http('GET', self::fill((string)$c['url'], ['ext' => '', 'number' => ''], true), self::jsonArr($c['http_headers'] ?? ''), null, self::truthy($c['verify_ssl'] ?? '1'));
				return $r ? [true, 'Serveur joignable (HTTP ' . $r['code'] . ').'] : [false, $e];
		}
		return [false, 'Standard inconnu.'];
	}
	public static function sipDial(array $c, string $ext, string $number, string $callerid = ''): array {
		$p = $c['provider'];
		if ($ext === '' && !in_array($p, ['uri', 'http'], true)) { return [false, 'Aucun poste (extension) défini pour cet utilisateur.']; }
		$verify = self::truthy($c['verify_ssl'] ?? '1');
		switch ($p) {
			case 'asterisk': return self::amiOriginate($c, str_replace('{ext}', $ext, (string)($c['channel'] ?: 'PJSIP/{ext}')), $number, $callerid ?: $ext);
			case 'yeastar':
				[$t, $e] = self::yeastarToken($c); if (!$t) { return [false, $e]; }
				[$r, $e] = self::http('POST', rtrim((string)$c['url'], '/') . '/openapi/v1.0/call/dial?access_token=' . rawurlencode($t), ['Content-Type' => 'application/json'],
					json_encode(['caller' => $ext, 'callee' => $number, 'auto_answer' => 'yes']), $verify);
				if (!$r) { return [false, $e]; }
				$j = self::j($r);
				return in_array($j['errcode'] ?? null, [0, '0'], true) ? [true, 'Appel lancé.'] : [false, 'Yeastar a refusé l’appel : ' . ($j['errmsg'] ?? ('HTTP ' . $r['code']))];
			case '3cx':
				[$t, $e] = self::threecxToken($c); if (!$t) { return [false, $e]; }
				[$r, $e] = self::http('POST', rtrim((string)$c['url'], '/') . '/callcontrol/' . rawurlencode($ext) . '/makecall', ['Authorization' => 'Bearer ' . $t, 'Content-Type' => 'application/json'],
					json_encode(['destination' => $number]), $verify);
				if (!$r) { return [false, $e]; }
				return in_array($r['code'], [200, 201, 202, 204], true) ? [true, 'Appel lancé.'] : [false, '3CX a refusé l’appel (HTTP ' . $r['code'] . ') : ' . substr($r['body'], 0, 120)];
			case 'http': return self::httpGeneric($c, $verify, ['ext' => $ext, 'number' => $number]);
		}
		return [false, 'Ce standard ne lance pas d’appel depuis le serveur.'];
	}
	public static function httpGeneric(array $c, bool $verify, array $values): array {
		$url = self::fill((string)$c['url'], $values, true);
		$body = !empty($c['http_body']) ? self::fill((string)$c['http_body'], $values) : null;
		[$r, $e] = self::http((string)($c['http_method'] ?: 'POST'), $url, self::jsonArr($c['http_headers'] ?? ''), $body, $verify);
		if (!$r) { return [false, $e]; }
		return ($r['code'] >= 200 && $r['code'] < 300) ? [true, 'OK (HTTP ' . $r['code'] . ').'] : [false, 'HTTP ' . $r['code'] . ' : ' . substr($r['body'], 0, 160)];
	}

	// ------------------------------------------------------------------ WhatsApp
	private static function waPayload(array $c, string $number, string $text, string $name): array {
		$tpl = trim((string)($c['template'] ?? ''));
		if ($tpl !== '') {
			return ['messaging_product' => 'whatsapp', 'to' => $number, 'type' => 'template', 'template' => ['name' => $tpl, 'language' => ['code' => $c['language'] ?: 'fr'],
				'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $name !== '' ? $name : ' ']]]]]];
		}
		return ['messaging_product' => 'whatsapp', 'to' => $number, 'type' => 'text', 'text' => ['body' => $text]];
	}
	/** Client avec nom d'utilisateur WhatsApp : identifiant BSUID dans « recipient » au lieu de « to » */
	private static function toRecipient(array $b): array {
		if (isset($b['to']) && preg_match('/^[A-Z]{2}\\.[A-Za-z0-9.]{1,140}$/', (string)$b['to'])) { $b['recipient'] = $b['to']; unset($b['to']); }
		return $b;
	}
	/** $number : chiffres internationaux sans « + » */
	public static function waSend(array $c, string $number, string $text, string $name = ''): array {
		$p = $c['provider'];
		if ($p === 'cloud') {
			[$r, $e] = self::http('POST', 'https://graph.facebook.com/' . ($c['api_version'] ?: 'v21.0') . '/' . rawurlencode((string)$c['phone_id']) . '/messages',
				['Authorization' => 'Bearer ' . $c['token'], 'Content-Type' => 'application/json'], json_encode(self::toRecipient(self::waPayload($c, $number, $text, $name))));
		} elseif ($p === '360dialog') {
			[$r, $e] = self::http('POST', 'https://waba-v2.360dialog.io/messages', ['D360-API-KEY' => (string)$c['token'], 'Content-Type' => 'application/json'],
				json_encode(self::toRecipient(self::waPayload($c, $number, $text, $name))));
		} elseif ($p === 'twilio') {
			$sid = (string)$c['account_sid'];
			$d = ['From' => 'whatsapp:+' . ltrim((string)$c['from_number'], '+'), 'To' => 'whatsapp:+' . $number];
			if (trim((string)($c['template'] ?? '')) !== '') { $d['ContentSid'] = trim($c['template']); $d['ContentVariables'] = json_encode(['1' => $name !== '' ? $name : ' ']); }
			else { $d['Body'] = $text; }
			[$r, $e] = self::http('POST', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json', ['Content-Type' => 'application/x-www-form-urlencoded'],
				http_build_query($d), true, [$sid, (string)$c['token']]);
		} elseif ($p === 'http') {
			return self::httpGeneric($c, true, ['number' => $number, 'text' => $text, 'name' => $name]) + [2 => ''];
		} else { return [false, 'Ce mode n’envoie pas de message depuis le serveur.', '']; }
		if (!$r) { return [false, $e, '']; }
		if ($r['code'] >= 200 && $r['code'] < 300) { return [true, 'Message envoyé.', self::msgId(self::j($r))]; }
		return [false, self::refusal($r), ''];
	}
	private static function refusal(array $r): string {
		$j = self::j($r);
		$m = is_array($j['error'] ?? null) ? ($j['error']['error_user_msg'] ?? $j['error']['message'] ?? '') : ($j['message'] ?? substr($r['body'], 0, 160));
		return 'Le fournisseur WhatsApp a refusé le message (HTTP ' . $r['code'] . ') : ' . $m;
	}
	/** Identifiant du message renvoyé par le fournisseur (Meta / 360dialog : messages[0].id ; Twilio : sid) */
	private static function msgId(array $j): string { return (string)($j['messages'][0]['id'] ?? $j['sid'] ?? $j['id'] ?? ''); }

	public const WA_MEDIA = ['image', 'document', 'audio', 'video', 'sticker'];
	public const SEND_IMAGE = ['image/jpeg', 'image/png'];
	public const SEND_DOC = ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
	/** Le fournisseur sait-il recevoir (webhook) et répondre depuis le CRM ? */
	public static function inbox(array $c): bool { return in_array($c['provider'], ['cloud', '360dialog', 'twilio', 'http'], true); }
	/** Requête binaire (médias) : redirections suivies, 120 s, 100 Mo max. @return array{0:?array,1:string} */
	public static function binary(string $method, string $url, array $headers = [], $body = null, ?array $basic = null): array {
		if (!preg_match('#^https://#i', $url)) { return [null, 'URL de média invalide.']; }
		$ch = curl_init($url);
		$h = []; foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }
		curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_MAXFILESIZE => 100 * 1024 * 1024]);
		if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
		if ($basic) { curl_setopt($ch, CURLOPT_USERPWD, $basic[0] . ':' . $basic[1]); }
		$res = curl_exec($ch);
		if ($res === false) { $e = curl_error($ch); curl_close($ch); return [null, 'Erreur de connexion : ' . $e]; }
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
		return [['code' => $code, 'body' => (string)$res, 'type' => $ct], ''];
	}
	/**
	 * Télécharge le média d'un message reçu. $m : ['id' => media id (Meta / 360dialog) | 'url' => lien (Twilio), 'mime' => …]
	 * @return array{0:?string,1:string,2:string} [contenu, type MIME, erreur]
	 */
	public static function waMedia(array $c, array $m): array {
		$p = $c['provider'];
		if ($p === 'cloud' && ($m['id'] ?? '') !== '') {
			$auth = ['Authorization' => 'Bearer ' . $c['token']];
			[$r, $e] = self::binary('GET', 'https://graph.facebook.com/' . ($c['api_version'] ?: 'v21.0') . '/' . rawurlencode((string)$m['id']), $auth);
			if (!$r || $r['code'] !== 200) { return [null, '', $e ?: 'Média introuvable chez Meta (HTTP ' . ($r['code'] ?? 0) . ')']; }
			$j = json_decode($r['body'], true) ?: [];
			if (empty($j['url'])) { return [null, '', 'Lien du média absent']; }
			[$r2, $e] = self::binary('GET', (string)$j['url'], $auth + ['User-Agent' => 'EasyCrm']);
			if (!$r2 || $r2['code'] !== 200) { return [null, '', $e ?: 'Téléchargement refusé (HTTP ' . ($r2['code'] ?? 0) . ')']; }
			return [$r2['body'], (string)($j['mime_type'] ?? $m['mime'] ?? 'application/octet-stream'), ''];
		}
		if ($p === '360dialog' && ($m['id'] ?? '') !== '') {
			$auth = ['D360-API-KEY' => (string)$c['token']];
			[$r, $e] = self::binary('GET', 'https://waba-v2.360dialog.io/' . rawurlencode((string)$m['id']), $auth);
			if (!$r || $r['code'] !== 200) { return [null, '', $e ?: 'Média introuvable chez 360dialog (HTTP ' . ($r['code'] ?? 0) . ')']; }
			$j = json_decode($r['body'], true) ?: [];
			if (empty($j['url'])) { return [null, '', 'Lien du média absent']; }
			$u = preg_replace('#^https://lookaside\.fbsbx\.com#', 'https://waba-v2.360dialog.io', (string)$j['url']);
			[$r2, $e] = self::binary('GET', $u, $auth);
			if (!$r2 || $r2['code'] !== 200) { return [null, '', $e ?: 'Téléchargement refusé (HTTP ' . ($r2['code'] ?? 0) . ')']; }
			return [$r2['body'], (string)($j['mime_type'] ?? $m['mime'] ?? 'application/octet-stream'), ''];
		}
		if (($m['url'] ?? '') !== '') {
			$basic = $p === 'twilio' ? [(string)$c['account_sid'], (string)$c['token']] : null;
			[$r, $e] = self::binary('GET', (string)$m['url'], [], null, $basic);
			if (!$r || $r['code'] !== 200) { return [null, '', $e ?: 'Téléchargement refusé (HTTP ' . ($r['code'] ?? 0) . ')']; }
			return [$r['body'], (string)($m['mime'] ?: ($r['type'] ?: 'application/octet-stream')), ''];
		}
		return [null, '', 'Média non récupérable avec ce fournisseur'];
	}
	/**
	 * Réponse libre d'un commercial (fenêtre de 24 h) : texte et/ou fichier ['data', 'name', 'mime'].
	 * @return array{0:bool,1:string,2:string} [ok, message, identifiant du message]
	 */
	public static function waSendRich(array $c, string $number, string $text, ?array $file = null): array {
		$p = $c['provider'];
		if ($file !== null) {
			$mime = strtolower(explode(';', (string)$file['mime'])[0]); if ($mime === 'image/jpg') { $mime = 'image/jpeg'; }
			if (!in_array($mime, array_merge(self::SEND_IMAGE, self::SEND_DOC), true)) { return [false, 'Type de fichier non accepté par WhatsApp : utilisez JPG, PNG, PDF, Word, Excel ou PowerPoint.', '']; }
			if (!in_array($p, ['cloud', '360dialog'], true)) { return [false, 'L’envoi de fichiers depuis le CRM nécessite Meta Cloud API ou 360dialog.', '']; }
			$part = new \CURLStringFile((string)$file['data'], (string)$file['name'], $mime);
			if ($p === 'cloud') {
				$base = 'https://graph.facebook.com/' . ($c['api_version'] ?: 'v21.0') . '/' . rawurlencode((string)$c['phone_id']);
				$auth = ['Authorization' => 'Bearer ' . $c['token']];
				[$r, $e] = self::binary('POST', $base . '/media', $auth, ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => $part]);
			} else {
				$base = 'https://waba-v2.360dialog.io';
				$auth = ['D360-API-KEY' => (string)$c['token']];
				[$r, $e] = self::binary('POST', $base . '/media', $auth, ['messaging_product' => 'whatsapp', 'file' => $part]);
			}
			if (!$r) { return [false, $e, '']; }
			$mid = (string)((json_decode($r['body'], true) ?: [])['id'] ?? '');
			if ($r['code'] >= 300 || $mid === '') { return [false, 'Fichier refusé par le fournisseur (HTTP ' . $r['code'] . ').', '']; }
			$kind = in_array($mime, self::SEND_IMAGE, true) ? 'image' : 'document';
			$obj = ['id' => $mid] + ($text !== '' ? ['caption' => $text] : []) + ($kind === 'document' ? ['filename' => (string)$file['name']] : []);
			[$r, $e] = self::http('POST', $base . '/messages', $auth + ['Content-Type' => 'application/json'],
				json_encode(self::toRecipient(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $number, 'type' => $kind, $kind => $obj])));
			if (!$r) { return [false, $e, '']; }
			return ($r['code'] >= 200 && $r['code'] < 300) ? [true, 'Message envoyé.', self::msgId(self::j($r))] : [false, self::refusal($r), ''];
		}
		if ($text === '') { return [false, 'Message vide', '']; }
		// texte libre : jamais de modèle dans une réponse
		return self::waSend(['template' => ''] + $c, $number, $text);
	}
	/** Événements WhatsApp entrants : messages (avec médias), accusés de livraison, échos de l'appli WhatsApp Business. */
	public static function parseWaEvents(array $d): array {
		$msgs = []; $sts = [];
		if (isset($d['entry'])) {
			foreach ((array)$d['entry'] as $en) { foreach ((array)($en['changes'] ?? []) as $ch) {
				$v = (array)($ch['value'] ?? []); $echo = ($ch['field'] ?? '') === 'smb_message_echoes';
				$names = []; foreach ((array)($v['contacts'] ?? []) as $ct) { $nmv = (string)($ct['profile']['name'] ?? $ct['profile']['username'] ?? ''); if (!empty($ct['wa_id'])) { $names[(string)$ct['wa_id']] = $nmv; } if (!empty($ct['user_id'])) { $names[(string)$ct['user_id']] = $nmv; } }
				foreach ((array)($echo ? ($v['message_echoes'] ?? []) : ($v['messages'] ?? [])) as $m) {
					$type = (string)($m['type'] ?? 'text'); $text = ''; $media = null;
					if (in_array($type, self::WA_MEDIA, true)) {
						$x = (array)($m[$type] ?? []);
						$media = ['id' => (string)($x['id'] ?? ''), 'mime' => (string)($x['mime_type'] ?? ''), 'filename' => (string)($x['filename'] ?? '')];
						$text = (string)($x['caption'] ?? '');
					} elseif ($type === 'text') { $text = (string)($m['text']['body'] ?? ''); }
					elseif ($type === 'location') { $l = (array)($m['location'] ?? []); $text = 'https://maps.google.com/?q=' . ($l['latitude'] ?? '') . ',' . ($l['longitude'] ?? '') . (!empty($l['name']) ? ' ' . $l['name'] : ''); }
					elseif ($type === 'button') { $text = (string)($m['button']['text'] ?? ''); }
					elseif ($type === 'interactive') { $text = (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''); }
					else { $text = '[' . $type . ']'; }
					$from = self::digits($m['from'] ?? ''); $uid = (string)($echo ? ($m['to_user_id'] ?? $m['recipient_user_id'] ?? '') : ($m['from_user_id'] ?? ''));
					$msgs[] = ['from' => $echo ? self::digits($m['to'] ?? '') : $from, 'user_id' => $uid, 'name' => $echo ? '' : ($names[$from] ?? $names[$uid] ?? ''), 'text' => $text, 'id' => (string)($m['id'] ?? ''),
						'type' => $type, 'media' => $media, 'ts' => (int)($m['timestamp'] ?? 0), 'direction' => $echo ? 'out' : 'in'];
				}
				foreach ((array)($v['statuses'] ?? []) as $st) {
					$sts[] = ['id' => (string)($st['id'] ?? ''), 'status' => (string)($st['status'] ?? ''), 'error' => (string)($st['errors'][0]['title'] ?? $st['errors'][0]['message'] ?? '')];
				}
			} }
		} elseif (isset($d['MessageStatus']) && !isset($d['Body'])) {
			$map = ['queued' => 'sent', 'accepted' => 'sent', 'sending' => 'sent', 'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', 'undelivered' => 'failed'];
			$sts[] = ['id' => (string)($d['MessageSid'] ?? ''), 'status' => $map[strtolower((string)$d['MessageStatus'])] ?? '', 'error' => (string)($d['ErrorMessage'] ?? $d['ErrorCode'] ?? '')];
		} elseif (isset($d['From']) || isset($d['WaId'])) {
			$media = null; $type = 'text';
			if ((int)($d['NumMedia'] ?? 0) > 0 && !empty($d['MediaUrl0'])) {
				$mt = (string)($d['MediaContentType0'] ?? '');
				$type = str_starts_with($mt, 'image/') ? 'image' : (str_starts_with($mt, 'audio/') ? 'audio' : (str_starts_with($mt, 'video/') ? 'video' : 'document'));
				$media = ['url' => (string)$d['MediaUrl0'], 'mime' => $mt, 'filename' => ''];
			}
			$msgs[] = ['from' => self::digits($d['WaId'] ?? str_replace('whatsapp:', '', (string)($d['From'] ?? ''))), 'name' => (string)($d['ProfileName'] ?? ''), 'text' => (string)($d['Body'] ?? ''),
				'id' => (string)($d['MessageSid'] ?? ''), 'type' => $type, 'media' => $media, 'ts' => 0, 'direction' => 'in'];
		} elseif (isset($d['from']) || isset($d['number'])) {
			$msgs[] = ['from' => self::digits($d['from'] ?? $d['number']), 'name' => (string)($d['name'] ?? ''), 'text' => (string)($d['text'] ?? $d['message'] ?? ''), 'id' => (string)($d['id'] ?? ''),
				'type' => 'text', 'media' => !empty($d['media_url']) ? ['url' => (string)$d['media_url'], 'mime' => (string)($d['media_type'] ?? ''), 'filename' => (string)($d['filename'] ?? '')] : null,
				'ts' => (int)($d['timestamp'] ?? 0), 'direction' => 'in'];
		}
		return ['messages' => $msgs, 'statuses' => $sts];
	}
	public static function waTest(array $c): array {
		switch ($c['provider']) {
			case 'link': case '': return [true, 'Rien à tester (lien WhatsApp manuel).'];
			case 'cloud':
				[$r, $e] = self::http('GET', 'https://graph.facebook.com/' . ($c['api_version'] ?: 'v21.0') . '/' . rawurlencode((string)$c['phone_id']) . '?fields=display_phone_number,verified_name', ['Authorization' => 'Bearer ' . $c['token']]);
				if (!$r) { return [false, $e]; }
				if ($r['code'] === 200) { $j = self::j($r); return [true, 'Connecté : ' . ($j['display_phone_number'] ?? '?') . ' (' . ($j['verified_name'] ?? '?') . ').']; }
				return [false, 'Meta a refusé les identifiants (HTTP ' . $r['code'] . ').'];
			case 'twilio':
				$sid = (string)$c['account_sid'];
				[$r, $e] = self::http('GET', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '.json', [], null, true, [$sid, (string)$c['token']]);
				if (!$r) { return [false, $e]; }
				return $r['code'] === 200 ? [true, 'Identifiants Twilio valides.'] : [false, 'Twilio a refusé les identifiants (HTTP ' . $r['code'] . ').'];
			case '360dialog': return !empty($c['token']) ? [true, 'Pas de test en direct : envoyez un message pour vérifier.'] : [false, 'Clé API manquante.'];
			case 'http': return !empty($c['url']) ? [true, 'Pas de test en direct : envoyez un message pour vérifier.'] : [false, 'URL manquante.'];
		}
		return [false, 'Mode inconnu.'];
	}

	// ------------------------------------------------------------------ webhooks
	public static function parsePbxEvent(array $data): array {
		$low = []; foreach ($data as $k => $v) { $low[strtolower((string)$k)] = $v; }
		$pick = function (string ...$keys) use ($low) { foreach ($keys as $k) { if (isset($low[$k]) && $low[$k] !== '' && $low[$k] !== null && !is_array($low[$k])) { return $low[$k]; } } return ''; };
		$dir = strtolower((string)$pick('direction', 'type')) ?: 'outbound';
		$in = str_starts_with($dir, 'in');
		$number = $pick('number', 'prospect', 'phone') ?: ($in ? $pick('caller', 'src', 'from') : $pick('callee', 'dst', 'destination', 'called', 'to'));
		$ext = $pick('ext', 'extension') ?: ($in ? $pick('callee', 'dst', 'to') : $pick('caller', 'src', 'from'));
		$status = strtolower((string)$pick('status', 'disposition', 'result'));
		$duration = (int)floatval($pick('duration', 'billsec', 'talk_time') ?: 0);
		$ok = $status === '' || in_array($status, ['answered', 'answer', 'completed', 'success', 'connected', 'ok'], true);
		return ['number' => self::digits($number), 'ext' => (string)$ext, 'status' => $status ?: ($duration ? 'answered' : 'unknown'),
			'answered' => $ok && $duration > 0, 'duration' => $duration, 'recording' => (string)$pick('recording', 'recording_url', 'recordingfile', 'recordurl'),
			'uid' => (string)$pick('uniqueid', 'id', 'call_id', 'callid'), 'inbound' => $in];
	}
	public static function parseWaInbound(array $d): array {
		$out = [];
		if (isset($d['entry'])) {
			foreach ((array)$d['entry'] as $e) { foreach ((array)($e['changes'] ?? []) as $ch) { foreach ((array)($ch['value']['messages'] ?? []) as $m) {
				$txt = $m['text']['body'] ?? ''; if ($txt === '') { $txt = !empty($m['type']) ? '[' . $m['type'] . ']' : ''; }
				$out[] = ['from' => self::digits($m['from'] ?? ''), 'text' => $txt, 'id' => (string)($m['id'] ?? '')];
			} } }
		} elseif (isset($d['From']) || isset($d['WaId'])) {
			$out[] = ['from' => self::digits($d['WaId'] ?? str_replace('whatsapp:', '', (string)($d['From'] ?? ''))), 'text' => (string)($d['Body'] ?? ''), 'id' => (string)($d['MessageSid'] ?? '')];
		} elseif (isset($d['from']) || isset($d['number'])) {
			$out[] = ['from' => self::digits($d['from'] ?? $d['number']), 'text' => (string)($d['text'] ?? $d['message'] ?? ''), 'id' => (string)($d['id'] ?? '')];
		}
		return $out;
	}
}
