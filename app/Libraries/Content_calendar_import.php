<?php

namespace App\Libraries;

/**
 * Content Calendar import (phase 2): read a CSV / spreadsheet, detect header and columns,
 * map them to a client calendar, and apply the rows.
 *
 * One planning function (build_plan) is used by the dry run (preview) and by the real import (run_sheet),
 * so the counts shown before importing are exactly the ones that are applied.
 *
 * A "sheet" is array("name" => string, "rows" => array(array(row_number, array(cells...)), ...)).
 * Only non-empty rows are kept, row numbers are the real row numbers of the file.
 */
class Content_calendar_import {

    //limits: easy to change
    const MAX_FILE_BYTES = 5242880; //5 MB
    const MAX_ROWS = 5000; //data rows per sheet
    const MAX_COLUMNS = 100;
    const MAX_SHEETS = 40;
    const MAX_NEW_OPTIONS = 100; //a new select / multi column with more distinct values becomes a text column
    const TOKEN_TTL = 1800; //temp files live 30 minutes
    const HEADER_SCAN_ROWS = 30;
    const MAX_UNPACKED_BYTES = 157286400; //150 MB, zip bomb guard
    const MAX_WORKBOOK_ROWS = 100000; //rows (even empty, formatted) read from one sheet
    const PREVIEW_ROWS = 10;
    const SKIPPED_SHOWN = 50;
    const BATCH_SIZE = 200;

    private $columns_model;
    private $items_model;
    private $months_model;
    private $calendars_model;
    private $logs_model;

    function __construct() {
        helper('content_calendar');
        $this->columns_model = model("App\Models\Cc_columns_model");
        $this->items_model = model("App\Models\Cc_items_model");
        $this->months_model = model("App\Models\Cc_months_model");
        $this->calendars_model = model("App\Models\Cc_calendars_model");
        $this->logs_model = model("App\Models\Cc_import_logs_model");
    }

    public static function allowed_extensions() {
        return array("csv", "tsv", "txt", "xlsx", "xls", "ods");
    }

    public static function limits() {
        return array("max_bytes" => self::MAX_FILE_BYTES, "max_rows" => self::MAX_ROWS, "extensions" => self::allowed_extensions());
    }

    /* ================================================================== */
    /* temp files                                                          */
    /* ================================================================== */

    public function dir() {
        $dir = WRITEPATH . "files/content_calendar/imports/";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_dir($dir)) {
            if (!is_file($dir . "index.html")) {
                @file_put_contents($dir . "index.html", "<html><body>Directory access is forbidden.</body></html>");
            }
            if (!is_file($dir . ".htaccess")) {
                @file_put_contents($dir . ".htaccess", "Require all denied\nDeny from all\n");
            }
        }

        return $dir;
    }

    public function new_token() {
        return bin2hex(random_bytes(16));
    }

    public function valid_token($token) {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    //delete temp files older than the time to live
    public function sweep_expired() {
        $dir = $this->dir();
        $files = glob($dir . "*");
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $name = basename($file);
            if ($name === "index.html" || !is_file($file)) {
                continue;
            }

            if (@filemtime($file) < time() - self::TOKEN_TTL) {
                @unlink($file);
            }
        }
    }

    public function store_parsed($user_id, $calendar_id, $file_name, $detected_type, $sheets) {
        $token = $this->new_token();
        $payload = array(
            "user_id" => (int) $user_id,
            "calendar_id" => (int) $calendar_id,
            "file_name" => $file_name,
            "detected_type" => $detected_type,
            "created" => time(),
            "sheets" => $sheets
        );

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($this->dir() . $token . ".json", $json) === false) {
            return "";
        }

        return $token;
    }

    /**
     * @return array|null the stored payload, or null when the token is unknown, expired or belongs to another user
     */
    public function load_parsed($token, $user_id) {
        if (!$this->valid_token($token)) {
            return null;
        }

        $file = $this->dir() . $token . ".json";
        if (!is_file($file)) {
            return null;
        }

        if (@filemtime($file) < time() - self::TOKEN_TTL) {
            @unlink($file);
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || (int) $payload["user_id"] !== (int) $user_id) {
            return null;
        }

        return $payload;
    }

    public function delete_parsed($token) {
        if ($this->valid_token($token)) {
            @unlink($this->dir() . $token . ".json");
        }
    }

    public function store_skipped($user_id, $payload) {
        $token = $this->new_token();
        $payload["user_id"] = (int) $user_id;
        if (file_put_contents($this->dir() . $token . ".skip", json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            return "";
        }

        return $token;
    }

    public function load_skipped($token, $user_id) {
        if (!$this->valid_token($token)) {
            return null;
        }

        $file = $this->dir() . $token . ".skip";
        if (!is_file($file) || @filemtime($file) < time() - self::TOKEN_TTL) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || (int) $payload["user_id"] !== (int) $user_id) {
            return null;
        }

        return $payload;
    }

    /* ================================================================== */
    /* reading files                                                       */
    /* ================================================================== */

    /**
     * Find the real type from the content, never from the extension.
     *
     * @return string csv | xlsx | xls | ods
     * @throws \RuntimeException with a user message
     */
    public function detect_type($path) {
        $size = @filesize($path);
        if (!$size) {
            throw new \RuntimeException(cc_lang("cc_imp_err_empty"));
        }

        if ($size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(sprintf(cc_lang("cc_imp_err_size"), round(self::MAX_FILE_BYTES / 1048576)));
        }

        $fh = fopen($path, "rb");
        $head = $fh ? fread($fh, 8192) : "";
        if ($fh) {
            fclose($fh);
        }

        if (substr($head, 0, 4) === "PK\x03\x04") {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
            }

            $unpacked = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $unpacked += $stat ? (int) $stat["size"] : 0;
            }

            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                $zip->close();
                throw new \RuntimeException(cc_lang("cc_imp_err_unpacked"));
            }

            $type = "";
            if ($zip->locateName("xl/workbook.xml") !== false) {
                $type = "xlsx";
            } else if ($zip->locateName("mimetype") !== false) {
                $mime = trim((string) $zip->getFromName("mimetype"));
                if ($mime === "application/vnd.oasis.opendocument.spreadsheet") {
                    $type = "ods";
                }
            }

            $zip->close();
            if ($type === "") {
                throw new \RuntimeException(cc_lang("cc_imp_err_binary"));
            }

            return $type;
        }

        if (substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return "xls";
        }

        if (preg_match('/^(%PDF|\x89PNG|GIF8|\xFF\xD8\xFF|MZ|Rar!|\x1F\x8B|7z\xBC\xAF|<\?php|<\?xml|<!DOCTYPE|<html)/i', $head)) {
            throw new \RuntimeException(cc_lang("cc_imp_err_binary"));
        }

        if (substr($head, 0, 2) === "\xFF\xFE" || substr($head, 0, 2) === "\xFE\xFF") {
            return "csv";
        }

        $nul = substr_count($head, "\0");
        if ($nul > 0) {
            //UTF-16 without a BOM has a NUL in every second byte for latin text; anything else is binary
            if ($nul < strlen($head) * 0.3) {
                throw new \RuntimeException(cc_lang("cc_imp_err_binary"));
            }

            return "csv";
        }

        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head);
        if ($control > max(3, strlen($head) * 0.02)) {
            throw new \RuntimeException(cc_lang("cc_imp_err_binary"));
        }

        return "csv";
    }

    /**
     * Read a whole file into sheets.
     *
     * @return array("type" => detected type, "sheets" => array of sheets, "notes" => array of strings)
     * @throws \RuntimeException with a user message
     */
    public function read_file($path, $chosen_type = "auto") {
        $type = $this->detect_type($path);
        $notes = array();

        $chosen = strtolower((string) $chosen_type);
        if ($chosen !== "" && $chosen !== "auto") {
            $matches = ($chosen === "csv" && $type === "csv") || ($chosen === "excel" && ($type === "xlsx" || $type === "xls")) || ($chosen === "ods" && $type === "ods");
            if (!$matches) {
                $notes[] = sprintf(cc_lang("cc_imp_type_mismatch"), $this->type_label($chosen), $this->type_label($type), $this->type_label($type));
            }
        }

        if ($type === "csv") {
            $sheets = array($this->read_csv($path));
        } else {
            $sheets = $this->read_workbook($path, $type);
        }

        if (!count($sheets)) {
            throw new \RuntimeException(cc_lang("cc_imp_err_no_sheets"));
        }

        return array("type" => $type, "sheets" => $sheets, "notes" => $notes);
    }

    public function type_label($type) {
        $labels = array("csv" => "CSV", "excel" => "Excel", "xlsx" => "Excel (.xlsx)", "xls" => "Excel (.xls)", "ods" => "OpenDocument (.ods)");
        return isset($labels[$type]) ? $labels[$type] : $type;
    }

    /* ---------- CSV ---------- */

    /**
     * Decode the bytes of a CSV to UTF-8 text: UTF-8 (with or without BOM), UTF-16 (BOM or not), Windows-1252.
     */
    public function decode_text($bytes, &$encoding = "") {
        if (substr($bytes, 0, 3) === "\xEF\xBB\xBF") {
            $encoding = "UTF-8 (BOM)";
            return substr($bytes, 3);
        }

        if (substr($bytes, 0, 2) === "\xFF\xFE") {
            $encoding = "UTF-16LE";
            return mb_convert_encoding(substr($bytes, 2), "UTF-8", "UTF-16LE");
        }

        if (substr($bytes, 0, 2) === "\xFE\xFF") {
            $encoding = "UTF-16BE";
            return mb_convert_encoding(substr($bytes, 2), "UTF-8", "UTF-16BE");
        }

        $sample = substr($bytes, 0, 400);
        if (strpos($sample, "\0") !== false) {
            $odd = 0;
            $even = 0;
            for ($i = 0, $n = strlen($sample); $i < $n; $i++) {
                if ($sample[$i] === "\0") {
                    if ($i % 2) {
                        $odd++;
                    } else {
                        $even++;
                    }
                }
            }

            $encoding = $odd >= $even ? "UTF-16LE" : "UTF-16BE";
            return mb_convert_encoding($bytes, "UTF-8", $encoding);
        }

        if (mb_check_encoding($bytes, "UTF-8")) {
            $encoding = "UTF-8";
            return $bytes;
        }

        $encoding = "Windows-1252";
        return mb_convert_encoding($bytes, "UTF-8", "Windows-1252");
    }

    /**
     * Most likely delimiter: the one that gives the same field count on most of the first lines.
     */
    public function detect_delimiter($text) {
        $lines = preg_split('/\r\n|\r|\n/', $text, 60);
        $best = ",";
        $best_score = -1;
        foreach (array(",", ";", "\t", "|") as $delimiter) {
            $counts = array();
            $used = 0;
            foreach ($lines as $line) {
                if (trim($line) === "") {
                    continue;
                }

                $used++;
                if ($used > 30) {
                    break;
                }

                $in_quotes = false;
                $count = 0;
                $len = strlen($line);
                for ($i = 0; $i < $len; $i++) {
                    $ch = $line[$i];
                    if ($ch === '"') {
                        $in_quotes = !$in_quotes;
                    } else if ($ch === $delimiter && !$in_quotes) {
                        $count++;
                    }
                }

                $counts[] = $count;
            }

            if (!count($counts)) {
                continue;
            }

            $freq = array_count_values($counts);
            arsort($freq);
            $mode_count = (int) array_key_first($freq);
            if ($mode_count < 1) {
                continue;
            }

            $score = $freq[$mode_count] * 1000 + $mode_count;
            if ($score > $best_score) {
                $best_score = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    public function read_csv($path) {
        $bytes = (string) file_get_contents($path);
        $encoding = "";
        $text = $this->decode_text($bytes, $encoding);
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $delimiter = $this->detect_delimiter($text);

        $fh = fopen("php://temp", "r+");
        fwrite($fh, $text);
        rewind($fh);

        $rows = array();
        $number = 0;
        $non_empty = 0;
        while (($record = fgetcsv($fh, 0, $delimiter, '"', "")) !== false) {
            $number++;
            if ($record === array(null)) {
                continue;
            }

            $cells = array();
            $any = false;
            foreach ($record as $i => $cell) {
                if ($i >= self::MAX_COLUMNS) {
                    break;
                }

                $cell = $this->clean_cell((string) $cell);
                $cells[] = $cell;
                if ($cell !== "") {
                    $any = true;
                }
            }

            if (!$any) {
                continue;
            }

            $non_empty++;
            if ($non_empty > self::MAX_ROWS + 200) {
                fclose($fh);
                throw new \RuntimeException(sprintf(cc_lang("cc_imp_err_rows"), number_format(self::MAX_ROWS), ">" . number_format(self::MAX_ROWS)));
            }

            $rows[] = array($number, $this->trim_cells($cells));
        }

        fclose($fh);

        return array("name" => "CSV", "rows" => $rows, "encoding" => $encoding, "delimiter" => $delimiter === "\t" ? "tab" : $delimiter);
    }

    private function clean_cell($value) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        return trim((string) $value);
    }

    private function trim_cells($cells) {
        while (count($cells) && end($cells) === "") {
            array_pop($cells);
        }

        return array_values($cells);
    }

    /* ---------- Excel / ODS ---------- */

    private function load_spreadsheet_library() {
        require_once(APPPATH . "ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php");
    }

    /**
     * The bundled PhpSpreadsheet is old and raises PHP warnings (for example from simplexml on optional parts of a workbook)
     * that the framework would turn into exceptions. Warnings and notices that come from the library itself are ignored
     * while a workbook is read; real library errors still arrive as exceptions.
     */
    public function read_workbook($path, $type) {
        $previous = null;
        $previous = set_error_handler(function ($severity, $message, $file, $line) use (&$previous) {
            if (strpos((string) $file, "PhpSpreadsheet") !== false && !($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR))) {
                return true;
            }

            return $previous ? call_user_func($previous, $severity, $message, $file, $line) : false;
        });

        try {
            return $this->read_workbook_inner($path, $type);
        } finally {
            restore_error_handler();
        }
    }

    private function read_workbook_inner($path, $type) {
        $this->load_spreadsheet_library();

        $reader_name = array("xlsx" => "Xlsx", "xls" => "Xls", "ods" => "Ods");
        $temp_copy = null;
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($reader_name[$type]);
            // ZipArchive on Windows cannot open WSL/UNC paths (\\wsl.localhost\...).
            // Copy to a native temp file when the original path is unreadable.
            if (!$reader->canRead($path)) {
                $temp_copy = $this->copy_workbook_to_native_temp($path, $type);
                if ($temp_copy && $reader->canRead($temp_copy)) {
                    $path = $temp_copy;
                } else {
                    throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
                }
            }

            $reader->setReadDataOnly(false); //styles are needed to know which numbers are dates (colors are ignored)
            $reader->setReadEmptyCells(false);

            $infos = $reader->listWorksheetInfo($path);
            $too_big = array();
            foreach ($infos as $info) {
                if ((int) $info["totalRows"] > self::MAX_WORKBOOK_ROWS) {
                    $too_big[$info["worksheetName"]] = (int) $info["totalRows"];
                }
            }

            $spreadsheet = $reader->load($path);
        } catch (\RuntimeException $e) {
            if ($temp_copy && is_file($temp_copy)) {
                @unlink($temp_copy);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($temp_copy && is_file($temp_copy)) {
                @unlink($temp_copy);
            }
            log_message('error', '[ERROR] cc import read_workbook: {message}', array('message' => $e->getMessage()));
            throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
        }

        if ($temp_copy && is_file($temp_copy)) {
            @unlink($temp_copy);
        }

        $sheets = array();
        $index = 0;
        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            $index++;
            if ($index > self::MAX_SHEETS) {
                break;
            }

            $name = (string) $worksheet->getTitle();
            if (isset($too_big[$name])) {
                $sheets[] = array("name" => $name, "rows" => array(), "error" => sprintf(cc_lang("cc_imp_err_rows"), number_format(self::MAX_ROWS), number_format($too_big[$name])));
                continue;
            }

            $sheets[] = $this->read_worksheet($worksheet);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $sheets;
    }

    private function read_worksheet($worksheet) {
        $rows = array();
        $non_empty = 0;
        $error = "";

        foreach ($worksheet->getRowIterator() as $row) {
            $cell_iterator = $row->getCellIterator();
            $cell_iterator->setIterateOnlyExistingCells(true);

            $cells = array();
            $max = -1;
            foreach ($cell_iterator as $cell) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                if ($col >= self::MAX_COLUMNS) {
                    continue;
                }

                $value = $this->cell_to_string($cell);
                if ($value === "") {
                    continue;
                }

                $cells[$col] = $value;
                if ($col > $max) {
                    $max = $col;
                }
            }

            if ($max < 0) {
                continue;
            }

            $list = array();
            for ($i = 0; $i <= $max; $i++) {
                $list[] = isset($cells[$i]) ? $cells[$i] : "";
            }

            $non_empty++;
            if ($non_empty > self::MAX_ROWS + 200) {
                $error = sprintf(cc_lang("cc_imp_err_rows"), number_format(self::MAX_ROWS), ">" . number_format(self::MAX_ROWS));
                $rows = array();
                break;
            }

            $rows[] = array((int) $row->getRowIndex(), $list);
        }

        $sheet = array("name" => (string) $worksheet->getTitle(), "rows" => $rows);
        if ($error !== "") {
            $sheet["error"] = $error;
        }

        return $sheet;
    }

    /**
     * Calculated value of a cell as text. Formulas give their result, dates give Y-m-d, colors are ignored.
     */
    private function cell_to_string($cell) {
        try {
            $value = $cell->getCalculatedValue();
        } catch (\Throwable $e) {
            try {
                $value = $cell->getOldCalculatedValue();
            } catch (\Throwable $e2) {
                $value = "";
            }
        }

        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }

        if ($value === null) {
            $value = "";
        }

        if (is_bool($value)) {
            return $value ? "TRUE" : "FALSE";
        }

        if (is_int($value) || is_float($value)) {
            $is_date = false;
            try {
                $is_date = \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTimeFormat($cell->getStyle()->getNumberFormat());
            } catch (\Throwable $e) {
                $is_date = false;
            }

            if ($is_date && $value >= 1 && $value < 2958466) {
                try {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value, "UTC");
                    return $date->format("Y-m-d");
                } catch (\Throwable $e) {
                    //fall through to the number
                }
            }

            return $this->number_to_string($value);
        }

        $value = $this->clean_cell((string) $value);
        if (preg_match('/^#(REF!|N\/A|VALUE!|DIV\/0!|NAME\?|NUM!|NULL!)$/', $value)) {
            return "";
        }

        if ($value === "") {
            try {
                if ($cell->hasHyperlink()) {
                    $url = trim((string) $cell->getHyperlink()->getUrl());
                    if (preg_match('#^https?://#i', $url)) {
                        return $url;
                    }
                }
            } catch (\Throwable $e) {
                //no hyperlink
            }
        }

        return $value;
    }

    private function number_to_string($value) {
        if (is_int($value)) {
            return (string) $value;
        }

        if (floor($value) == $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf("%.10F", $value), "0"), ".");
    }

    /* ================================================================== */
    /* small text helpers                                                  */
    /* ================================================================== */

    public function lower($text) {
        return mb_strtolower((string) $text, "UTF-8");
    }

    public function len($text) {
        return mb_strlen((string) $text, "UTF-8");
    }

    public function cut($text, $max) {
        return $this->len($text) > $max ? mb_substr($text, 0, $max, "UTF-8") : (string) $text;
    }

    //lowercase letters and digits only (any script), used to compare names
    public function norm($text) {
        return preg_replace('/[^\p{L}\p{N}]+/u', "", $this->lower($text));
    }

    public function clean_header($text) {
        $text = preg_replace('/\s+/u', " ", trim((string) $text));
        return $this->cut($text, 60);
    }

    public function col_letter($index) {
        $index = (int) $index;
        $letters = "";
        $n = $index + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $n = (int) (($n - $mod - 1) / 26);
        }

        return $letters;
    }

    private function month_number($word) {
        $word = $this->lower(rtrim($word, "."));
        if (strlen($word) < 3) {
            return 0;
        }

        $names = array("january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december");
        foreach ($names as $i => $name) {
            if (strpos($name, $word) === 0) {
                return $i + 1;
            }
        }

        return $word === "sept" ? 9 : 0;
    }

    /**
     * Month / year found in a month title such as "MAY MONTH CONTENT CALENDAR" or "November 2026".
     *
     * @return array(month or 0, year or 0)
     */
    public function month_context($text) {
        $text = trim((string) $text);
        if ($text === "" || $this->len($text) > 60) {
            return array(0, 0);
        }

        $month = 0;
        if (preg_match_all('/[A-Za-z]{3,9}/', $text, $words)) {
            foreach ($words[0] as $word) {
                $number = $this->month_number($word);
                if ($number) {
                    $month = $number;
                    break;
                }
            }
        }

        $year = 0;
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            $year = (int) $m[1];
        }

        return array($month, $year);
    }

    /* ================================================================== */
    /* dates                                                               */
    /* ================================================================== */

    /**
     * Day-first date parser. Returns Y-m-d or null.
     *
     * @param string $text
     * @param int $default_year year for dates that have none
     * @param int $ctx_month month of the last month title (for a bare day number like "5")
     * @param bool $allow_serial accept Excel serial numbers (45000 style)
     */
    public function parse_date($text, $default_year = 0, $ctx_month = 0, $allow_serial = false) {
        $s = preg_replace('/\s+/u', " ", trim((string) $text));
        if ($s === "" || $this->len($s) > 40) {
            return null;
        }

        $s = preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/iu', "", $s);
        $s = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*([ap]\.?m\.?)?(Z|[+-]\d{2}:?\d{2})?$/iu', "", $s);
        $s = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $s);
        $s = trim($s);

        $y = 0;
        $m = 0;
        $d = 0;

        if ($allow_serial && preg_match('/^\d{5}(\.\d+)?$/', $s)) {
            $serial = (float) $s;
            if ($serial >= 20000 && $serial <= 80000) {
                $date = new \DateTime("1899-12-30", new \DateTimeZone("UTC"));
                $date->modify("+" . (int) floor($serial) . " days");
                return $this->valid_ymd((int) $date->format("Y"), (int) $date->format("n"), (int) $date->format("j"));
            }

            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $p)) {
            return $this->valid_ymd((int) $p[1], (int) $p[2], (int) $p[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4}|\d{2})$/', $s, $p)) {
            $year = (int) $p[3];
            if ($year < 100) {
                $year += 2000;
            }

            return $this->valid_ymd($year, (int) $p[2], (int) $p[1]);
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})$/', $s, $p)) {
            return $default_year ? $this->valid_ymd((int) $default_year, (int) $p[2], (int) $p[1]) : null;
        }

        if (preg_match('/^(\d{1,2})[ \-\/.]*([A-Za-z]{3,9})\.?(?:[ ,\-\/.]*(\d{4}|\d{2}))?$/u', $s, $p)) {
            $month = $this->month_number($p[2]);
            if (!$month) {
                return null;
            }

            $year = isset($p[3]) && $p[3] !== "" ? (int) $p[3] : (int) $default_year;
            if ($year > 0 && $year < 100) {
                $year += 2000;
            }

            return $year ? $this->valid_ymd($year, $month, (int) $p[1]) : null;
        }

        if (preg_match('/^([A-Za-z]{3,9})\.?[ \-]*(\d{1,2})(?:[ ,\-\/.]*(\d{4}|\d{2}))?$/u', $s, $p)) {
            $month = $this->month_number($p[1]);
            if (!$month) {
                return null;
            }

            $year = isset($p[3]) && $p[3] !== "" ? (int) $p[3] : (int) $default_year;
            if ($year > 0 && $year < 100) {
                $year += 2000;
            }

            return $year ? $this->valid_ymd($year, $month, (int) $p[2]) : null;
        }

        if ($ctx_month && $default_year && preg_match('/^\d{1,2}$/', $s)) {
            return $this->valid_ymd((int) $default_year, (int) $ctx_month, (int) $s);
        }

        return null;
    }

    private function valid_ymd($y, $m, $d) {
        if ($y < 2000 || $y > 2100 || !checkdate($m, $d, $y)) {
            return null;
        }

        return sprintf("%04d-%02d-%02d", $y, $m, $d);
    }

    /* ================================================================== */
    /* detection                                                           */
    /* ================================================================== */

    public function is_date_header($text) {
        return preg_match('/publish|(^|[^\p{L}])dates?([^\p{L}]|$)/iu', (string) $text) === 1;
    }

    /**
     * First row within the first 30 rows that looks like the header row.
     *
     * @return int row number, or 0
     */
    public function detect_header_row($rows) {
        $candidates = array();
        $fallback = 0;
        foreach ($rows as $pair) {
            if ($pair[0] > self::HEADER_SCAN_ROWS) {
                break;
            }

            $cells = $pair[1];
            $non_empty = 0;
            $short_text = 0;
            $date_like = false;
            foreach ($cells as $cell) {
                if ($cell === "") {
                    continue;
                }

                $non_empty++;
                if (!is_numeric(str_replace(",", "", $cell)) && $this->len($cell) <= 40 && strpos($cell, "\n") === false) {
                    $short_text++;
                }

                if ($this->is_date_header($cell)) {
                    $date_like = true;
                }
            }

            if ($non_empty >= 2 && !$fallback) {
                $fallback = $pair[0];
            }

            if ($non_empty >= 3 && $short_text >= $non_empty * 0.7) {
                $candidates[] = array("row" => $pair[0], "text" => $short_text, "date_like" => $date_like);
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate["date_like"]) {
                return $candidate["row"];
            }
        }

        $best = 0;
        $best_text = 0;
        foreach ($candidates as $candidate) {
            if ($candidate["text"] > $best_text) {
                $best_text = $candidate["text"];
                $best = $candidate["row"];
            }
        }

        return $best ? $best : $fallback;
    }

    private function split_tokens($value) {
        $tokens = array();
        foreach (preg_split('/[,\/;\n]+/u', (string) $value) as $token) {
            $token = trim($token);
            if ($token !== "") {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Column type from its non-empty values: date | number | link | check | multi | select | long | text.
     */
    public function detect_column_type($values) {
        $n = count($values);
        if (!$n) {
            return "text";
        }

        $year = (int) date("Y");
        $date_hits = 0;
        $number_hits = 0;
        $link_hits = 0;
        $check_hits = 0;
        $has_alpha_check = false;
        $multi_cells = 0;
        $tokens = array();
        $distinct = array();
        $total_len = 0;
        $max_len = 0;
        $has_break = false;
        $token_too_long = false;
        $token_total = 0;

        foreach ($values as $value) {
            $lower = $this->lower($value);
            if ($this->parse_date($value, $year, 0, false) !== null) {
                $date_hits++;
            }

            $plain = str_replace(array(",", " "), "", $value);
            if (is_numeric($plain)) {
                $number_hits++;
            }

            if (preg_match('#^https?://#i', $value)) {
                $link_hits++;
            }

            if (in_array($lower, array("true", "false", "yes", "no", "1", "0", "y", "n"), true)) {
                $check_hits++;
                if (!is_numeric($lower)) {
                    $has_alpha_check = true;
                }
            }

            $parts = $this->split_tokens($value);
            if (count($parts) >= 2) {
                $multi_cells++;
            }

            foreach ($parts as $part) {
                $token_total++;
                $tokens[$this->lower($part)] = true;
                if ($this->len($part) > 40) {
                    $token_too_long = true;
                }
            }

            $distinct[$lower] = true;
            $length = $this->len($value);
            $total_len += $length;
            $max_len = max($max_len, $length);
            if (strpos($value, "\n") !== false) {
                $has_break = true;
            }
        }

        if ($date_hits >= $n * 0.8) {
            return "date";
        }

        if ($check_hits === $n && $has_alpha_check) {
            return "check";
        }

        if ($number_hits >= $n * 0.9) {
            return "number";
        }

        if ($link_hits >= $n * 0.8) {
            return "link";
        }

        if ($check_hits === $n) {
            return "check";
        }

        //several short values per cell, and the same values come back again and again (low variety)
        if ($multi_cells >= $n * 0.25 && !$token_too_long && count($tokens) <= 12 && count($tokens) <= $token_total * 0.6 && !$has_break) {
            return "multi";
        }

        if (count($distinct) <= 12 && count($distinct) <= $n * 0.4 && $max_len < 40) {
            return "select";
        }

        if ($total_len / $n > 80 || $has_break) {
            return "long";
        }

        return "text";
    }

    /* ================================================================== */
    /* the plan (dry run and apply share this)                             */
    /* ================================================================== */

    public function default_config() {
        return array("header_row" => 0, "year" => (int) date("Y"), "carry_blank_date" => true, "skip_duplicates" => true, "mapping" => array());
    }

    //clean a config that came from the browser
    public function normalize_config($config) {
        $clean = $this->default_config();
        if (!is_array($config)) {
            return $clean;
        }

        if (isset($config["header_row"])) {
            $header_row = (int) $config["header_row"];
            $clean["header_row"] = ($header_row >= 1 && $header_row <= 100000) ? $header_row : 0;
        }

        if (isset($config["year"])) {
            $year = (int) $config["year"];
            if ($year >= 2000 && $year <= 2100) {
                $clean["year"] = $year;
            }
        }

        if (isset($config["carry_blank_date"])) {
            $clean["carry_blank_date"] = filter_var($config["carry_blank_date"], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($config["skip_duplicates"])) {
            $clean["skip_duplicates"] = filter_var($config["skip_duplicates"], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($config["mapping"]) && is_array($config["mapping"])) {
            foreach ($config["mapping"] as $index => $map) {
                if (!is_array($map) || !is_numeric($index) || (int) $index < 0 || (int) $index >= self::MAX_COLUMNS) {
                    continue;
                }

                $target = isset($map["target"]) ? (string) $map["target"] : "";
                $clean["mapping"][(int) $index] = array(
                    "target" => $this->cut($target, 90),
                    "type" => isset($map["type"]) ? (string) $map["type"] : "",
                    "label" => isset($map["label"]) ? $this->clean_header($map["label"]) : ""
                );
            }
        }

        return $clean;
    }

    public function virtual_core_columns() {
        $columns = array();
        $sort = 1;
        foreach ($this->columns_model->get_core_definitions() as $key => $def) {
            $columns[] = (object) array("id" => 0, "field_key" => $key, "label" => $def["label"], "input_type" => $def["input_type"], "options_list" => $def["options"], "is_core" => 1, "is_visible" => 1, "sort_order" => $sort++);
        }

        return $columns;
    }

    private function empty_plan() {
        return array(
            "ok" => true, "error" => "", "warnings" => array(),
            "header_row" => 0, "detected_header_row" => 0,
            "columns" => array(), "items" => array(), "skipped" => array(),
            "new_columns" => array(), "option_additions" => array(),
            "stats" => array("header_repeats" => 0, "notes" => 0, "locked_option_cells" => 0, "invalid_cells" => 0, "truncated_cells" => 0, "locked_columns" => array(), "data_rows" => 0),
            "date_min" => "", "date_max" => "", "months" => array(), "months_new" => array(),
            "rows_total" => 0, "options_created" => 0, "can_import" => false, "labels" => array(), "preview_rows" => array()
        );
    }

    private function plan_error($plan, $message) {
        $plan["ok"] = false;
        $plan["error"] = $message;
        $plan["can_import"] = false;
        return $plan;
    }

    private function is_repeat_header($cells, $header_norm) {
        $non_empty = 0;
        $matches = 0;
        foreach ($cells as $i => $cell) {
            if ($cell === "") {
                continue;
            }

            $non_empty++;
            if (isset($header_norm[$i]) && $header_norm[$i] !== "" && $this->norm($cell) === $header_norm[$i]) {
                $matches++;
            }
        }

        return $matches >= 2 && $matches >= $non_empty * 0.6;
    }

    /**
     * Auto mapping of the header columns.
     *
     * @param array $info per column index: header, values, detected_type
     * @param array $existing custom columns by normalized label => column
     * @param array $core_labels normalized core labels => role
     * @return array index => array(target, type, label)
     */
    private function auto_map($info, $existing, $core_labels, $year) {
        $map = array();
        $roles = array();

        $pick = function ($patterns) use ($info, &$map) {
            foreach ($patterns as $pattern) {
                foreach ($info as $i => $col) {
                    if (isset($map[$i])) {
                        continue;
                    }

                    if (preg_match($pattern, $col["header"])) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $date_index = $pick(array('/publish/iu', '/(^|[^\p{L}])dates?([^\p{L}]|$)/iu'));
        if ($date_index === null) {
            $best = 0;
            foreach ($info as $i => $col) {
                if (isset($map[$i]) || $col["detected_type"] !== "date") {
                    continue;
                }

                $hits = 0;
                foreach ($col["values"] as $value) {
                    if ($this->parse_date($value, $year, 0, false) !== null) {
                        $hits++;
                    }
                }

                if ($hits > $best) {
                    $best = $hits;
                    $date_index = $i;
                }
            }
        }

        if ($date_index !== null) {
            $map[$date_index] = array("target" => "date", "type" => "", "label" => "");
        }

        $type_index = $pick(array('/^content\s*type$/iu', '/content\s*type|(^|[^\p{L}])type([^\p{L}]|$)|format/iu'));
        if ($type_index !== null) {
            $map[$type_index] = array("target" => "type", "type" => "", "label" => "");
        }

        $title_index = $pick(array('/topic|title|subject|post\s*name/iu'));
        if ($title_index !== null) {
            $map[$title_index] = array("target" => "title", "type" => "", "label" => "");
        }

        $status_index = $pick(array('/(^|[^\p{L}])status([^\p{L}]|$)/iu', '/post\s*update|(^|[^\p{L}])update([^\p{L}]|$)/iu'));
        if ($status_index !== null) {
            $map[$status_index] = array("target" => "status", "type" => "", "label" => "");
        }

        foreach ($map as $map_row) {
            $roles[$map_row["target"]] = true;
        }

        $used_existing = array();
        foreach ($info as $i => $col) {
            if (isset($map[$i])) {
                continue;
            }

            $n = $this->norm($col["header"]);
            if (isset($core_labels[$n]) && !isset($roles[$core_labels[$n]])) {
                $roles[$core_labels[$n]] = true;
                $map[$i] = array("target" => $core_labels[$n], "type" => "", "label" => "");
                continue;
            }

            if ($n !== "" && isset($existing[$n]) && !isset($used_existing[$n])) {
                $used_existing[$n] = true;
                $map[$i] = array("target" => "col:" . $existing[$n]->field_key, "type" => "", "label" => "");
                continue;
            }

            $map[$i] = array("target" => "new", "type" => $col["detected_type"], "label" => $col["header"]);
        }

        return $map;
    }

    private function empty_value($value) {
        return $value === "" || $value === null || $value === false || (is_array($value) && !count($value));
    }

    /**
     * Validate and convert one cell for a target column (types, option membership, dates, URL scheme, lengths).
     *
     * @return mixed "" for no value (cell left empty), a string, true, or an array (multi)
     */
    private function convert_value(&$target, $raw, &$stats, $year, $ctx_month) {
        $raw = (string) $raw;
        if ($raw === "") {
            return "";
        }

        $type = $target["type"];

        if ($type === "select" || $type === "multi") {
            $tokens = array();
            if ($type === "multi") {
                $whole = $this->lower(trim($raw));
                if (isset($target["options"][$whole])) {
                    $tokens = array(trim($raw));
                } else {
                    $tokens = $this->split_tokens($raw);
                }
            } else {
                $tokens = array(preg_replace('/\s+/u', " ", trim($raw)));
            }

            $result = array();
            foreach ($tokens as $token) {
                if ($token === "") {
                    continue;
                }

                if ($this->len($token) > 60) {
                    $stats["invalid_cells"]++;
                    continue;
                }

                $key = $this->lower($token);
                if (isset($target["options"][$key])) {
                    $result[] = $target["options"][$key];
                } else if ($target["can_add"]) {
                    $target["options"][$key] = $token;
                    $target["added"][] = $token;
                    $result[] = $token;
                } else {
                    $stats["locked_option_cells"]++;
                    if (count($target["locked_values"]) < 30) {
                        $target["locked_values"][$key] = $token;
                    }
                }
            }

            if ($type === "select") {
                return count($result) ? $result[0] : "";
            }

            return array_values(array_unique($result));
        }

        if ($type === "date") {
            $date = $this->parse_date($raw, $year, $ctx_month, true);
            if ($date === null) {
                $stats["invalid_cells"]++;
                return "";
            }

            return $date;
        }

        if ($type === "number") {
            $value = str_replace(array(" ", "\xC2\xA0"), "", $raw);
            if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $value)) {
                $value = str_replace(",", "", $value);
            }

            if (!is_numeric($value) || strlen($value) > 30) {
                $stats["invalid_cells"]++;
                return "";
            }

            return $value;
        }

        if ($type === "link" || $type === "file") {
            $value = trim($raw);
            $max = $type === "link" ? 1000 : 500;
            if (!preg_match('#^https?://[^\s]+$#i', $value) || $this->len($value) > $max) {
                $stats["invalid_cells"]++;
                return "";
            }

            return $value;
        }

        if ($type === "check") {
            $lower = $this->lower(trim($raw));
            if (in_array($lower, array("true", "yes", "y", "1", "x", "done", "\xE2\x9C\x93", "\xE2\x9C\x94"), true)) {
                return true;
            }

            if (in_array($lower, array("false", "no", "n", "0"), true)) {
                return "";
            }

            $stats["invalid_cells"]++;
            return "";
        }

        if ($type === "long") {
            $value = trim($raw);
            if ($this->len($value) > 10000) {
                $stats["truncated_cells"]++;
                $value = $this->cut($value, 10000);
            }

            return $value;
        }

        //text
        $value = trim(preg_replace('/\s*\n\s*/u', " ", $raw));
        if ($this->len($value) > 500) {
            $stats["truncated_cells"]++;
            $value = $this->cut($value, 500);
        }

        return $value;
    }

    /**
     * Build the whole plan for one sheet: header, columns, mapping, rows to import, rows skipped, new columns / options, months.
     * Nothing is written. $columns are the non-deleted columns of the target calendar (core ones included).
     * $calendar_id = 0 means a calendar that does not exist yet (a new client).
     *
     * @param array $rows sheet rows (see class comment)
     * @param array $config see normalize_config()
     * @param int $calendar_id
     * @param array $columns column objects of the calendar (options_list, field_key, label, input_type, is_core)
     * @param array $perms add, admin_manage, manage_options, add_client, add_column (bool)
     */
    public function build_plan($rows, $config, $calendar_id, $columns, $perms) {
        $cfg = $this->normalize_config($config);
        $plan = $this->empty_plan();
        $perm_admin = !empty($perms["add_column"]) || !empty($perms["admin_manage"]);
        $perm_options = !empty($perms["manage_options"]);
        $year = (int) $cfg["year"];

        $by_key = array();
        $by_label = array();
        $existing_custom = array();
        $core_labels = array();
        foreach ($columns as $column) {
            $by_key[$column->field_key] = $column;
            $by_label[$this->lower(trim($column->label))] = $column;
            if (!$column->is_core) {
                $existing_custom[$this->norm($column->label)] = $column;
            } else {
                $core_labels[$this->norm($column->label)] = $column->field_key;
            }
        }

        foreach (array("date", "type", "title", "status") as $required_key) {
            if (!isset($by_key[$required_key])) {
                return $this->plan_error($plan, cc_lang("cc_error"));
            }
        }

        /* ---- header row ---- */
        $detected = $this->detect_header_row($rows);
        $plan["detected_header_row"] = $detected;
        $header_row = $cfg["header_row"] > 0 ? $cfg["header_row"] : $detected;
        if (!$header_row) {
            return $this->plan_error($plan, cc_lang("cc_imp_no_header"));
        }

        $header_cells = null;
        $data_rows = array();
        foreach ($rows as $pair) {
            if ($pair[0] == $header_row) {
                $header_cells = $pair[1];
            } else if ($pair[0] > $header_row) {
                $data_rows[] = $pair;
            }
        }

        $plan["header_row"] = $header_row;
        if ($header_cells === null) {
            return $this->plan_error($plan, sprintf(cc_lang("cc_imp_header_row_empty"), $header_row));
        }

        if (count($data_rows) > self::MAX_ROWS) {
            return $this->plan_error($plan, sprintf(cc_lang("cc_imp_err_rows"), number_format(self::MAX_ROWS), number_format(count($data_rows))));
        }

        /* ---- classify rows, collect column values ---- */
        $ncols = count($header_cells);
        foreach ($data_rows as $pair) {
            $ncols = max($ncols, count($pair[1]));
        }
        $ncols = min($ncols, self::MAX_COLUMNS);

        $header_norm = array();
        for ($i = 0; $i < $ncols; $i++) {
            $header_norm[$i] = isset($header_cells[$i]) ? $this->norm($header_cells[$i]) : "";
        }

        $values = array_fill(0, max(1, $ncols), array());
        $data = array();
        $ctx_month = 0;
        $ctx_year = 0;
        foreach ($data_rows as $pair) {
            $cells = $pair[1];
            $non_empty = 0;
            $only = "";
            foreach ($cells as $cell) {
                if ($cell !== "") {
                    $non_empty++;
                    $only = $cell;
                }
            }

            if ($non_empty === 0) {
                continue;
            }

            if ($this->is_repeat_header($cells, $header_norm)) {
                $plan["stats"]["header_repeats"]++;
                continue;
            }

            if ($non_empty === 1) {
                $plan["stats"]["notes"]++;
                list($m, $y) = $this->month_context($only);
                if ($m) {
                    $ctx_month = $m;
                    $ctx_year = $y ? $y : $ctx_year;
                } else if ($y) {
                    $ctx_year = $y;
                }
                continue;
            }

            $data[] = array($pair[0], $cells, $ctx_month, $ctx_year);
            for ($i = 0; $i < $ncols; $i++) {
                if (isset($cells[$i]) && $cells[$i] !== "") {
                    $values[$i][] = $cells[$i];
                }
            }
        }

        $plan["stats"]["data_rows"] = count($data);

        /* ---- column info ---- */
        $info = array();
        $seen = array();
        for ($i = 0; $i < $ncols; $i++) {
            if (!count($values[$i])) {
                continue; //entirely empty column
            }

            $raw_header = isset($header_cells[$i]) ? $this->clean_header($header_cells[$i]) : "";
            $label = $raw_header !== "" ? $raw_header : "Column " . $this->col_letter($i);
            $key = $this->lower($label);
            if (isset($seen[$key])) {
                $n = 2;
                do {
                    $suffix = " " . $n;
                    $try = $this->cut($label, 60 - strlen($suffix)) . $suffix;
                    $n++;
                } while (isset($seen[$this->lower($try)]));
                $label = $try;
                $key = $this->lower($label);
            }
            $seen[$key] = true;

            $samples = array();
            foreach ($values[$i] as $value) {
                $samples[] = $this->cut(preg_replace('/\s+/u', " ", $value), 60);
                if (count($samples) >= 3) {
                    break;
                }
            }

            $info[$i] = array(
                "index" => $i, "letter" => $this->col_letter($i), "header" => $label, "has_header" => $raw_header !== "",
                "samples" => $samples, "values" => $values[$i], "detected_type" => $this->detect_column_type($values[$i])
            );
        }

        /* ---- mapping ---- */
        $map = $this->auto_map($info, $existing_custom, $core_labels, $year);
        $valid_new_types = array("text", "long", "select", "multi", "date", "link", "number", "check");
        foreach ($cfg["mapping"] as $i => $override) {
            if (!isset($info[$i])) {
                continue;
            }

            $target = $override["target"];
            if ($target === "skip" || $target === "new" || in_array($target, array("date", "type", "title", "status"), true)) {
                $map[$i] = array("target" => $target, "type" => "", "label" => "");
                if ($target === "new") {
                    $map[$i]["type"] = in_array($override["type"], $valid_new_types, true) ? $override["type"] : $info[$i]["detected_type"];
                    $map[$i]["label"] = $override["label"] !== "" ? $override["label"] : $info[$i]["header"];
                }
            } else if (strpos($target, "col:") === 0) {
                $field_key = substr($target, 4);
                if (isset($by_key[$field_key]) && !$by_key[$field_key]->is_core) {
                    $map[$i] = array("target" => $target, "type" => "", "label" => "");
                }
            }
        }

        //every role / existing column only once (the first column wins)
        $taken = array();
        foreach ($map as $i => $m) {
            if ($m["target"] === "skip" || $m["target"] === "new") {
                continue;
            }

            if (isset($taken[$m["target"]])) {
                $plan["warnings"][] = sprintf(cc_lang("cc_imp_warn_twice"), $info[$i]["header"]);
                $map[$i] = array("target" => "skip", "type" => "", "label" => "");
                continue;
            }

            $taken[$m["target"]] = true;
        }

        $date_index = null;
        foreach ($map as $i => $m) {
            if ($m["target"] === "date") {
                $date_index = $i;
            }
        }

        /* ---- targets ---- */
        $targets = array(); //column index => target
        $new_labels = array();
        foreach ($by_label as $label_lower => $unused) {
            $new_labels[$label_lower] = true;
        }

        $core_target_index = array();
        foreach ($map as $i => $m) {
            $t = $m["target"];
            if ($t === "skip") {
                continue;
            }

            if (in_array($t, array("date", "type", "title", "status"), true)) {
                $column = $by_key[$t];
                $options = array();
                foreach ($column->options_list as $option) {
                    $options[$this->lower($option)] = $option;
                }

                $targets[$i] = array("role" => $t, "ref" => $t, "type" => $column->input_type, "label" => $column->label, "options" => $options, "added" => array(), "can_add" => $perm_options, "locked_values" => array(), "locked" => false, "is_new" => false, "key" => $t);
                $core_target_index[$t] = $i;
            } else if (strpos($t, "col:") === 0) {
                $column = $by_key[substr($t, 4)];
                $options = array();
                foreach ($column->options_list as $option) {
                    $options[$this->lower($option)] = $option;
                }

                $targets[$i] = array("role" => "col", "ref" => $column->field_key, "type" => $column->input_type, "label" => $column->label, "options" => $options, "added" => array(), "can_add" => $perm_options, "locked_values" => array(), "locked" => false, "is_new" => false, "key" => $column->field_key);
            }
        }

        foreach ($map as $i => $m) {
            if ($m["target"] !== "new") {
                continue;
            }

            $label = $m["label"] !== "" ? $m["label"] : $info[$i]["header"];
            $label_lower = $this->lower($label);

            //a column with this name already exists: the values go there
            if (isset($by_label[$label_lower])) {
                $column = $by_label[$label_lower];
                if (!$column->is_core && !isset($taken["col:" . $column->field_key])) {
                    $taken["col:" . $column->field_key] = true;
                    $options = array();
                    foreach ($column->options_list as $option) {
                        $options[$this->lower($option)] = $option;
                    }

                    $targets[$i] = array("role" => "col", "ref" => $column->field_key, "type" => $column->input_type, "label" => $column->label, "options" => $options, "added" => array(), "can_add" => $perm_options, "locked_values" => array(), "locked" => false, "is_new" => false, "key" => $column->field_key);
                    $map[$i] = array("target" => "col:" . $column->field_key, "type" => "", "label" => "");
                    $plan["warnings"][] = sprintf(cc_lang("cc_imp_warn_exists"), $column->label);
                    continue;
                }
            }

            if (isset($new_labels[$label_lower])) {
                $n = 2;
                do {
                    $suffix = " " . $n;
                    $try = $this->cut($label, 60 - strlen($suffix)) . $suffix;
                    $n++;
                } while (isset($new_labels[$this->lower($try)]));
                $label = $try;
                $label_lower = $this->lower($label);
            }

            $new_labels[$label_lower] = true;
            $type = in_array($m["type"], $valid_new_types, true) ? $m["type"] : "text";
            if ($type === "select" || $type === "multi") {
                $distinct = array();
                foreach ($info[$i]["values"] as $value) {
                    foreach ($type === "multi" ? $this->split_tokens($value) : array(trim($value)) as $token) {
                        $distinct[$this->lower($token)] = true;
                    }
                }

                if (count($distinct) > self::MAX_NEW_OPTIONS) {
                    $type = "text";
                    $plan["warnings"][] = sprintf(cc_lang("cc_imp_warn_many_options"), $label);
                }
            }

            $locked = !$perm_admin;
            if ($locked) {
                $plan["stats"]["locked_columns"][] = $label;
            }

            $targets[$i] = array("role" => "new", "ref" => "new:" . $i, "type" => $type, "label" => $label, "options" => array(), "added" => array(), "can_add" => true, "locked_values" => array(), "locked" => $locked, "is_new" => true, "key" => "");
            $map[$i]["type"] = $type;
            $map[$i]["label"] = $label;
        }

        /* ---- column info for the screen ---- */
        foreach ($info as $i => $col) {
            $plan["columns"][$i] = array(
                "index" => $i, "letter" => $col["letter"], "header" => $col["header"], "samples" => $col["samples"],
                "detected_type" => $col["detected_type"], "map" => isset($map[$i]) ? $map[$i] : array("target" => "skip", "type" => "", "label" => ""),
                "locked" => isset($targets[$i]) ? $targets[$i]["locked"] : false
            );
        }

        if ($date_index === null) {
            return $this->plan_error($plan, cc_lang("cc_imp_no_date_col"));
        }

        /* ---- rows ---- */
        $existing_keys = array();
        if ($cfg["skip_duplicates"] && $calendar_id > 0) {
            $existing_keys = $this->items_model->get_existing_keys($calendar_id);
        }

        $stats = array("locked_option_cells" => 0, "invalid_cells" => 0, "truncated_cells" => 0);
        $items = array();
        $skipped = array();
        $last_date = "";
        $title_index = isset($core_target_index["title"]) ? $core_target_index["title"] : null;
        $fallback_order = array();
        foreach ($targets as $i => $t) {
            if ($t["role"] !== "date" && !$t["locked"]) {
                $fallback_order[$i] = $t["role"];
            }
        }
        ksort($fallback_order);

        $first_pass = array();
        $second_pass = array();
        foreach ($fallback_order as $i => $role) {
            if ($role === "type" || $role === "status") {
                $second_pass[] = $i;
            } else {
                $first_pass[] = $i;
            }
        }

        foreach ($data as $entry) {
            list($row_number, $cells, $row_month, $row_year) = $entry;
            $row_default_year = $row_year ? $row_year : $year;

            $date_text = isset($cells[$date_index]) ? $cells[$date_index] : "";
            $date = null;
            if ($date_text !== "") {
                $date = $this->parse_date($date_text, $row_default_year, $row_month, true);
                if ($date === null) {
                    $skipped[] = array($row_number, sprintf(cc_lang("cc_imp_reason_bad_date"), $this->cut($date_text, 30)), $cells);
                    continue;
                }

                $last_date = $date;
            } else if ($cfg["carry_blank_date"] && $last_date !== "") {
                $date = $last_date;
            } else {
                $skipped[] = array($row_number, cc_lang("cc_imp_reason_no_date"), $cells);
                continue;
            }

            //title
            $title = "";
            if ($title_index !== null && isset($cells[$title_index])) {
                $title = trim(preg_replace('/\s*\n\s*/u', " ", $cells[$title_index]));
            }

            if ($title === "") {
                foreach (array($first_pass, $second_pass) as $pass) {
                    foreach ($pass as $i) {
                        $candidate = isset($cells[$i]) ? trim($cells[$i]) : "";
                        if ($candidate === "" || is_numeric(str_replace(",", "", $candidate)) || preg_match('#^https?://#i', $candidate)) {
                            continue;
                        }

                        $title = trim(preg_replace('/\s*\n\s*/u', " ", $candidate));
                        break 2;
                    }
                }
            }

            if ($title === "") {
                $skipped[] = array($row_number, cc_lang("cc_imp_reason_no_title"), $cells);
                continue;
            }

            $title = $this->cut($title, 255);

            if (count($existing_keys) && isset($existing_keys[$date . "|" . cc_title_key($title)])) {
                $skipped[] = array($row_number, cc_lang("cc_imp_reason_duplicate"), $cells);
                continue;
            }

            $item = array("row" => $row_number, "date" => $date, "type" => "", "status" => "", "title" => $title, "values" => array());
            foreach ($targets as $i => &$t) {
                if ($t["locked"] || $t["role"] === "date" || $t["role"] === "title") {
                    continue;
                }

                $raw = isset($cells[$i]) ? $cells[$i] : "";
                $value = $this->convert_value($t, $raw, $stats, $row_default_year, $row_month);
                if ($t["role"] === "type") {
                    $item["type"] = (string) $value;
                } else if ($t["role"] === "status") {
                    $item["status"] = (string) $value;
                } else if (!$this->empty_value($value)) {
                    $item["values"][$t["ref"]] = $value;
                }
            }
            unset($t);

            $items[] = $item;
        }

        foreach ($stats as $key => $value) {
            $plan["stats"][$key] = $value;
        }

        /* ---- results ---- */
        $months = array();
        $date_min = "";
        $date_max = "";
        foreach ($items as $item) {
            $months[substr($item["date"], 0, 7)] = true;
            if ($date_min === "" || $item["date"] < $date_min) {
                $date_min = $item["date"];
            }
            if ($date_max === "" || $item["date"] > $date_max) {
                $date_max = $item["date"];
            }
        }
        ksort($months);

        $existing_months = array();
        if ($calendar_id > 0) {
            foreach ($this->months_model->get_for_calendar($calendar_id) as $row) {
                $existing_months[sprintf("%04d-%02d", $row->year, $row->month)] = true;
            }
        } else {
            $previous = strtotime(date("Y-m-01") . " -1 month");
            $existing_months[date("Y-m", $previous)] = true;
            $existing_months[date("Y-m")] = true;
        }

        $months_new = array();
        foreach ($months as $ym => $unused) {
            if (!isset($existing_months[$ym])) {
                $months_new[] = $ym;
            }
        }

        $options_created = 0;
        $new_columns = array();
        $option_additions = array();
        ksort($targets);
        foreach ($targets as $i => $t) {
            if ($t["locked"]) {
                continue;
            }

            if ($t["is_new"]) {
                $new_columns[] = array("ref" => $t["ref"], "index" => $i, "label" => $t["label"], "type" => $t["type"], "options" => ($t["type"] === "select" || $t["type"] === "multi") ? $t["added"] : array());
            } else if (count($t["added"])) {
                $option_additions[] = array("key" => $t["key"], "label" => $t["label"], "added" => $t["added"]);
                $options_created += count($t["added"]);
            }

            if (isset($plan["columns"][$i])) {
                $plan["columns"][$i]["new_options"] = $t["is_new"] ? array() : $t["added"];
                $plan["columns"][$i]["locked_values"] = array_values($t["locked_values"]);
                $plan["columns"][$i]["options_locked"] = !$t["is_new"] && !$t["can_add"] && count($t["locked_values"]) > 0;
            }
        }

        //locked option values of locked targets are not processed, nothing to report
        $plan["items"] = $items;
        $plan["skipped"] = $skipped;
        $plan["new_columns"] = $new_columns;
        $plan["option_additions"] = $option_additions;
        $plan["options_created"] = $options_created;
        $plan["date_min"] = $date_min;
        $plan["date_max"] = $date_max;
        $plan["months"] = array_keys($months);
        $plan["months_new"] = $months_new;
        $plan["rows_total"] = count($items) + count($skipped);
        $plan["can_import"] = count($items) > 0 && !empty($perms["add"]);

        //preview table: the first rows after mapping, with the target column names
        $labels = array($by_key["date"]->label, $by_key["type"]->label, $by_key["title"]->label, $by_key["status"]->label);
        $order = array();
        foreach ($targets as $i => $t) {
            if (!$t["locked"] && ($t["role"] === "col" || $t["role"] === "new")) {
                $order[] = $t;
                $labels[] = $t["label"];
            }
        }

        $preview_rows = array();
        foreach (array_slice($items, 0, self::PREVIEW_ROWS) as $item) {
            $line = array($item["date"], $item["type"], $item["title"], $item["status"]);
            foreach ($order as $t) {
                $v = isset($item["values"][$t["ref"]]) ? $item["values"][$t["ref"]] : "";
                $line[] = is_array($v) ? implode(", ", $v) : ($v === true ? "Yes" : (string) $v);
            }
            $preview_rows[] = array("row" => $item["row"], "cells" => $line);
        }

        $plan["labels"] = $labels;
        $plan["preview_rows"] = $preview_rows;

        return $plan;
    }

    /**
     * The plan as it goes to the browser (no item list, only counts, 10 preview rows, first 50 skipped).
     */
    public function plan_for_browser($plan, $with_columns = true) {
        $out = array(
            "ok" => $plan["ok"], "error" => $plan["error"], "warnings" => $plan["warnings"],
            "header_row" => $plan["header_row"], "detected_header_row" => $plan["detected_header_row"],
            "can_import" => $plan["can_import"],
            "summary" => array(
                "to_import" => count($plan["items"]),
                "skipped" => count($plan["skipped"]),
                "rows_total" => $plan["rows_total"],
                "new_columns" => $plan["new_columns"],
                "new_options" => $plan["option_additions"],
                "options_created" => $plan["options_created"],
                "date_min" => $plan["date_min"],
                "date_max" => $plan["date_max"],
                "months_new" => $plan["months_new"],
                "header_repeats" => $plan["stats"]["header_repeats"],
                "notes" => $plan["stats"]["notes"],
                "locked_columns" => $plan["stats"]["locked_columns"],
                "locked_option_cells" => $plan["stats"]["locked_option_cells"],
                "invalid_cells" => $plan["stats"]["invalid_cells"],
                "truncated_cells" => $plan["stats"]["truncated_cells"]
            ),
            "skipped" => array()
        );

        foreach (array_slice($plan["skipped"], 0, self::SKIPPED_SHOWN) as $row) {
            $out["skipped"][] = array("row" => $row[0], "reason" => $row[1]);
        }

        if ($with_columns) {
            $out["columns"] = array_values($plan["columns"]);
            $out["labels"] = $plan["labels"];
            $out["preview_rows"] = $plan["preview_rows"];
        }

        return $out;
    }

    /* ================================================================== */
    /* sheets, targets, dry run, apply                                     */
    /* ================================================================== */

    /**
     * Sheet info for the screen, right after parsing: name, rows, detected header and the data row count.
     */
    public function sheet_summary($sheet, $index) {
        $rows = $sheet["rows"];
        $header = count($rows) ? $this->detect_header_row($rows) : 0;
        $data_rows = 0;
        foreach ($rows as $pair) {
            if ($pair[0] > $header) {
                $data_rows++;
            }
        }

        $info = array("index" => $index, "name" => $sheet["name"], "rows" => count($rows), "data_rows" => $header ? $data_rows : 0, "header_row" => $header, "usable" => true, "error" => "");
        if (!empty($sheet["error"])) {
            $info["usable"] = false;
            $info["error"] = $sheet["error"];
        } else if (!$header || $info["data_rows"] < 1) {
            $info["usable"] = false;
            $info["error"] = cc_lang("cc_imp_sheet_no_table");
        } else if ($info["data_rows"] > self::MAX_ROWS) {
            $info["usable"] = false;
            $info["error"] = sprintf(cc_lang("cc_imp_err_rows"), number_format(self::MAX_ROWS), number_format($info["data_rows"]));
        }

        if (isset($sheet["encoding"])) {
            $info["encoding"] = $sheet["encoding"];
            $info["delimiter"] = $sheet["delimiter"];
        }

        return $info;
    }

    //"Acme Pvt. Ltd" and "acme pvt ltd" are the same client
    public function match_calendar($sheet_name, $calendars) {
        $n = $this->norm($sheet_name);
        if ($n === "") {
            return 0;
        }

        foreach ($calendars as $calendar) {
            if ($this->norm($calendar->name) === $n) {
                return (int) $calendar->id;
            }
        }

        return 0;
    }

    /**
     * Dry run for one sheet into an existing calendar (id > 0) or a new one (id = 0).
     */
    public function dry_run_sheet($sheet, $config, $calendar_id, $perms) {
        $calendar_id = (int) $calendar_id;
        $columns = $calendar_id > 0 ? $this->columns_model->get_for_calendar($calendar_id) : $this->virtual_core_columns();
        return $this->build_plan($sheet["rows"], $config, $calendar_id, $columns, $perms);
    }

    /**
     * Apply one sheet in ONE database transaction. Nothing is trusted from the preview: the plan is built again here.
     *
     * @param array $target array("calendar_id" => int) or array("new_name" => string)
     * @return array success, message, plus the result numbers
     */
    public function run_sheet($sheet, $config, $target, $perms, $user_id, $file_name) {
        $fail = function ($message) {
            return array("success" => false, "message" => $message);
        };

        if (empty($perms["add"])) {
            return $fail(cc_lang("cc_forbidden"));
        }

        $db = db_connect("default");
        $calendar_id = 0;
        $calendar_name = "";
        $is_new_client = false;
        $db->transBegin();

        try {
            if (!empty($target["new_name"])) {
                if (empty($perms["add_client"]) && empty($perms["admin_manage"])) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_forbidden"));
                }

                $name = trim(preg_replace('/\s+/u', " ", (string) $target["new_name"]));
                if ($name === "" || $this->len($name) > 120) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_name_required"));
                }

                if ($this->calendars_model->name_exists($name)) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_client_exists") . " (" . $name . ")");
                }

                $client_id = $this->calendars_model->find_client_id_by_name($name);
                $calendar_id = $this->calendars_model->create_calendar($name, $client_id, $user_id);
                $this->columns_model->seed_core($calendar_id);
                $this->months_model->seed_default($calendar_id);
                $calendar_name = $name;
                $is_new_client = true;
            } else {
                $calendar = $this->calendars_model->get_active((int) $target["calendar_id"]);
                if (!$calendar) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_calendar_not_found"));
                }

                $calendar_id = (int) $calendar->id;
                $calendar_name = $calendar->name;
            }

            $columns = $this->columns_model->get_for_calendar($calendar_id);
            $plan = $this->build_plan($sheet["rows"], $config, $calendar_id, $columns, $perms);
            if (!$plan["ok"]) {
                $db->transRollback();
                return $fail($plan["error"]);
            }

            if (!count($plan["items"])) {
                $db->transRollback();
                return $fail(cc_lang("cc_imp_nothing_to_import"));
            }

            $now = cc_now();
            $columns_by_key = array();
            foreach ($columns as $column) {
                $columns_by_key[$column->field_key] = $column;
            }

            //new options of existing columns (case-insensitive, trimmed, max 60 characters were checked in the plan)
            foreach ($plan["option_additions"] as $addition) {
                $column = $columns_by_key[$addition["key"]];
                $this->columns_model->update_options($column->id, array_merge($column->options_list, $addition["added"]));
            }

            //new columns in sheet order, same key / unique logic as the column designer
            $ref_to_key = array();
            foreach ($plan["new_columns"] as $new_column) {
                $options = ($new_column["type"] === "select" || $new_column["type"] === "multi") ? $new_column["options"] : array();
                if (($new_column["type"] === "select" || $new_column["type"] === "multi") && !count($options)) {
                    $options = array("-");
                }

                $column_id = $this->columns_model->add_column($calendar_id, $new_column["label"], $new_column["type"], $options);
                $saved = $this->columns_model->get_active($column_id);
                $ref_to_key[$new_column["ref"]] = $saved->field_key;
            }

            $batch_id = bin2hex(random_bytes(12));
            $rows = array();
            $inserted = 0;
            foreach ($plan["items"] as $item) {
                $values = array();
                foreach ($item["values"] as $ref => $value) {
                    if (isset($ref_to_key[$ref])) {
                        $values[$ref_to_key[$ref]] = $value;
                    } else if (strpos($ref, "new:") !== 0) {
                        $values[$ref] = $value;
                    }
                }

                $rows[] = array(
                    "calendar_id" => $calendar_id,
                    "publish_date" => $item["date"],
                    "content_type" => $item["type"],
                    "status" => $item["status"],
                    "title" => $item["title"],
                    "values_json" => $this->items_model->encode_values($values),
                    "deleted" => 0,
                    "created_by" => (int) $user_id,
                    "updated_by" => (int) $user_id,
                    "created_at" => $now,
                    "updated_at" => $now,
                    "import_batch_id" => $batch_id
                );

                if (count($rows) >= self::BATCH_SIZE) {
                    $this->items_model->insert_batch_rows($rows);
                    $inserted += count($rows);
                    $rows = array();
                }
            }

            if (count($rows)) {
                $this->items_model->insert_batch_rows($rows);
                $inserted += count($rows);
            }

            $months_added = 0;
            foreach ($plan["months"] as $ym) {
                if ($this->months_model->ensure($calendar_id, (int) substr($ym, 0, 4), (int) substr($ym, 5, 2))) {
                    $months_added++;
                }
            }

            $log_id = $this->logs_model->create_log(array(
                "calendar_id" => $calendar_id,
                "user_id" => (int) $user_id,
                "file_name" => $this->cut($file_name, 255),
                "sheet_name" => $this->cut($sheet["name"], 120),
                "rows_total" => $plan["rows_total"],
                "rows_imported" => $inserted,
                "rows_skipped" => count($plan["skipped"]),
                "columns_created" => count($plan["new_columns"]),
                "options_created" => $plan["options_created"],
                "batch_id" => $batch_id,
                "undone" => 0,
                "created_at" => $now
            ));

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $fail(cc_lang("cc_imp_failed"));
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR] cc import run_sheet: {message}', array('message' => $e->getMessage()));
            return $fail(cc_lang("cc_imp_failed"));
        }

        return array(
            "success" => true,
            "calendar_id" => $calendar_id,
            "calendar_name" => $calendar_name,
            "is_new_client" => $is_new_client,
            "log_id" => $log_id,
            "batch_id" => $batch_id,
            "header_row" => $plan["header_row"],
            "imported" => $inserted,
            "skipped" => count($plan["skipped"]),
            "skipped_rows" => $plan["skipped"],
            "columns_created" => count($plan["new_columns"]),
            "new_column_names" => array_map(function ($c) {
                return $c["label"];
            }, $plan["new_columns"]),
            "options_created" => $plan["options_created"],
            "months_added" => $months_added,
            "months" => $plan["months"],
            "date_min" => $plan["date_min"],
            "date_max" => $plan["date_max"],
            "locked_columns" => $plan["stats"]["locked_columns"],
            "locked_option_cells" => $plan["stats"]["locked_option_cells"],
            "message" => ""
        );
    }

    /**
     * CSV text of skipped rows (UTF-8 with BOM so Excel shows Kannada correctly). Cells that start with = + - @ are neutralised.
     */
    public function skipped_csv($skipped_rows, $header_cells = array()) {
        $fh = fopen("php://temp", "r+");
        fwrite($fh, "\xEF\xBB\xBF");

        $safe = function ($value) {
            $value = (string) $value;
            if ($value !== "" && strpos("=+-@\t\r", $value[0]) !== false) {
                return "'" . $value;
            }

            return $value;
        };

        $head = array("Row", "Reason");
        foreach ($header_cells as $cell) {
            $head[] = $safe($cell);
        }
        fputcsv($fh, $head, ",", '"', "");

        foreach ($skipped_rows as $row) {
            $line = array($row[0], $safe($row[1]));
            foreach (isset($row[2]) ? $row[2] : array() as $cell) {
                $line[] = $safe($cell);
            }
            fputcsv($fh, $line, ",", '"', "");
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }


    /**
     * Copy a workbook to sys_get_temp_dir() so ZipArchive can open it on Windows/UNC.
     */
    private function copy_workbook_to_native_temp($path, $type) {
        // is_readable() is false for some WSL/UNC paths even when copy() works, so only require is_file().
        if (!is_file($path)) {
            return null;
        }
        $ext = preg_replace('/[^a-z0-9]/i', '', (string) $type);
        if ($ext === '') {
            $ext = 'bin';
        }
        $dest = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'cc_imp_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@copy($path, $dest) || !is_file($dest)) {
            return null;
        }
        return $dest;
    }
}
