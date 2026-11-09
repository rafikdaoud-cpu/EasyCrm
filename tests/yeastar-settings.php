<?php
namespace OCP { interface IConfig {} interface IRequest {} interface IGroupManager {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\AppFramework { class Controller { public function __construct($name, protected $request) {} } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public $data, public $status = 200) {} } }
namespace OCA\CallCrm\AppInfo { class Application { const APP_ID = 'callcrm'; } }
namespace OCA\CallCrm\Service {
    class Access { public function __construct(private bool $admin) {} public function can($action) { return $this->admin; } public static function groups() { return ['commercial' => 'grp-comm-all']; } }
}
namespace {
    require __DIR__.'/../callcrm/lib/Service/YeastarSettings.php';
    require __DIR__.'/../callcrm/lib/Controller/YeastarController.php';
    class Config implements \OCP\IConfig {
        public array $values = [];
        public function getAppValue($app, $key, $default) { return $this->values[$key] ?? $default; }
        public function setAppValue($app, $key, $value) { $this->values[$key] = $value; }
    }
    class Crypto implements \OCP\Security\ICrypto { public function encrypt($value) { return 'encrypted:'.base64_encode($value); } }
    class Request implements \OCP\IRequest { public array $params = []; public function getParam($key) { return $this->params[$key] ?? null; } }
    class Groups implements \OCP\IGroupManager { public function isInGroup($uid, $group) { return $group === 'grp-comm-all' && in_array($uid, ['alice', 'bob'], true); } }
    function check($ok, $message) { if (!$ok) throw new \RuntimeException($message); echo "PASS $message\n"; }
    $config = new Config(); $settings = new \OCA\CallCrm\Service\YeastarSettings($config, new Crypto());
    check($settings->settings()['url'] === 'https://erp.csmtaksit.dz:8088', 'P550 address is prefilled');
    check($settings->settings()['allow_self_signed'] === false, 'certificate verification bypass is disabled by default');
    $settings->save(['allow_self_signed'=>true]);
    check($settings->settings()['allow_self_signed'] === true, 'admin opt-in is persisted');
    $settings->save([]);
    check($settings->settings()['allow_self_signed'] === true, 'omitted TLS setting preserves opt-in');
    $settings->save(['allow_self_signed'=>false]);
    check($settings->settings()['allow_self_signed'] === false, 'certificate verification can be restored');
    foreach (['false', 'true', 1, [], 'invalid'] as $value) {
        $before = $config->values;
        try { $settings->save(['allow_self_signed'=>$value]); throw new \LogicException('Expected invalid TLS setting'); } catch (\InvalidArgumentException $e) {}
        check($config->values === $before, 'non-boolean TLS setting is rejected without writes');
    }
    $input = ['client_id' => 'test-client', 'secret' => 'private-test-key', 'extensions' => [['uid' => 'alice', 'extension' => '1000']]];
    $result = $settings->save($input);
    check($result['has_secret'] && !str_contains(json_encode($result), 'private-test-key') && !isset($result['secret']), 'settings never return the secret');
    check(!str_contains($config->values['yeastar_settings'], 'private-test-key'), 'secret is encrypted before persistence');
    $settings->save(['secret' => '']); check($settings->settings()['has_secret'], 'blank secret preserves existing key');
    foreach ([['url' => 'http://pbx.test:8088'], ['url' => 'https://pbx.test/integration/api'], ['url' => 'https://user:pass@pbx.test'], ['url' => 'https://pbx.test?token=secret'], ['extensions' => [['uid'=>'alice','extension'=>'1000'],['uid'=>'bob','extension'=>'1000']]], ['extensions' => [['uid'=>'alice','extension'=>'1000'],['uid'=>'alice','extension'=>'1001']]], ['extensions'=>[['uid'=>'alice','extension'=>'abc']]], ['secret'=>'new','clear_secret'=>true]] as $invalid) {
        $before = $config->values;
        try { $settings->save($invalid); throw new \LogicException('Expected invalid input'); } catch (\InvalidArgumentException $e) {}
        check($config->values === $before, 'invalid settings rejected without partial writes');
    }
    $settings->save(['client_id' => 'replacement']); check(!$settings->settings()['has_secret'], 'new identity cannot reuse old secret');
    $settings->save(['secret'=>'replacement-key']); $settings->save(['url'=>'https://other.test:8088']);
    check(!$settings->settings()['has_secret'], 'new server cannot reuse old secret');
    $settings->save(['secret'=>'replacement-key']); $settings->save(['clear_secret'=>true]);
    check(!$settings->settings()['has_secret'], 'explicit secret removal works');
    $request = new Request(); $groups = new Groups();
    $denied = new \OCA\CallCrm\Controller\YeastarController($request, new \OCA\CallCrm\Service\Access(false), $settings, $groups);
    check($denied->settings()->status === 403 && $denied->configure()->status === 403, 'non-admin cannot read or save settings');
    $controller = new \OCA\CallCrm\Controller\YeastarController($request, new \OCA\CallCrm\Service\Access(true), $settings, $groups);
    $request->params = ['extensions'=>[['uid'=>'outsider','extension'=>'1001']]];
    check($controller->configure()->status === 400, 'extension assignment requires commercial group membership');
    $request->params = $input + ['allow_self_signed'=>true];
    check($controller->configure()->status === 200, 'admin can save valid commercial assignment');
    check($controller->settings()->data['allow_self_signed'] === true, 'controller accepts and returns TLS opt-in');
    $method = new \ReflectionMethod($controller, 'configure');
    check(!$method->getAttributes('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired'), 'save keeps framework CSRF protection');
}
