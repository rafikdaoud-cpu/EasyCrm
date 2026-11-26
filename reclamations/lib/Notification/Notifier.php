<?php

declare(strict_types=1);

namespace OCA\Reclamations\Notification;

use OCA\Reclamations\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {

    public function __construct(private IURLGenerator $url) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Réclamations';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            $this->unknown();
        }
        $p = $notification->getSubjectParameters();
        $ref = (string)($p['ref'] ?? '');
        $actor = (string)($p['actor'] ?? '');
        $by = $actor !== '' ? ' par ' . $actor : '';

        $text = match ($notification->getSubject()) {
            'assigned' => sprintf('La réclamation %s vous a été affectée%s', $ref, $by),
            'status_resolu' => sprintf('La réclamation %s a été résolue%s — à confirmer avec le client', $ref, $by),
            'status_rejetee' => sprintf('La réclamation %s a été rejetée%s', $ref, $by),
            'reopened' => sprintf('La réclamation %s a été réouverte%s', $ref, $by),
            'comment' => sprintf('Nouveau commentaire sur la réclamation %s%s', $ref, $by),
            'sla' => sprintf('Délai dépassé : la réclamation %s est en retard', $ref),
            default => null,
        };
        if ($text === null) {
            $this->unknown();
        }

        $notification->setParsedSubject($text)
            ->setLink($this->url->linkToRouteAbsolute('reclamations.page.index') . '#/r/' . $notification->getObjectId())
            ->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
        return $notification;
    }

    /** @return never */
    private function unknown(): never {
        if (class_exists(UnknownNotificationException::class)) {
            throw new UnknownNotificationException();
        }
        throw new \InvalidArgumentException();
    }
}
