<?php
// SMS ICOSNET : numéros, comptage, réponses de l'API, réglages (désactivé par défaut) et confidentialité du mot de passe.
namespace OCP { interface IConfig {} }
namespace OCP\Security { interface ICrypto {} }
namespace OCP\Http\Client { interface IClientService {} }
namespace {
require __DIR__.'/../callcrm/lib/Service/Sms.php';
use OCA\CallCrm\Service\Sms;
$fail=0;$n=0;
function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
function throws(callable $f,string $needle,string $m){try{$f();ok(false,$m.' (pas d’erreur)');}catch(\Throwable $e){ok(str_contains($e->getMessage(),$needle),$m.' : '.$e->getMessage());}}
class Config implements \OCP\IConfig {public array $v=[];public function getAppValue($a,$k,$d=''){return $this->v[$k]??$d;}public function setAppValue($a,$k,$val){$this->v[$k]=$val;}}
class Crypto implements \OCP\Security\ICrypto {public function encrypt($s){return 'enc:'.strrev($s);}public function decrypt($s){return strrev(substr($s,4));}}
class Resp {public function __construct(private int $code,private string $body){}public function getStatusCode(){return $this->code;}public function getBody(){return $this->body;}}
class Client {public array $urls=[];public $reply;public function get($url,$opt){$this->urls[]=$url;return ($this->reply)($url);}}
class Http implements \OCP\Http\Client\IClientService {public Client $c;public function __construct(){$this->c=new Client;}public function newClient(){return $this->c;}}

// Numéros
ok(Sms::number('0550 12 34 56')==='213550123456','0550 → 213');
ok(Sms::number('+213 770-11-22-33')==='213770112233','+213');
ok(Sms::number('00213661000000')==='213661000000','00213');
ok(Sms::number('661000000')==='213661000000','9 chiffres');
ok(Sms::number('021 23 45 67')===null,'fixe refusé');
ok(Sms::number('0450123456')===null,'préfixe 4 refusé');
ok(Sms::number('')===null && Sms::number('abc')===null,'vide');

// Comptage
ok(Sms::segments(str_repeat('a',160))==['chars'=>160,'segments'=>1,'unicode'=>false],'160 GSM = 1 SMS');
ok(Sms::segments(str_repeat('a',161))['segments']===2,'161 GSM = 2 SMS');
ok(Sms::segments('Prix 10€')['chars']===9,'€ compte double');
ok(Sms::segments('Merci, à bientôt')['unicode']===true,'ô hors GSM → Unicode');
ok(Sms::segments('éèàù Ç É')['unicode']===false,'accents GSM restent GSM');
$ar=str_repeat('س',71);ok(Sms::segments($ar)==['chars'=>71,'segments'=>2,'unicode'=>true],'arabe : 70 par SMS');
ok(Sms::segments('')['segments']===0,'vide 0');
throws(fn()=>Sms::check(str_repeat('a',766)),'trop long','plus de 5 SMS refusé');
throws(fn()=>Sms::check('   '),'vide','message vide refusé');
ok(Sms::render('Bonjour {prenom} {nom}, {commercial}',['firstname'=>'Karim','lastname'=>'BENALI'],'Alice')==='Bonjour Karim BENALI, Alice','variables');
ok(Sms::render('Bonjour {prenom} !',['firstname'=>''],'x')==='Bonjour !','variable vide sans double espace');

// Réponses ICOSNET
$r=Sms::parse('1701|213550123456:abc-123',['213550123456']);ok($r['213550123456']['ok']&&$r['213550123456']['id']==='abc-123','succès un numéro');
$r=Sms::parse("1701|213550000001:id1,1706|213550000002:",['213550000001','213550000002']);ok($r['213550000001']['ok']&&!$r['213550000002']['ok']&&str_contains($r['213550000002']['error'],'invalide'),'mixte');
$r=Sms::parse('1703',['213550000001','213550000002']);ok(!$r['213550000001']['ok']&&!$r['213550000002']['ok']&&str_contains($r['213550000002']['error'],'mot de passe'),'erreur globale appliquée à tous');
$r=Sms::parse('<html>oops</html>',['213550000001']);ok(!$r['213550000001']['ok']&&str_contains($r['213550000001']['error'],'illisible'),'réponse illisible = échec');
ok(str_contains(Sms::error('18'),'non autorisée'),'IP non autorisée');ok(str_contains(Sms::error('1725'),'Crédit'),'crédit');

// Réglages : désactivé par défaut, activation impossible sans les accès
$cfg=new Config;$http=new Http;$sms=new Sms($cfg,new Crypto,$http);
$s=$sms->settings();ok($s['enabled']===false&&$sms->runtime()['enabled']===false,'désactivé par défaut');
throws(fn()=>$sms->save(['enabled'=>true]),'renseignez','activation sans accès refusée');
throws(fn()=>$sms->save(['url'=>'https://x.dz/bulksms/bulksms']),'Adresse','chemin refusé');
throws(fn()=>$sms->save(['url'=>'https://u:p@x.dz']),'Adresse','identifiants dans l’URL refusés');
throws(fn()=>$sms->save(['url'=>'ftp://x.dz']),'Adresse','schéma refusé');
throws(fn()=>$sms->save(['sender'=>'CSM TAKSIT 2026']),'Expéditeur','expéditeur trop long');
throws(fn()=>$sms->save(['templates'=>[['name'=>'','text'=>'x']]]),'nom','modèle sans nom');
$s=$sms->save(['url'=>'https://sms.icosnet.test:8443/','username'=>'csm','password'=>'S3cr&t=1','sender'=>'CSMTAKSIT','templates'=>[['name'=>'Relance','text'=>'Bonjour {prenom}']]]);
ok($s['url']==='https://sms.icosnet.test:8443'&&$s['has_password']&&!isset($s['password'])&&$s['enabled']===false,'enregistré, toujours désactivé, mot de passe non renvoyé');
ok(!str_contains($cfg->v['sms_settings'],'S3cr&t=1'),'mot de passe chiffré au repos');
ok($sms->runtime()['enabled']===false,'configuré mais pas activé : pas de bouton');
$s=$sms->save(['enabled'=>true]);ok($s['enabled']&&$sms->runtime()['enabled'],'activation');
$s=$sms->save(['password'=>'']);ok($s['has_password'],'mot de passe vide = conservé');
$s=$sms->save(['enabled'=>'false']);ok($s['enabled']===false,'désactivation (chaîne false)');
$sms->save(['enabled'=>true]);
throws(fn()=>$sms->save(['clear_password'=>true]),'renseignez','effacer le mot de passe d’une option active refusé');

// Envoi : paramètres, Unicode, paquets de 50, mot de passe jamais dans les erreurs
$http->c->reply=fn($u)=>new Resp(200,'1701|213550123456:m1');
$res=$sms->send(['213550123456'],'Bonjour Karim');
ok($res['213550123456']['ok'],'envoi OK');
parse_str(parse_url($http->c->urls[0],PHP_URL_QUERY),$q);
ok(str_starts_with($http->c->urls[0],'https://sms.icosnet.test:8443/bulksms/bulksms?'),'point d’entrée');
ok($q['username']==='csm'&&$q['password']==='S3cr&t=1'&&$q['type']==='0'&&$q['dlr']==='0'&&$q['source']==='CSMTAKSIT'&&$q['message']==='Bonjour Karim'&&$q['destination']==='213550123456','paramètres');
$http->c->urls=[];$sms->send(['213550123456'],'مرحبا');parse_str(parse_url($http->c->urls[0],PHP_URL_QUERY),$q);ok($q['type']==='2'&&$q['message']==='مرحبا','arabe en Unicode (type 2)');
$nums=[];for($i=0;$i<120;$i++)$nums[]='2135500'.str_pad((string)$i,5,'0',STR_PAD_LEFT);
$http->c->urls=[];$http->c->reply=function($u){parse_str(parse_url($u,PHP_URL_QUERY),$q);return new Resp(200,implode(',',array_map(fn($d)=>'1701|'.$d.':x'.$d,explode(',',$q['destination']))));};
$res=$sms->send($nums,'Promo');ok(count($http->c->urls)===3&&count(array_filter($res,fn($r)=>$r['ok']))===120,'120 numéros en 3 paquets');
$http->c->reply=function($u){throw new \RuntimeException('cURL error for '.$u);};
$res=$sms->send(['213550123456'],'x');ok(!$res['213550123456']['ok']&&!str_contains(json_encode($res),'S3cr'),'erreur réseau sans mot de passe');
$http->c->reply=fn($u)=>new Resp(500,'S3cr&t=1');
throws(fn()=>$sms->credit(),'Connexion','HTTP 500');
$http->c->reply=fn($u)=>new Resp(200,'BALANCE:12345.12');ok($sms->credit()==='12345.12','crédit');
ok(str_contains(end($http->c->urls),'/CreditCheck/checkcredits?'),'point d’entrée crédit');
$http->c->reply=fn($u)=>new Resp(200,'AUTHORIZATION_FAILED');throws(fn()=>$sms->credit(),'refusé','crédit refusé');

echo $fail?"$fail/$n SMS checks FAILED\n":"callcrm: $n SMS checks passed\n";exit($fail?1:0);
}
