<?php
// public/r.php — the actual link users share: mulaearn.com/r.php?c=abc123
// Logs a click, then redirects to the advertiser's real URL. This is
// deliberately a plain redirect, not a JSON endpoint — it's meant to
// be clicked from WhatsApp/social, not called by a frontend script.

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$code = (string)($_GET['c'] ?? '');

if ($code === '' || !preg_match('/^[a-f0-9]{10}$/', $code)) {
    http_response_code(404);
    echo 'Link not found.';
    exit;
}

$affiliate = new AffiliateService();
$link = $affiliate->linkByTrackingCode($code);

if (!$link || $link['campaign_status'] !== 'active') {
    http_response_code(404);
    echo 'This link is no longer active.';
    exit;
}

$affiliate->recordClick(
    (int)$link['id'],
    client_ip(),
    $_SERVER['HTTP_USER_AGENT'] ?? null
);

header('Location: ' . $link['target_url'], true, 302);
exit;
