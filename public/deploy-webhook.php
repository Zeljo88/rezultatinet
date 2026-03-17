<?php
$secret = 'rezultati_deploy_2026';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');

if (!hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $signature)) {
    http_response_code(403);
    die('Forbidden');
}

$output = shell_exec('cd /var/www/vhosts/rezultati.net/httpdocs && /bin/bash deploy.sh 2>&1');
file_put_contents('/var/www/vhosts/rezultati.net/httpdocs/storage/logs/deploy.log', date('Y-m-d H:i:s') . "\n" . $output . "\n", FILE_APPEND);
echo 'OK';
