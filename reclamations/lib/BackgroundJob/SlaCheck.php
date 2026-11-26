<?php

declare(strict_types=1);

namespace OCA\Reclamations\BackgroundJob;

use OCA\Reclamations\Service\ReclamationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Vérifie toutes les heures les réclamations hors délai et prévient les personnes concernées. */
class SlaCheck extends TimedJob {

    public function __construct(ITimeFactory $time, private ReclamationService $service) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run($argument): void {
        $this->service->checkSla();
    }
}
