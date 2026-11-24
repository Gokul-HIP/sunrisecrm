<?php

namespace App\Libraries;

/**
 * Content Calendar import (phase 2, v2): read a CSV / spreadsheet, detect header and columns,
 * map them to a client calendar, and apply the rows.
 *
 * One planning function (build_group_plan, build_plan for one sheet) is used by the dry run (preview) and by the
 * real import (run_group / run_sheet), so the counts shown before importing are exactly the ones that are applied.
 *
 * A "sheet" is array("name" => string, "rows" => array(array(row_number, array(cells...)), ...)).
 * Only non-empty rows are kept, row numbers are the real row numbers of the file.
 *
 * v2: big workbooks are read ONE sheet at a time (list_sheet_names / read_one_sheet) and every parsed sheet is kept in a
 * temp token file on the server (begin_parse / parse_next_sheets / load_sheet_parsed): the browser never holds rows.
 * Sheets of the same client (normalized client name) are ONE calendar (group_sheets / build_group_plan / run_group).
 */
class Content_calendar_import {

    //limits: easy to change
    const MAX_FILE_BYTES = 5242880; //5 MB
    const MAX_ROWS = 5000; //content rows per sheet
    const MAX_COLUMNS = 100; //csv / ods
    const MAX_SHEETS = 40;
    const READ_MAX_COLUMNS = 40; //workbook read filter: columns
    const READ_MAX_ROWS = 6000; //workbook read filter: rows (rows beyond are never loaded)
    const MAX_NEW_OPTIONS = 100; //kept for old callers
    const WARN_NEW_OPTIONS = 40; //more new options than this: warn, the values stay text
    const TOKEN_TTL = 1800; //temp files live 30 minutes
    const HEADER_SCAN_ROWS = 30;
    const MAX_UNPACKED_BYTES = 157286400; //150 MB, zip bomb guard
    const MAX_WORKBOOK_ROWS = 100000; //rows (even empty, formatted) read from one sheet
    const PREVIEW_ROWS = 10;
    const SKIPPED_SHOWN = 50;
    const BATCH_SIZE = 200;

    private $models = array();
    private $native_cache = array();
    private $temp_files = array();

    function __construct() {
        helper('content_calendar');
    }

    function __destruct() {
        foreach ($this->temp_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    //models are loaded when they are first needed (analysing a file needs no database)
    private function get_model($name) {
        if (!isset($this->models[$name])) {
            $this->models[$name] = model("App\\Models\\" . $name);
        }

        return $this->models[$name];
    }

    private function columns_model() {
        return $this->get_model("Cc_columns_model");
    }

    private function items_model() {
        return $this->get_model("Cc_items_model");
    }

    private function months_model() {
        return $this->get_model("Cc_months_model");
    }

    private function calendars_model() {
        return $this->get_model("Cc_calendars_model");
    }

    private function logs_model() {
        return $this->get_model("Cc_import_logs_model");
    }

    private function special_days_model() {
        return $this->get_model("Cc_special_days_model");
    }

    private function targets_model() {
        return $this->get_model("Cc_targets_model");
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

    /**
     * Store an already parsed file: a meta file (token.json) and one file per sheet (token.s0.json, token.s1.json ...).
     *
     * @return string the token, "" on failure
     */
    public function store_parsed($user_id, $calendar_id, $file_name, $detected_type, $sheets, $notes = array()) {
        $token = $this->new_token();
        $names = array();
        $parsed = array();
        foreach ($sheets as $index => $sheet) {
            $names[$index] = isset($sheet["name"]) ? (string) $sheet["name"] : "";
            if (!$this->store_sheet_parsed($token, $index, $sheet)) {
                $this->delete_parsed($token);
                return "";
            }

            $parsed[$index] = true;
        }

        $meta = array(
            "user_id" => (int) $user_id,
            "calendar_id" => (int) $calendar_id,
            "file_name" => $file_name,
            "detected_type" => $detected_type,
            "created" => time(),
            "sheet_names" => $names,
            "parsed" => $parsed,
            "complete" => true,
            "notes" => $notes,
            "upload" => ""
        );

        if (!$this->save_meta($token, $meta)) {
            $this->delete_parsed($token);
            return "";
        }

        return $token;
    }

    private function save_meta($token, $meta) {
        $json = json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE);
        return $json !== false && file_put_contents($this->dir() . $token . ".json", $json) !== false;
    }

    /**
     * Keep one parsed sheet (rows, summary ...) in a temp file under the token.
     */
    public function store_sheet_parsed($token, $index, $sheet) {
        if (!$this->valid_token($token) || (int) $index < 0) {
            return false;
        }

        $json = json_encode($sheet, JSON_INVALID_UTF8_SUBSTITUTE);
        return $json !== false && file_put_contents($this->dir() . $token . ".s" . (int) $index . ".json", $json) !== false;
    }

    /**
     * The meta of a token (file name, sheet names, which sheets are parsed), or null when it is unknown, expired or not yours.
     */
    public function load_meta($token, $user_id) {
        if (!$this->valid_token($token)) {
            return null;
        }

        $file = $this->dir() . $token . ".json";
        if (!is_file($file)) {
            return null;
        }

        if (@filemtime($file) < time() - self::TOKEN_TTL) {
            $this->delete_parsed($token);
            return null;
        }

        $meta = json_decode((string) file_get_contents($file), true);
        if (!is_array($meta) || !isset($meta["user_id"]) || (int) $meta["user_id"] !== (int) $user_id) {
            return null;
        }

        return $meta;
    }

    /**
     * One parsed sheet of a token (array with name, rows ...), or null.
     */
    public function load_sheet_parsed($token, $user_id, $index) {
        if ($this->load_meta($token, $user_id) === null) {
            return null;
        }

        $file = $this->dir() . $token . ".s" . (int) $index . ".json";
        if (!is_file($file)) {
            return null;
        }

        $sheet = json_decode((string) file_get_contents($file), true);
        return is_array($sheet) ? $sheet : null;
    }

    /**
     * @return array|null the meta plus "sheets" (index => sheet) of the sheets that are parsed so far; null when the token is unknown, expired or belongs to another user
     */
    public function load_parsed($token, $user_id) {
        $meta = $this->load_meta($token, $user_id);
        if ($meta === null) {
            return null;
        }

        $meta["sheets"] = array();
        foreach ($meta["sheet_names"] as $index => $unused) {
            $sheet = $this->load_sheet_parsed($token, $user_id, $index);
            if ($sheet !== null) {
                $meta["sheets"][(int) $index] = $sheet;
            }
        }

        return $meta;
    }

    public function delete_parsed($token) {
        if (!$this->valid_token($token)) {
            return;
        }

        $dir = $this->dir();
        @unlink($dir . $token . ".json");
        @unlink($dir . $token . ".upload");
        foreach (glob($dir . $token . ".s*.json") ?: array() as $file) {
            @unlink($file);
        }
    }

    /* ---------- progressive parsing: sheet by sheet ---------- */

    /**
     * Step 1 of a parse: look at the file (type, sheet names only), keep the uploaded file under a token and write the meta.
     * No sheet is read yet. The file is renamed to token.upload; parse_next_sheets() deletes it after the last sheet.
     *
     * @param string $upload_path the uploaded file inside dir()
     * @return array token, type, sheet_names, notes, total
     * @throws \RuntimeException with a user message
     */
    public function begin_parse($user_id, $calendar_id, $file_name, $upload_path, $chosen_type = "auto") {
        $meta = $this->read_file_meta($upload_path, $chosen_type);
        $token = $this->new_token();
        $kept = $this->dir() . $token . ".upload";
        if (!@rename($upload_path, $kept)) {
            if (!@copy($upload_path, $kept)) {
                throw new \RuntimeException(cc_lang("cc_imp_upload_failed"));
            }

            @unlink($upload_path);
        }

        $names = $meta["sheet_names"];
        if ($meta["type"] === "csv") {
            $names = array(mb_substr(pathinfo((string) $file_name, PATHINFO_FILENAME), 0, 120, "UTF-8"));
        }

        $stored = array(
            "user_id" => (int) $user_id,
            "calendar_id" => (int) $calendar_id,
            "file_name" => $file_name,
            "detected_type" => $meta["type"],
            "created" => time(),
            "sheet_names" => array_values($names),
            "parsed" => array(),
            "complete" => false,
            "notes" => $meta["notes"],
            "upload" => $token . ".upload"
        );

        if (!$this->save_meta($token, $stored)) {
            @unlink($kept);
            throw new \RuntimeException(cc_lang("cc_imp_upload_failed"));
        }

        return array("token" => $token, "type" => $meta["type"], "sheet_names" => $stored["sheet_names"], "notes" => $meta["notes"], "total" => count($stored["sheet_names"]));
    }

    /**
     * Step 2 of a parse (call again until "done"): read the next sheets one by one, keep each in its token file.
     *
     * @param int $max_sheets sheets to read in this call
     * @return array done, parsed, total, sheets (summaries of the sheets read in this call), peak_memory
     * @throws \RuntimeException with a user message (unknown token, unreadable file)
     */
    public function parse_next_sheets($token, $user_id, $max_sheets = 1) {
        $meta = $this->load_meta($token, $user_id);
        if ($meta === null) {
            throw new \RuntimeException(cc_lang("cc_imp_expired"));
        }

        $total = count($meta["sheet_names"]);
        $summaries = array();
        $read = 0;
        for ($index = 0; $index < $total && $read < max(1, (int) $max_sheets); $index++) {
            if (!empty($meta["parsed"][$index])) {
                continue;
            }

            $summaries[] = $this->parse_one($token, $meta, $index);
            $meta["parsed"][$index] = true;
            $read++;
        }

        $done = count($meta["parsed"]) >= $total;
        $meta["complete"] = $done;
        if ($done && !empty($meta["upload"])) {
            @unlink($this->dir() . $meta["upload"]);
            $meta["upload"] = "";
        }

        $this->save_meta($token, $meta);
        return array("done" => $done, "parsed" => count($meta["parsed"]), "total" => $total, "sheets" => $summaries, "peak_memory" => memory_get_peak_usage(true));
    }

    //read, summarise and store one sheet of the kept upload
    private function parse_one($token, $meta, $index) {
        $path = $this->dir() . $meta["upload"];
        if (!is_file($path)) {
            throw new \RuntimeException(cc_lang("cc_imp_expired"));
        }

        $name = (string) $meta["sheet_names"][$index];
        if ($meta["detected_type"] === "csv") {
            $sheet = $this->read_csv($path);
            $sheet["name"] = $name;
        } else {
            $sheet = $this->read_one_sheet($path, $name, $meta["detected_type"]);
        }

        $summary = $this->sheet_summary($sheet, $index);
        $sheet["summary"] = $summary;
        if (!$this->store_sheet_parsed($token, $index, $sheet)) {
            throw new \RuntimeException(cc_lang("cc_imp_upload_failed"));
        }

        return $summary;
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
    private function with_library_errors_ignored($callback) {
        $previous = null;
        $previous = set_error_handler(function ($severity, $message, $file, $line) use (&$previous) {
            if (strpos((string) $file, "PhpSpreadsheet") !== false && !($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR))) {
                return true;
            }

            return $previous ? call_user_func($previous, $severity, $message, $file, $line) : false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private function reader_name($type) {
        $names = array("xlsx" => "Xlsx", "xls" => "Xls", "ods" => "Ods");
        return isset($names[$type]) ? $names[$type] : "Xlsx";
    }

    /**
     * ZipArchive on Windows cannot open WSL/UNC paths (\\wsl.localhost\...): the file is copied to a native temp file
     * once, when the original path is unreadable. The copy is deleted when this object is destroyed.
     *
     * @throws \RuntimeException with a user message
     */
    private function native_path($reader, $path, $type) {
        if (isset($this->native_cache[$path])) {
            return $this->native_cache[$path];
        }

        if ($reader->canRead($path)) {
            $this->native_cache[$path] = $path;
            return $path;
        }

        $temp = $this->copy_workbook_to_native_temp($path, $type);
        if ($temp && $reader->canRead($temp)) {
            $this->temp_files[] = $temp;
            $this->native_cache[$path] = $temp;
            return $temp;
        }

        if ($temp && is_file($temp)) {
            @unlink($temp);
        }

        throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
    }

    /**
     * Names of the sheets of a workbook (reads only the workbook list, not the sheets).
     *
     * @return array list of sheet names
     * @throws \RuntimeException with a user message
     */
    public function list_sheet_names($path, $type = "xlsx") {
        return $this->with_library_errors_ignored(function () use ($path, $type) {
                    $this->load_spreadsheet_library();
                    try {
                        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($this->reader_name($type));
                        $native = $this->native_path($reader, $path, $type);
                        $names = $reader->listWorksheetNames($native);
                    } catch (\RuntimeException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        log_message('error', '[ERROR] cc import list_sheet_names: {message}', array('message' => $e->getMessage()));
                        throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
                    }

                    return array_values(array_map("strval", $names));
                });
    }

    /**
     * Look at a file without reading any sheet: type, notes, sheet names.
     *
     * @return array type, sheet_names, notes, truncated (more than MAX_SHEETS sheets: the rest is ignored)
     * @throws \RuntimeException with a user message
     */
    public function read_file_meta($path, $chosen_type = "auto") {
        $type = $this->detect_type($path);
        $notes = array();

        $chosen = strtolower((string) $chosen_type);
        if ($chosen !== "" && $chosen !== "auto") {
            $matches = ($chosen === "csv" && $type === "csv") || ($chosen === "excel" && ($type === "xlsx" || $type === "xls")) || ($chosen === "ods" && $type === "ods");
            if (!$matches) {
                $notes[] = sprintf(cc_lang("cc_imp_type_mismatch"), $this->type_label($chosen), $this->type_label($type), $this->type_label($type));
            }
        }

        $truncated = false;
        if ($type === "csv") {
            $names = array("CSV");
        } else {
            $names = $this->list_sheet_names($path, $type);
            if (count($names) > self::MAX_SHEETS) {
                $names = array_slice($names, 0, self::MAX_SHEETS);
                $truncated = true;
                $notes[] = sprintf(cc_lang("cc_imp_warn_max_sheets"), self::MAX_SHEETS);
            }
        }

        if (!count($names)) {
            throw new \RuntimeException(cc_lang("cc_imp_err_no_sheets"));
        }

        return array("type" => $type, "sheet_names" => $names, "notes" => $notes, "truncated" => $truncated);
    }

    //limits what the reader loads: first READ_MAX_COLUMNS columns, first READ_MAX_ROWS rows
    private function make_read_filter() {
        $max_letters = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(self::READ_MAX_COLUMNS);
        return new class(self::READ_MAX_ROWS, $max_letters) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {

            private $max_rows;
            private $max_letters;

            public function __construct($max_rows, $max_letters) {
                $this->max_rows = $max_rows;
                $this->max_letters = $max_letters;
            }

            public function readCell($columnAddress, $row, $worksheetName = '') {
                if ($row > $this->max_rows) {
                    return false;
                }

                $length = strlen($columnAddress);
                $max_length = strlen($this->max_letters);
                if ($length !== $max_length) {
                    return $length < $max_length;
                }

                return strcmp($columnAddress, $this->max_letters) <= 0;
            }
        };
    }

    /**
     * Read ONE sheet of a workbook: only this sheet is loaded, data only, read filter 40 columns x 6000 rows.
     * The spreadsheet is disconnected and freed before returning.
     *
     * @return array sheet (name, rows, error?)
     * @throws \RuntimeException with a user message
     */
    public function read_one_sheet($path, $sheet_name, $type = "xlsx") {
        return $this->with_library_errors_ignored(function () use ($path, $sheet_name, $type) {
                    return $this->read_one_sheet_inner($path, (string) $sheet_name, $type);
                });
    }

    private function read_one_sheet_inner($path, $sheet_name, $type) {
        $this->load_spreadsheet_library();

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($this->reader_name($type));
            $native = $this->native_path($reader, $path, $type);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly(array($sheet_name));
            $reader->setReadFilter($this->make_read_filter());
            $spreadsheet = $reader->load($native);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            log_message('error', '[ERROR] cc import read_one_sheet: {message}', array('message' => $e->getMessage()));
            throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
        }

        try {
            $worksheet = $spreadsheet->getSheetByName($sheet_name);
            if (!$worksheet) {
                $worksheet = $spreadsheet->getSheet(0);
            }

            $sheet = $this->read_worksheet($worksheet);
            $sheet["name"] = $sheet_name;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $reader, $worksheet);
            gc_collect_cycles();
        }

        return $sheet;
    }

    /**
     * Read every sheet of a workbook (one sheet at a time, see read_one_sheet).
     */
    public function read_workbook($path, $type) {
        $sheets = array();
        $names = $this->list_sheet_names($path, $type);
        foreach (array_slice($names, 0, self::MAX_SHEETS) as $name) {
            $sheets[] = $this->read_one_sheet($path, $name, $type);
        }

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
            if ($non_empty > self::MAX_ROWS + 200 || (int) $row->getRowIndex() >= self::READ_MAX_ROWS) {
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
                $number = $this->month_number_fuzzy($word);
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
        $s = preg_replace('/\s*[—–-]\s*(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?$/iu', "", $s);
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

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.\s]+(\d{4}|\d{2})$/', $s, $p)) {
            $year = (int) $p[3];
            if ($year < 100) {
                $year += 2000;
            }

            // Prefer day-first: d/m/y
            $ymd = $this->valid_ymd($year, (int) $p[2], (int) $p[1]);
            if ($ymd !== null) {
                return $ymd;
            }

            // Fallback: if month > 12, try month-first: m/d/y (e.g. 07-13-26 -> 13 cannot be a month)
            return $this->valid_ymd($year, (int) $p[1], (int) $p[2]);
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})$/', $s, $p)) {
            if (!$default_year) {
                return null;
            }

            $ymd = $this->valid_ymd((int) $default_year, (int) $p[2], (int) $p[1]);
            if ($ymd !== null) {
                return $ymd;
            }

            return $this->valid_ymd((int) $default_year, (int) $p[1], (int) $p[2]);
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

        if (preg_match('/^([A-Za-z]{3,9})\.?[ \-]*(\d{1,2})(?!\d)(?:[ ,\-\/.]*(\d{4}|\d{2}))?$/u', $s, $p)) {
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
        $text = trim((string) $text);
        if ($text === "" || $this->len($text) > 30) {
            return false;
        }

        return $this->canon_header($text) === "date" || preg_match('/(^|[^\p{L}])dates?([^\p{L}]|$)/iu', $text) === 1;
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

            if (($non_empty >= 3 || ($date_like && $non_empty >= 2)) && $short_text >= $non_empty * 0.7) {
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
    /* names, headers, header counts (v2)                                  */
    /* ================================================================== */

    /**
     * UTF-8 aware Levenshtein distance. Returns $max + 1 when the strings are clearly further apart than $max.
     */
    public function lev($a, $b, $max = 99) {
        $a = (string) $a;
        $b = (string) $b;
        if ($a === $b) {
            return 0;
        }

        if (!preg_match('/[^\x00-\x7F]/', $a . $b)) {
            if (abs(strlen($a) - strlen($b)) > $max) {
                return $max + 1;
            }

            if (strlen($a) <= 255 && strlen($b) <= 255) {
                return levenshtein($a, $b);
            }
        }

        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($x);
        $m = count($y);
        if (abs($n - $m) > $max) {
            return $max + 1;
        }

        if (!$n) {
            return $m;
        }

        if (!$m) {
            return $n;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = array($i);
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return $prev[$m];
    }

    /**
     * "Almost the same name": both normalized forms have 6+ characters, differ by at most 2 edits and have the same digits
     * (so "Design 01" and "Design 02" are never merged).
     */
    public function near_names($a, $b) {
        $na = $this->norm($a);
        $nb = $this->norm($b);
        if ($na === "" || $nb === "" || $na === $nb) {
            return false;
        }

        if ($this->len($na) < 6 || $this->len($nb) < 6) {
            return false;
        }

        if (preg_replace('/\D+/', "", $na) !== preg_replace('/\D+/', "", $nb)) {
            return false;
        }

        return $this->lev($na, $nb, 2) <= 2;
    }

    private function header_synonyms() {
        static $map = null;
        if ($map === null) {
            $map = array();
            $groups = array(
                "description" => array("discription", "descriptions", "descripton", "descrption"),
                "reference" => array("refrence", "referance", "referal", "referral", "referencelink", "referencelinks", "refrencelink", "referancelink", "referallink", "referrallink", "referances", "references", "refrences"),
                "designcopy" => array("desgincopy", "desingcopy"),
                "platforms" => array("platform"),
                "status" => array("status", "postupdate", "postupdates", "videoupdate", "videoupdates", "poststatus"),
                "date" => array("date", "dates", "publishingdate", "publishdate", "publishingdates", "postingdate", "postdate"),
                "title" => array("title", "topic", "topictitle", "aifeedtopic", "posttitle", "subject", "postname"),
                "contenttype" => array("contenttype", "contenttypes", "contenttypeformat", "contenttypeformats"),
                "specialday" => array("specialday", "specialdays", "specialdate", "specialoccasion")
            );

            foreach ($groups as $canon => $list) {
                foreach ($list as $name) {
                    $map[$name] = $canon;
                }
            }
        }

        return $map;
    }

    private function weekday_headers() {
        return array("week", "weeks", "day", "days", "weekday", "weekdays", "publishingday", "publishingdays", "dayname", "dayofweek", "publishday");
    }

    //canonical normalized header: synonyms and spelling variants collapse into one name
    public function canon_header($text) {
        $n = $this->norm($text);
        if ($n === "") {
            return "";
        }

        $map = $this->header_synonyms();
        return isset($map[$n]) ? $map[$n] : $n;
    }

    //header that is a typo of a core header (never for week / day columns)
    private function fuzzy_role_canon($norm) {
        if ($norm === "" || in_array($norm, $this->weekday_headers(), true) || $this->len($norm) < 6) {
            return "";
        }

        foreach (array("publishingdate" => "date", "contenttype" => "contenttype", "specialdays" => "specialday", "postupdate" => "status") as $name => $canon) {
            if ($this->lev($norm, $name, 2) <= 2) {
                return $canon;
            }
        }

        return "";
    }

    private function canon_label($canon, $raw) {
        $labels = array("description" => "Description", "reference" => "Reference", "designcopy" => "Design Copy", "platforms" => "Platforms");
        if (isset($labels[$canon])) {
            return $labels[$canon];
        }

        return $raw;
    }

    //"Total Design: 12" + "Total Reel : 1" + "Stories Daily 2*31=62"  =>  Design 12, Reel 1, Stories 62
    public function extract_header_counts($text) {
        $counts = array();
        $text = (string) $text;
        if ($text === "") {
            return $counts;
        }

        $add = function ($label, $count) use (&$counts) {
            $label = trim(preg_replace('/\s+/u', " ", $label));
            if ($label === "" || $this->len($label) > 30) {
                return;
            }

            $key = $this->lower($label);
            if (in_array($key, array("client name", "name", "total", "client", "date", "year"), true)) {
                return;
            }

            $label = mb_strtoupper(mb_substr($label, 0, 1, "UTF-8"), "UTF-8") . mb_strtolower(mb_substr($label, 1, null, "UTF-8"), "UTF-8");
            if (!isset($counts[$label])) {
                $counts[$label] = (int) $count;
            }
        };

        if (preg_match_all('/([A-Za-z]+)\s+daily\b([^\n|]*)/iu', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (preg_match_all('/=\s*(\d+)/', $m[2], $nums) && count($nums[1])) {
                    $add($m[1], end($nums[1]));
                }
            }

            $text = preg_replace('/([A-Za-z]+)\s+daily\b([^\n|]*)/iu', " ", $text);
        }

        if (preg_match_all('/(?:total\s+)?([A-Za-z][A-Za-z ]{0,24}?)\s*[:=]\s*(\d{1,4})(?!\d)/iu', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $add($m[1], $m[2]);
            }
        }

        return $counts;
    }

    /**
     * Client name written in A1 / A2: "Client Name: X" up to the first new line, comma, pipe or the word "Total".
     *
     * @return string "" when there is none
     */
    public function client_name_from_rows($rows) {
        foreach ($rows as $pair) {
            if ($pair[0] > 2) {
                break;
            }

            foreach ($pair[1] as $cell) {
                if ($cell === "" || !preg_match('/client\s*name\s*[:\-]\s*(.*)$/isu', $cell, $m)) {
                    continue;
                }

                $parts = preg_split('/[\r\n,|]|\bTotal\b/iu', $m[1], 2);
                $name = trim(preg_replace('/\s+/u', " ", (string) $parts[0]), " \t\"'");
                if ($name !== "") {
                    return $this->cut($name, 120);
                }
            }
        }

        return "";
    }

    //sheet name without a trailing Design / Desgin / Video / Vidoes / videos
    public function clean_sheet_client_name($sheet_name) {
        $name = trim(preg_replace('/\s+/u', " ", (string) $sheet_name));
        $cleaned = trim(preg_replace('/[\s\-_]*\b(designs?|desgin|desing|videos?|vidoes|viideos?|vedios?|vidios?)\s*$/iu', "", $name));
        return $cleaned !== "" ? $cleaned : $name;
    }

    /**
     * @return array("name" => string, "source" => "cell" | "sheet")
     */
    public function detect_client($rows, $sheet_name) {
        $name = $this->client_name_from_rows($rows);
        if ($name !== "") {
            return array("name" => $name, "source" => "cell");
        }

        return array("name" => $this->cut($this->clean_sheet_client_name($sheet_name), 120), "source" => "sheet");
    }

    public function extract_client_name($rows, $sheet_name = "") {
        $client = $this->detect_client($rows, $sheet_name);
        return $client["name"];
    }

    /* ---------- month names, section titles ---------- */

    //month number of a (possibly misspelled) month word: "Auguts" => 8
    public function month_number_fuzzy($word) {
        $number = $this->month_number($word);
        if ($number) {
            return $number;
        }

        $word = $this->lower(rtrim((string) $word, "."));
        $length = strlen($word);
        if ($length < 5 || !preg_match('/^[a-z]+$/', $word)) {
            return 0;
        }

        $names = array("january", "february", "march", "april", "june", "july", "august", "september", "october", "november", "december");
        $numbers = array(1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12);
        $limit = $length >= 6 ? 2 : 1;
        foreach ($names as $i => $name) {
            if (strlen($name) >= 5 && abs(strlen($name) - $length) <= $limit && levenshtein($word, $name) <= $limit) {
                return $numbers[$i];
            }
        }

        return 0;
    }

    /**
     * A single-cell row that only names a month: "MAY MONTH CONTENT CALENDAR", "JULY", "AUGUST ", "Auguts 15", "MAY MONTH - 2026".
     * Real dates ("August 15, 2026") are not titles.
     */
    public function is_section_title($text) {
        $text = trim(preg_replace('/\s+/u', " ", (string) $text));
        if ($text === "" || $this->len($text) > 60) {
            return false;
        }

        if (!preg_match('/^[\p{L}\p{N}\s\-\x{2013}\x{2014}_.:|&\/()]+$/u', $text)) {
            return false;
        }

        if ($this->parse_date($text, 2026, 0, false) !== null) {
            return false;
        }

        $allowed = array("month", "months", "monthly", "content", "contents", "calendar", "calender", "plan", "schedule", "for", "of", "the", "and");
        $has_month = false;
        foreach (preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $lower = $this->lower($word);
            if (in_array($lower, $allowed, true)) {
                continue;
            }

            if ($this->month_number_fuzzy($word)) {
                $has_month = true;
                continue;
            }

            return false;
        }

        if (!$has_month) {
            return false;
        }

        //only short numbers (a day, a year) next to the words
        foreach (preg_split('/[^\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $number) {
            if (strlen($number) > 4) {
                return false;
            }
        }

        return true;
    }

    //"November", "JULY ", "Auguts" - a month and nothing else: not a date, but a hint
    public function month_only_hint($text) {
        $text = trim(preg_replace('/\s+/u', " ", (string) $text));
        if ($text === "" || $this->len($text) > 20) {
            return 0;
        }

        if (preg_match('/^([A-Za-z]{3,12})\.?(?:\s*[,\-]?\s*20\d{2})?$/', $text, $m)) {
            return $this->month_number_fuzzy($m[1]);
        }

        return 0;
    }

    //dates that really mean "no date" (Excel 0, dashes)
    private function is_blank_date_text($text) {
        $text = trim((string) $text);
        return $text === "" || in_array($text, array("0", "0.0", "00", "-", "--", "\xE2\x80\x94", "\xE2\x80\x93", ".", "N/A", "n/a", "NA", "na", "nil", "null"), true);
    }

    //a text that only holds weekday names (Monday, Tue, Wednesd ...)
    public function is_weekday_only_column($values) {
        $n = 0;
        $hits = 0;
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === "") {
                continue;
            }

            $n++;
            if (preg_match('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?$/i', $value)) {
                $hits++;
            }
        }

        //SPN and similar sheets mix weekday names with a few odd cells; still treat as weekday-only
        return $n > 0 && $hits >= $n * 0.7;
    }

    private function date_ratio($values, $year) {
        $n = 0;
        $hits = 0;
        foreach ($values as $value) {
            $n++;
            if ($this->parse_date($value, $year, 0, true) !== null) {
                $hits++;
            }

            if ($n >= 300) {
                break;
            }
        }

        return $n ? $hits / $n : 0;
    }

    /* ================================================================== */
    /* config                                                              */
    /* ================================================================== */

    public function default_config() {
        return array(
            "header_row" => 0, "year" => (int) date("Y"),
            "carry_blank_date" => false, "skip_duplicates" => true,
            "import_without_date" => true, "fix_swapped_dates" => true,
            "default_status" => "Planned",
            "special_ignore" => array("regular design", "regular", "regular post"),
            "set_targets_from_counts" => false,
            "mapping" => array(), "merges" => array(), "leave_as_text" => array()
        );
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

        foreach (array("carry_blank_date", "skip_duplicates", "import_without_date", "fix_swapped_dates", "set_targets_from_counts") as $flag) {
            if (isset($config[$flag])) {
                $clean[$flag] = filter_var($config[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($config["default_status"]) && is_string($config["default_status"])) {
            $clean["default_status"] = $this->cut(trim($config["default_status"]), 60);
        }

        if (isset($config["special_ignore"])) {
            $list = $config["special_ignore"];
            if (is_string($list)) {
                $list = preg_split('/[\r\n,;|]+/u', $list);
            }

            if (is_array($list)) {
                $clean["special_ignore"] = array();
                foreach ($list as $item) {
                    $item = $this->lower(trim(preg_replace('/\s+/u', " ", (string) $item)));
                    if ($item !== "" && count($clean["special_ignore"]) < 100) {
                        $clean["special_ignore"][] = $this->cut($item, 120);
                    }
                }
            }
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

        //accepted / rejected value merges: target key => (lowercase value => true | false | "other value")
        if (isset($config["merges"]) && is_array($config["merges"])) {
            foreach ($config["merges"] as $target_key => $list) {
                if (!is_array($list) || !is_string($target_key)) {
                    continue;
                }

                foreach ($list as $from => $to) {
                    if (!is_string($from) || count($clean["merges"]) > 500) {
                        continue;
                    }

                    if (is_string($to) && !in_array(strtolower($to), array("true", "false", "1", "0"), true)) {
                        $clean["merges"][$target_key][$this->lower($from)] = $this->cut(trim($to), 60);
                    } else {
                        $clean["merges"][$target_key][$this->lower($from)] = filter_var($to, FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }
        }

        if (isset($config["leave_as_text"]) && is_array($config["leave_as_text"])) {
            foreach ($config["leave_as_text"] as $target_key => $flag) {
                if (is_string($target_key)) {
                    $clean["leave_as_text"][$target_key] = filter_var($flag, FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        return $clean;
    }

    private function core_definitions() {
        return array(
            "date" => array("label" => "Date", "input_type" => "date", "options" => array()),
            "type" => array("label" => "Content Type", "input_type" => "select", "options" => array("Design", "Reel", "Story", "Carousel")),
            "title" => array("label" => "Title", "input_type" => "text", "options" => array()),
            "status" => array("label" => "Status", "input_type" => "select", "options" => array("Planned", "In design", "Scheduled", "Posted"))
        );
    }

    public function virtual_core_columns() {
        $definitions = $this->core_definitions();
        try {
            $definitions = $this->columns_model()->get_core_definitions();
        } catch (\Throwable $e) {
            //no database (command line checks): the built-in copy of the 4 core columns is used
        }

        $columns = array();
        $sort = 1;
        foreach ($definitions as $key => $def) {
            $columns[] = (object) array("id" => 0, "field_key" => $key, "label" => $def["label"], "input_type" => $def["input_type"], "options_list" => $def["options"], "is_core" => 1, "is_visible" => 1, "sort_order" => $sort++);
        }

        return $columns;
    }

    /* ================================================================== */
    /* sheet layout: header, section titles, repeated headers, columns     */
    /* ================================================================== */

    private function empty_plan() {
        return array(
            "ok" => true, "error" => "", "warnings" => array(),
            "header_row" => 0, "detected_header_row" => 0,
            "columns" => array(), "items" => array(), "skipped" => array(),
            "new_columns" => array(), "option_additions" => array(), "new_options" => array(),
            "stats" => array("header_repeats" => 0, "notes" => 0, "locked_option_cells" => 0, "invalid_cells" => 0, "truncated_cells" => 0, "locked_columns" => array(), "data_rows" => 0),
            "date_min" => "", "date_max" => "", "months" => array(), "months_new" => array(),
            "rows_total" => 0, "options_created" => 0, "can_import" => false, "labels" => array(), "preview_rows" => array(),
            "sheets" => array(), "special_days" => array(), "special_days_locked" => false, "special_days_to_write" => array(),
            "counts" => array("imported" => 0, "without_date" => 0, "special_days_written" => 0, "skipped" => 0, "new_columns" => 0, "new_options" => 0, "months_added" => 0, "empty_date_rows" => 0, "swap_flags" => 0),
            "swap_flags" => array("count" => 0, "examples" => array(), "fixed" => false),
            "out_of_range" => array("count" => 0, "examples" => array()),
            "value_cleanup" => array(), "header_counts" => array(), "targets_to_set" => array(), "clients" => array()
        );
    }

    private function plan_error($plan, $message) {
        $plan["ok"] = false;
        $plan["error"] = $message;
        $plan["can_import"] = false;
        return $plan;
    }

    private function is_repeat_header($cells, $header_canon) {
        $non_empty = 0;
        $matches = 0;
        foreach ($cells as $i => $cell) {
            if ($cell === "") {
                continue;
            }

            $non_empty++;
            if (isset($header_canon[$i]) && $header_canon[$i] !== "" && $this->canon_header($cell) === $header_canon[$i]) {
                $matches++;
            }
        }

        return $matches >= 2 && $matches >= $non_empty * 0.6;
    }

    /**
     * Header, section titles, repeated headers and column values of one sheet. Nothing depends on the database here.
     *
     * @return array ok, error, header_row, detected_header_row, header_cells, ncols, data (row, cells, ctx_month, ctx_year), info (per column), pre (client / counts), stats
     */
    public function analyze_layout($rows, $cfg, $sheet_name = "") {
        $layout = array(
            "ok" => false, "error" => "", "header_row" => 0, "detected_header_row" => 0, "header_cells" => array(), "ncols" => 0,
            "data" => array(), "info" => array(), "stats" => array("header_repeats" => 0, "notes" => 0, "data_rows" => 0),
            "pre" => array("client_name" => "", "client_source" => "", "counts" => array(), "ctx_month" => 0, "ctx_year" => 0), "sections" => array()
        );

        $detected = $this->detect_header_row($rows);
        $layout["detected_header_row"] = $detected;
        $header_row = $cfg["header_row"] > 0 ? $cfg["header_row"] : $detected;
        $layout["header_row"] = $header_row;
        if (!$header_row) {
            $layout["error"] = cc_lang("cc_imp_no_header");
            return $layout;
        }

        $header_cells = null;
        $data_rows = array();
        $pre_rows = array();
        foreach ($rows as $pair) {
            if ($pair[0] == $header_row) {
                $header_cells = $pair[1];
            } else if ($pair[0] > $header_row) {
                $data_rows[] = $pair;
            } else {
                $pre_rows[] = $pair;
            }
        }

        if ($header_cells === null) {
            $layout["error"] = sprintf(cc_lang("cc_imp_header_row_empty"), $header_row);
            return $layout;
        }

        if (count($data_rows) > self::MAX_ROWS) {
            $layout["error"] = sprintf(cc_lang("cc_imp_err_rows"), number_format(self::MAX_ROWS), number_format(count($data_rows)));
            return $layout;
        }

        $layout["header_cells"] = $header_cells;

        /* ---- above the header: client name, monthly counts, month title ---- */
        $client = $this->detect_client($rows, $sheet_name);
        $layout["pre"]["client_name"] = $client["name"];
        $layout["pre"]["client_source"] = $client["source"];
        $pre_ctx_month = 0;
        $pre_ctx_year = 0;
        foreach ($pre_rows as $pair) {
            foreach ($pair[1] as $cell) {
                if ($cell === "") {
                    continue;
                }

                foreach ($this->extract_header_counts($cell) as $label => $count) {
                    if (!isset($layout["pre"]["counts"][$label])) {
                        $layout["pre"]["counts"][$label] = $count;
                    }
                }
            }

            $texts = array_values(array_filter($pair[1], function ($cell) {
                return $cell !== "";
            }));
            if (count($texts) === 1 && $this->is_section_title($texts[0])) {
                list($m, $y) = $this->month_context($texts[0]);
                if ($m) {
                    $pre_ctx_month = $m;
                    $pre_ctx_year = $y ? $y : $pre_ctx_year;
                }
            }
        }

        $layout["pre"]["ctx_month"] = $pre_ctx_month;
        $layout["pre"]["ctx_year"] = $pre_ctx_year;

        /* ---- columns ---- */
        $ncols = count($header_cells);
        foreach ($data_rows as $pair) {
            $ncols = max($ncols, count($pair[1]));
        }
        $ncols = min($ncols, self::MAX_COLUMNS);
        $layout["ncols"] = $ncols;

        $header_canon = array();
        for ($i = 0; $i < $ncols; $i++) {
            $header_canon[$i] = isset($header_cells[$i]) ? $this->canon_header($header_cells[$i]) : "";
        }

        /* ---- rows ---- */
        $values = array_fill(0, max(1, $ncols), array());
        $data = array();
        $ctx_month = $pre_ctx_month;
        $ctx_year = $pre_ctx_year;
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

            if ($this->is_repeat_header($cells, $header_canon)) {
                $layout["stats"]["header_repeats"]++;
                continue;
            }

            if ($non_empty === 1 && $this->is_section_title($only)) {
                $layout["stats"]["notes"]++;
                list($m, $y) = $this->month_context($only);
                if ($m) {
                    $ctx_month = $m;
                    $ctx_year = $y ? $y : $ctx_year;
                } else if ($y) {
                    $ctx_year = $y;
                }

                $layout["sections"][] = array("row" => $pair[0], "text" => $this->cut($only, 60), "month" => $m, "year" => $y);
                continue;
            }

            $data[] = array($pair[0], $cells, $ctx_month, $ctx_year);
            for ($i = 0; $i < $ncols; $i++) {
                if (isset($cells[$i]) && $cells[$i] !== "") {
                    $values[$i][] = $cells[$i];
                }
            }
        }

        $layout["data"] = $data;
        $layout["stats"]["data_rows"] = count($data);

        /* ---- column info ---- */
        $info = array();
        $seen = array();
        $role_canons = array("date", "title", "contenttype", "status", "specialday");
        for ($i = 0; $i < $ncols; $i++) {
            $raw_header = isset($header_cells[$i]) ? $this->clean_header($header_cells[$i]) : "";
            $is_date_head = $raw_header !== "" && $this->is_date_header($raw_header);
            if (!count($values[$i]) && !$is_date_head) {
                continue; //entirely empty column
            }

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

            $raw_norm = $this->norm($raw_header);
            $canon = $this->canon_header($raw_header);
            $has_header = $raw_header !== "";
            if (!$has_header) {
                $gkey = "column" . strtolower($this->col_letter($i));
            } else if (in_array($canon, $role_canons, true)) {
                $gkey = $raw_norm; //a second "Topic" column is its own column, not the title again
            } else {
                $gkey = $canon;
            }

            $sample_values = count($values[$i]) > 400 ? array_slice($values[$i], 0, 400) : $values[$i];
            $info[$i] = array(
                "index" => $i, "letter" => $this->col_letter($i), "header" => $label, "has_header" => $has_header,
                "raw_norm" => $raw_norm, "canon" => $canon, "gkey" => $gkey,
                "samples" => $samples, "values" => $values[$i], "count" => count($values[$i]),
                "detected_type" => count($sample_values) ? $this->detect_column_type($sample_values) : "text",
                "weekday_only" => count($values[$i]) ? $this->is_weekday_only_column($values[$i]) : false
            );
        }

        $layout["info"] = $info;
        $layout["ok"] = true;
        return $layout;
    }

    /* ================================================================== */
    /* column mapping                                                      */
    /* ================================================================== */

    private function new_map($col) {
        return array("target" => "new", "type" => $col["detected_type"], "label" => $col["header"], "gkey" => $col["gkey"]);
    }

    /**
     * Auto mapping of the header columns of one sheet.
     *
     * @param array $info per column index: header, canon, gkey, values, detected_type ...
     * @param array $existing_custom custom columns of the calendar by canonical label
     * @return array index => array(target, type, label, gkey)
     */
    private function auto_map($info, $existing_custom, $year) {
        $map = array();
        $roles = array("date" => null, "special_day" => null, "type" => null, "status" => null, "title" => null);
        $role_canon = array("date" => "date", "special_day" => "specialday", "type" => "contenttype", "status" => "status", "title" => "title");
        $title_priority = array("title" => 1, "topictitle" => 2, "topic" => 3, "posttitle" => 4, "subject" => 5, "postname" => 6, "aifeedtopic" => 7);

        foreach (array_keys($roles) as $role) {
            $best = null;
            $best_score = PHP_INT_MAX;
            foreach ($info as $i => $col) {
                if (isset($map[$i])) {
                    continue;
                }

                if ($col["canon"] !== $role_canon[$role] && $this->fuzzy_role_canon($col["raw_norm"]) !== $role_canon[$role]) {
                    continue;
                }

                $score = $i;
                if ($role === "title") {
                    $score = (isset($title_priority[$col["raw_norm"]]) ? $title_priority[$col["raw_norm"]] : 8) * 1000 + $i;
                }

                if (!$col["count"]) {
                    $score += 100000; //a date column without values is the last choice
                }

                if ($score < $best_score) {
                    $best_score = $score;
                    $best = $i;
                }
            }

            if ($best !== null) {
                $map[$best] = array("target" => $role, "type" => "", "label" => "", "gkey" => $info[$best]["gkey"]);
                $roles[$role] = $best;
            }
        }

        //no header says "date": the column whose values look most like dates
        if ($roles["date"] === null) {
            $best = 0;
            $date_index = null;
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

            if ($date_index !== null) {
                $map[$date_index] = array("target" => "date", "type" => "", "label" => "", "gkey" => $info[$date_index]["gkey"]);
            }
        }

        //Week / Day / Publishing Day (and empty headers that only hold weekday names or dates): Skip by default
        $weekday_heads = $this->weekday_headers();
        foreach ($info as $i => $col) {
            if (isset($map[$i])) {
                continue;
            }

            $is_weekday_header = in_array($col["raw_norm"], $weekday_heads, true);
            if (!$is_weekday_header && $col["has_header"]) {
                continue;
            }

            //Named Week/Day/Publishing Day columns always default to Skip (user can remap).
            //Blank-header columns skip only when they look like weekdays or dates.
            if ($is_weekday_header || $col["weekday_only"] || ($col["count"] && $this->date_ratio($col["values"], $year) >= 0.9)) {
                $map[$i] = array("target" => "skip", "type" => "", "label" => "", "gkey" => $col["gkey"]);
            }
        }

        $used_existing = array();
        foreach ($info as $i => $col) {
            if (isset($map[$i])) {
                continue;
            }

            $existing = null;
            if ($col["has_header"] && $col["gkey"] !== "") {
                if (isset($existing_custom[$col["gkey"]])) {
                    $existing = $existing_custom[$col["gkey"]];
                } else {
                    foreach ($existing_custom as $canon => $column) {
                        if ($this->near_names($col["gkey"], $canon)) {
                            $existing = $column;
                            break;
                        }
                    }
                }
            }

            if ($existing && !isset($used_existing[$existing->field_key])) {
                $used_existing[$existing->field_key] = true;
                $map[$i] = array("target" => "col:" . $existing->field_key, "type" => "", "label" => "", "gkey" => $col["gkey"]);
                continue;
            }

            $map[$i] = $this->new_map($col);
        }

        return $map;
    }

    /**
     * Automatic mapping plus the choices the user made on the review screen (by column index).
     */
    private function map_sheet($info, $cfg, $existing_custom, $by_key, &$warnings) {
        $map = $this->auto_map($info, $existing_custom, (int) $cfg["year"]);
        $valid_new_types = array("text", "long", "select", "multi", "date", "link", "number", "check");
        $role_targets = array("date", "type", "title", "status", "special_day");
        $overridden = array();

        foreach ($cfg["mapping"] as $i => $override) {
            if (!isset($info[$i])) {
                continue;
            }

            $target = $override["target"];
            if ($target === "skip" || $target === "new" || in_array($target, $role_targets, true)) {
                if (in_array($target, $role_targets, true)) {
                    //the column the user picked wins; an automatic choice for the same field becomes a normal new column
                    foreach ($map as $j => $m) {
                        if ($j !== $i && $m["target"] === $target && !isset($overridden[$j])) {
                            $map[$j] = $this->new_map($info[$j]);
                        }
                    }
                }

                $map[$i] = array("target" => $target, "type" => "", "label" => "", "gkey" => $info[$i]["gkey"]);
                if ($target === "new") {
                    $map[$i]["type"] = in_array($override["type"], $valid_new_types, true) ? $override["type"] : $info[$i]["detected_type"];
                    $map[$i]["label"] = $override["label"] !== "" ? $override["label"] : $info[$i]["header"];
                }

                $overridden[$i] = true;
            } else if (strpos($target, "col:") === 0) {
                $field_key = substr($target, 4);
                if (isset($by_key[$field_key]) && !$by_key[$field_key]->is_core) {
                    $map[$i] = array("target" => $target, "type" => "", "label" => "", "gkey" => $info[$i]["gkey"]);
                    $overridden[$i] = true;
                }
            }
        }

        //every field / existing column only once (the first column wins)
        $taken = array();
        ksort($map);
        foreach ($map as $i => $m) {
            if ($m["target"] === "skip" || $m["target"] === "new") {
                continue;
            }

            if (isset($taken[$m["target"]])) {
                $warnings[] = sprintf(cc_lang("cc_imp_warn_twice"), $info[$i]["header"]);
                $map[$i] = array("target" => "skip", "type" => "", "label" => "", "gkey" => $m["gkey"]);
                continue;
            }

            $taken[$m["target"]] = true;
        }

        return $map;
    }

    /* ================================================================== */
    /* targets (one object per destination column, shared by all sheets)   */
    /* ================================================================== */

    private function make_target($role, $key, $ref, $label, $type, $options_list, $can_add, $is_new, $locked) {
        $options = array();
        foreach ((array) $options_list as $option) {
            $options[$this->lower($option)] = $option;
        }

        return (object) array(
            "role" => $role, "key" => $key, "ref" => $ref, "label" => $label, "type" => $type,
            "options" => $options, "added" => array(), "can_add" => $can_add, "locked_values" => array(),
            "locked" => $locked, "is_new" => $is_new, "detected" => array(), "sources" => array(),
            "samples" => array(), "counts" => array(), "display" => array(), "merge" => array(),
            "suggestions" => array(), "new_distinct" => 0, "over_limit" => false, "as_text" => false, "companion" => null, "used" => false
        );
    }

    private function unique_label($label, &$taken) {
        $label = $this->cut($label, 60);
        $lower = $this->lower($label);
        if (isset($taken[$lower])) {
            $n = 2;
            do {
                $suffix = " " . $n;
                $try = $this->cut($label, 60 - strlen($suffix)) . $suffix;
                $n++;
            } while (isset($taken[$this->lower($try)]));
            $label = $try;
            $lower = $this->lower($label);
        }

        $taken[$lower] = true;
        return $label;
    }

    //columns of other sheets with (almost) the same header are one column
    private function resolve_gkey($gkey, &$known) {
        if (isset($known[$gkey])) {
            return $gkey;
        }

        foreach (array_keys($known) as $other) {
            if ($this->near_names($gkey, $other)) {
                return $other;
            }
        }

        $known[$gkey] = true;
        return $gkey;
    }

    private function final_new_type($detected) {
        $set = array_values(array_unique($detected));
        if (!count($set)) {
            return "text";
        }

        if (count($set) === 1) {
            return $set[0];
        }

        $diff = array_diff($set, array("text", "long"));
        if (!count($diff)) {
            return "long";
        }

        $diff = array_diff($set, array("select", "multi"));
        if (!count($diff)) {
            return "multi";
        }

        return in_array("long", $set, true) ? "long" : "text";
    }

    /**
     * Connect the columns of one sheet to destination targets, creating them in the registry when the sheet is the first to need them.
     */
    private function resolve_sheet_targets(&$state, &$reg, $ctx) {
        $info = $state["layout"]["info"];
        $map = $state["map"];
        $state["cols"] = array();
        $state["date_index"] = null;
        $state["special_index"] = null;
        $state["title_index"] = null;
        $state["alt"] = array();
        $used_gkeys = array();
        $weekday_heads = $this->weekday_headers();

        foreach ($map as $i => $m) {
            $target = $m["target"];
            if ($target === "skip") {
                continue;
            }

            if (in_array($target, array("date", "title", "special_day"), true)) {
                if (!isset($reg["roles"][$target])) {
                    $reg["roles"][$target] = $this->make_target($target, $target, $target, $target, $target === "date" ? "date" : "text", array(), false, false, false);
                }

                $state["cols"][$i] = $reg["roles"][$target];
                if ($target === "date") {
                    $state["date_index"] = $i;
                } else if ($target === "special_day") {
                    $state["special_index"] = $i;
                } else {
                    $state["title_index"] = $i;
                }
            } else if ($target === "type" || $target === "status") {
                if (!isset($reg["roles"][$target])) {
                    $column = $ctx["by_key"][$target];
                    $reg["roles"][$target] = $this->make_target($target, $target, $target, $column->label, $column->input_type, $column->options_list, $ctx["perm_options"], false, false);
                    $reg["all"][] = $reg["roles"][$target];
                }

                $state["cols"][$i] = $reg["roles"][$target];
            } else if (strpos($target, "col:") === 0) {
                $state["cols"][$i] = $this->existing_target($reg, $ctx, substr($target, 4));
            } else {
                $label = $m["label"] !== "" ? $m["label"] : $info[$i]["header"];
                $label_lower = $this->lower($label);
                if (isset($ctx["by_label"][$label_lower]) && !$ctx["by_label"][$label_lower]->is_core) {
                    $column = $ctx["by_label"][$label_lower];
                    $state["cols"][$i] = $this->existing_target($reg, $ctx, $column->field_key);
                    $state["map"][$i] = array("target" => "col:" . $column->field_key, "type" => "", "label" => "", "gkey" => $m["gkey"]);
                    $reg["warnings"][] = sprintf(cc_lang("cc_imp_warn_exists"), $column->label);
                    continue;
                }

                $gkey = $this->resolve_gkey($m["gkey"], $reg["known"]);
                if (isset($used_gkeys[$gkey])) {
                    $used_gkeys[$gkey]++;
                    $gkey .= "#" . $used_gkeys[$gkey];
                } else {
                    $used_gkeys[$gkey] = 1;
                }

                if (!isset($reg["new"][$gkey])) {
                    if ($m["label"] === "") {
                        $label = $this->canon_label($info[$i]["canon"], $label);
                    }

                    $label = $this->unique_label($label, $reg["labels"]);
                    $reg["new"][$gkey] = $this->make_target("new", "new:" . $gkey, "new:" . $gkey, $label, "text", array(), true, true, !$ctx["perm_admin"]);
                    $reg["all"][] = $reg["new"][$gkey];
                    if ($reg["new"][$gkey]->locked) {
                        $reg["locked_columns"][] = $label;
                    }
                }

                $new = $reg["new"][$gkey];
                $new->detected[] = in_array($m["type"], array("text", "long", "select", "multi", "date", "link", "number", "check"), true) ? $m["type"] : "text";
                $new->sources[] = array($state["index"], $i);
                $state["cols"][$i] = $new;
                $state["map"][$i]["label"] = $new->label;
                $state["map"][$i]["ref"] = $new->key;
            }
        }

        //columns that may hold the date of a row when the date column is empty: Week / Day / blank header
        foreach ($info as $i => $col) {
            if (isset($map[$i]) && in_array($map[$i]["target"], array("date", "title", "type", "status", "special_day"), true)) {
                continue;
            }

            if (!$col["has_header"] || in_array($col["raw_norm"], $weekday_heads, true)) {
                $state["alt"][] = $i;
            }
        }

        //cells that count as "content" of a row, and where a title can come from
        $state["content_idx"] = array();
        $state["first_pass"] = array();
        $state["second_pass"] = array();
        $state["third_pass"] = array();
        ksort($state["cols"]);
        foreach ($state["cols"] as $i => $t) {
            if (in_array($t->role, array("date", "special_day"), true)) {
                continue;
            }

            if ($t->role !== "status") {
                $state["content_idx"][] = $i;
            }

            if ($t->locked || $t->role === "title") {
                continue;
            }

            if ($t->role === "type") {
                $state["second_pass"][] = $i;
            } else if ($t->role !== "status") {
                $state["first_pass"][] = $i;
            }
        }

        $state["third_pass"] = array_merge($state["first_pass"], $state["second_pass"]);
    }

    private function existing_target(&$reg, $ctx, $field_key) {
        $key = "col:" . $field_key;
        if (!isset($reg["cols"][$key])) {
            $column = $ctx["by_key"][$field_key];
            $reg["cols"][$key] = $this->make_target("col", $key, $field_key, $column->label, $column->input_type, $column->options_list, $ctx["perm_options"], false, false);
            $reg["all"][] = $reg["cols"][$key];
        }

        return $reg["cols"][$key];
    }

    /* ================================================================== */
    /* select / multi values: distinct values, merges, too many options    */
    /* ================================================================== */

    private function tokens_of($t, $raw) {
        $raw = (string) $raw;
        if ($raw === "") {
            return array();
        }

        if ($t->type === "multi") {
            $whole = $this->lower(trim($raw));
            $tokens = isset($t->options[$whole]) ? array(trim($raw)) : $this->split_tokens($raw);
        } else {
            $tokens = array($raw);
        }

        $clean = array();
        foreach ($tokens as $token) {
            $token = trim(preg_replace('/\s+/u', " ", $token));
            if ($token !== "") {
                $clean[] = $token;
            }
        }

        return $clean;
    }

    /**
     * Count the distinct values of a select / multi target (case-insensitive), suggest merges of near-equal values,
     * and decide when there are too many new options (then the values stay text).
     */
    private function prepare_option_target($t, $cfg, &$reg, $ctx) {
        $freq = array();
        foreach ($t->samples as $raw) {
            foreach ($this->tokens_of($t, $raw) as $token) {
                if ($this->len($token) > 60) {
                    continue;
                }

                $key = $this->lower($token);
                if (!isset($freq[$key])) {
                    $freq[$key] = array();
                }

                $freq[$key][$token] = (isset($freq[$key][$token]) ? $freq[$key][$token] : 0) + 1;
            }
        }

        $counts = array();
        $display = array();
        foreach ($freq as $key => $variants) {
            arsort($variants);
            $counts[$key] = array_sum($variants);
            if (isset($t->options[$key])) {
                $display[$key] = $t->options[$key];
            } else {
                //Prefer a readable casing: "POSTED"/"posted" → "Posted" when every seen form is all-upper or all-lower
                $best = (string) array_key_first($variants);
                $all_flat = true;
                foreach (array_keys($variants) as $variant) {
                    if ($variant !== $this->lower($variant) && $variant !== mb_strtoupper($variant, "UTF-8")) {
                        $all_flat = false;
                        break;
                    }
                }

                if ($all_flat && preg_match('/^[a-z]+(?:[ \\-][a-z]+)*$/i', $best)) {
                    $display[$key] = mb_convert_case($this->lower($best), MB_CASE_TITLE, "UTF-8");
                } else {
                    $display[$key] = $best;
                }
            }
        }

        $t->counts = $counts;
        $t->display = $display;

        //suggested merges
        $universe = array_unique(array_merge(array_keys($counts), array_keys($t->options)));
        $suggestions = array();
        $merge = array();
        $overrides = isset($cfg["merges"][$t->key]) ? $cfg["merges"][$t->key] : array();
        if (count($universe) <= 400) {
            sort($universe);
            $n = count($universe);
            $norms = array();
            foreach ($universe as $value) {
                $norms[$value] = $this->norm($value);
            }

            for ($a = 0; $a < $n; $a++) {
                for ($b = $a + 1; $b < $n; $b++) {
                    $x = $universe[$a];
                    $y = $universe[$b];
                    if ($norms[$x] === "" || $norms[$y] === "") {
                        continue;
                    }

                    $reason = "";
                    if ($norms[$x] === $norms[$y]) {
                        $reason = "same";
                    } else if ($this->near_names($x, $y)) {
                        $reason = "similar";
                    }

                    if ($reason === "") {
                        continue;
                    }

                    $x_existing = isset($t->options[$x]);
                    $y_existing = isset($t->options[$y]);
                    if ($x_existing && $y_existing) {
                        continue;
                    }

                    if ($x_existing || $y_existing) {
                        $to = $x_existing ? $x : $y;
                        $from = $x_existing ? $y : $x;
                    } else {
                        $cx = isset($counts[$x]) ? $counts[$x] : 0;
                        $cy = isset($counts[$y]) ? $counts[$y] : 0;
                        if ($cx === $cy) {
                            $to = strlen($x) <= strlen($y) ? $x : $y;
                        } else {
                            $to = $cx > $cy ? $x : $y;
                        }
                        $from = $to === $x ? $y : $x;
                    }

                    if (!isset($counts[$from])) {
                        continue; //nothing to merge: the value does not occur in the file
                    }

                    $accept = $reason === "same";
                    $custom_to = "";
                    if (array_key_exists($from, $overrides)) {
                        if (is_string($overrides[$from])) {
                            $accept = true;
                            $custom_to = $overrides[$from];
                        } else {
                            $accept = (bool) $overrides[$from];
                        }
                    }

                    $target_value = $custom_to !== "" ? $custom_to : $display[$to] ?? ($t->options[$to] ?? $to);
                    $suggestions[] = array(
                        "from" => isset($display[$from]) ? $display[$from] : $from, "from_key" => $from,
                        "to" => $target_value, "count_from" => $counts[$from], "count_to" => isset($counts[$to]) ? $counts[$to] : 0,
                        "reason" => $reason, "accept" => $accept
                    );

                    if ($accept) {
                        $merge[$from] = $this->lower($target_value);
                        if ($custom_to !== "" && !isset($display[$merge[$from]])) {
                            $display[$merge[$from]] = $custom_to;
                        }
                    }

                    if (count($suggestions) >= 200) {
                        break 2;
                    }
                }
            }
        }

        //explicit merges that were not suggested
        foreach ($overrides as $from => $to) {
            if (is_string($to) && isset($counts[$from]) && !isset($merge[$from]) && $this->lower($to) !== $from) {
                $merge[$from] = $this->lower($to);
                if (!isset($display[$merge[$from]])) {
                    $display[$merge[$from]] = $to;
                }
            }
        }

        //follow chains a -> b -> c
        foreach ($merge as $from => $to) {
            $guard = 0;
            while (isset($merge[$to]) && $merge[$to] !== $to && $guard++ < 5) {
                $to = $merge[$to];
            }

            $merge[$from] = $to;
            if ($to === $from) {
                unset($merge[$from]);
            }
        }

        $t->merge = $merge;
        $t->display = $display;
        $t->suggestions = $suggestions;

        $new = array();
        foreach ($counts as $key => $n) {
            $final = isset($merge[$key]) ? $merge[$key] : $key;
            if (!isset($t->options[$final])) {
                $new[$final] = true;
            }
        }

        $t->new_distinct = count($new);
        if ($t->new_distinct > self::WARN_NEW_OPTIONS) {
            $t->over_limit = true;
            $leave = isset($cfg["leave_as_text"][$t->key]) ? $cfg["leave_as_text"][$t->key] : true;
            $t->as_text = (bool) $leave;
            $reg["warnings"][] = sprintf(cc_lang($t->as_text ? "cc_imp_warn_many_options_text" : "cc_imp_warn_many_options_keep"), $t->label, $t->new_distinct);
        }
    }

    /* ================================================================== */
    /* values                                                              */
    /* ================================================================== */

    private function empty_value($value) {
        return $value === "" || $value === null || $value === false || (is_array($value) && !count($value));
    }

    /**
     * Validate and convert one cell for a target column (types, option membership, dates, URL scheme, lengths).
     *
     * @param string $leftover receives the original text of values that stay out of a select column (as text), see prepare_option_target()
     * @return mixed "" for no value (cell left empty), a string, true, or an array (multi)
     */
    private function convert_value($t, $raw, &$stats, $year, $ctx_month, &$leftover = "") {
        $raw = (string) $raw;
        $leftover = "";
        if ($raw === "") {
            return "";
        }

        $type = $t->type;

        if ($type === "select" || $type === "multi") {
            $result = array();
            $left = array();
            foreach ($this->tokens_of($t, $raw) as $token) {
                if ($this->len($token) > 60) {
                    $stats["invalid_cells"]++;
                    continue;
                }

                $key = $this->lower($token);
                if (isset($t->merge[$key])) {
                    $key = $t->merge[$key];
                }

                if (isset($t->options[$key])) {
                    $result[] = $t->options[$key];
                } else if ($t->as_text) {
                    $left[] = $token;
                } else if ($t->can_add) {
                    $text = isset($t->display[$key]) ? $t->display[$key] : $token;
                    $t->options[$key] = $text;
                    $t->added[] = $text;
                    $result[] = $text;
                } else {
                    $stats["locked_option_cells"]++;
                    if (count($t->locked_values) < 30) {
                        $t->locked_values[$key] = $token;
                    }
                }
            }

            if (count($left)) {
                $leftover = $this->cut(implode(", ", $left), 500);
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

    //special day text without placeholders ("Regular Design", ".", "-")
    private function clean_special($text, $ignore) {
        $text = trim(preg_replace('/\s+/u', " ", (string) $text));
        if ($text === "" || !preg_match('/[\p{L}\p{N}]/u', $text)) {
            return "";
        }

        $weekday_token = '(?:monday|mon|tuesday|tue|tues|wednesday|wed|thursday|thu|thur|thurs|friday|fri|saturday|sat|sunday|sun)\.?';

        // 1. If text is only a weekday name (case-insensitive, optional trailing dot)
        if (preg_match('/^\s*' . $weekday_token . '\s*$/ui', $text)) {
            return "";
        }

        // 2. If the cell holds a real special day plus a weekday (separated by / , | or a line break), drop the weekday part
        $changed = true;
        while ($changed) {
            $before = $text;
            $text = preg_replace('/\s*[\/|,\r\n]\s*' . $weekday_token . '\s*$/ui', '', $text);
            $text = preg_replace('/^\s*' . $weekday_token . '\s*[\/|,\r\n]\s*/ui', '', $text);
            $text = preg_replace('/([\/|,\r\n])\s*' . $weekday_token . '\s*([\/|,\r\n])/ui', '$1', $text);
            $changed = ($before !== $text);
        }
        $text = trim($text);

        if ($text === "" || preg_match('/^\s*' . $weekday_token . '\s*$/ui', $text)) {
            return "";
        }

        // Built-in weekday words ignored in addition to custom ignores
        static $builtin_weekdays = array(
            'monday' => true, 'mon' => true,
            'tuesday' => true, 'tue' => true, 'tues' => true,
            'wednesday' => true, 'wed' => true,
            'thursday' => true, 'thu' => true, 'thur' => true, 'thurs' => true,
            'friday' => true, 'fri' => true,
            'saturday' => true, 'sat' => true,
            'sunday' => true, 'sun' => true
        );

        $lower_no_dot = $this->lower(rtrim(trim($text), '.'));
        if (isset($builtin_weekdays[$lower_no_dot])) {
            return "";
        }

        if (isset($ignore[$this->lower($text)]) || isset($ignore[$lower_no_dot])) {
            return "";
        }

        return $this->cut($text, 120);
    }

    //2026-01-07 read the wrong way round: the same text as 2026-07-01. null when the date can not be swapped
    private function swapped_date($date) {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        $d = (int) substr($date, 8, 2);
        if ($d > 12 || $d === $m) {
            return null;
        }

        return $this->valid_ymd($y, $d, $m);
    }

    private function month_of($date) {
        return (int) substr($date, 5, 2);
    }

    //row date: the mapped date column, else a date in a Week / Day / blank-header column of the same row
    public function resolve_row_date($cells, $date_index, $alt_indexes, $default_year, $ctx_month, &$src_text = "", &$month_hint = 0, &$used_index = -1) {
        $src_text = "";
        $month_hint = 0;
        $used_index = -1;

        $text = ($date_index !== null && isset($cells[$date_index])) ? trim($cells[$date_index]) : "";
        if ($text !== "" && !$this->is_blank_date_text($text)) {
            $date = $this->parse_date($text, $default_year, $ctx_month, true);
            if ($date !== null) {
                $used_index = $date_index;
                return $date;
            }

            $src_text = $this->cut(preg_replace('/\s+/u', " ", $text), 120);
            $hint = $this->month_only_hint($text);
            if ($hint) {
                $month_hint = $hint;
            }
        }

        foreach ($alt_indexes as $j) {
            if ($j === $date_index || !isset($cells[$j])) {
                continue;
            }

            $other = trim($cells[$j]);
            if ($other === "" || $this->is_blank_date_text($other)) {
                continue;
            }

            $date = $this->parse_date($other, $default_year, 0, true);
            if ($date !== null) {
                $used_index = $j;
                return $date;
            }

            if (!$month_hint) {
                $hint = $this->month_only_hint($other);
                if ($hint) {
                    $month_hint = $hint;
                    if ($src_text === "") {
                        $src_text = $this->cut(preg_replace('/\s+/u', " ", $other), 120);
                    }
                }
            }
        }

        return null;
    }

    /* ================================================================== */
    /* the plan (dry run and apply share this)                             */
    /* ================================================================== */

    /**
     * Plan for ONE sheet. Same result as build_group_plan() with one sheet.
     *
     * @param array $rows sheet rows (see class comment)
     * @param array $config see normalize_config()
     * @param int $calendar_id 0 = a calendar that does not exist yet (a new client)
     * @param array $columns column objects of the calendar (options_list, field_key, label, input_type, is_core)
     * @param array $perms add, edit, admin_manage, manage_options, add_client(s), add_column(s) (bool)
     */
    public function build_plan($rows, $config, $calendar_id, $columns, $perms) {
        return $this->build_group_plan(array(array("index" => 0, "sheet" => array("name" => "", "rows" => $rows), "config" => $config)), $config, $calendar_id, $columns, $perms);
    }

    /**
     * Plan for all the sheets that go into ONE calendar (sheets of the same client are merged: columns with the same
     * normalized header are one column). Nothing is written.
     *
     * @param array $jobs list of array("index" => int, "sheet" => sheet, "config" => per sheet config (header_row, mapping, + options))
     * @param array $options global options (year, carry_blank_date, skip_duplicates, import_without_date, fix_swapped_dates,
     *                       default_status, special_ignore, set_targets_from_counts, merges, leave_as_text)
     */
    public function build_group_plan($jobs, $options, $calendar_id, $columns, $perms) {
        $calendar_id = (int) $calendar_id;
        $opts = $this->normalize_config($options);
        $plan = $this->empty_plan();
        $perm_admin = !empty($perms["add_column"]) || !empty($perms["add_columns"]) || !empty($perms["admin_manage"]);
        $perm_options = !empty($perms["manage_options"]) || !empty($perms["admin_manage"]);
        $perm_special = !empty($perms["edit"]) || !empty($perms["admin_manage"]);

        $by_key = array();
        $by_label = array();
        $existing_custom = array();
        $labels_taken = array();
        foreach ($columns as $column) {
            $by_key[$column->field_key] = $column;
            $label_lower = $this->lower(trim($column->label));
            $by_label[$label_lower] = $column;
            $labels_taken[$label_lower] = true;
            if (!$column->is_core) {
                $canon = $this->canon_header($column->label);
                if ($canon !== "" && !isset($existing_custom[$canon])) {
                    $existing_custom[$canon] = $column;
                }
            }
        }

        foreach (array("date", "type", "title", "status") as $required_key) {
            if (!isset($by_key[$required_key])) {
                return $this->plan_error($plan, cc_lang("cc_error"));
            }
        }

        $ctx = array("by_key" => $by_key, "by_label" => $by_label, "perm_admin" => $perm_admin, "perm_options" => $perm_options);
        $reg = array("roles" => array(), "cols" => array(), "new" => array(), "all" => array(), "known" => array(), "labels" => $labels_taken, "warnings" => array(), "locked_columns" => array());

        /* ---- 1. layout + mapping of every sheet ---- */
        $states = array();
        $sheet_plans = array();
        $global_keys = array("year", "carry_blank_date", "skip_duplicates", "import_without_date", "fix_swapped_dates", "default_status", "special_ignore", "set_targets_from_counts", "merges", "leave_as_text");
        foreach ($jobs as $job) {
            $sheet = $job["sheet"];
            $index = isset($job["index"]) ? (int) $job["index"] : count($sheet_plans);
            $name = isset($sheet["name"]) ? (string) $sheet["name"] : "";
            $sp = $this->empty_sheet_plan($index, $name);
            if (!empty($sheet["error"])) {
                $sp["error"] = $sheet["error"];
                $sheet_plans[$index] = $sp;
                continue;
            }

            $cfg = $this->normalize_config(isset($job["config"]) ? $job["config"] : array());
            foreach ($global_keys as $key) {
                $cfg[$key] = $opts[$key];
            }

            $layout = $this->analyze_layout($sheet["rows"], $cfg, $name);
            $sp["header_row"] = $layout["header_row"];
            $sp["detected_header_row"] = $layout["detected_header_row"];
            $sp["client_name"] = $layout["pre"]["client_name"];
            $sp["header_counts"] = $layout["pre"]["counts"];
            $sp["stats"]["header_repeats"] = $layout["stats"]["header_repeats"];
            $sp["stats"]["notes"] = $layout["stats"]["notes"];
            $sp["stats"]["data_rows"] = $layout["stats"]["data_rows"];
            if (!$layout["ok"]) {
                $sp["error"] = $layout["error"];
                $sheet_plans[$index] = $sp;
                continue;
            }

            $warnings = array();
            $map = $this->map_sheet($layout["info"], $cfg, $existing_custom, $by_key, $warnings);
            foreach ($warnings as $warning) {
                $reg["warnings"][] = $warning;
            }

            $state = array("index" => $index, "name" => $name, "cfg" => $cfg, "layout" => $layout, "map" => $map, "no_date" => true);
            foreach ($map as $m) {
                if ($m["target"] === "date") {
                    $state["no_date"] = false;
                }
            }

            $this->resolve_sheet_targets($state, $reg, $ctx);
            if ($state["no_date"]) {
                $sp["error"] = cc_lang("cc_imp_no_date_col");
            }

            $states[$index] = $state;
            $sheet_plans[$index] = $sp;
        }

        /* ---- 2. types of the new columns, options, merges ---- */
        foreach ($reg["new"] as $new) {
            if (count($new->sources)) {
                $new->type = $this->final_new_type($new->detected);
                $new->used = true;
            }
        }

        foreach ($states as $state) {
            if ($state["no_date"]) {
                continue;
            }

            foreach ($state["cols"] as $i => $t) {
                if ($t->locked || ($t->type !== "select" && $t->type !== "multi")) {
                    continue;
                }

                foreach ($state["layout"]["data"] as $entry) {
                    if (isset($entry[1][$i]) && $entry[1][$i] !== "" && count($t->samples) < 20000) {
                        $t->samples[] = $entry[1][$i];
                    }
                }
            }
        }

        $snapshot = $reg["all"];
        foreach ($snapshot as $t) {
            if (!$t->locked && ($t->type === "select" || $t->type === "multi") && count($t->samples)) {
                $this->prepare_option_target($t, $opts, $reg, $ctx);
            }
        }

        foreach ($snapshot as $t) {
            if (!$t->over_limit || !$t->as_text) {
                continue;
            }

            if ($t->is_new) {
                $t->type = "text"; //too many different values: a plain text column
                $t->merge = array();
                $t->options = array();
            } else {
                $companion = $this->make_target("new", "new:txt:" . $t->key, "new:txt:" . $t->key, $this->unique_label($t->label . " (text)", $reg["labels"]), "text", array(), true, true, !$perm_admin);
                $reg["new"][$companion->key] = $companion;
                $reg["all"][] = $companion;
                $t->companion = $companion;
                if ($companion->locked) {
                    $reg["locked_columns"][] = $companion->label;
                }
            }
        }

        /* ---- 3. rows ---- */
        $existing_keys = array();
        if ($opts["skip_duplicates"] && $calendar_id > 0) {
            $existing_keys = $this->items_model()->get_existing_keys($calendar_id);
        }

        $status_options = isset($by_key["status"]) ? $by_key["status"]->options_list : array();
        $ctx["status_options"] = $status_options;
        $ctx["perm_special"] = $perm_special;
        $ctx["existing_keys"] = $existing_keys;

        $out = array(
            "skipped" => array(), "special" => array(), "special_no_date" => 0,
            "stats" => array("locked_option_cells" => 0, "invalid_cells" => 0, "truncated_cells" => 0),
            "swap" => array("count" => 0, "examples" => array()), "range" => array("count" => 0, "examples" => array())
        );

        $all_items = array();
        foreach ($states as $index => $state) {
            if ($state["no_date"]) {
                continue;
            }

            $result = $this->process_sheet($state, $ctx, $out);
            $sheet_plans[$index]["items"] = $result["items"];
            $sheet_plans[$index]["counts"] = $result["counts"];
            foreach ($result["items"] as $item) {
                $all_items[] = $item;
            }
        }

        /* ---- 4. results ---- */
        $special_days = array();
        foreach ($out["special"] as $date => $texts) {
            $special_days[$date] = $this->cut(implode(" / ", $texts), 120);
        }
        ksort($special_days);

        $special_final = $this->merge_special_days($calendar_id, $special_days);

        $months = array();
        $date_min = "";
        $date_max = "";
        $without_date = 0;
        foreach ($all_items as $item) {
            if ($item["date"] === null) {
                $without_date++;
                continue;
            }

            $months[substr($item["date"], 0, 7)] = true;
            if ($date_min === "" || $item["date"] < $date_min) {
                $date_min = $item["date"];
            }

            if ($date_max === "" || $item["date"] > $date_max) {
                $date_max = $item["date"];
            }
        }

        foreach ($special_days as $date => $label) {
            $months[substr($date, 0, 7)] = true;
            if ($date_min === "" || $date < $date_min) {
                $date_min = $date;
            }

            if ($date_max === "" || $date > $date_max) {
                $date_max = $date;
            }
        }
        ksort($months);

        $existing_months = array();
        if ($calendar_id > 0) {
            foreach ($this->months_model()->get_for_calendar($calendar_id) as $row) {
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
        $new_option_count = 0;
        foreach ($reg["all"] as $t) {
            if ($t->locked) {
                continue;
            }

            if ($t->is_new) {
                if (!$t->used) {
                    continue;
                }

                $options = ($t->type === "select" || $t->type === "multi") ? $t->added : array();
                $new_option_count += count($options);
                $first = count($t->sources) ? $t->sources[0] : array(0, 0);
                $new_columns[] = array("ref" => $t->ref, "index" => $first[1], "sheet" => $first[0], "label" => $t->label, "type" => $t->type, "options" => $options);
            } else if (count($t->added)) {
                $option_additions[] = array("key" => $t->key, "field_key" => $t->ref, "label" => $t->label, "added" => $t->added);
                $options_created += count($t->added);
            }
        }

        $locked_columns = array_values(array_unique($reg["locked_columns"]));
        $locked_cells = $out["stats"]["locked_option_cells"];

        //sheet level: columns for the screen, preview rows
        foreach ($sheet_plans as $index => $sp) {
            $sheet_plans[$index] = $this->finish_sheet_plan($sp, isset($states[$index]) ? $states[$index] : null, $by_key);
        }

        $header_counts = array();
        foreach ($sheet_plans as $sp) {
            foreach ($sp["header_counts"] as $label => $count) {
                if (!isset($header_counts[$label]) || $count > $header_counts[$label]) {
                    $header_counts[$label] = $count;
                }
            }
        }

        $targets_to_set = array();
        if ($opts["set_targets_from_counts"]) {
            $type_options = isset($by_key["type"]) ? $by_key["type"]->options_list : array();
            foreach ($header_counts as $label => $count) {
                $targets_to_set[] = array("label" => $label, "content_type" => $this->type_for_count_label($label, $type_options), "count" => $count);
            }
        }

        $skipped_total = count($out["skipped"]);
        $empty_date_rows = 0;
        $special_only_rows = 0;
        foreach ($sheet_plans as $sp) {
            $empty_date_rows += $sp["counts"]["empty_date_rows"];
            $special_only_rows += $sp["counts"]["special_only_rows"];
        }

        if ($out["swap"]["count"] > 0) {
            $reg["warnings"][] = sprintf(cc_lang($opts["fix_swapped_dates"] ? "cc_imp_warn_swapped_fixed" : "cc_imp_warn_swapped"), $out["swap"]["count"]);
        }

        if ($out["range"]["count"] > 0) {
            $reg["warnings"][] = sprintf(cc_lang("cc_imp_warn_year_range"), $out["range"]["count"]);
        }

        if ($special_final["count"] > 0 && !$perm_special) {
            $plan["special_days_locked"] = true;
        }

        $first_sheet = null;
        foreach ($sheet_plans as $sp) {
            if ($first_sheet === null || ($sp["ok"] && !$first_sheet["ok"])) {
                $first_sheet = $sp;
            }
        }

        $plan["sheets"] = $sheet_plans;
        $plan["warnings"] = array_values(array_unique($reg["warnings"]));
        if ($first_sheet) {
            $plan["header_row"] = $first_sheet["header_row"];
            $plan["detected_header_row"] = $first_sheet["detected_header_row"];
            $plan["columns"] = $first_sheet["columns"];
            $plan["labels"] = $first_sheet["labels"];
            $plan["preview_rows"] = $first_sheet["preview_rows"];
        }

        foreach ($sheet_plans as $sp) {
            $plan["stats"]["header_repeats"] += $sp["stats"]["header_repeats"];
            $plan["stats"]["notes"] += $sp["stats"]["notes"];
            $plan["stats"]["data_rows"] += $sp["stats"]["data_rows"];
        }

        $plan["stats"]["locked_option_cells"] = $locked_cells;
        $plan["stats"]["invalid_cells"] = $out["stats"]["invalid_cells"];
        $plan["stats"]["truncated_cells"] = $out["stats"]["truncated_cells"];
        $plan["stats"]["locked_columns"] = $locked_columns;

        $plan["items"] = $all_items;
        $plan["skipped"] = $out["skipped"];
        $plan["new_columns"] = $new_columns;
        $plan["option_additions"] = $option_additions;
        $plan["new_options"] = $option_additions;
        $plan["options_created"] = $options_created;
        $plan["date_min"] = $date_min;
        $plan["date_max"] = $date_max;
        $plan["months"] = array_keys($months);
        $plan["months_new"] = $months_new;
        $plan["rows_total"] = count($all_items) + $skipped_total + $empty_date_rows + $special_only_rows;
        $plan["special_days"] = $special_days;
        $plan["special_days_to_write"] = $special_final["write"];
        $plan["swap_flags"] = array("count" => $out["swap"]["count"], "examples" => $out["swap"]["examples"], "fixed" => (bool) $opts["fix_swapped_dates"]);
        $plan["out_of_range"] = $out["range"];
        $plan["value_cleanup"] = $this->value_cleanup_report($reg["all"]);
        $plan["header_counts"] = $header_counts;
        $plan["targets_to_set"] = $targets_to_set;
        $plan["counts"] = array(
            "imported" => count($all_items), "without_date" => $without_date, "special_days_written" => $special_final["count"],
            "skipped" => $skipped_total, "new_columns" => count($new_columns), "new_options" => $options_created + $new_option_count,
            "months_added" => count($months_new), "empty_date_rows" => $empty_date_rows, "swap_flags" => $out["swap"]["count"],
            "special_only_rows" => $special_only_rows, "special_without_date" => $out["special_no_date"]
        );

        $usable_sheets = 0;
        foreach ($sheet_plans as $sp) {
            if ($sp["ok"]) {
                $usable_sheets++;
            }
        }

        if (!$usable_sheets) {
            $message = $first_sheet && $first_sheet["error"] !== "" ? $first_sheet["error"] : cc_lang("cc_imp_sheet_no_table");
            $plan = $this->plan_error($plan, $message);
            return $plan;
        }

        $plan["can_import"] = (count($all_items) > 0 || $special_final["count"] > 0) && !empty($perms["add"]);
        return $plan;
    }

    private function empty_sheet_plan($index, $name) {
        return array(
            "index" => $index, "name" => $name, "ok" => false, "error" => "", "client_name" => "",
            "header_row" => 0, "detected_header_row" => 0, "columns" => array(), "labels" => array(), "preview_rows" => array(),
            "items" => array(), "header_counts" => array(),
            "stats" => array("header_repeats" => 0, "notes" => 0, "data_rows" => 0),
            "counts" => array("items" => 0, "without_date" => 0, "empty_date_rows" => 0, "special_only_rows" => 0, "skipped" => 0, "swap_flags" => 0)
        );
    }

    //singular / plural / Stories => Story: the content type that a header count belongs to
    private function type_for_count_label($label, $type_options) {
        $n = $this->norm($label);
        $candidates = array($n, rtrim($n, "s"), preg_replace('/ies$/', "y", $n));
        foreach ($type_options as $option) {
            $on = $this->norm($option);
            if (in_array($on, $candidates, true) || $on . "s" === $n) {
                return $option;
            }
        }

        return $this->cut($label, 60);
    }

    /**
     * Special days to write: array("count" => dates that change, "write" => date => final label).
     * A date that already has a different text gets "existing / new".
     */
    private function merge_special_days($calendar_id, $special_days) {
        $existing = array();
        if ($calendar_id > 0 && count($special_days)) {
            foreach ($this->special_days_model()->get_details(array("calendar_id" => $calendar_id)) as $row) {
                $existing[$row->special_date] = (string) $row->label;
            }
        }

        $write = array();
        foreach ($special_days as $date => $label) {
            if (!isset($existing[$date]) || trim($existing[$date]) === "") {
                $write[$date] = $label;
                continue;
            }

            $have = $this->lower($existing[$date]);
            $parts = array_map(function ($part) {
                return $this->lower(trim($part));
            }, explode("/", $existing[$date]));
            if ($have === $this->lower($label) || in_array($this->lower($label), $parts, true)) {
                continue;
            }

            $write[$date] = $this->cut($existing[$date] . " / " . $label, 120);
        }

        return array("count" => count($write), "write" => $write);
    }

    private function value_cleanup_report($targets) {
        $report = array();
        foreach ($targets as $t) {
            if ($t->locked || !count($t->counts)) {
                continue;
            }

            $values = array();
            arsort($t->counts);
            foreach ($t->counts as $key => $count) {
                $final = isset($t->merge[$key]) ? $t->merge[$key] : $key;
                $disp = isset($t->display[$final]) ? $t->display[$final] : $final;
                $is_new = $t->is_new || in_array($disp, $t->added, true);
                $values[] = array("value" => isset($t->display[$key]) ? $t->display[$key] : $key, "count" => $count, "is_new" => $is_new, "merged_into" => $final !== $key ? (isset($t->display[$final]) ? $t->display[$final] : $final) : "");
                if (count($values) >= 60) {
                    break;
                }
            }

            $report[] = array(
                "key" => $t->key, "label" => $t->label, "type" => $t->type, "is_new_column" => $t->is_new,
                "distinct" => count($t->counts), "new_distinct" => $t->new_distinct, "over_limit" => $t->over_limit, "as_text" => $t->as_text,
                "values" => $values, "suggestions" => $t->suggestions
            );
        }

        return $report;
    }

    private function finish_sheet_plan($sp, $state, $by_key) {
        if ($state === null) {
            return $sp;
        }

        $sp["ok"] = $sp["error"] === "";
        $info = $state["layout"]["info"];
        foreach ($info as $i => $col) {
            $map = isset($state["map"][$i]) ? $state["map"][$i] : array("target" => "skip", "type" => "", "label" => "");
            $t = isset($state["cols"][$i]) ? $state["cols"][$i] : null;
            if ($t !== null && $t->role === "new" && $map["target"] === "new") {
                $map["type"] = $t->type;
                $map["label"] = $t->label;
            }

            $sp["columns"][$i] = array(
                "index" => $i, "letter" => $col["letter"], "header" => $col["header"], "samples" => $col["samples"],
                "detected_type" => $col["detected_type"], "weekday_only" => $col["weekday_only"],
                "map" => array("target" => $map["target"], "type" => isset($map["type"]) ? $map["type"] : "", "label" => isset($map["label"]) ? $map["label"] : ""),
                "locked" => $t !== null ? $t->locked : false,
                "new_options" => ($t !== null && !$t->is_new && !$t->locked) ? $t->added : array(),
                "locked_values" => ($t !== null && !$t->is_new) ? array_values($t->locked_values) : array(),
                "options_locked" => $t !== null && !$t->is_new && !$t->can_add && count($t->locked_values) > 0
            );
        }

        if (!$sp["ok"]) {
            return $sp;
        }

        //preview table: the first rows after mapping, with the target column names
        $labels = array($by_key["date"]->label, $by_key["type"]->label, $by_key["title"]->label, $by_key["status"]->label, cc_lang("cc_imp_special_days"));
        $order = array();
        $seen = array();
        foreach ($state["cols"] as $i => $t) {
            if (!$t->locked && ($t->role === "col" || $t->role === "new") && !isset($seen[$t->ref])) {
                $seen[$t->ref] = true;
                $order[] = $t;
                $labels[] = $t->label;
            }
        }

        $rows = array();
        foreach (array_slice($sp["items"], 0, self::PREVIEW_ROWS) as $item) {
            $line = array($item["date"] === null ? "" : $item["date"], $item["type"], $item["title"], $item["status"], $item["special"]);
            foreach ($order as $t) {
                $v = isset($item["values"][$t->ref]) ? $item["values"][$t->ref] : "";
                $line[] = is_array($v) ? implode(", ", $v) : ($v === true ? "Yes" : (string) $v);
            }
            $rows[] = array("row" => $item["row"], "cells" => $line);
        }

        $sp["labels"] = $labels;
        $sp["preview_rows"] = $rows;
        return $sp;
    }

    /**
     * All rows of one sheet: dates, special days, content, swapped dates, items, skipped rows.
     */
    private function process_sheet($state, $ctx, &$out) {
        $cfg = $state["cfg"];
        $layout = $state["layout"];
        $name = $state["name"];
        $year = (int) $cfg["year"];
        $ignore = array_flip($cfg["special_ignore"]);
        $date_index = $state["date_index"];
        $special_index = $state["special_index"];
        $title_index = $state["title_index"];
        $counts = array("items" => 0, "without_date" => 0, "empty_date_rows" => 0, "special_only_rows" => 0, "skipped" => 0, "swap_flags" => 0);

        /* ---- dates, special day, "is there content" for every row ---- */
        $records = array();
        $last_date = "";
        foreach ($layout["data"] as $entry) {
            list($row_number, $cells, $row_month, $row_year) = $entry;
            $default_year = $row_year ? $row_year : $year;

            $src = "";
            $hint = 0;
            $used = -1;
            $date = $this->resolve_row_date($cells, $date_index, $state["alt"], $default_year, $row_month, $src, $hint, $used);
            if ($date === null && $cfg["carry_blank_date"] && $last_date !== "" && $src === "" && !$hint) {
                $date = $last_date;
            }

            if ($date !== null) {
                $last_date = $date;
            }

            $special = "";
            if ($special_index !== null && isset($cells[$special_index])) {
                $special = $this->clean_special($cells[$special_index], $ignore);
            }

            $has_content = false;
            foreach ($state["content_idx"] as $i) {
                if ($i !== $used && isset($cells[$i]) && $cells[$i] !== "") {
                    $has_content = true;
                    break;
                }
            }

            $records[] = array("row" => $row_number, "cells" => $cells, "date" => $date, "src" => $src, "hint" => $hint, "used" => $used, "month" => $row_month, "year" => $default_year, "special" => $special, "content" => $has_content, "swapped" => false);
        }

        /* ---- swapped day / month ---- */
        $dated = array();
        foreach ($records as $k => $record) {
            if ($record["date"] !== null) {
                $dated[] = $k;
            }
        }

        $previous = array();
        $next = array();
        foreach ($dated as $pos => $k) {
            $previous[$k] = $pos > 0 ? $dated[$pos - 1] : null;
            $next[$k] = $pos < count($dated) - 1 ? $dated[$pos + 1] : null;
        }

        $flips = array();
        foreach ($dated as $k) {
            $date = $records[$k]["date"];
            $swapped = $this->swapped_date($date);
            if ($swapped === null) {
                continue;
            }

            $m = $this->month_of($date);
            $ym = substr($date, 0, 7);
            $swapped_month = $this->month_of($swapped);
            $prev_date = $previous[$k] !== null ? $records[$previous[$k]]["date"] : null;
            $next_date = $next[$k] !== null ? $records[$next[$k]]["date"] : null;
            $ctx_month = (int) $records[$k]["month"];
            $flag = false;
            if ($ctx_month && $m !== $ctx_month && $swapped_month === $ctx_month
                    && ($prev_date === null || $this->month_of($prev_date) !== $m) && ($next_date === null || $this->month_of($next_date) !== $m)) {
                $flag = true;
            } else if ($prev_date !== null && $next_date !== null && substr($prev_date, 0, 7) === substr($next_date, 0, 7)
                    && $ym !== substr($prev_date, 0, 7) && substr($swapped, 0, 7) === substr($prev_date, 0, 7)) {
                $flag = true;
            }

            if ($flag) {
                $flips[$k] = $swapped;
            }
        }

        foreach ($flips as $k => $swapped) {
            $counts["swap_flags"]++;
            $out["swap"]["count"]++;
            if (count($out["swap"]["examples"]) < 5) {
                $out["swap"]["examples"][] = array("sheet" => $name, "row" => $records[$k]["row"], "from" => $records[$k]["date"], "to" => $swapped, "section" => $records[$k]["month"]);
            }

            if ($cfg["fix_swapped_dates"]) {
                $records[$k]["date"] = $swapped;
                $records[$k]["swapped"] = true;
            }
        }

        /* ---- dates outside the year range of the sheet ---- */
        $years = array();
        foreach ($records as $record) {
            if ($record["date"] !== null) {
                $y = (int) substr($record["date"], 0, 4);
                $years[$y] = (isset($years[$y]) ? $years[$y] : 0) + 1;
            }
        }

        if (count($years) > 1) {
            arsort($years);
            $dominant = (int) array_key_first($years);
            foreach ($records as $record) {
                if ($record["date"] === null) {
                    continue;
                }

                $y = (int) substr($record["date"], 0, 4);
                $m = $this->month_of($record["date"]);
                $ok = $y === $dominant || ($y === $dominant + 1 && $m <= 3) || ($y === $dominant - 1 && $m >= 10);
                if (!$ok) {
                    $out["range"]["count"]++;
                    if (count($out["range"]["examples"]) < 5) {
                        $out["range"]["examples"][] = array("sheet" => $name, "row" => $record["row"], "date" => $record["date"]);
                    }
                }
            }
        }

        /* ---- items ---- */
        $items = array();
        $status_options = array();
        foreach ($ctx["status_options"] as $option) {
            $status_options[$this->lower($option)] = $option;
        }

        $default_status = $this->lower(trim($cfg["default_status"]));
        $stats = $out["stats"];
        foreach ($records as $record) {
            $row_number = $record["row"];
            $cells = $record["cells"];
            $date = $record["date"];
            $special = $record["special"];

            if (!$record["content"]) {
                if ($date !== null) {
                    if ($special !== "") {
                        $out["special"][$date][] = $special;
                        $out["special"][$date] = $this->unique_texts($out["special"][$date]);
                        $counts["special_only_rows"]++;
                    } else {
                        $counts["empty_date_rows"]++;
                    }
                } else if ($special !== "") {
                    $counts["skipped"]++;
                    $out["skipped"][] = array($row_number, cc_lang("cc_imp_reason_special_no_date"), $cells, $name);
                } else {
                    $counts["empty_date_rows"]++;
                }

                continue;
            }

            if ($date === null && !$cfg["import_without_date"]) {
                $counts["skipped"]++;
                $reason = $record["src"] !== "" ? sprintf(cc_lang("cc_imp_reason_bad_date"), $this->cut($record["src"], 30)) : cc_lang("cc_imp_reason_no_date");
                $out["skipped"][] = array($row_number, $reason, $cells, $name);
                continue;
            }

            $title = "";
            if ($title_index !== null && isset($cells[$title_index])) {
                $title = trim(preg_replace('/\s*\n\s*/u', " ", $cells[$title_index]));
            }

            if ($title === "") {
                foreach (array($state["first_pass"], $state["second_pass"]) as $pass) {
                    foreach ($pass as $i) {
                        if ($i === $record["used"]) {
                            continue;
                        }

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
                foreach ($state["third_pass"] as $i) {
                    if ($i !== $record["used"] && isset($cells[$i]) && trim($cells[$i]) !== "") {
                        $title = trim(preg_replace('/\s*\n\s*/u', " ", $cells[$i]));
                        break;
                    }
                }
            }

            if ($title === "") {
                $counts["skipped"]++;
                $out["skipped"][] = array($row_number, cc_lang("cc_imp_reason_no_title"), $cells, $name);
                continue;
            }

            $title = $this->cut($title, 255);

            if (count($ctx["existing_keys"]) && isset($ctx["existing_keys"][($date === null ? "null" : $date) . "|" . cc_title_key($title)])) {
                $counts["skipped"]++;
                $out["skipped"][] = array($row_number, cc_lang("cc_imp_reason_duplicate"), $cells, $name);
                continue;
            }

            $item = array("row" => $row_number, "sheet" => $state["index"], "date" => $date, "type" => "", "status" => "", "title" => $title, "values" => array(), "month_hint" => "", "special" => $special);
            foreach ($state["cols"] as $i => $t) {
                if ($t->locked || in_array($t->role, array("date", "title", "special_day"), true) || $i === $record["used"]) {
                    continue;
                }

                $raw = isset($cells[$i]) ? $cells[$i] : "";
                $leftover = "";
                $value = $this->convert_value($t, $raw, $stats, $record["year"], $record["month"], $leftover);
                if ($leftover !== "" && $t->companion && !$t->companion->locked) {
                    $item["values"][$t->companion->ref] = $leftover;
                    $t->companion->used = true;
                }

                if ($t->role === "type") {
                    $item["type"] = (string) $value;
                } else if ($t->role === "status") {
                    $item["status"] = (string) $value;
                } else if (!$this->empty_value($value)) {
                    $item["values"][$t->ref] = $value;
                }
            }

            if ($item["status"] === "" && $default_status !== "" && isset($status_options[$default_status])) {
                $item["status"] = $status_options[$default_status];
            }

            if ($date === null) {
                if ($record["src"] !== "") {
                    $item["values"]["_src_date_text"] = $record["src"];
                }

                $hint_month = $record["hint"] ? $record["hint"] : (int) $record["month"];
                if ($hint_month) {
                    $item["month_hint"] = sprintf("%04d-%02d", $record["year"], $hint_month);
                }

                $counts["without_date"]++;
                if ($special !== "") {
                    $out["special_no_date"]++;
                }
            } else if ($special !== "") {
                $out["special"][$date][] = $special;
                $out["special"][$date] = $this->unique_texts($out["special"][$date]);
            }

            $counts["items"]++;
            $items[] = $item;
        }

        $out["stats"] = $stats;
        return array("items" => $items, "counts" => $counts);
    }

    private function unique_texts($texts) {
        $seen = array();
        $result = array();
        foreach ($texts as $text) {
            $key = $this->lower($text);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $text;
            }
        }

        return $result;
    }

    /**
     * The plan as it goes to the browser (no item list: counts, 10 preview rows of the detail sheet, first 50 skipped rows).
     *
     * @param bool $with_columns include the columns and preview rows of one sheet
     * @param int|null $detail_index which sheet (null = the first one)
     */
    public function plan_for_browser($plan, $with_columns = true, $detail_index = null) {
        $counts = $plan["counts"];
        $out = array(
            "ok" => $plan["ok"], "error" => $plan["error"], "warnings" => $plan["warnings"],
            "header_row" => $plan["header_row"], "detected_header_row" => $plan["detected_header_row"],
            "can_import" => $plan["can_import"],
            "summary" => array(
                "to_import" => count($plan["items"]),
                "dated" => count($plan["items"]) - (int) $counts["without_date"],
                "without_date" => (int) $counts["without_date"],
                "special_days" => (int) $counts["special_days_written"],
                "special_days_locked" => !empty($plan["special_days_locked"]),
                "skipped" => count($plan["skipped"]),
                "empty_date_rows" => (int) $counts["empty_date_rows"],
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
                "truncated_cells" => $plan["stats"]["truncated_cells"],
                "swap_flags" => $plan["swap_flags"],
                "out_of_range" => $plan["out_of_range"],
                "header_counts" => $plan["header_counts"],
                "targets_to_set" => $plan["targets_to_set"]
            ),
            "skipped" => array(),
            "value_cleanup" => $plan["value_cleanup"],
            "sheets" => array()
        );

        foreach (array_slice($plan["skipped"], 0, self::SKIPPED_SHOWN) as $row) {
            $out["skipped"][] = array("row" => $row[0], "reason" => $row[1], "sheet" => isset($row[3]) ? $row[3] : "");
        }

        foreach ($plan["sheets"] as $sp) {
            $out["sheets"][] = array(
                "index" => $sp["index"], "name" => $sp["name"], "client_name" => $sp["client_name"], "ok" => $sp["ok"], "error" => $sp["error"],
                "header_row" => $sp["header_row"], "detected_header_row" => $sp["detected_header_row"],
                "counts" => $sp["counts"], "header_counts" => $sp["header_counts"]
            );
        }

        if ($with_columns) {
            $detail = null;
            foreach ($plan["sheets"] as $sp) {
                if ($detail === null || ($detail_index !== null && (int) $sp["index"] === (int) $detail_index)) {
                    $detail = $sp;
                }
            }

            if ($detail) {
                $out["detail_index"] = $detail["index"];
                $out["columns"] = array_values($detail["columns"]);
                $out["labels"] = $detail["labels"];
                $out["preview_rows"] = $detail["preview_rows"];
            }
        }

        return $out;
    }

    /* ================================================================== */
    /* sheets, clients, dry run                                            */
    /* ================================================================== */

    /**
     * Sheet info for the screen, right after parsing: name, rows, client, detected header, counts, "is this a content calendar".
     * Needs no database.
     */
    public function sheet_summary($sheet, $index) {
        $rows = isset($sheet["rows"]) ? $sheet["rows"] : array();
        $name = isset($sheet["name"]) ? (string) $sheet["name"] : "";
        $client = $this->detect_client($rows, $name);
        $info = array(
            "index" => $index, "name" => $name, "rows" => count($rows), "data_rows" => 0, "header_row" => 0,
            "usable" => true, "error" => "", "status" => "ok", "reason" => "",
            "client_name" => $client["name"], "client_norm" => $this->norm($client["name"]), "client_source" => $client["source"],
            "is_content_calendar" => false, "checked" => false,
            "content_rows" => 0, "without_date_rows" => 0, "empty_date_rows" => 0, "special_day_rows" => 0, "skipped_rows" => 0,
            "swap_flags" => 0, "monthly_counts" => array(), "date_min" => "", "date_max" => ""
        );

        if (isset($sheet["encoding"])) {
            $info["encoding"] = $sheet["encoding"];
            $info["delimiter"] = $sheet["delimiter"];
        }

        if (!empty($sheet["error"])) {
            $info["usable"] = false;
            $info["status"] = "error";
            $info["error"] = $sheet["error"];
            return $info;
        }

        if (!count($rows)) {
            $info["usable"] = false;
            $info["status"] = "empty";
            $info["error"] = cc_lang("cc_imp_sheet_no_table");
            return $info;
        }

        $cfg = $this->default_config();
        $layout = $this->analyze_layout($rows, $cfg, $name);
        $info["header_row"] = $layout["header_row"];
        $info["monthly_counts"] = $layout["pre"]["counts"];
        $info["data_rows"] = $layout["stats"]["data_rows"];
        if (!$layout["ok"] || $layout["stats"]["data_rows"] < 1) {
            $info["usable"] = false;
            $info["status"] = "error";
            $info["error"] = $layout["ok"] ? cc_lang("cc_imp_sheet_no_table") : $layout["error"];
            return $info;
        }

        $has_date_header = false;
        foreach ($layout["header_cells"] as $cell) {
            if ($cell !== "" && $this->is_date_header($cell)) {
                $has_date_header = true;
                break;
            }
        }

        if (!$has_date_header) {
            $info["status"] = "not_calendar";
            $info["reason"] = cc_lang("cc_imp_not_calendar");
            return $info;
        }

        $info["is_content_calendar"] = true;
        $info["checked"] = true;

        $columns = $this->virtual_core_columns();
        $perms = array("add" => true, "edit" => true, "manage_options" => true, "add_client" => true, "add_column" => true, "admin_manage" => true);
        $plan = $this->build_group_plan(array(array("index" => $index, "sheet" => $sheet, "config" => $cfg)), $cfg, 0, $columns, $perms);
        if (!$plan["ok"]) {
            $info["checked"] = false;
            $info["reason"] = $plan["error"];
            if ($plan["error"] === cc_lang("cc_imp_no_date_col")) {
                $info["is_content_calendar"] = false;
                $info["status"] = "not_calendar";
                $info["reason"] = cc_lang("cc_imp_not_calendar");
            }

            return $info;
        }

        $c = $plan["counts"];
        $info["content_rows"] = (int) $c["imported"];
        $info["without_date_rows"] = (int) $c["without_date"];
        $info["empty_date_rows"] = (int) $c["empty_date_rows"];
        $info["special_day_rows"] = (int) $c["special_only_rows"];
        $info["skipped_rows"] = (int) $c["skipped"];
        $info["swap_flags"] = (int) $c["swap_flags"];
        $info["date_min"] = $plan["date_min"];
        $info["date_max"] = $plan["date_max"];
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
     * Sheets of the same client (normalized client name) are ONE calendar.
     *
     * @param array $summaries from sheet_summary()
     * @param array $calendars existing calendars (objects with id, name), optional
     * @return array list of array(key, client_name, sheet_indexes, sheet_names, is_content_calendar, checked, match_calendar_id, similar)
     */
    public function group_sheets($summaries, $calendars = array()) {
        $groups = array();
        $bases = array();
        $rows_of = array();
        foreach ($summaries as $summary) {
            if (empty($summary["usable"])) {
                continue;
            }

            $is_calendar = !empty($summary["is_content_calendar"]);
            $base = $this->client_alias($this->norm($this->clean_sheet_client_name($summary["name"])));
            $key = $is_calendar ? $this->client_alias($summary["client_norm"]) : "~sheet" . $summary["index"];
            if ($key === "") {
                $key = $base !== "" ? $base : "~sheet" . $summary["index"];
            }

            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    "key" => $key, "client_name" => $summary["client_name"], "client_names" => array(), "sheet_indexes" => array(), "sheet_names" => array(),
                    "is_content_calendar" => $is_calendar, "checked" => !empty($summary["checked"]),
                    "match_calendar_id" => 0, "similar" => array()
                );
                $bases[$key] = array();
                $rows_of[$key] = array();
            }

            $groups[$key]["sheet_indexes"][] = $summary["index"];
            $groups[$key]["sheet_names"][] = $summary["name"];
            if ($summary["client_name"] !== "") {
                $groups[$key]["client_names"][$summary["client_name"]] = true;
                $rows_of[$key][$summary["client_name"]] = (isset($rows_of[$key][$summary["client_name"]]) ? $rows_of[$key][$summary["client_name"]] : 0) + (int) (isset($summary["content_rows"]) ? $summary["content_rows"] : 0);
            }

            if ($base !== "") {
                $bases[$key][$base] = true;
            }

            if ($groups[$key]["client_name"] === "" && $summary["client_name"] !== "") {
                $groups[$key]["client_name"] = $summary["client_name"];
            }
        }

        /* Merge client groups that are the same client written slightly differently ("Truscan" / "Truscan Diagnostis",
         * "Sunrise Digital" / "Sunrise"): similar names (near or one is the start of the other), the same sheet base name once
         * Design / Video is stripped, or a known alias (hip => healthinpocket). Only content calendar groups are merged. */
        $cal_keys = array();
        foreach ($groups as $key => $group) {
            if ($group["is_content_calendar"]) {
                $cal_keys[] = $key;
            }
        }

        $parent = array();
        foreach ($cal_keys as $key) {
            $parent[$key] = $key;
        }

        $find = function ($key) use (&$parent) {
            while ($parent[$key] !== $key) {
                $parent[$key] = $parent[$parent[$key]];
                $key = $parent[$key];
            }

            return $key;
        };

        $n_keys = count($cal_keys);
        for ($a = 0; $a < $n_keys; $a++) {
            for ($b = $a + 1; $b < $n_keys; $b++) {
                $x = $cal_keys[$a];
                $y = $cal_keys[$b];
                $short = strlen($x) <= strlen($y) ? $x : $y;
                $long = $short === $x ? $y : $x;
                $linked = $this->near_names($x, $y) || (strlen($short) >= 5 && strpos($long, $short) === 0) || count(array_intersect_key($bases[$x], $bases[$y])) > 0;
                if ($linked) {
                    $root_x = $find($x);
                    $root_y = $find($y);
                    if ($root_x !== $root_y) {
                        //the earlier group (position in cal_keys) stays the root
                        if (array_search($root_x, $cal_keys, true) <= array_search($root_y, $cal_keys, true)) {
                            $parent[$root_y] = $root_x;
                        } else {
                            $parent[$root_x] = $root_y;
                        }
                    }
                }
            }
        }

        $merged = array();
        foreach ($groups as $key => $group) {
            $root = $group["is_content_calendar"] ? $find($key) : $key;
            if (!isset($merged[$root])) {
                $merged[$root] = $group;
                $merged[$root]["key"] = $root;
                $merged[$root]["_rows"] = array();
            } else {
                $merged[$root]["sheet_indexes"] = array_merge($merged[$root]["sheet_indexes"], $group["sheet_indexes"]);
                $merged[$root]["sheet_names"] = array_merge($merged[$root]["sheet_names"], $group["sheet_names"]);
                $merged[$root]["client_names"] = $merged[$root]["client_names"] + $group["client_names"];
                $merged[$root]["checked"] = $merged[$root]["checked"] || $group["checked"];
            }

            foreach ($rows_of[$key] as $name => $count) {
                $merged[$root]["_rows"][$name] = (isset($merged[$root]["_rows"][$name]) ? $merged[$root]["_rows"][$name] : 0) + $count;
            }
        }

        foreach ($merged as $key => $group) {
            //sheets in file order, the name of the client with most rows is the one shown (the longest name when equal)
            $pairs = array();
            foreach ($group["sheet_indexes"] as $i => $index) {
                $pairs[$index] = $group["sheet_names"][$i];
            }
            ksort($pairs);
            $merged[$key]["sheet_indexes"] = array_keys($pairs);
            $merged[$key]["sheet_names"] = array_values($pairs);

            $best = $group["client_name"];
            $best_rows = -1;
            foreach ($group["_rows"] as $name => $count) {
                if ($count > $best_rows || ($count === $best_rows && strlen($name) > strlen($best))) {
                    $best = $name;
                    $best_rows = $count;
                }
            }

            $merged[$key]["client_name"] = $best;
            $merged[$key]["client_names"] = array_keys($group["client_names"]);
            unset($merged[$key]["_rows"]);

            //an existing client that matches the shown name, or any of the other names of the group
            $match = $this->match_calendar($best, $calendars);
            if (!$match && $group["is_content_calendar"]) {
                foreach ($merged[$key]["client_names"] as $name) {
                    $match = $this->match_calendar($name, $calendars);
                    if ($match) {
                        break;
                    }
                }
            }
            $merged[$key]["match_calendar_id"] = $match;
        }

        return array_values($merged);
    }

    //names that are known to be the same client: normalized sheet / client name => normalized name of the main one
    public function client_alias($norm) {
        $aliases = array("hip" => "healthinpocket");
        return isset($aliases[$norm]) ? $aliases[$norm] : $norm;
    }

    /**
     * Dry run for one sheet into an existing calendar (id > 0) or a new one (id = 0).
     */
    public function dry_run_sheet($sheet, $config, $calendar_id, $perms) {
        return $this->dry_run_group(array(array("index" => 0, "sheet" => $sheet, "config" => $config)), $config, $calendar_id, $perms);
    }

    /**
     * Dry run for all the sheets of one client.
     */
    public function dry_run_group($jobs, $options, $calendar_id, $perms) {
        $calendar_id = (int) $calendar_id;
        $columns = $calendar_id > 0 ? $this->columns_model()->get_for_calendar($calendar_id) : $this->virtual_core_columns();
        return $this->build_group_plan($jobs, $options, $calendar_id, $columns, $perms);
    }

    /* ================================================================== */
    /* apply                                                               */
    /* ================================================================== */

    //a run id groups the logs of all sheets of one import (40 hex characters)
    public function new_run_id() {
        return bin2hex(random_bytes(20));
    }

    public function valid_run_id($run_id) {
        return is_string($run_id) && preg_match('/^[a-f0-9]{8,40}$/', $run_id) === 1;
    }

    /**
     * One sheet into one calendar. Wrapper of run_group().
     *
     * @param array $target array("calendar_id" => int) or array("new_name" => string)
     * @return array success, message, plus the result numbers
     */
    public function run_sheet($sheet, $config, $target, $perms, $user_id, $file_name) {
        return $this->run_group(array(array("index" => 0, "sheet" => $sheet, "config" => $config)), $config, $target, $perms, $user_id, $file_name, "");
    }

    /**
     * Apply all the sheets of one client in ONE database transaction. Nothing is trusted from the preview: the plan is built again here.
     * Every sheet gets its own batch id and log; all logs share the run id.
     *
     * @param array $jobs list of array("index", "sheet", "config")
     * @param array $target array("calendar_id" => int) or array("new_name" => string)
     */
    public function run_group($jobs, $options, $target, $perms, $user_id, $file_name, $run_id = "") {
        $fail = function ($message) {
            return array("success" => false, "message" => $message);
        };

        if (empty($perms["add"])) {
            return $fail(cc_lang("cc_forbidden"));
        }

        if (!$this->valid_run_id($run_id)) {
            $run_id = $this->new_run_id();
        }

        $perm_special = !empty($perms["edit"]) || !empty($perms["admin_manage"]);
        $db = db_connect("default");
        $calendar_id = 0;
        $calendar_name = "";
        $is_new_client = false;
        $db->transBegin();

        try {
            if (!empty($target["new_name"])) {
                if (empty($perms["add_client"]) && empty($perms["add_clients"]) && empty($perms["admin_manage"])) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_forbidden"));
                }

                $name = trim(preg_replace('/\s+/u', " ", (string) $target["new_name"]));
                if ($name === "" || $this->len($name) > 120) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_name_required"));
                }

                if ($this->calendars_model()->name_exists($name)) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_client_exists") . " (" . $name . ")");
                }

                $client_id = $this->calendars_model()->find_client_id_by_name($name);
                $calendar_id = $this->calendars_model()->create_calendar($name, $client_id, $user_id);
                $this->columns_model()->seed_core($calendar_id);
                $this->months_model()->seed_default($calendar_id);
                $calendar_name = $name;
                $is_new_client = true;
            } else {
                $calendar = $this->calendars_model()->get_active((int) $target["calendar_id"]);
                if (!$calendar) {
                    $db->transRollback();
                    return $fail(cc_lang("cc_calendar_not_found"));
                }

                $calendar_id = (int) $calendar->id;
                $calendar_name = $calendar->name;
            }

            $columns = $this->columns_model()->get_for_calendar($calendar_id);
            $plan = $this->build_group_plan($jobs, $options, $calendar_id, $columns, $perms);
            if (!$plan["ok"]) {
                $db->transRollback();
                return $fail($plan["error"]);
            }

            $special_write = $perm_special ? $plan["special_days_to_write"] : array();
            if (!count($plan["items"]) && !count($special_write)) {
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
                $column = $columns_by_key[$addition["field_key"]];
                $this->columns_model()->update_options($column->id, array_merge($column->options_list, $addition["added"]));
            }

            //new columns in sheet order, same key / unique logic as the column designer
            $ref_to_key = array();
            foreach ($plan["new_columns"] as $new_column) {
                $column_options = ($new_column["type"] === "select" || $new_column["type"] === "multi") ? $new_column["options"] : array();
                if (($new_column["type"] === "select" || $new_column["type"] === "multi") && !count($column_options)) {
                    $column_options = array("-");
                }

                $column_id = $this->columns_model()->add_column($calendar_id, $new_column["label"], $new_column["type"], $column_options);
                $saved = $this->columns_model()->get_active($column_id);
                $ref_to_key[$new_column["ref"]] = $saved->field_key;
            }

            //one batch id per sheet
            $batches = array();
            $sheet_names = array();
            foreach ($plan["sheets"] as $index => $sp) {
                if ($sp["ok"]) {
                    $batches[$index] = bin2hex(random_bytes(12));
                    $sheet_names[$index] = $sp["name"];
                }
            }

            $rows = array();
            $inserted = array();
            $inserted_total = 0;
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
                    "values_json" => $this->items_model()->encode_values($values),
                    "deleted" => 0,
                    "created_by" => (int) $user_id,
                    "updated_by" => (int) $user_id,
                    "created_at" => $now,
                    "updated_at" => $now,
                    "import_batch_id" => $batches[$item["sheet"]]
                );

                $inserted[$item["sheet"]] = (isset($inserted[$item["sheet"]]) ? $inserted[$item["sheet"]] : 0) + 1;
                $inserted_total++;
                if (count($rows) >= self::BATCH_SIZE) {
                    $this->items_model()->insert_batch_rows($rows);
                    $rows = array();
                }
            }

            if (count($rows)) {
                $this->items_model()->insert_batch_rows($rows);
            }

            $months_added = 0;
            foreach ($plan["months"] as $ym) {
                if ($this->months_model()->ensure($calendar_id, (int) substr($ym, 0, 4), (int) substr($ym, 5, 2))) {
                    $months_added++;
                }
            }

            $special_written = 0;
            foreach ($special_write as $date => $label) {
                $this->special_days_model()->save_label($calendar_id, $date, $label);
                $special_written++;
            }

            $targets_written = 0;
            if (count($plan["targets_to_set"]) && !empty($perms["admin_manage"])) {
                foreach ($plan["targets_to_set"] as $one) {
                    $this->targets_model()->save_target($calendar_id, $one["content_type"], $one["count"]);
                    $targets_written++;
                }
            }

            $has_run_column = $db->fieldExists("run_id", "cc_import_logs");
            $logs = array();
            $first = true;
            foreach ($batches as $index => $batch_id) {
                $sp = $plan["sheets"][$index];
                $log = array(
                    "calendar_id" => $calendar_id,
                    "user_id" => (int) $user_id,
                    "file_name" => $this->cut($file_name, 255),
                    "sheet_name" => $this->cut($sp["name"], 120),
                    "rows_total" => $sp["counts"]["items"] + $sp["counts"]["skipped"] + $sp["counts"]["empty_date_rows"] + $sp["counts"]["special_only_rows"],
                    "rows_imported" => isset($inserted[$index]) ? $inserted[$index] : 0,
                    "rows_skipped" => $sp["counts"]["skipped"],
                    "columns_created" => $first ? count($plan["new_columns"]) : 0,
                    "options_created" => $first ? $plan["options_created"] : 0,
                    "batch_id" => $batch_id,
                    "undone" => 0,
                    "created_at" => $now
                );

                if ($has_run_column) {
                    $log["run_id"] = $run_id;
                }

                $log_id = $this->logs_model()->create_log($log);
                $logs[] = array("index" => $index, "sheet_name" => $sp["name"], "log_id" => $log_id, "batch_id" => $batch_id, "imported" => $log["rows_imported"], "without_date" => $sp["counts"]["without_date"], "skipped" => $sp["counts"]["skipped"], "header_row" => $sp["header_row"]);
                $first = false;
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $fail(cc_lang("cc_imp_failed"));
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR] cc import run_group: {message}', array('message' => $e->getMessage()));
            return $fail(cc_lang("cc_imp_failed"));
        }

        $c = $plan["counts"];
        return array(
            "success" => true,
            "calendar_id" => $calendar_id,
            "calendar_name" => $calendar_name,
            "is_new_client" => $is_new_client,
            "run_id" => $has_run_column ? $run_id : "",
            "logs" => $logs,
            "log_id" => count($logs) ? $logs[0]["log_id"] : 0,
            "batch_id" => count($logs) ? $logs[0]["batch_id"] : "",
            "header_row" => $plan["header_row"],
            "imported" => $inserted_total,
            "without_date" => (int) $c["without_date"],
            "special_days_written" => $special_written,
            "special_days_locked" => !$perm_special && count($plan["special_days_to_write"]) > 0,
            "targets_written" => $targets_written,
            "skipped" => count($plan["skipped"]),
            "skipped_rows" => $plan["skipped"],
            "empty_date_rows" => (int) $c["empty_date_rows"],
            "swap_flags" => $plan["swap_flags"],
            "columns_created" => count($plan["new_columns"]),
            "new_column_names" => array_map(function ($new_column) {
                return $new_column["label"];
            }, $plan["new_columns"]),
            "options_created" => $plan["options_created"],
            "new_options" => $plan["option_additions"],
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
     * Soft delete the items of every sheet (batch) of one import run. The user who ran it or an admin, once.
     * New columns, options, months and special days stay.
     *
     * @return array success, message, removed, calendars
     */
    public function undo_run($run_id, $user_id, $is_admin = false) {
        $fail = function ($message) {
            return array("success" => false, "message" => $message);
        };

        if (!$this->valid_run_id($run_id)) {
            return $fail(cc_lang("cc_imp_log_not_found"));
        }

        $logs = $this->logs_model()->get_logs_by_run($run_id);
        if (!count($logs)) {
            return $fail(cc_lang("cc_imp_log_not_found"));
        }

        $by_calendar = array();
        foreach ($logs as $log) {
            if ((int) $log->user_id !== (int) $user_id && !$is_admin) {
                return $fail(cc_lang("cc_forbidden"));
            }

            if ((int) $log->undone === 0) {
                $by_calendar[(int) $log->calendar_id][] = $log->batch_id;
            }
        }

        if (!count($by_calendar)) {
            return $fail(cc_lang("cc_imp_already_undone"));
        }

        $db = db_connect("default");
        $db->transBegin();
        $removed = 0;
        try {
            if (!$this->logs_model()->mark_run_undone($run_id)) {
                $db->transRollback();
                return $fail(cc_lang("cc_imp_already_undone"));
            }

            foreach ($by_calendar as $calendar_id => $batch_ids) {
                $removed += (int) $this->items_model()->soft_delete_batches($calendar_id, $batch_ids, $user_id);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR] cc import undo_run: {message}', array('message' => $e->getMessage()));
            return $fail(cc_lang("cc_error"));
        }

        return array("success" => true, "removed" => $removed, "calendars" => array_keys($by_calendar), "run_id" => $run_id, "message" => sprintf(cc_lang("cc_imp_undone"), $removed));
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
