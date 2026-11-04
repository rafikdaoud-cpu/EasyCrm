<?php
namespace OCA\CallCrm\Service;
/** Dedicated IMAP collector; messages are fetched with PEEK and are never deleted or marked read. */
class DocumentMailbox {
 public function __construct(private Documents $documents){}
 private function connect(){
  if(!function_exists('imap_open'))throw new \RuntimeException('Extension PHP IMAP absente : configurez-la pour le PHP exécutant cron.php.');
  $s=$this->documents->mailboxCredentials();if(!$s['mail_host']||!$s['mail_user']||!$s['password'])throw new \RuntimeException('Complétez les réglages de la boîte de réception.');
  imap_timeout(IMAP_OPENTIMEOUT,15);imap_timeout(IMAP_READTIMEOUT,20);
  $mailbox='{'.$s['mail_host'].':'.$s['mail_port'].'/imap/'.$s['mail_security'].'/validate-cert}'.$s['mail_folder'];
  $connection=@imap_open($mailbox,$s['mail_user'],$s['password'],OP_READONLY,1);
  if(!$connection)throw new \RuntimeException('Connexion IMAP refusée. Vérifiez serveur, certificat et identifiants.');return [$connection,$mailbox,$s];
 }
 public function test():void{[$c]=$this->connect();imap_close($c);}
 public static function decodeHeader(string $value):string{
  $result=function_exists('iconv_mime_decode')?iconv_mime_decode($value,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8'):mb_decode_mimeheader($value);return $result===false?$value:$result;
 }
 public static function parts(object $structure,string $prefix='',int $depth=0):array{
  if($depth>8)throw new \InvalidArgumentException('Message MIME trop imbriqué.');$result=[];
  if(!empty($structure->parts)){foreach($structure->parts as $i=>$part){$result=array_merge($result,self::parts($part,$prefix===''?(string)($i+1):$prefix.'.'.($i+1),$depth+1));if(count($result)>100)throw new \InvalidArgumentException('Trop de pièces dans le message.');}return $result;}
  $name='';foreach(array_merge($structure->parameters??[],$structure->dparameters??[])as $p){if(in_array(strtolower($p->attribute??''),['name','filename','filename*'],true)){$name=(string)$p->value;if(str_contains($name,"''"))$name=rawurldecode(explode("''",$name,2)[1]);}}
  if($name===''&&in_array((int)($structure->type??0),[3,5],true)){$ext=match(strtoupper($structure->subtype??'')){'PDF'=>'pdf','JPEG'=>'jpg','PNG'=>'png','GIF'=>'gif','MSWORD'=>'doc','VND.OPENXMLFORMATS-OFFICEDOCUMENT.WORDPROCESSINGML.DOCUMENT'=>'docx',default=>'bin'};$name='piece-'.($prefix?:'1').'.'.$ext;}if($name==='')return [];return [['part'=>$prefix?:'1','name'=>self::decodeHeader($name),'encoding'=>(int)($structure->encoding??0),'bytes'=>(int)($structure->bytes??0)]];
 }
 public function poll():void{
  if(!$this->documents->settings()['mail_enabled'])return;
  [$c,$mailbox,$settings]=$this->connect();
  try{
   $status=imap_status($c,$mailbox,SA_UIDVALIDITY);if(!$status)throw new \RuntimeException('Statut de boîte IMAP indisponible.');
   $marker=(string)$status->uidvalidity;$state=$this->documents->mailState();$last=$state['validity']===$marker?(int)$state['uid']:0;
   $uids=imap_search($c,'UID '.($last+1).':*',SE_UID)?:[];sort($uids,SORT_NUMERIC);$processed=0;
   foreach($uids as $uid){if($uid<=$last)continue;if($processed++>=10)break;
    $structure=imap_fetchstructure($c,$uid,FT_UID);$overview=imap_fetch_overview($c,(string)$uid,FT_UID);if(!$structure||!$overview)throw new \RuntimeException('Impossible de lire un message de la boîte.');
    $sender='';$from=imap_rfc822_parse_adrlist($overview[0]->from??'','')?:[];if(count($from)===1&&isset($from[0]->mailbox,$from[0]->host))$sender=mb_strtolower($from[0]->mailbox.'@'.$from[0]->host);
    $pid=$this->documents->resolveEmail($sender);$subject=self::decodeHeader($overview[0]->subject??'');
    foreach(self::parts($structure)as $part){$key=hash('sha256',$settings['mail_host'].'|'.$settings['mail_user'].'|'.$settings['mail_folder']).':'.$marker.':'.$uid.':'.$part['part'];
     if($part['bytes']>(int)ceil(Documents::MAX_SIZE*($part['encoding']===3?1.4:1)))throw new \RuntimeException('Pièce supérieure à 25 Mo dans la boîte ; récupérez-la manuellement puis retirez ce message du dossier de collecte.');
     $body=imap_fetchbody($c,$uid,$part['part'],FT_UID|FT_PEEK);if($body===false)throw new \RuntimeException('Téléchargement d’une pièce joint impossible.');
     $body=match($part['encoding']){3=>base64_decode($body,true),4=>quoted_printable_decode($body),default=>$body};if($body===false)throw new \RuntimeException('Encodage de pièce joint invalide.');
     if($body==='')continue;
     $this->documents->receive($pid,'email',$key,$part['name'],$body,$sender,$subject);
    }
    $last=(int)$uid;$this->documents->setConfig('mail_uid',(string)$last);$this->documents->setConfig('mail_validity',$marker);
   }
   $this->documents->setConfig('last_poll',(string)time());$this->documents->setConfig('mail_error','');
  }finally{imap_close($c);}
 }
}
