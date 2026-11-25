<?php
declare(strict_types=1);
namespace OCA\CallCrm\BackgroundJob;

use OCA\CallCrm\Service\YeastarTeams;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Équipes (AD) → files du standard → menu d'accueil ; ne fait rien tant que le réglage n'est pas activé. */
final class SyncYeastarTeams extends TimedJob {
    public function __construct(ITimeFactory $time, private YeastarTeams $teams) {
        parent::__construct($time);
        $this->setInterval(300);
    }
    protected function run($argument): void { $this->teams->sync(); }
}
