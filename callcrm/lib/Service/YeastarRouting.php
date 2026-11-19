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
 *   repassent par le circuit normal (SVI, file d'attente) ;
 * - le message d'accueil reste obligatoire : aux heures d'ouverture, la route du commercial mène à un SVI « CRM_<poste> »,
 *   copie du SVI d'accueil de la route générale (même annonce), dont toutes les touches, le délai et l'erreur
 *   aboutissent au poste du commercial.
 */
class YeastarRouting {
	public const CHUNK = 250;
	public const PLACEHOLDER = '0000000000';
	public const NAME = '/^CRM_(\d{1,10})_(\d{1,3})$/D';
	public const IVR = '/^CRM_(\d{1,10})$/D';
	/** Numéros libres pris pour les SVI d'accueil des commerciaux. */
	public const IVR_FIRST = 6250, IVR_LAST = 6299;
	private const IVR_COPY = ['prompt', 'prompt_repeat', 'resp_timeout', 'digit_timeout', 'alert_info'];
	private const FORWARD_KEYS = ['enb_ex_no_answer_forward', 'ex_no_answer_forward_dest', 'ex_no_answer_forward_prefix', 'ex_no_answer_forward_value', 'ex_no_answer_forward_num',
		'enb_ex_busy_forward', 'ex_busy_forward_dest', 'ex_busy_forward_prefix', 'ex_busy_forward_value', 'ex_busy_forward_num', 'ring_timeout'];
	/** Sonnerie du poste avant de basculer vers la file (s'applique aussi aux appels internes vers ce poste). */
	public const RING_SECONDS = 15;

	public function __construct(private YeastarClient $client, private YeastarSettings $settings, private IConfig $config, private IDBConnection $db, private Presence $presence) {}

	private function state(): array {
		$v = json_decode($this->config->getAppValue('callcrm', 'yeastar_routing', '{}'), true);
		return is_array($v) ? $v : [];
	}
	private function saveState(array $s): void { $this->config->setAppValue('callcrm', 'yeastar_routing', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }

	public function status(): array {
		$s = $this->state();
		return ['enabled' => ($s['enabled'] ?? false) === true, 'last_sync' => (int)($s['last_sync'] ?? 0), 'last_error' => (string)($s['last_error'] ?? ''),
			'summary' => $s['summary'] ?? null, 'fallback_queue' => (string)($s['fallback_queue'] ?? '1'), 'queues' => $s['queues'] ?? [],
			'excluded' => array_values($s['excluded'] ?? []), 'extensions' => $this->mappedExtensions()];
	}

	private function mappedExtensions(): array {
		try { $rows = $this->settings->settings()['extensions'] ?? []; } catch (\Throwable $e) { return []; }
		$out = array_values(array_filter(array_map(fn($r) => (string)($r['extension'] ?? ''), $rows))); sort($out); return $out;
	}

	public function setEnabled(bool $on, ?string $queue = null, ?array $excluded = null): array {
		$s = $this->state(); $s['enabled'] = $on;
		if ($queue !== null && preg_match('/^\d{1,6}$/D', $queue)) $s['fallback_queue'] = $queue;
		if ($excluded !== null) $s['excluded'] = array_values(array_unique(array_filter(array_map('strval', $excluded), fn($x) => preg_match('/^\d{1,10}$/D', $x))));
		$this->saveState($s);
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
	private function ivrs(): array { $r = $this->ok($this->client->call('GET', 'ivr/list', []), 'Lecture des SVI'); return $r['ivr_list'] ?? $r['data'] ?? []; }

	/**
	 * SVI d'accueil de chaque commercial joignable : annonce du SVI de la route générale aux heures d'ouverture,
	 * puis le poste du commercial quelle que soit la réponse. Renvoie [poste => id du SVI] ; vide si la route
	 * générale ne passe pas par un SVI (les routes visent alors directement le poste, comme avant).
	 */
	private function welcomeIvrs(array $general, array $extIds): array {
		if (!$extIds) return [];
		[$d, $v] = (int)($general['enb_time_condition'] ?? 0) === 1 ? ['business_hours_destination', 'business_hours_destination_value'] : ['def_dest', 'def_dest_value'];
		if (($general[$d] ?? '') !== 'ivr') return [];
		$list = $this->ivrs(); $mine = []; $used = []; $tpl = null;
		foreach ($list as $i) {
			$used[(string)$i['number']] = true;
			if (preg_match(self::IVR, (string)$i['name'], $m)) $mine[$m[1]] = $i;
			elseif ((string)$i['id'] === (string)($general[$v] ?? '')) $tpl = $this->ok($this->client->call('GET', 'ivr/get', ['id' => (int)$i['id']]), 'Lecture du SVI d’accueil');
		}
		$tpl = $tpl['data'] ?? $tpl;
		if (!$tpl || empty($tpl['prompt'])) return [];
		$s = $this->state(); $hashes = $s['ivr_hashes'] ?? []; $out = [];
		foreach ($extIds as $ext => $id) {
			$id = (string)$id;
			$b = ['name' => 'CRM_' . $ext] + array_intersect_key($tpl, array_flip(self::IVR_COPY)) + ['dial_ext_option' => 'disable'];
			foreach ($tpl as $k => $x) if (preg_match('/^press(\d|_hash|_star)_dest$/D', $k) && $x !== '') { $b[$k] = 'extension'; $b[$k . '_value'] = $id; }
			foreach (['timeout', 'invalid'] as $k) { $b[$k . '_dest'] = 'extension'; $b[$k . '_dest_value'] = $id; }
			$h = hash('sha256', json_encode($b));
			if (isset($mine[$ext])) {
				$iid = (int)$mine[$ext]['id'];
				if (($hashes[$iid] ?? '') !== $h) { $this->ok($this->client->call('POST', 'ivr/update', [], ['id' => $iid] + $b), 'Mise à jour du SVI ' . $b['name']); $hashes[$iid] = $h; }
			} else {
				$num = null;
				for ($x = self::IVR_FIRST; $x <= self::IVR_LAST; $x++) if (!isset($used[(string)$x])) { $num = (string)$x; break; }
				if ($num === null) continue; // plus de numéro libre : ce commercial reçoit ses clients directement
				$used[$num] = true;
				$iid = (int)$this->ok($this->client->call('POST', 'ivr/create', [], ['number' => $num] + $b), 'Création du SVI ' . $b['name'])['id'];
				$hashes[$iid] = $h;
			}
			$out[$ext] = $iid;
		}
		$s = $this->state(); $s['ivr_hashes'] = $hashes; $this->saveState($s);
		return $out;
	}

	/** Supprime les SVI d'accueil gérés (tous, ou ceux des postes donnés) ; à appeler une fois leurs routes supprimées. */
	private function dropIvrs(?array $exts = null): void {
		foreach ($this->ivrs() as $i) {
			if (!preg_match(self::IVR, (string)$i['name'], $m) || ($exts !== null && !in_array($m[1], $exts, true))) continue;
			$this->ok($this->client->call('GET', 'ivr/delete', ['id' => (int)$i['id']]), 'Suppression du SVI ' . $i['name']);
			$s = $this->state(); unset($s['ivr_hashes'][(int)$i['id']]); $this->saveState($s);
		}
	}

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
			// Postes exclus par l'administrateur : ni route, ni renvoi ; réglage d'origine du poste remis en place.
			$excluded = array_flip($this->state()['excluded'] ?? []);
			$want = array_diff_key($want, $excluded);
			$this->restoreForwards(array_values(array_filter(array_map(fn($x) => (int)($extensions[$x]['id'] ?? 0), array_keys($excluded)))));
			$this->forwards($want);

			$this->resumeSwap();
			$all = $this->routes(); $managed = []; $general = null; $details = [];
			foreach ($all as $r) {
				if (preg_match(self::NAME, (string)$r['name'], $m)) {
					if (isset($excluded[$m[1]])) { $this->ok($this->client->call('GET', 'inbound_route/delete', ['id' => (int)$r['id']]), 'Suppression de ' . $r['name']); unset($s['hashes'][(int)$r['id']]); continue; }
					$managed[$m[1] . '_' . (int)$m[2]] = $r; continue;
				}
				$d = $details[(int)$r['id']] = $this->route((int)$r['id']);
				if (self::catchAll($d)) {
					if ($general) throw new \RuntimeException('Le standard a plusieurs routes générales : synchronisation arrêtée par précaution.');
					$general = $d;
				}
			}
			if (!$general) throw new \RuntimeException('Route générale introuvable sur le standard : synchronisation arrêtée par précaution.');
			if ($excluded) $this->dropIvrs(array_map('strval', array_keys($excluded)));
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
			$extIds = [];
			foreach ($needed as $n) if ($n['ext_id']) $extIds[$n['ext']] = $n['ext_id'];
			$ivr = $this->welcomeIvrs($general, $extIds);

			foreach ($needed as $key => $n) {
				[$ext, $idx] = explode('_', $key);
				$body = $template;
				$body['name'] = 'CRM_' . $ext . '_' . $idx;
				$body['cid_option'] = 'patterns';
				$body['cid_pattern_list'] = array_map(fn($x) => ['cid_pattern' => $x], $n['numbers'] ?: [self::PLACEHOLDER]);
				if ($n['ext_id']) {
					[$dest, $value] = isset($ivr[$n['ext']]) ? ['ivr', (string)$ivr[$n['ext']]] : ['extension', $n['ext_id']];
					$body['def_dest'] = $dest; $body['def_dest_value'] = $value;
					if ((int)($body['enb_time_condition'] ?? 0) === 1) { $body['business_hours_destination'] = $dest; $body['business_hours_destination_value'] = $value; }
				}
				$hash = hash('sha256', json_encode($body));
				if (isset($managed[$key])) {
					$id = (int)$managed[$key]['id'];
					if (!$force && ($hashes[$id] ?? '') === $hash) continue;
					if (!$n['ext_id']) { $keep = $this->route($id); foreach (['def_dest', 'def_dest_value', 'business_hours_destination', 'business_hours_destination_value'] as $k) if (isset($keep[$k])) $body[$k] = $keep[$k]; }
					// Codes de fonction de la route : lus mais refusés en modification par le standard.
					$send = array_diff_key($body, array_flip(['prefix_code', 'reset_code', 'holiday_code', 'office_code']));
					$this->ok($this->client->call('POST', 'inbound_route/update', [], ['id' => $id] + $send), 'Mise à jour de ' . $body['name']);
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

	/**
	 * Sur le poste de chaque commercial, statut « disponible », appels EXTERNES seulement : non-réponse et occupé
	 * renvoient vers la file d'attente choisie au lieu de la messagerie (sinon un client routé sans réponse finirait
	 * sur la boîte vocale). Les appels internes ne changent pas. Réglage d'origine sauvegardé, restauré à la désactivation.
	 */
	private function forwards(array $want): void {
		$s = $this->state(); $queue = (string)($s['fallback_queue'] ?? '1'); $backup = $s['forward_backup'] ?? []; $done = $s['forward_done'] ?? [];
		$queues = [];
		foreach ($this->ok($this->client->call('GET', 'queue/list', []), 'Lecture des files')['queue_list'] ?? [] as $q) $queues[(string)$q['id']] = $q['number'] . ' · ' . $q['name'];
		$s['queues'] = $queues;
		if (!isset($queues[$queue])) { $s['last_error'] = 'File d’attente de secours introuvable : renvoi non modifié.'; $this->saveState($s); return; }
		foreach ($want as $w) {
			$id = (int)($w['ext_id'] ?? 0); $mark = $queue . '|' . self::RING_SECONDS; if (!$id || ($done[$id] ?? '') === $mark) continue;
			$e = $this->ok($this->client->call('GET', 'extension/get', ['id' => $id]), 'Lecture d’un poste')['data'] ?? [];
			$available = null;
			foreach ($e['presence_list'] ?? [] as $p) if (($p['status'] ?? '') === 'available') $available = $p;
			if ($available) {
				// Le standard refuse qu'on lui renvoie certains champs (mobile_prefix…) : on n'envoie que ceux du renvoi externe.
				$backup[$id] ??= array_intersect_key($available, array_flip(self::FORWARD_KEYS));
				if (!isset($backup[$id]['ring_timeout'])) $backup[$id]['ring_timeout'] = (int)($available['ring_timeout'] ?? 30) === self::RING_SECONDS ? 30 : (int)($available['ring_timeout'] ?? 30);
				$set = ['status' => 'available', 'ring_timeout' => self::RING_SECONDS];
				foreach (['no_answer', 'busy'] as $k) $set += ['enb_ex_' . $k . '_forward' => 1, 'ex_' . $k . '_forward_dest' => 'queue', 'ex_' . $k . '_forward_value' => $queue];
				$this->ok($this->client->call('POST', 'extension/update', [], ['id' => $id, 'presence_list' => [$set]]), 'Renvoi du poste ' . ($e['number'] ?? $id));
			}
			$done[$id] = $mark;
			$s['forward_backup'] = $backup; $s['forward_done'] = $done; $this->saveState($s);
		}
		$this->saveState($s);
	}

	private function restoreForwards(?array $only = null): void {
		$s = $this->state();
		foreach ($s['forward_backup'] ?? [] as $id => $orig) {
			if ($only !== null && !in_array((int)$id, $only, true)) continue;
			$this->ok($this->client->call('POST', 'extension/update', [], ['id' => (int)$id, 'presence_list' => [['status' => 'available'] + array_intersect_key($orig, array_flip(self::FORWARD_KEYS))]]), 'Restauration du poste ' . $id);
			unset($s['forward_backup'][$id], $s['forward_done'][$id]); $this->saveState($s);
		}
	}

	/**
	 * Reprise d'un déplacement de la route générale interrompu (coupure, disque plein…) : il reste l'originale « X » et sa copie
	 * « X_tmp ». Si elles sont identiques, l'originale est supprimée et la copie reprend son nom ; sinon on ne touche à rien.
	 */
	private function resumeSwap(): void {
		$all = $this->routes(); $by = [];
		foreach ($all as $r) $by[(string)$r['name']] = $r;
		foreach ($by as $name => $r) {
			if (!str_ends_with($name, '_tmp') || !isset($by[substr($name, 0, -4)])) continue;
			$orig = $this->route((int)$by[substr($name, 0, -4)]['id']); $copy = $this->route((int)$r['id']);
			if (!self::catchAll($orig) || !self::catchAll($copy)) continue;
			$a = self::body($orig); $b = self::body($copy); unset($a['name'], $b['name']); ksort($a); ksort($b);
			if ($a !== $b) continue;
			$this->ok($this->client->call('GET', 'inbound_route/delete', ['id' => (int)$orig['id']]), 'Reprise : suppression de l’ancienne route générale');
			$this->ok($this->client->call('POST', 'inbound_route/update', [], ['id' => (int)$copy['id'], 'name' => substr($name, 0, -4)]), 'Reprise : renommage de la route générale');
		}
	}

	/** Désactivation : les routes gérées sont supprimées, tous les appels reprennent le circuit normal. */
	private function removeAll(): array {
		if (!$this->lock()) throw new \RuntimeException('Une synchronisation est en cours, réessayez dans un instant.');
		try {
			$this->restoreForwards();
			foreach ($this->routes() as $r) if (preg_match(self::NAME, (string)$r['name'])) $this->ok($this->client->call('GET', 'inbound_route/delete', ['id' => (int)$r['id']]), 'Suppression de ' . $r['name']);
			$this->dropIvrs();
			$s = $this->state(); $s['hashes'] = []; $s['summary'] = null; $s['last_sync'] = time(); $s['last_error'] = ''; $this->saveState($s);
			return $this->status();
		} finally { $this->unlock(); }
	}
}
