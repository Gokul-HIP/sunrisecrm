<?php
foreach ([1902, 390] as $w) {
    $p = dirname(__DIR__) . "/writable/phase16g_done_dropdown_{$w}.html";
    $h = file_get_contents($p);
    $h = str_replace("id='modal-status'", "id='modal-status' size='5'", $h);
    file_put_contents($p, $h);
    echo $w, (strpos($h, "size='5'") !== false ? " OK\n" : " FAIL\n");
}
