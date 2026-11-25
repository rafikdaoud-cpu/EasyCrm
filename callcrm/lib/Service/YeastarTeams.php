<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;
use OCP\IGroupManager;

/**
 * Menu du standard par équipe : groupes AD → équipes CRM → files Yeastar → touches du SVI d'accueil.
 *
 * Réglage (appconfig callcrm/yeastar_team_queues, JSON) :
 *   {"enabled":true,"ivr":"6200","prompt":"Accueil-CSM.wav,Menu-CSM.wav","prompt_repeat":2,"template_queue":1,"fallback_queue":1,
 *    "teams":[{"team":"crm_equipe_motos","ad":"callcrm_Motos","key":"1","queue":"CSM_MOTOS"}, …]}
 * - l'équipe CRM (groupe local) reprend les membres du groupe AD ; un groupe AD vide ou absent est ignoré (panne d'annuaire) ;
 * - chaque équipe a sa file, copie de la file modèle (musique, messages d'attente), avec les postes des commerciaux
 *   de l'équipe ; administrateurs et superviseurs ne reçoivent pas d'appel ;
 * - le SVI d'accueil envoie chaque touche vers la file de son équipe ; sans réponse ou touche invalide, après les
 *   répétitions du menu, vers la file de secours (tous les commerciaux). Sans « prompt », le SVI n'est pas modifié.
 */
class YeastarTeams {
	public const CONFIG = 'yeastar_team_queues';
	private const STATE = 'yeastar_team_state';
	public const QUEUE_FIRST = 6402, QUEUE_LAST = 6449;

	public function __construct(private YeastarClient $client, private YeastarSettings $settings, private IConfig $config, private IGroupManager $gm) {}

	public function config(): array {
		$v = json_decode($this->config->getAppValue('callcrm', self::CONFIG, '{}'), true);
		return is_array($v) ? $v : [];
	}
	public function state(): array {
		$v = json_decode($this->config->getAppValue('callcrm', self::STATE, '{}'), true);
		return is_array($v) ? $v : [];
	}
	private function saveState(array $s): void { $this->config->setAppValue('callcrm', self::STATE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }

	private function ok(array $r, string $what): array {
		if ((string)($r['errcode'] ?? '') !== '0') throw new \RuntimeException($what . ' : ' . substr((string)($r['errmsg'] ?? '?') . ' ' . json_encode($r['invalid_param_list'] ?? [], JSON_UNESCAPED_UNICODE), 0, 240));
		return $r;
	}

	/** Postes Yeastar (id) des comptes donnés, sans les comptes exclus ni ceux sans poste ; triés pour un résultat stable. */
	public static function agents(array $uids, array $uidToExt, array $extToId, array $excluded): array {
		$out = [];
		foreach ($uids as $uid) {
			$ext = $uidToExt[$uid] ?? null;
			if (isset($excluded[$uid]) || $ext === null || !isset($extToId[$ext])) continue;
			$out[(string)$extToId[$ext]] = true;
		}
		$ids = array_map('strval', array_keys($out)); sort($ids);
		return $ids;
	}

	/** Champs du SVI à changer pour obtenir le menu voulu ([] si déjà conforme). $keys : touche => id de file. */
	public static function ivrChanges(array $ivr, array $keys, string $fallback, string $prompt, int $repeat): array {
		$want = ['prompt' => $prompt, 'prompt_repeat' => $repeat];
		foreach ($keys as $k => $q) { $want['press' . $k . '_dest'] = 'queue'; $want['press' . $k . '_dest_value'] = (string)$q; }
		foreach (['timeout', 'invalid'] as $k) { $want[$k . '_dest'] = 'queue'; $want[$k . '_dest_value'] = $fallback; }
		return array_filter($want, fn($v, $k) => (string)($ivr[$k] ?? '') !== (string)$v, ARRAY_FILTER_USE_BOTH);
	}

	private function members(string $gid): array {
		$g = $this->gm->get($gid);
		return $g ? array_map(fn($u) => $u->getUID(), array_filter($g->getUsers(), fn($u) => $u->isEnabled())) : [];
	}

	/** L'équipe CRM reprend exactement les membres du groupe AD. */
	private function teamFromAd(string $team, string $ad): void {
		$src = $this->gm->get($ad); $dst = $this->gm->get($team);
		if (!$src || !$dst) return;
		$want = array_map(fn($u) => $u->getUID(), $src->getUsers());
		if (!$want) return; // annuaire vide ou injoignable : on ne vide pas l'équipe
		$have = array_map(fn($u) => $u->getUID(), $dst->getUsers());
		$um = \OCP\Server::get(\OCP\IUserManager::class);
		foreach (array_diff($want, $have) as $uid) if ($u = $um->get($uid)) $dst->addUser($u);
		foreach (array_diff($have, $want) as $uid) if ($u = $um->get($uid)) $dst->removeUser($u);
	}

	public function sync(): array {
		$c = $this->config();
		if (($c['enabled'] ?? false) !== true || !$this->settings->settings()['enabled']) return $this->state();
		$s = $this->state();
		try {
			$groups = Access::groups();
			$excluded = array_flip(array_merge($this->members($groups['admin']), $this->members($groups['superviseur'])));
			$uidToExt = [];
			foreach ($this->settings->settings()['extensions'] as $e) $uidToExt[(string)$e['uid']] = (string)$e['extension'];
			$extToId = [];
			foreach ($this->ok($this->client->call('GET', 'extension/list', ['page' => 1, 'page_size' => 1000]), 'Lecture des postes')['data'] ?? [] as $e) $extToId[(string)$e['number']] = (string)$e['id'];
			$queues = [];
			foreach ($this->ok($this->client->call('GET', 'queue/list', []), 'Lecture des files')['queue_list'] ?? [] as $q) $queues[(string)$q['name']] = $q;
			$tpl = $this->ok($this->client->call('GET', 'queue/get', ['id' => (int)($c['template_queue'] ?? 1)]), 'Lecture de la file modèle')['data'] ?? [];
			unset($tpl['id'], $tpl['number'], $tpl['name'], $tpl['static_agent_list'], $tpl['dynamic_agent_list']);
			// Le standard renvoie ces valeurs à 0 quand l'option est coupée, mais les refuse à l'écriture.
			foreach (['max_pause_miss_call', 'weight'] as $k) if (empty($tpl[$k])) unset($tpl[$k]);
			$tpl['manager_list'] = array_map(fn($m) => ['value' => (string)$m['value'], 'type' => $m['type']], $tpl['manager_list'] ?? []);
			$keys = []; $summary = [];
			foreach ($c['teams'] ?? [] as $t) {
				if (!preg_match('/^[1-9]$/D', (string)($t['key'] ?? '')) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', (string)($t['queue'] ?? ''))) continue;
				if (!empty($t['ad'])) $this->teamFromAd((string)$t['team'], (string)$t['ad']);
				$agents = self::agents($this->members((string)$t['team']), $uidToExt, $extToId, $excluded);
				$body = ['name' => $t['queue'], 'static_agent_list' => array_map(fn($id) => ['value' => $id, 'type' => 'extension'], $agents)] + $tpl;
				$h = hash('sha256', json_encode($body));
				if ($q = $queues[$t['queue']] ?? null) {
					$qid = (int)$q['id'];
					if (($s['hashes'][$qid] ?? '') !== $h) $this->ok($this->client->call('POST', 'queue/update', [], ['id' => $qid] + $body), 'Mise à jour de la file ' . $t['queue']);
				} else {
					$used = array_flip(array_map(fn($q) => (string)$q['number'], $queues));
					$num = null;
					for ($x = self::QUEUE_FIRST; $x <= self::QUEUE_LAST; $x++) if (!isset($used[(string)$x])) { $num = (string)$x; break; }
					if ($num === null) throw new \RuntimeException('Plus de numéro libre pour les files.');
					$qid = (int)$this->ok($this->client->call('POST', 'queue/create', [], ['number' => $num] + $body), 'Création de la file ' . $t['queue'])['id'];
					$queues[$t['queue']] = ['id' => $qid, 'number' => $num, 'name' => $t['queue']];
				}
				$s['hashes'][$qid] = $h;
				$keys[$t['key']] = $qid;
				$summary[$t['queue']] = count($agents);
			}
			$prompt = (string)($c['prompt'] ?? '');
			if ($prompt !== '' && $keys) {
				$ivr = null;
				foreach ($this->ok($this->client->call('GET', 'ivr/list', []), 'Lecture des SVI')['data'] ?? [] as $i) if ((string)$i['number'] === (string)($c['ivr'] ?? '6200')) $ivr = $i;
				if (!$ivr) throw new \RuntimeException('SVI d’accueil introuvable.');
				$cur = $this->ok($this->client->call('GET', 'ivr/get', ['id' => (int)$ivr['id']]), 'Lecture du SVI d’accueil')['data'] ?? [];
				$diff = self::ivrChanges($cur, $keys, (string)($c['fallback_queue'] ?? 1), $prompt, (int)($c['prompt_repeat'] ?? 2));
				if ($diff) $this->ok($this->client->call('POST', 'ivr/update', [], ['id' => (int)$ivr['id']] + $diff), 'Mise à jour du SVI d’accueil');
			}
			$s['summary'] = $summary; $s['last_error'] = '';
		} catch (\Throwable $e) {
			$s['last_error'] = substr($e->getMessage(), 0, 300);
		}
		$s['last_sync'] = time();
		$this->saveState($s);
		return $s;
	}
}
