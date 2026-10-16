<?php
namespace OCA\EasyCrm\AppInfo;
use OCP\AppFramework\App;
class Application extends App {
	public const APP_ID = 'easycrm';
	public function __construct() { parent::__construct(self::APP_ID); }
}
