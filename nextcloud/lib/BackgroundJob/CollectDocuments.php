<?php
namespace OCA\EasyCrm\BackgroundJob;
use OCP\BackgroundJob\TimedJob;use OCP\AppFramework\Utility\ITimeFactory;
use OCA\EasyCrm\Service\Documents;use OCA\EasyCrm\Service\DocumentMailbox;
class CollectDocuments extends TimedJob {
 public function __construct(ITimeFactory $time,private Documents $documents,private DocumentMailbox $mailbox){parent::__construct($time);$this->setInterval(300);}
 protected function run($argument):void{
  try{$this->documents->setConfig('wa_error','');$this->documents->scanWhatsApp();}catch(\Throwable $e){$this->documents->setConfig('wa_error','Collecte WhatsApp interrompue : vérifiez le compte de stockage et les fichiers sources.');}
  try{$this->mailbox->poll();}catch(\Throwable $e){$this->documents->setConfig('mail_error',mb_substr($e->getMessage(),0,500));}
  $this->documents->processQueue();
 }
}
