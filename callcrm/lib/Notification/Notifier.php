<?php
namespace OCA\CallCrm\Notification;

use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Notifications Nextcloud de Call CRM : documents envoyés par un client via son lien de dépôt. */
class Notifier implements INotifier {
	public function __construct(private IURLGenerator $url) {}
	public function getID(): string { return 'callcrm'; }
	public function getName(): string { return 'Call CRM'; }
	public function prepare(INotification $n, string $languageCode): INotification {
		if ($n->getApp() !== 'callcrm' || !in_array($n->getSubject(), ['documents_started', 'documents_received', 'wa_routed'], true)) {
			throw new UnknownNotificationException();
		}
		$p = $n->getSubjectParameters();
		$name = trim((string)($p['name'] ?? '')) ?: 'Un client';
		$count = (int)($p['count'] ?? 0);
		if ($n->getSubject() === 'wa_routed') {
			$n->setParsedSubject($name . ' vous attend sur WhatsApp' . (($p['category'] ?? '') !== '' ? ' (' . $p['category'] . ')' : '') . '.');
			$n->setParsedMessage('Le chatbot vous a confié ce client : répondez-lui dans l’onglet WhatsApp.');
			$n->setLink($this->url->linkToRouteAbsolute('callcrm.page.index') . '#fiche=' . (int)$n->getObjectId());
			$n->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/comment.svg')));
			return $n;
		}
		$n->setParsedSubject($n->getSubject() === 'documents_received'
			? $name . ' a terminé l’envoi de ses documents' . ($count ? ' (' . $count . ')' : '') . '.'
			: $name . ' a commencé à envoyer ses documents.');
		$n->setParsedMessage('Ouvrez la fiche pour les consulter : ils sont aussi dans votre dossier Nextcloud.');
		$n->setLink($this->url->linkToRouteAbsolute('callcrm.page.index') . '#fiche=' . (int)$n->getObjectId());
		$n->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/download.svg')));
		return $n;
	}
}
