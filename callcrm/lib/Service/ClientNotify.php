<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

use OCP\Notification\IManager;
use OCP\Server;

/**
 * Notification Nextcloud au commercial quand un client écrit (WhatsApp) ou envoie une pièce (e-mail) :
 * une seule par sujet (fiche ou conversation), remplacée à chaque nouvel envoi, retirée quand il l'ouvre.
 */
class ClientNotify {
	public static function send(?string $owner, string $subject, string $objectType, string $objectId, array $params): void {
		if (!$owner) return;
		try {
			$m = Server::get(IManager::class);
			self::clear($owner, $objectType, $objectId);
			$n = $m->createNotification();
			$n->setApp('callcrm')->setUser($owner)->setDateTime(new \DateTime())->setObject($objectType, $objectId)->setSubject($subject, $params);
			$m->notify($n);
		} catch (\Throwable $e) {}  // une notification ne doit jamais bloquer la réception
	}

	public static function clear(string $owner, string $objectType, string $objectId): void {
		try {
			$m = Server::get(IManager::class);
			$n = $m->createNotification();
			$n->setApp('callcrm')->setUser($owner)->setObject($objectType, $objectId);
			$m->markProcessed($n);
		} catch (\Throwable $e) {}
	}
}
