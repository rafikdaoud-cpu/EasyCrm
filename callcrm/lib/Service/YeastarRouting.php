<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Routage des appels entrants vers le commercial de la fiche, sans module payant du standard :
 * le CRM tient à jour, dans le Yeastar, des routes entrantes « CRM_<poste>_<n> » qui reconnaissent le numéro
 * de l'appelant (300 numéros au plus par route) et font sonner le poste du commercial.
 *
 * Règles de sécurité :
 * - une route gérée n'est jamais vide (une route sans numéro intercepterait tous les appels) : elle reçoit
 *   alors un numéro impossible ;
 * - les horaires, la destination hors horaires et les jours fériés sont copiés de la route générale ;
 * - la route générale (sans filtre d'appelant) doit rester la dernière : l'API ne permettant pas de choisir
 *   l'ordre, elle est recopiée puis l'originale supprimée, uniquement si la copie est identique ;
 * - un commercial hors ligne, indisponible dans Yeastar ou en pause dans le CRM ne reçoit rien : ses clients
 *   repassent par le circuit normal (SVI, file d'attente).
 */
class YeastarRouting {
	public const CHUNK = 250;
	public const PLACEHOLDER = '0000000000';
	public const NAME = '/^CRM_(\d{1,10})_(\d{1,3})$/D';

	public function __construct(private YeastarClient $client, private YeastarSettings $settings, private IConfig $config, private IDBConnection $db, private Presence $presence) {}

	private function state(): array {
		$v = json_decode($this->config->getAppValue('callcrm', 'yeastar_routing', '{}'), true);
		return is_array($v) ? $v : [];
	}
	private function saveState(array $s): void { $this->config->setAppValue('callcrm', 'yeastar_routing', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }

	public function status(): array {
		$s = $this->state();
		return ['enabled' => ($s['enabled'] ?? false) === true, 'last_sync' => (int)($s['last_sync'] ?? 0), 'last_error' => (string)($s['last_error'] ?? ''),
			'summary' => $s['summary'] ?? null];
	}

	public function setEnabled(bool $on): array {
		$s = $this->state(); $s['enabled'] = $on; $this->saveState($s);
		return $on ? $this->sync(true) : $this->removeAll();
	}

	/** Numéro tel que le standard le reçoit de l'opérateur : 0XXXXXXXXX (mobile) ou 0XXXXXXXX (fixe). */
	public static function callerId(string $raw): ?string {
		$d = preg_replace('/\D+/', '', $raw) ?? '';
		if (str_starts_with($d, '00213')) $d = '0' . substr($d, 5);
		elseif (str_starts_with($d, '213') && strlen($d) >= 11) $d = '0' . substr($d, 3);
		elseif (preg_match('/^[5-7]\d{8}$/D', $d)) $d = '0' . $d;
		return preg_match('/^0([5-7]\d{8}|[1-4]\d{7})$/D', $d) ? $d : null;
	}

	private function ok(array $r, string $what): array {
		if ((string)($r['errcode'] ?? '') !== '0') throw new \RuntimeException($what . ' : le standard a répondu « ' . substr((string)($r['errmsg'] ?? '?'), 0, 60) . ' » (code ' . substr((string)($r['errcode'] ?? '?'), 0, 10) . ').');
		return $r;
	}

	private function routes(): array {
		$r = $this->ok($this->client->call('GET', 'inbound_route/list', ['page' => 1, 'page_size' => 200, 'sort_by' => 'pos', 'order_by' => 'asc']), 'Lecture des routes');
		return $r['data'] ?? [];
	}
	private function route(int $id): array { return $this->ok($this->client->call('GET', 'inbound_route/get', ['id' => $id]), 'Lecture d’une route')['data'] ?? []; }

	/** Route générale : sans filtre d'appelant ni de numéro composé. Il n'en faut qu'une, et elle doit être la dernière. */
	private static function catchAll(array $r): bool {
		return empty($r['cid_pattern_list']) && empty($r['did_pattern_list']) && ($r['cid_option'] ?? 'patterns') === 'patterns' && in_array($r['did_option'] ?? 'patterns', ['patterns', ''], true);
	}

	/** Corps « create » équivalent à une route lue (identifiants internes retirés). */
	private static function body(array $r): array {
		unset($r['id'], $r['pos'], $r['timezone_options']);
		if (isset($r['trunk_list'])) $r['trunk_list'] = array_map(fn($x) => ['id' => $x['id']], $r['trunk_list']);
		foreach (['time_condition_value', 'cid_pattern_list', 'did_pattern_list'] as $k) {
			if (isset($r[$k])) $r[$k] = array_map(function ($x) { unset($x['id'], $x['pos']); return $x; }, $r[$k]);
		}
		foreach ($r as $k => $v) if ($v === null || $v === 'null') unset($r[$k]);
		return $r;
	}

	/** Ce que chaque commercial doit recevoir : poste Yeastar (id), joignable ou non, numéros de ses clients. */
	public function desired(array $extensions): array {
		$map = [];
		foreach ($this->settings->settings()['extensions'] as $row) if (!empty($row['uid']) && !empty($row['extension'])) $map[(string)$row['extension']] = (string)$row['uid'];
		$owners = []; $numbers = [];
		if ($map) {
			$q = $this->db->getQueryBuilder();
			$q->select('assigned_to', 'phone')->from('callcrm_prospects')->where($q->expr()->in('assigned_to', $q->createNamedParameter(array_values($map), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			foreach ($q->executeQuery()->fetchAll() as $p) {
				$n = self::callerId((string)$p['phone']);
				if ($n) $owners[$n][(string)$p['assigned_to']] = true;
			}
		}
		foreach ($owners as $n => $uids) if (count($uids) === 1) $numbers[array_key_first($uids)][] = $n; // numéro partagé entre deux commerciaux : circuit normal
		$out = [];
		foreach ($map as $ext => $uid) {
			$e = $extensions[$ext] ?? null;
			$online = $e && array_filter($e['online_status'] ?? [], fn($d) => is_array($d) && (int)($d['status'] ?? 0) === 1);
			$available = $e && in_array($e['presence_status'] ?? '', ['available', ''], true);
			$paused = $this->presence->state($uid)['status'] === 'pause';
			$list = $numbers[$uid] ?? []; sort($list);
			$out[$ext] = ['uid' => $uid, 'ext_id' => $e['id'] ?? null, 'reachable' => (bool)($e && $online && $available && !$paused),
				'why' => !$e ? 'poste introuvable' : (!$online ? 'hors ligne' : (!$available ? 'indisponible' : ($paused ? 'en pause' : ''))), 'numbers' => $list];
		}
		return $out;
	}

	private function lock(): bool {
		$until = (int)$this->config->getAppValue('callcrm', 'yeastar_routing_lock', '0');
		if ($until > time()) return false;
		$this->config->setAppValue('callcrm', 'yeastar_routing_lock', (string)(time() + 600));
		return true;
	}
	private function unlock(): void { $this->config->setAppValue('callcrm', 'yeastar_routing_lock', '0'); }

	public function sync(bool $force = false): array {
		$s = $this->state();
		if (($s['enabled'] ?? false) !== true) return $this->status();
		if (!$this->lock()) throw new \RuntimeException('Une synchronisation est déjà en cours.');
		try {
			$extensions = [];
			foreach ($this->ok($this->client->call('GET', 'extension/list', ['page' => 1, 'page_size' => 1000]), 'Lecture des postes')['data'] ?? [] as $e) $extensions[(string)$e['number']] = $e;
			$want = $this->desired($extensions);

			$all = $this->routes(); $managed = []; $general = null; $details = [];
			foreach ($all as $r) {
				if (preg_match(self::NAME, (string)$r['name'], $m)) { $managed[$m[1] . '_' . (int)$m[2]] = $r; continue; }
				$d = $details[(int)$r['id']] = $this->route((int)$r['id']);
				if (self::catchAll($d)) {
					if ($general) throw new \RuntimeException('Le standard a plusieurs routes générales : synchronisation arrêtée par précaution.');
					$general = $d;
				}
			}
			if (!$general) throw new \RuntimeException('Route générale introuvable sur le standard : synchronisation arrêtée par précaution.');
			$template = self::body($general);

			$hashes = $s['hashes'] ?? []; $created = 0; $updated = 0; $routed = 0; $people = 0; $off = [];
			$needed = [];
			foreach ($want as $ext => $w) {
				$chunks = $w['reachable'] && $w['ext_id'] ? array_chunk($w['numbers'], self::CHUNK) : [];
				if ($w['reachable'] && $chunks) { $people++; $routed += count($w['numbers']); }
				elseif (!$w['reachable'] && $w['numbers']) $off[] = $ext . ' (' . $w['why'] . ')';
				foreach ($chunks as $i => $c) $needed[$ext . '_' . ($i + 1)] = ['ext' => $ext, 'ext_id' => (string)$w['ext_id'], 'numbers' => $c];
			}
			// Routes en trop (moins de clients, commercial absent) : neutralisées, pas supprimées, pour éviter de réordonner.
			foreach ($managed as $key => $r) if (!isset($needed[$key])) $needed[$key] = ['ext' => explode('_', $key)[0], 'ext_id' => null, 'numbers' => []];

			foreach ($needed as $key => $n) {
				[$ext, $idx] = explode('_', $key);
				$body = $template;
				$body['name'] = 'CRM_' . $ext . '_' . $idx;
				$body['cid_option'] = 'patterns';
				$body['cid_pattern_list'] = array_map(fn($x) => ['cid_pattern' => $x], $n['numbers'] ?: [self::PLACEHOLDER]);
				if ($n['ext_id']) {
					$body['def_dest'] = 'extension'; $body['def_dest_value'] = $n['ext_id'];
					if ((int)($body['enb_time_condition'] ?? 0) === 1) { $body['business_hours_destination'] = 'extension'; $body['business_hours_destination_value'] = $n['ext_id']; }
				}
				$hash = hash('sha256', json_encode($body));
				if (isset($managed[$key])) {
					$id = (int)$managed[$key]['id'];
					if (!$force && ($hashes[$id] ?? '') === $hash) continue;
					if (!$n['ext_id']) { $keep = $this->route($id); foreach (['def_dest', 'def_dest_value', 'business_hours_destination', 'business_hours_destination_value'] as $k) if (isset($keep[$k])) $body[$k] = $keep[$k]; }
					$this->ok($this->client->call('POST', 'inbound_route/update', [], ['id' => $id] + $body), 'Mise à jour de ' . $body['name']);
					$hashes[$id] = $hash; $updated++;
				} elseif ($n['numbers']) {
					$r = $this->ok($this->client->call('POST', 'inbound_route/create', [], $body), 'Création de ' . $body['name']);
					$hashes[(int)$r['id']] = $hash; $created++;
				}
			}
			if ($created) $this->generalLast($general);

			$s = $this->state();
			$s['hashes'] = $hashes; $s['last_sync'] = time(); $s['last_error'] = '';
			$s['summary'] = ['commercials' => $people, 'numbers' => $routed, 'routes' => count($needed), 'created' => $created, 'updated' => $updated, 'not_routed' => array_slice($off, 0, 40)];
			$this->saveState($s);
			return $this->status();
		} catch (\Throwable $e) {
			$s = $this->state(); $s['last_error'] = mb_substr($e->getMessage(), 0, 300); $s['last_sync'] = time(); $this->saveState($s);
			throw $e instanceof \RuntimeException ? $e : new \RuntimeException('Synchronisation impossible.');
		} finally { $this->unlock(); }
	}

	/** Remet la route générale en dernière position : copie, vérification champ par champ, suppression de l'originale, renommage. */
	private function generalLast(array $general): void {
		$all = $this->routes();
		$last = end($all);
		if ($last && (int)$last['id'] === (int)$general['id']) return;
		$this->config->setAppValue('callcrm', 'yeastar_routing_backup', json_encode($general, JSON_UNESCAPED_UNICODE));
		$body = self::body($general); $name = (string)$general['name']; $body['name'] = mb_substr($name, 0, 40) . '_tmp';
		$r = $this->ok($this->client->call('POST', 'inbound_route/create', [], $body), 'Copie de la route générale');
		$copy = $this->route((int)$r['id']);
		$a = self::body($general); $b = self::body($copy); unset($a['name'], $b['name']); ksort($a); ksort($b);
		if ($a !== $b) {
			$this->client->call('GET', 'inbound_route/delete', ['id' => (int)$r['id']]);
			throw new \RuntimeException('La copie de la route générale ne correspond pas à l’originale : rien n’a été changé.');
		}
		$this->ok($this->client->call('GET', 'inbound_route/delete', ['id' => (int)$general['id']]), 'Suppression de l’ancienne route générale');
		$this->ok($this->client->call('POST', 'inbound_route/update', [], ['id' => (int)$r['id'], 'name' => $name]), 'Renommage de la route générale');
	}

	/** Désactivation : les routes gérées sont supprimées, tous les appels reprennent le circuit normal. */
	private function removeAll(): array {
		if (!$this->lock()) throw new \RuntimeException('Une synchronisation est en cours, réessayez dans un instant.');
		try {
			foreach ($this->routes() as $r) if (preg_match(self::NAME, (string)$r['name'])) $this->ok($this->client->call('GET', 'inbound_route/delete', ['id' => (int)$r['id']]), 'Suppression de ' . $r['name']);
			$s = $this->state(); $s['hashes'] = []; $s['summary'] = null; $s['last_sync'] = time(); $s['last_error'] = ''; $this->saveState($s);
			return $this->status();
		} finally { $this->unlock(); }
	}
}
