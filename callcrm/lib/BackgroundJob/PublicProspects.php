<?php
declare(strict_types=1);
namespace OCA\CallCrm\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/** Retry the independent public intake using the existing Nextcloud scheduler. */
final class PublicProspects extends TimedJob {
    public function __construct(ITimeFactory $time, private LoggerInterface $logger) {
        parent::__construct($time);
        $this->setInterval(120);
    }

    protected function run($argument): void {
        $root = dirname(__DIR__, 4);
        $worker = $root . '/prospects/bin/manage.php';
        if (!is_file($worker)) { return; }
        if (!function_exists('proc_open')) { $this->logger->error('Public prospects: PHP process execution unavailable'); return; }
        $directory = dirname($root) . '/csm-prospects-private';
        if (!is_dir($directory)) { return; }
        $log = $directory . '/maintenance.log';
        $process = proc_open(['/usr/local/bin/php', $worker, 'mail'], [0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $root . '/prospects');
        if (!is_resource($process)) { $this->logger->error('Public prospects: background worker could not start'); return; }
        $status = proc_close($process);
        if ($status !== 0) { $this->logger->warning('Public prospects: pending delivery requires attention', ['exitCode'=>$status]); }
    }
}
