<?php
namespace OCA\EasyCrm\Controller;
use OCA\EasyCrm\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
class PageController extends Controller {
	public function __construct(IRequest $r, private IURLGenerator $url) { parent::__construct(Application::APP_ID, $r); }
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'main', ['api' => rtrim($this->url->linkToRoute('easycrm.page.index'), '/') . '/api']);
	}
}
