<?php
namespace OCA\CallCrm\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\CallCrm\Service\YeastarRecordings;

class CollectYeastarRecordings extends TimedJob {
    public function __construct(ITimeFactory $time, private YeastarRecordings $recordings) {
        parent::__construct($time);
        $this->setInterval(60);
    }
    protected function run($argument): void { $this->recordings->processQueue(); }
}
