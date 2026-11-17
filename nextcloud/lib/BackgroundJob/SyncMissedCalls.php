<?php
declare(strict_types=1);
namespace OCA\EasyCrm\BackgroundJob;

use OCA\EasyCrm\Service\MissedCalls;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Clôture et attribution des appels manqués même quand personne n'a le CRM ouvert. */
final class SyncMissedCalls extends TimedJob {
    public function __construct(ITimeFactory $time, private MissedCalls $missed) {
        parent::__construct($time);
        $this->setInterval(60);
    }
    protected function run($argument): void { $this->missed->sync(); }
}
