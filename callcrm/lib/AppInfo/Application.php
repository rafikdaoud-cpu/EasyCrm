<?php
namespace OCA\CallCrm\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
class Application extends App implements IBootstrap {
	public const APP_ID = 'callcrm';
	public function __construct() { parent::__construct(self::APP_ID); }
	public function register(IRegistrationContext $context): void {
		// Notifications « documents reçus » dans la cloche Nextcloud (et sur bureau / mobile).
		$context->registerNotifierService(\OCA\CallCrm\Notification\Notifier::class);
	}
	public function boot(IBootContext $context): void {}
}
