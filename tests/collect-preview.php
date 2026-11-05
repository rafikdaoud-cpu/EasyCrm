<?php
/** Aperçu du lien client (WhatsApp, SMS, iMessage) : nom et logo de l'entreprise, jamais l'icône Nextcloud. */
namespace OCP {
 interface IURLGenerator{} interface IRequest{} interface IUserManager{} interface IAppConfig{}
 class Defaults{public function getName(){return 'CSM Cloud';}}
 class Server{public static array $s=[];public static function get($c){if(!isset(self::$s[$c]))throw new \RuntimeException("no $c");return self::$s[$c];}}
 class Util{public static array $h=[];public static function addHeader($tag,$attr){self::$h[]=[$tag,$attr];}public static function addStyle(...$a){}public static function addScript(...$a){}}
}
namespace OCP\AppFramework {class Controller{public function __construct($app,$req){}}}
namespace OCP\AppFramework\Http {class JSONResponse{} class TemplateResponse{const RENDER_AS_BASE='base';public function __construct(public $app,public $tpl,public $params,public $as){}public function setFeaturePolicy($p){}public function setContentSecurityPolicy($p){}public function addHeader($k,$v){}public function throttle($m){}}
 class ContentSecurityPolicy{public function addAllowedWorkerSrcDomain($d){}} class FeaturePolicy{public function addAllowedCameraDomain($d){}}}
namespace OCP\AppFramework\Http\Attribute {#[\Attribute] class PublicPage{} #[\Attribute] class NoCSRFRequired{} #[\Attribute] class BruteForceProtection{public function __construct(...$a){}} #[\Attribute] class AnonRateLimit{public function __construct(...$a){}}}
namespace Psr\Log {interface LoggerInterface{}}
namespace OCA\CallCrm\AppInfo {class Application{const APP_ID='callcrm';}}
namespace OCA\CallCrm\Service {class Store{} class Collect{public function byToken($t){return ['id'=>1];}} class ClientActivity{}}
namespace {
$root=dirname(__DIR__).'/callcrm';require $root.'/lib/Controller/CollectPublicController.php';
class Urls implements \OCP\IURLGenerator{public function linkToRoute($r,$a=[]){return $r==='theming.Theming.getImage'?'/index.php/apps/theming/image/'.$a['key']:'/index.php/apps/callcrm/';}public function getAbsoluteURL($u){return 'https://cloud.test'.$u;}}
class Conf implements \OCP\IAppConfig{public function __construct(public array $v){}public function getValueString($app,$key,$def=''){return $this->v[$app.'.'.$key]??$def;}}
class Log implements \Psr\Log\LoggerInterface{public function debug($m,$c=[]){}}
function p($s){echo htmlspecialchars((string)$s);}
$n=0;function ok($c,$l){global $n;if(!$c)throw new RuntimeException($l);$n++;echo "PASS $l\n";}
function render(array $conf):array{
 \OCP\Server::$s=[\OCP\Defaults::class=>new \OCP\Defaults(),\OCP\IAppConfig::class=>new Conf($conf)];\OCP\Util::$h=[];
 $c=new \OCA\CallCrm\Controller\CollectPublicController(new class implements \OCP\IRequest{},new \OCA\CallCrm\Service\Collect(),new \OCA\CallCrm\Service\Store(),new \OCA\CallCrm\Service\ClientActivity(),new Urls(),new class implements \OCP\IUserManager{},new Log());
 $r=$c->page('T');$_=$r->params;ob_start();require dirname(__DIR__).'/callcrm/templates/collect.php';ob_end_clean();
 $m=[];foreach(\OCP\Util::$h as [$t,$a])$m[$a['property']??$a['name']??$a['rel']]=$a['content']??$a['href'];return $m;
}
$m=render(['theming.faviconMime'=>'image/png','theming.logoMime'=>'image/svg+xml','theming.cachebuster'=>'7']);
ok($m['og:image']==='https://cloud.test/index.php/apps/theming/image/favicon?v=7','PNG favicon is the preview image');
ok($m['apple-touch-icon']===$m['og:image'],'iMessage icon uses the company logo');
ok($m['og:title']==='CSM Cloud – Envoi de vos documents'&&$m['og:site_name']==='CSM Cloud','preview title uses the company name');
$m=render(['theming.faviconMime'=>'image/x-icon','theming.logoMime'=>'image/png']);
ok(str_ends_with(explode('?',$m['og:image'])[0],'/image/logo'),'falls back to the PNG logo when the favicon is an .ico');
$m=render([]);
ok(!isset($m['og:image'])&&!isset($m['apple-touch-icon'])&&isset($m['og:title']),'no custom logo: no broken image, title kept');
\OCP\Server::$s=[];\OCP\Util::$h=[];$c=new \OCA\CallCrm\Controller\CollectPublicController(new class implements \OCP\IRequest{},new \OCA\CallCrm\Service\Collect(),new \OCA\CallCrm\Service\Store(),new \OCA\CallCrm\Service\ClientActivity(),new Urls(),new class implements \OCP\IUserManager{},new Log());
ok($c->page('T')->params['preview']['title']==='Envoi de vos documents','page still opens if theming is unavailable');
echo "callcrm: $n link preview checks passed\n";
}
