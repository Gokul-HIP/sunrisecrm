<?php
/**
 * CLI Invariant Verification Script: Task Points (Earned <= Overall)
 *
 * Verifies the mathematical invariant that earned points can never exceed
 * overall points for both solo tasks and collaborative tasks, across all
 * active user and month combinations.
 *
 * Usage: php tests/check_task_points_invariant.php [--db=database_name]
 */

ini_set('memory_limit', '512M');

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require __DIR__ . '/../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();

// Parse optional --db parameter
$targetDb = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $targetDb = substr($arg, 5);
    }
}

if ($targetDb) {
    $dbConfig = config('Database');
    $dbConfig->default['database'] = $targetDb;
    $dbConfig->default['hostname'] = '127.0.0.1';
    echo "Using target database: $targetDb\n";
}

$db = \Config\Database::connect();
$prefix = $db->getPrefix();

echo "========================================================\n";
echo "Task Points Invariant Check (Earned <= Overall)\n";
echo "Connected Database: " . $db->getDatabase() . "\n";
echo "========================================================\n\n";

// Gather all (user_id, year, month) combinations
$pairs = [];

// 1. From awards table
$query1 = $db->query("SELECT DISTINCT user_id, year, month FROM {$prefix}task_point_awards WHERE deleted = 0 AND year > 0 AND month > 0");
foreach ($query1->getResultArray() as $r) {
    $key = $r['user_id'] . '_' . $r['year'] . '_' . $r['month'];
    $pairs[$key] = [
        'user_id' => (int) $r['user_id'],
        'year'    => (int) $r['year'],
        'month'   => (int) $r['month']
    ];
}

// 2. From tasks created or assigned
$query2 = $db->query("SELECT DISTINCT assigned_to AS user_id, YEAR(created_date) as yr, MONTH(created_date) as mo FROM {$prefix}tasks WHERE deleted = 0 AND assigned_to > 0 AND created_date >= '2025-01-01'");
foreach ($query2->getResultArray() as $r) {
    $key = $r['user_id'] . '_' . $r['yr'] . '_' . $r['mo'];
    $pairs[$key] = [
        'user_id' => (int) $r['user_id'],
        'year'    => (int) $r['yr'],
        'month'   => (int) $r['mo']
    ];
}

// 3. From collaborators
$query3 = $db->query("SELECT id, collaborators, YEAR(created_date) as yr, MONTH(created_date) as mo FROM {$prefix}tasks WHERE deleted = 0 AND collaborators != '' AND created_date >= '2025-01-01'");
foreach ($query3->getResultArray() as $r) {
    $collabs = explode(',', $r['collaborators']);
    foreach ($collabs as $c) {
        $cid = (int) trim($c);
        if ($cid > 0) {
            $key = $cid . '_' . $r['yr'] . '_' . $r['mo'];
            $pairs[$key] = [
                'user_id' => $cid,
                'year'    => (int) $r['yr'],
                'month'   => (int) $r['mo']
            ];
        }
    }
}

echo "Testing invariant over " . count($pairs) . " distinct (user, year, month) combinations...\n\n";

$violations = [];
$checked = 0;

foreach ($pairs as $p) {
    $userId = $p['user_id'];
    $year = $p['year'];
    $month = $p['month'];

    $monthStr = sprintf('%04d-%02d', $year, $month);
    $options = ['task_points_month' => $monthStr];

    $summary = get_user_task_points_header_summary($userId, $options);

    $soloEarned    = (float) $summary['solo_earned'];
    $soloOverall   = (float) $summary['solo_overall'];
    $collabEarned  = (float) $summary['collab_earned'];
    $collabOverall = (float) $summary['collab_overall'];

    $checked++;

    $hasViolation = false;
    $vMsg = [];

    if ($soloEarned > $soloOverall + 0.001) {
        $hasViolation = true;
        $vMsg[] = "Solo Earned ($soloEarned) > Solo Overall ($soloOverall)";
    }

    if ($collabEarned > $collabOverall + 0.001) {
        $hasViolation = true;
        $vMsg[] = "Collab Earned ($collabEarned) > Collab Overall ($collabOverall)";
    }

    if ($hasViolation) {
        $violations[] = [
            'user_id' => $userId,
            'period'  => $monthStr,
            'details' => implode('; ', $vMsg)
        ];
    }
}

echo "Combinations checked: $checked\n";
echo "Violations found: " . count($violations) . "\n\n";

if (empty($violations)) {
    echo ">>> TEST PASSED: Invariant satisfied (Earned <= Overall) across 100% of checked user-months! <<<\n";
    exit(0);
} else {
    echo ">>> TEST FAILED: Found " . count($violations) . " invariant violations: <<<\n";
    foreach ($violations as $v) {
        echo "  - User {$v['user_id']} [{$v['period']}]: {$v['details']}\n";
    }
    exit(1);
}
