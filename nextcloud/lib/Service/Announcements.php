<?php
namespace OCA\EasyCrm\Service;
use OCP\IDBConnection;

class Announcements {
	public function __construct(private IDBConnection $db) {}

	/** Toutes les livraisons sont enregistrées ensemble, ou aucune. */
	public function send(string $sender, string $name, array $recipients, string $body): void {
		$this->db->beginTransaction();
		try {
			$now = time();
			foreach ($recipients as $uid) {
				$q = $this->db->getQueryBuilder();
				$q->insert('easycrm_announcements');
				foreach (['sender_id' => $sender, 'sender_name' => $name, 'recipient_id' => $uid, 'body' => $body, 'created_at' => $now] as $k => $v) {
					$q->setValue($k, $q->createNamedParameter($v));
				}
				$q->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function pending(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'sender_name', 'body', 'created_at')->from('easycrm_announcements')
			->where($q->expr()->eq('recipient_id', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->isNull('dismissed_at'))->orderBy('id', 'ASC')->setMaxResults(20);
		return $q->executeQuery()->fetchAll();
	}

	/** Le destinataire est imposé côté serveur ; fermer deux fois est sans effet. */
	public function dismiss(int $id, string $uid): void {
		$q = $this->db->getQueryBuilder();
		$q->update('easycrm_announcements')->set('dismissed_at', $q->createNamedParameter(time()))
			->where($q->expr()->eq('id', $q->createNamedParameter($id)))
			->andWhere($q->expr()->eq('recipient_id', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->isNull('dismissed_at'))->executeStatement();
	}
}
