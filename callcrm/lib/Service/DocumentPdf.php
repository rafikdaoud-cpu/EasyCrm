<?php
namespace OCA\CallCrm\Service;
use Symfony\Component\Process\Process;
/** Original bytes are never replaced by this optional PDF derivative. */
class DocumentPdf {
 public static function jpeg(string $bytes): string {
  $info=@getimagesizefromstring($bytes);if(!$info||$info[2]!==IMAGETYPE_JPEG||$info[0]*$info[1]>40000000)throw new \InvalidArgumentException('Image JPEG invalide ou trop grande.');
  [$w,$h]=$info;$scale=min(555/$w,802/$h);$pw=$w*$scale;$ph=$h*$scale;$x=(595-$pw)/2;$y=(842-$ph)/2;
  $stream=sprintf('q %.3F 0 0 %.3F %.3F %.3F cm /Im0 Do Q',$pw,$ph,$x,$y);
  $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
   '<< /Type /XObject /Subtype /Image /Width '.$w.' /Height '.$h.' /ColorSpace '.(($info['channels']??3)===1?'/DeviceGray':(($info['channels']??3)===4?'/DeviceCMYK':'/DeviceRGB')).' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes).' >>' . "\nstream\n".$bytes."\nendstream",
   '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream"];
  $pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}$start=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1) as $offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";
  return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
 }
 public static function convert(string $bytes,string $name,string $mime,string $directory,string $office='/usr/bin/libreoffice'): string {
  if($mime==='application/pdf'){if(!str_starts_with($bytes,'%PDF-'))throw new \InvalidArgumentException('PDF invalide.');return $bytes;}
  if(str_starts_with($mime,'image/')){
   if($mime==='image/jpeg')return self::jpeg($bytes);
   if(!function_exists('imagecreatefromstring'))throw new \RuntimeException('Extension PHP GD requise pour cette image.');
   $size=@getimagesizefromstring($bytes);if(!$size||$size[0]*$size[1]>40000000)throw new \InvalidArgumentException('Image invalide ou trop grande.');
   $memory=ini_get('memory_limit');if($memory!=='-1'){ $limit=(int)$memory; $suffix=strtolower(substr($memory,-1));if($suffix==='g')$limit*=1073741824;elseif($suffix==='m')$limit*=1048576;elseif($suffix==='k')$limit*=1024;if(memory_get_usage(true)+$size[0]*$size[1]*12+strlen($bytes)*3+16777216>$limit)throw new \RuntimeException('Mémoire PHP insuffisante pour convertir cette image ; original conservé.'); }
   $im=@imagecreatefromstring($bytes);if(!$im)throw new \InvalidArgumentException('Image non prise en charge.');$flat=imagecreatetruecolor(imagesx($im),imagesy($im));imagefill($flat,0,0,imagecolorallocate($flat,255,255,255));imagecopy($flat,$im,0,0,0,0,imagesx($im),imagesy($im));ob_start();imagejpeg($flat,null,92);$jpeg=ob_get_clean();imagedestroy($flat);imagedestroy($im);return self::jpeg($jpeg);
  }
  $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['doc','docx','odt','rtf','txt','xls','xlsx','ods','ppt','pptx','odp'],true))throw new \RuntimeException('Format conservé en original, conversion PDF non disponible.');
  if(strlen($bytes)>25*1024*1024)throw new \InvalidArgumentException('Document limité à 25 Mo.');
  if(!is_executable($office))throw new \RuntimeException('LibreOffice requis sur le serveur pour convertir ce document.');
  if(!function_exists('proc_open'))throw new \RuntimeException('Exécution de processus PHP désactivée ; original conservé.');
  file_put_contents($directory.'/source.'.$ext,$bytes);$process=new Process([$office,'-env:UserInstallation=file://'.implode('/',array_map('rawurlencode',explode('/',$directory.'/profile'))),'--headless','--convert-to','pdf','--outdir',$directory,$directory.'/source.'.$ext]);$process->setTimeout(90);$process->run();
  if(!$process->isSuccessful()||!is_file($directory.'/source.pdf'))throw new \RuntimeException('Conversion PDF échouée ; le fichier original est conservé.');if(filesize($directory.'/source.pdf')>26214400)throw new \RuntimeException('PDF converti supérieur à 25 Mo.');$pdf=file_get_contents($directory.'/source.pdf');if(!str_starts_with($pdf,'%PDF-'))throw new \RuntimeException('Le convertisseur n’a pas produit un PDF valide.');return $pdf;
 }
}
