<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * SMS automatique aux clients qui appellent (Administration → SMS) : un modèle par cas, vide = désactivé.
 * « closed » : appel hors des horaires de travail ; « missed » : appel manqué ou perdu.
 * Un seul SMS par numéro et par 24 h, mobiles algériens seulement, appels de plus de 2 h ignorés.
 */
class AutoSms {
	public const KINDS = ['closed', 'missed'];
	private const GAP = 86400;
	private const MAX_AGE = 7200;

	public function __construct(private IConfig $config, private Sms $sms, private IDBConnection $db, private IUserManager $users,
		private ClientActivity $activity, private Store $store, private Wa $wa, private IClientService $http) {}

	/** @param array<int, array{kind:string, phone:string, pid:?int, at:int}> $calls */
	public function reply(array $calls, int $now): int {
		$st = $this->sms->settings();
		$this->whatsapp($calls, $now, $st['auto_wa'] ?? []);
		$auto = $st['auto'] ?? [];
		if (!$this->sms->runtime()['enabled'] || !array_filter($auto)) return 0;
		$texts = [];
		foreach ($st['templates'] as $t) $texts[$t['name']] = $t['text'];
		$sent = json_decode($this->config->getAppValue('callcrm', 'sms_auto_sent', '{}'), true);
		$sent = array_filter(is_array($sent) ? $sent : [], fn($t) => is_int($t) && $t > $now - self::GAP);
		$count = 0;
		foreach ($calls as $c) {
			$text = $texts[$auto[$c['kind']] ?? ''] ?? '';
			$n = Sms::number($c['phone']);
			if ($text === '' || $n === null || isset($sent[$n]) || $c['at'] < $now - self::MAX_AGE) continue;
			$sent[$n] = $now;
			// Noté avant l'envoi : jamais deux SMS au même numéro, même si la synchronisation est rejouée.
			$this->config->setAppValue('callcrm', 'sms_auto_sent', json_encode($sent));
			$p = $c['pid'] ? $this->prospect((int)$c['pid']) : null;
			$owner = $p && $p['assigned_to'] ? ($this->users->get((string)$p['assigned_to'])?->getDisplayName() ?? (string)$p['assigned_to']) : 'CSM Taksit';
			$body = Sms::render($text, $p ?? [], $owner);
			try { $r = $this->sms->send([$n], $body)[$n]; }
			catch (\Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage(), 'id' => '']; }
			$label = $c['kind'] === 'closed' ? 'hors horaires' : 'appel manqué';
			try {
				$this->store->audit(null, 'sms_auto', $p ? (int)$p['id'] : null, $label . ' · ' . $c['phone'] . ' · ' . ($r['ok'] ? 'envoyé' : 'échec : ' . $r['error']));
				if ($p) $this->activity->event((int)$p['id'], 'system', 'sms', ['text' => $body, 'ok' => $r['ok'], 'error' => $r['error'], 'message_id' => $r['id'],
					'segments' => Sms::segments($body)['segments'], 'auto' => $c['kind']]);
			} catch (\Throwable $e) {}  // le journal ne doit jamais bloquer l'envoi suivant
			if ($r['ok']) $count++;
		}
		return $count;
	}

	/** Même chose par WhatsApp : modèle approuvé par Meta (« nom|langue »), seul envoi permis à un client qui n'a pas écrit. */
	private function whatsapp(array $calls, int $now, array $auto): void {
		if (!array_filter($auto) || $this->wa->conf('wa_secret') === '') return;
		$sent = json_decode($this->config->getAppValue('callcrm', 'wa_auto_sent', '{}'), true);
		$sent = array_filter(is_array($sent) ? $sent : [], fn($t) => is_int($t) && $t > $now - self::GAP);
		foreach ($calls as $c) {
			$choice = (string)($auto[$c['kind']] ?? '');
			$n = Sms::number($c['phone']);
			if ($choice === '' || $n === null || isset($sent[$n]) || $c['at'] < $now - self::MAX_AGE) continue;
			[$name, $lang] = explode('|', $choice, 2) + ['', ''];
			$sent[$n] = $now;
			$this->config->setAppValue('callcrm', 'wa_auto_sent', json_encode($sent));
			$label = $c['kind'] === 'closed' ? 'hors horaires' : 'appel manqué';
			$ok = false; $err = '';
			try {
				$res = $this->http->newClient()->post(rtrim($this->wa->conf('wa_service', 'http://127.0.0.1:8085'), '/') . '/send', [
					'headers' => ['X-CRM-Secret' => $this->wa->conf('wa_secret'), 'Content-Type' => 'application/json'],
					'body' => json_encode(['to' => $n, 'template' => ['name' => $name, 'language' => $lang], 'values' => [], 'user' => 'system', 'user_name' => 'Message automatique']),
					'timeout' => 60, 'http_errors' => false, 'nextcloud' => ['allow_local_address' => true]]);
				$j = json_decode((string)$res->getBody(), true) ?: [];
				$ok = $res->getStatusCode() < 300 && !empty($j['wamid']);
				$err = $ok ? '' : (string)($j['error'] ?? ('erreur ' . $res->getStatusCode()));
				if ($ok) $this->wa->record(['wa_id' => $n, 'wamid' => $j['wamid'], 'direction' => 'out', 'type' => 'text',
					'text' => '📋 Modèle « ' . $name . ' » (' . $label . ')' . "\n" . (string)($j['text'] ?? ''), 'user' => 'system', 'ts' => $now, 'status' => 'sent']);
			} catch (\Throwable $e) { $err = $e->getMessage(); }
			try { $this->store->audit(null, 'wa_auto', $c['pid'] ? (int)$c['pid'] : null, $label . ' · ' . $c['phone'] . ' · ' . ($ok ? 'envoyé' : 'échec : ' . $err)); } catch (\Throwable $e) {}
		}
	}

	private function prospect(int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('id', 'firstname', 'lastname', 'assigned_to')->from('callcrm_prospects')
			->where($q->expr()->eq('id', $q->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
		return $row ?: null;
	}
}
