<?php
declare(strict_types=1);
namespace OCA\CallCrm\BackgroundJob;

use OCA\CallCrm\Service\YeastarRouting;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Routes entrantes du standard à jour (attributions, présence) sans intervention ; ne fait rien tant que l'option est désactivée. */
final class SyncYeastarRouting extends TimedJob {
    public function __construct(ITimeFactory $time, private YeastarRouting $routing) {
        parent::__construct($time);
        $this->setInterval(120);
    }
    protected function run($argument): void {
        try { $this->routing->sync(); } catch (\Throwable $e) { /* erreur conservée dans l'état, visible dans l'administration */ }
    }
}
