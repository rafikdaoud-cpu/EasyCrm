<?php
namespace OCP { interface IConfig {} class Server { public static function get($c){ return new class { public function getAppValue(...$a){ return ''; } }; } } }
namespace {
require __DIR__.'/../callcrm/lib/Service/Lookup.php';require __DIR__.'/../callcrm/lib/Service/Fields.php';
use OCA\CallCrm\Service\Fields;
$cases=['0550 12 34 56'=>'+213550123456','550123456'=>'+213550123456','+213 550 12 34 56'=>'+213550123456','00213550123456'=>'+213550123456','021 23 45 67'=>'+21321234567',
 '+33 6 12 34 56 78'=>'+33612345678','0033612345678'=>'+33612345678','+1 (415) 555-2671'=>'+14155552671','+971 50 123 4567'=>'+971501234567','٠٥٥٠١٢٣٤٥٦'=>'+213550123456',
 '12345'=>null,'0612'=>null,'+12'=>null,'abc'=>null,'33612345678'=>null,''=>null];
$f=0;foreach($cases as $in=>$want){$got=Fields::whatsapp((string)$in);if($got!==$want){$f++;echo "FAIL «$in» → ",var_export($got,true)," attendu ",var_export($want,true),"\n";}}
echo "callcrm: ",count($cases)," WhatsApp number checks",$f?", $f FAILED":" passed","\n";exit($f?1:0);}
