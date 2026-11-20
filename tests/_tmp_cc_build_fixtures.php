<?php
/**
 * Builds the Content Calendar import fixtures into tests/_tmp_cc_fixtures/ (never uploaded).
 * Uses PhpSpreadsheet from APPPATH (same copy as the Projects Excel import).
 *
 * Usage: php tests/_tmp_cc_build_fixtures.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('APPPATH', dirname(__DIR__) . '/app/');
require_once APPPATH . 'ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\IOFactory;

$dir = __DIR__ . '/_tmp_cc_fixtures/';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

function put($name, $bytes) {
    global $dir;
    file_put_contents($dir . $name, $bytes);
    echo "  $name (" . strlen($bytes) . " bytes)\n";
}

echo "Building fixtures in $dir\n";

/* 1) UTF-8 with BOM, Kannada text ------------------------------------------------ */
$csv = "\xEF\xBB\xBF" . "Date,Content Type,Topic,Status,Caption\n"
        . "2026-11-02,Reel,ದೀಪಾವಳಿ ಶುಭಾಶಯ,Posted,\"ಹಬ್ಬದ ಶುಭಾಶಯಗಳು, ಎಲ್ಲರಿಗೂ\"\n"
        . "2026-11-03,Story,ನಮ್ಮ ಸೇವೆಗಳು,Scheduled,ಸೇವೆಗಳ ವಿವರ\n"
        . "2026-11-05,Design,Offer poster,Planned,\"Line one\nLine two ಕನ್ನಡ\"\n"
        . "2026-11-05,Carousel,ಕನ್ನಡ ಸಲಹೆಗಳು,In design,Tips 😀\n";
put('utf8_bom_kannada.csv', $csv);

/* 2) Semicolon, Windows-1252, dd/mm/yyyy -------------------------------------------- */
$utf8 = "Date;Type;Title;Status;Notes\n"
        . "02/11/2026;Reel;Café opening teaser;Posted;Prix 5€ – “soft launch”\n"
        . "03/11/2026;Story;Menu du jour;Scheduled;Crème brûlée\n"
        . "13/11/2026;Design;Diwali offer;Planned;d/m check: 13 can only be a day\n"
        . "05/11/2026;Carousel;Weekend specials;In design;\n";
put('semicolon_win1252.csv', mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'));

/* 3) Complex xlsx --------------------------------------------------------------------- */
function complex_workbook($with_dates_as_real_cells = true) {
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Acme Foods');

    $sheet->setCellValue('A1', 'Client Name');
    $sheet->setCellValue('B1', 'Acme Foods');
    $sheet->setCellValue('A2', 'Total Design');
    $sheet->setCellValue('B2', 'Total Reels');
    $sheet->setCellValue('C2', 'Stories Daily 2*31=62');
    $sheet->setCellValue('A4', 'NOVEMBER 2026 MONTH CONTENT CALENDAR');

    $header = array('Publishing Date', 'Content Type', 'Topic', 'Goal', 'Post Update', 'Caption', 'Link', '', 'Empty Col A', 'Empty Col B');
    // column H has values but no header; I and J are empty trailing columns (header only)
    foreach ($header as $i => $text) {
        if ($text !== '') {
            $sheet->setCellValueByColumnAndRow($i + 1, 5, $text);
        }
    }

    $long = 'This caption is intentionally long so that the column is detected as long text, with more than eighty characters in it. ';
    $rows = array(
        // row, date, type, topic, goal, status, caption, link, nohead
        array(6, '2026-11-02', 'Reel', 'Diwali teaser', 'Awareness', 'POSTED', $long . 'One', 'https://example.com/a', 'A1'),
        array(7, '', 'Story', 'Behind the scenes', 'Awareness', 'scheduled', $long . 'Two', 'https://example.com/b', 'B2'),
        array(8, '3rd Nov', 'Design', 'Offer poster', 'Sales', 'Upload Done', $long . "Three\nsecond line", 'https://example.com/c', 'C3'),
        array(9, '05/11/2026', 'reel', 'Festival reel', 'Sales', 'NOT GET', $long . 'Four', 'https://example.com/d', 'D4'),
        array(10, '', 'Carousel', 'Tips carousel', 'Awareness', 'posted', $long . 'Five', 'www.example.com', 'E5'),
        array(11, 'TBD', 'Reel', 'Unscheduled idea', 'Sales', 'Planned', $long . 'Six', 'https://example.com/f', 'F6'),
    );

    foreach ($rows as $r) {
        list($n, $date, $type, $topic, $goal, $status, $caption, $link, $nohead) = $r;
        if ($date === '2026-11-02' && $with_dates_as_real_cells) {
            $sheet->setCellValue("A$n", Date::PHPToExcel(new DateTime('2026-11-02 00:00:00', new DateTimeZone('UTC'))));
            $sheet->getStyle("A$n")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        } else if ($date !== '') {
            $sheet->setCellValueExplicit("A$n", $date, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $sheet->setCellValue("B$n", $type);
        $sheet->setCellValue("C$n", $topic);
        $sheet->setCellValue("D$n", $goal);
        $sheet->setCellValue("E$n", $status);
        $sheet->setCellValue("F$n", $caption);
        $sheet->setCellValue("G$n", $link);
        $sheet->setCellValue("H$n", $nohead);
    }

    $sheet->setCellValue('A12', 'DECEMBER 2026 MONTH CONTENT CALENDAR');
    foreach ($header as $i => $text) {
        if ($text !== '') {
            $sheet->setCellValueByColumnAndRow($i + 1, 13, $text);
        }
    }

    $rows2 = array(
        array(14, '2 Dec', 'Reel', 'December kickoff', 'Sales', 'scheduled', $long . 'Seven', 'https://example.com/g', 'G7'),
        array(15, '', 'Static Post', 'Year end post', 'Awareness', 'In Review', $long . 'Eight', 'https://example.com/h', 'H8'),
        array(16, '7/12/2026', 'Story', 'Story Dec 7', 'Awareness', 'POSTED', $long . 'Nine', 'https://example.com/i', 'I9'),
    );
    foreach ($rows2 as $r) {
        list($n, $date, $type, $topic, $goal, $status, $caption, $link, $nohead) = $r;
        if ($date !== '') {
            $sheet->setCellValueExplicit("A$n", $date, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $sheet->setCellValue("B$n", $type);
        $sheet->setCellValue("C$n", $topic);
        $sheet->setCellValue("D$n", $goal);
        $sheet->setCellValue("E$n", $status);
        $sheet->setCellValue("F$n", $caption);
        $sheet->setCellValue("G$n", $link);
        $sheet->setCellValue("H$n", $nohead);
    }
    $sheet->setCellValue('A17', 'Note: check these dates with the client');

    // a formula cell: the calculated value ("Story Dec 7") must be read, never the formula text
    $sheet->setCellValue('C16', '="Story Dec "&7');
    return $book;
}

function save_book($book, $name, $writer) {
    global $dir;
    $w = IOFactory::createWriter($book, $writer);
    $w->save($dir . $name);
    echo "  $name (" . filesize($dir . $name) . " bytes)\n";
}

$complex = complex_workbook(true);
save_book($complex, 'complex.xlsx', 'Xlsx');

// same content as .ods and .xls
save_book(complex_workbook(true), 'complex.ods', 'Ods');
save_book(complex_workbook(true), 'complex.xls', 'Xls');

/* 4) Multi-sheet workbook ------------------------------------------------------------------ */
$book = new Spreadsheet();
$s1 = $book->getActiveSheet();
$s1->setTitle('ZZ CC Acme Foods');
$s2 = $book->createSheet();
$s2->setTitle('ZZ CC Bright-Dental!');
$s3 = $book->createSheet();
$s3->setTitle('ZZ CC NewCo Salon');
$s4 = $book->createSheet();
$s4->setTitle('Special Days');

function simple_sheet($sheet, $prefix, $count, $with_goal) {
    $head = array('Date', 'Content Type', 'Topic', 'Status');
    if ($with_goal) {
        $head[] = 'Goal';
    }
    foreach ($head as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    $types = array('Reel', 'Story', 'Design', 'Carousel');
    for ($i = 0; $i < $count; $i++) {
        $r = $i + 2;
        $sheet->setCellValue("A$r", sprintf('%02d/11/2026', $i + 1));
        $sheet->setCellValue("B$r", $types[$i % 4]);
        $sheet->setCellValue("C$r", "$prefix post " . ($i + 1));
        $sheet->setCellValue("D$r", $i % 2 ? 'Posted' : 'Planned');
        if ($with_goal) {
            $sheet->setCellValue("E$r", $i % 2 ? 'Awareness' : 'Sales');
        }
    }
}
simple_sheet($s1, 'Acme', 5, true);
simple_sheet($s2, 'Bright', 4, false);
simple_sheet($s3, 'NewCo', 3, true);
$s4->setCellValue('A1', 'Date');
$s4->setCellValue('B1', 'Occasion');
$s4->setCellValue('A2', '12/11/2026');
$s4->setCellValue('B2', 'Diwali');
$s4->setCellValue('A3', '14/11/2026');
$s4->setCellValue('B3', 'Childrens Day');
save_book($book, 'multi_sheet.xlsx', 'Xlsx');

/* 6) Bad inputs ------------------------------------------------------------------------------ */
put('wrong_extension.pdf', "Date,Topic,Status\n2026-11-01,Hello,Posted\n");
put('empty.csv', '');
put('binary_garbage.csv', random_bytes(3000) . "\0\0\0\0");
put('not_a_spreadsheet.xlsx', "<?php echo 'x'; ?>\nthis is not a workbook\n");
put('no_dates.csv', "Date,Content Type,Topic,Status\n,Reel,No date at all,Posted\n,Story,Still no date,Posted\n");
put('single_column.csv', "Topic\nHello\nWorld\n");
put('no_date_column.csv', "Topic,Status,Notes\nHello,Posted,x\nWorld,Planned,y\n");

// 6 MB csv (over the 5 MB limit)
$big = "Date,Content Type,Topic,Status,Caption\n";
$line = "2026-11-01,Reel,Big file row,Posted," . str_repeat('x', 400) . "\n";
while (strlen($big) < 6 * 1024 * 1024) {
    $big .= $line;
}
put('over_5mb.csv', $big);

// 5001 data rows (over the row limit) and exactly 5000 rows (allowed)
foreach (array(5001 => 'rows_5001.csv', 5000 => 'rows_5000.csv') as $count => $file) {
    $out = "Date,Content Type,Topic,Status,Goal\n";
    $types = array('Reel', 'Story', 'Design', 'Carousel');
    $goals = array('Awareness', 'Sales', 'Leads');
    for ($i = 0; $i < $count; $i++) {
        $day = ($i % 28) + 1;
        $month = (int) (($i / 28) % 12) + 1;
        $out .= sprintf("2026-%02d-%02d,%s,Row %d,%s,%s\n", $month, $day, $types[$i % 4], $i + 1, $i % 2 ? 'Posted' : 'Planned', $goals[$i % 3]);
    }
    put($file, $out);
}

// rows with no date mixed with good rows
put('some_rows_no_date.csv', "Date,Content Type,Topic,Status\n2026-11-01,Reel,Good one,Posted\nnot a date,Story,Bad date,Posted\n2026-13-45,Story,Impossible date,Posted\n,Design,Blank date carries forward,Planned\n");

echo "Done.\n";
