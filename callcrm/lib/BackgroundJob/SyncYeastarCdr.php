<?php
declare(strict_types=1);
namespace OCA\CallCrm\BackgroundJob;

use OCA\CallCrm\Service\YeastarCdr;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Journal d'appels complet relu sur le standard toutes les 2 minutes (le webhook n'envoie pas toutes les étapes). */
final class SyncYeastarCdr extends TimedJob {
    public function __construct(ITimeFactory $time, private YeastarCdr $cdr) {
        parent::__construct($time);
        $this->setInterval(120);
    }
    protected function run($argument): void {
        try { $this->cdr->sync(); } catch (\Throwable $e) { /* erreur conservée dans callcrm/yeastar_cdr_error */ }
    }
}
