<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\Access;
use OCA\CallCrm\Service\YeastarSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IGroupManager;

class YeastarController extends Controller {
    public function __construct(IRequest $request, private Access $access, private YeastarSettings $settings, private IGroupManager $groups) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function settings(): JSONResponse {
        if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
        return new JSONResponse($this->settings->settings());
    }

    #[NoAdminRequired]
    public function configure(): JSONResponse {
        if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
        $input = [];
        foreach (['url', 'client_id', 'secret', 'clear_secret', 'linkus_url', 'extensions', 'allow_self_signed', 'enabled', 'webhook_secret'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) $input[$key] = $value;
        }
        try {
            if (isset($input['extensions']) && is_array($input['extensions'])) {
                foreach ($input['extensions'] as $row) {
                    if (!is_array($row) || !$this->groups->isInGroup((string)($row['uid'] ?? ''), Access::groups()['commercial'])) {
                        throw new \InvalidArgumentException('Un compte sélectionné ne fait pas partie du groupe des commerciaux.');
                    }
                }
            }
            return new JSONResponse($this->settings->save($input));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }
}
