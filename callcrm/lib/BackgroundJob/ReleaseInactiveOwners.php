<?php
declare(strict_types=1);
namespace OCA\CallCrm\BackgroundJob;

use OCA\CallCrm\Service\InactiveOwners;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Toutes les heures : fiches des commerciaux désactivés ou supprimés dans l'AD remises en non attribué. */
final class ReleaseInactiveOwners extends TimedJob {
    public function __construct(ITimeFactory $time, private InactiveOwners $owners) {
        parent::__construct($time);
        $this->setInterval(3600);
    }
    protected function run($argument): void { $this->owners->run(); }
}
