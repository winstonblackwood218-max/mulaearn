<?php
// public/api/affiliate/campaigns.php — GET. No auth required; anyone
// can browse what's available before signing up.

declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';

$affiliate = new AffiliateService();
json_response([
    'success'     => true,
    'campaigns'   => $affiliate->activeCampaigns(),
    'aggregates'  => $affiliate->campaignAggregates(),
]);
