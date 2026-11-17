<?php
$ch = curl_init('http://127.0.0.1:8080/signin');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => '/tmp/c17d.txt',
    CURLOPT_COOKIEFILE => '/tmp/c17d.txt',
    CURLOPT_HEADER => true,
]);
$raw = curl_exec($ch);
curl_close($ch);
if (preg_match('/rise_csrf_token["\']\s*value=["\']([^"\']+)/', $raw, $m)) {
    echo "token=" . $m[1] . "\n";
} elseif (preg_match('/name=["\']rise_csrf_token["\'][^>]*value=["\']([^"\']+)/', $raw, $m)) {
    echo "token=" . $m[1] . "\n";
} else {
    echo "no token in body\n";
}
if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $raw, $m)) {
    foreach ($m[1] as $c) echo "cookie $c\n";
}
file_put_contents('/tmp/c17d_signin.html', $raw);
echo "saved /tmp/c17d_signin.html len=" . strlen($raw) . "\n";
