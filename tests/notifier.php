<?php
// Notification « documents reçus » : texte, lien direct vers la fiche, sujets inconnus ignorés.
namespace OCP { interface IURLGenerator {} }
namespace OCP\Notification { interface INotifier {} interface INotification {} class UnknownNotificationException extends \Exception {} }
namespace {
require __DIR__.'/../callcrm/lib/Notification/Notifier.php';
$fail=0;$n=0;function ok($c,$m){global $fail,$n;$n++;if(!$c){$fail++;echo "FAIL $m\n";}}
class Url implements \OCP\IURLGenerator {public function linkToRouteAbsolute($r){return 'https://cloud.test/index.php/apps/callcrm/';}public function getAbsoluteURL($u){return 'https://cloud.test'.$u;}public function imagePath($a,$i){return '/core/img/'.$i;}}
class Notif implements \OCP\Notification\INotification {public array $v=[];public function __construct(private string $app,private string $subject,private array $params,private string $obj){}
 public function getApp(){return $this->app;}public function getSubject(){return $this->subject;}public function getSubjectParameters(){return $this->params;}public function getObjectId(){return $this->obj;}
 public function setParsedSubject($s){$this->v['subject']=$s;return $this;}public function setParsedMessage($s){$this->v['message']=$s;return $this;}public function setLink($s){$this->v['link']=$s;return $this;}public function setIcon($s){$this->v['icon']=$s;return $this;}}
$no=new \OCA\CallCrm\Notification\Notifier(new Url);
$r=$no->prepare(new Notif('callcrm','documents_received',['name'=>'Karim Benali','count'=>3],'42'),'fr')->v;
ok($r['subject']==='Karim Benali a terminé l’envoi de ses documents (3).','texte « documents reçus »');
ok($r['link']==='https://cloud.test/index.php/apps/callcrm/#fiche=42','lien direct vers la fiche');
$r=$no->prepare(new Notif('callcrm','documents_started',['name'=>'','count'=>0],'7'),'fr')->v;
ok($r['subject']==='Un client a commencé à envoyer ses documents.','texte « premier document », nom inconnu');
foreach([['other','documents_received'],['callcrm','autre']] as [$a,$s]){try{$no->prepare(new Notif($a,$s,[],'1'),'fr');ok(false,'sujet inconnu accepté');}catch(\OCP\Notification\UnknownNotificationException $e){ok(true,'');}}
echo "callcrm: $n notifier checks".($fail?", $fail FAILED":' passed')."\n";exit($fail?1:0);
}
