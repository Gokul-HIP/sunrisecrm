<?php
// Temporary deep-dive helper - safe to delete.
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../app/ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory; use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
class F implements IReadFilter{ function readCell($c,$r,$w=''){return $r<=6000 && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($c)<=40;}}
$f=__DIR__.'/_tmp_fixtures/Content_Calender_2026.xlsx';
function load($f,$n){$r=IOFactory::createReader('Xlsx');$r->setReadDataOnly(true);$r->setLoadSheetsOnly([$n]);$r->setReadFilter(new F);return $r->load($f)->getSheet(0);}
function t($v,$n=60){$s=str_replace(["\r","\n"],['','\n'],(string)$v);return mb_strlen($s)>$n?mb_substr($s,0,$n).'…':$s;}
function d($v){return is_numeric($v)&&$v>40000&&$v<50000?$v.'('.\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d D').')':t($v);}
$ws=load($f,'Organic Mohan ');
echo "== Organic Mohan rows 1-71 ==\n";
for($r=1;$r<=71;$r++){echo "R$r A=".d($ws->getCell([1,$r])->getValue())." | B=".d($ws->getCell([2,$r])->getValue())." | C=".t($ws->getCell([3,$r])->getValue(),30)." | F=".t($ws->getCell([6,$r])->getValue(),30)." | L=".t($ws->getCell([12,$r])->getValue(),20)." | Hlen=".mb_strlen((string)$ws->getCell([8,$r])->getValue())."\n";}
$ws=load($f,'naati fresh ');
echo "== naati A2 full ==\n".json_encode($ws->getCell('A2')->getValue(),JSON_UNESCAPED_UNICODE)."\n";
for($r=3;$r<=14;$r++){echo "R$r A=".d($ws->getCell([1,$r])->getValue())." B=".t($ws->getCell([2,$r])->getValue(),15)." C=".t($ws->getCell([3,$r])->getValue(),30)." E=".t($ws->getCell([5,$r])->getValue(),20)." F=".t($ws->getCell([6,$r])->getValue(),30)."\n";}
echo "R4 A raw: ".var_export($ws->getCell('A4')->getValue(),true)."\n";
$ws=load($f,'Kakubal Design');
echo "== Kakubal Design rows 36-67 col A-D ==\n";
for($r=36;$r<=67;$r++){echo "R$r A=".t($ws->getCell([1,$r])->getValue(),30)." | B=".t($ws->getCell([2,$r])->getValue(),30)." | C=".t($ws->getCell([3,$r])->getValue(),25)." | D=".t($ws->getCell([4,$r])->getValue(),20)."\n";}
$ws=load($f,'November  special days');
echo "== special days ==\n";
for($c=1;$c<=26;$c++){ $v=$ws->getCell([$c,1])->getValue(); if($v!==null&&trim($v)!=='')echo "R1 C$c=".t($v)."\n";}
echo "numeric serial cells:\n";
for($r=1;$r<=1027;$r++)for($c=1;$c<=26;$c++){$v=$ws->getCell([$c,$r])->getValue();if(is_numeric($v)&&$v>40000&&$v<50000)echo "  R$r C$c=".d($v)."\n";}
echo "non-empty cols in sheet: ";
$cols=[];for($r=1;$r<=60;$r++)for($c=1;$c<=26;$c++){$v=$ws->getCell([$c,$r])->getValue();if($v!==null&&trim((string)$v)!=='')$cols[$c]=1;}
echo implode(',',array_keys($cols))."\n";
$ws=load($f,'SPN');
echo "== SPN ==\n";
for($r=1;$r<=327;$r++){$a=(string)$ws->getCell([1,$r])->getValue();$b=(string)$ws->getCell([2,$r])->getValue();if(preg_match('/MONTH|CALENDAR|Week|Client/i',$a.$b))echo "R$r A=".t($a,70)." | B=".t($b,50)." | C=".t($ws->getCell([3,$r])->getValue(),30)." | D=".t($ws->getCell([4,$r])->getValue(),20)." | E=".t($ws->getCell([5,$r])->getValue(),20)." | F=".t($ws->getCell([6,$r])->getValue(),20)."\n";}
echo "SPN A2 full: ".json_encode($ws->getCell('A2')->getValue(),JSON_UNESCAPED_UNICODE)."\n";
echo "Peak ".round(memory_get_peak_usage(true)/1048576,1)."MB\n";
