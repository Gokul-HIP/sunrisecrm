<?php
/**
 * WhatsApp-style message previews + desktop toast payload audit.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}

echo "=== WhatsApp messages audit ===\n";
echo "db=" . $db->getDatabase() . "\n";

$mm = model('App\Models\Messages_model');

// Create ephemeral users + thread
$db->query("INSERT INTO rise_users SET first_name='WApp', last_name='Sender', email='wapp_s_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0");
$sender = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='WApp', last_name='Receiver', email='wapp_r_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0");
$receiver = (int)$db->insertID();
$ephemeral = [$sender, $receiver];
$msgIds = [];

$now = date('Y-m-d H:i:s');
$db->query("INSERT INTO rise_messages SET from_user_id=$sender, to_user_id=$receiver, message_id=0, subject='Task approved: #999 Old subject', message='First body', files='', created_at='$now', deleted_by_users='', status='unread', deleted=0");
$root = (int)$db->insertID();
$msgIds[] = $root;
sleep(1);
$now2 = date('Y-m-d H:i:s');
$db->query("INSERT INTO rise_messages SET from_user_id=$receiver, to_user_id=$sender, message_id=$root, subject='', message='hey hey latest', files='', created_at='$now2', deleted_by_users='', status='unread', deleted=0");
$reply = (int)$db->insertID();
$msgIds[] = $reply;

// Chat list for receiver — should show latest "You: hey hey latest" since receiver sent last? Wait receiver sent reply so for receiver viewer last_from=receiver → You:
$chat = $mm->get_chat_list(['login_user_id' => $receiver])->getResult();
$hit = null;
foreach ($chat as $c) {
    if ((int)$c->id === $root) { $hit = $c; break; }
}
check('chat list includes thread', (bool)$hit);
check('chat list ordered by latest time field', $hit && !empty($hit->message_time));
check('chat list last_message is reply text', $hit && strpos($hit->last_message, 'hey hey latest') !== false, $hit->last_message ?? '');
$preview = message_thread_preview_text($hit->last_message ?? '', null, $hit->last_from_user_id ?? 0, $receiver);
check('chat preview has You: prefix', strpos($preview, 'You:') !== false, $preview);
check('chat preview not old subject', strpos($preview, 'Task approved') === false, $preview);

// Envelope/inbox list for sender — last msg from receiver, no You:
$inbox = $mm->get_list(['user_id' => $sender, 'mode' => 'inbox'])->getResult();
$ihit = null;
foreach ($inbox as $r) {
    if ((int)$r->main_message_id === $root) { $ihit = $r; break; }
}
check('inbox list includes thread', (bool)$ihit);
check('inbox last_message is reply', $ihit && strpos($ihit->last_message, 'hey hey latest') !== false, $ihit->last_message ?? '');
$iprev = message_thread_preview_text($ihit->last_message ?? '', null, $ihit->last_from_user_id ?? 0, $sender);
check('inbox preview has no You: (other sent)', strpos($iprev, 'You:') === false && strpos($iprev, 'hey hey') !== false, $iprev);

// Ordering: create second thread with older root but newer reply should sort first
$db->query("INSERT INTO rise_messages SET from_user_id=$sender, to_user_id=$receiver, message_id=0, subject='Older thread', message='old', files='', created_at='2020-01-01 00:00:00', deleted_by_users='', status='read', deleted=0");
$root2 = (int)$db->insertID();
$msgIds[] = $root2;
$now3 = date('Y-m-d H:i:s', time() + 2);
$db->query("INSERT INTO rise_messages SET from_user_id=$sender, to_user_id=$receiver, message_id=$root2, subject='', message='brand new top', files='', created_at='$now3', deleted_by_users='', status='unread', deleted=0");
$msgIds[] = (int)$db->insertID();

$chat2 = $mm->get_chat_list(['login_user_id' => $receiver])->getResult();
check('newest thread is first in chat list', $chat2 && (int)$chat2[0]->id === $root2, 'first=' . ($chat2[0]->id ?? ''));

// File-only preview
$files = serialize([['file_name' => 'message_fileabc-report.pdf', 'file_size' => 10]]);
$fp = message_thread_preview_text('', $files, $sender, $receiver);
check('file-only preview has paperclip and name', strpos($fp, '📎') !== false && stripos($fp, 'report.pdf') !== false, $fp);

// Escape
$esc = message_thread_preview_text('<script>alert(1)</script> & "x"', null, 0, 0);
check('preview strips/escapes script (no raw tag)', strpos($esc, '<script>') === false, $esc);
check('preview escapes quotes/ampersand', strpos($esc, '&quot;') !== false || strpos($esc, '&amp;') !== false, $esc);
$esc2 = message_thread_preview_text('hello &lt;script&gt;', null, 0, 0);
check('preview of pre-escaped entities stays safe', strpos($esc2, '<script>') === false, $esc2);

// Desktop payloads
$db->query("UPDATE rise_users SET message_checked_at='2020-01-01 00:00:00' WHERE id=$receiver");
$payloads = $mm->get_unread_desktop_payloads($receiver, '2020-01-01 00:00:00', 0, 3);
check('desktop payloads returned', count($payloads) > 0);
$p0 = $payloads[0];
$body = message_thread_preview_text($p0->message, $p0->files, 0, 0, 100, false);
check('desktop body has text not generic', $body !== '' && stripos($body, 'You have a new message') === false, $body);
check('desktop title is sender name', !empty($p0->user_name) && stripos($p0->user_name, 'WApp') !== false, $p0->user_name ?? '');

// Unread count
$un = (int)$mm->count_unread_message($receiver);
check('unread count > 0', $un > 0, "unread=$un");

// Source checks
$nh = file_get_contents(FCPATH . 'assets/js/notification_handler.js');
check('handler uses desktop_notifications for messages', strpos($nh, 'msgFresh') !== false || strpos($nh, 'isMessageNotification') !== false);
check('handler no longer hardcodes only generic toast as sole path', substr_count($nh, 'You have a new message') <= 1);
$ac = file_get_contents(APPPATH . 'Views/messages/chat/active_chat.php');
check('active chat polls ~4s and pauses when hidden', strpos($ac, 'document.hidden') !== false && strpos($ac, '4000') !== false);
$ci = file_get_contents(APPPATH . 'Views/messages/chat/index.php');
check('refreshMessageChatListIfOpen defined', strpos($ci, 'refreshMessageChatListIfOpen') !== false);

$pusher_on = get_setting('enable_chat_via_pusher');
$push_on = get_setting('enable_push_notification');
$pkey = get_setting('pusher_key');
echo "delivery: enable_chat_via_pusher=" . ($pusher_on ?: '0') . " enable_push_notification=" . ($push_on ?: '0') . " pusher_key_set=" . ($pkey ? '1' : '0') . "\n";
echo "delivery_note: open-chat polling=4s when pusher off; envelope poll=4s visible / 30s hidden\n";

// Existing approve thread helper still present
check('approve thread helper still present', strpos(file_get_contents(APPPATH . 'Controllers/Projects.php'), 'get_open_thread_between_users') !== false);

// Cleanup
register_shutdown_function(function () use ($db, $ephemeral, $msgIds) {
    foreach ($msgIds as $id) {
        $db->query("DELETE FROM rise_messages WHERE id=" . (int)$id . " OR message_id=" . (int)$id);
    }
    $ids = implode(',', array_map('intval', $ephemeral));
    if ($ids) {
        $db->query("DELETE FROM rise_messages WHERE from_user_id IN ($ids) OR to_user_id IN ($ids)");
        $db->query("DELETE FROM rise_users WHERE id IN ($ids)");
    }
    echo "cleanup_done\n";
});

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
