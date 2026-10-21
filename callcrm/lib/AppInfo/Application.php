<?php
namespace OCA\CallCrm\AppInfo;
use OCP\AppFramework\App;
class Application extends App {
	public const APP_ID = 'callcrm';
	public function __construct() { parent::__construct(self::APP_ID); }
}
