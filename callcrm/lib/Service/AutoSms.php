<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

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
		private ClientActivity $activity, private Store $store) {}

	/** @param array<int, array{kind:string, phone:string, pid:?int, at:int}> $calls */
	public function reply(array $calls, int $now): int {
		$st = $this->sms->settings();
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

	private function prospect(int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('id', 'firstname', 'lastname', 'assigned_to')->from('callcrm_prospects')
			->where($q->expr()->eq('id', $q->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
		return $row ?: null;
	}
}
