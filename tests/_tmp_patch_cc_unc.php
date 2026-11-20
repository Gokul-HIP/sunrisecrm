<?php
/**
 * Patch Content_calendar_import::read_workbook_inner to copy to a native temp
 * path when ZipArchive/canRead fails on UNC/WSL paths (XAMPP on Windows).
 */
$path = dirname(__DIR__) . '/app/Libraries/Content_calendar_import.php';
$text = file_get_contents($path);
$old = <<<'PHP'
    private function read_workbook_inner($path, $type) {
        $this->load_spreadsheet_library();

        $reader_name = array("xlsx" => "Xlsx", "xls" => "Xls", "ods" => "Ods");
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($reader_name[$type]);
            if (!$reader->canRead($path)) {
                throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
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
            throw $e;
        } catch (\Throwable $e) {
            log_message('error', '[ERROR] cc import read_workbook: {message}', array('message' => $e->getMessage()));
            throw new \RuntimeException(cc_lang("cc_imp_err_corrupt"));
        }
PHP;

$new = <<<'PHP'
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
PHP;

if (strpos($text, 'copy_workbook_to_native_temp') !== false) {
    echo "already patched\n";
    exit(0);
}
if (strpos($text, $old) === false) {
    fwrite(STDERR, "pattern not found\n");
    exit(1);
}
$text = str_replace($old, $new, $text);

$helper = <<<'PHP'

    /**
     * Copy a workbook to sys_get_temp_dir() so ZipArchive can open it on Windows/UNC.
     */
    private function copy_workbook_to_native_temp($path, $type) {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $ext = preg_replace('/[^a-z0-9]/i', '', (string) $type);
        if ($ext === '') {
            $ext = 'bin';
        }
        $dest = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'cc_imp_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@copy($path, $dest)) {
            return null;
        }
        return $dest;
    }

PHP;

// Insert helper before the closing brace of the class — find last occurrence of "}\n" at end
if (substr(rtrim($text), -1) !== '}') {
    fwrite(STDERR, "unexpected file ending\n");
    exit(1);
}
$text = preg_replace('/\}\s*$/', $helper . "}\n", $text, 1);
file_put_contents($path, $text);
echo "patched UNC copy fallback\n";
