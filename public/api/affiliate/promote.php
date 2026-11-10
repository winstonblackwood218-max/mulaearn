<?php
// public/api/affiliate/promote.php — POST campaign_id, csrf_token.
// Requires login. Returns the user's tracking URL for that campaign
// (creating it on first request, reusing it after).

declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$user = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (!Csrf::verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid or expired form. Refresh and try again.'], 419);
}

$campaignId = (int)($input['campaign_id'] ?? 0);
if ($campaignId <= 0) {
    json_response(['success' => false, 'error' => 'campaign_id is required.'], 422);
}

$affiliate = new AffiliateService();
try {
    $link = $affiliate->getOrCreateLink((int)$user['id'], $campaignId);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 422);
}

$config = require __DIR__ . '/../../../config/config.php';
$trackingUrl = rtrim($config['app']['url'], '/') . '/r.php?c=' . $link['tracking_code'];

json_response(['success' => true, 'tracking_code' => $link['tracking_code'], 'tracking_url' => $trackingUrl]);
