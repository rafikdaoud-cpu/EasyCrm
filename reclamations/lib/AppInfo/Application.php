<?php
namespace OCA\Reclamations\AppInfo;
use OCP\AppFramework\App;
class Application extends App {
	public const APP_ID = 'reclamations';
	public function __construct() { parent::__construct(self::APP_ID); }
}
