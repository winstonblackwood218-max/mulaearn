<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$affiliate = new AffiliateService();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Form expired, please retry.'];
    } elseif (isset($_POST['create'])) {
        try {
            $id = $affiliate->createCampaign([
                'brand_name'        => trim((string)$_POST['brand_name']),
                'title'             => trim((string)$_POST['title']),
                'category'          => trim((string)$_POST['category']),
                'description'       => trim((string)$_POST['description']),
                'target_url'        => trim((string)$_POST['target_url']),
                'commission_amount' => (string)$_POST['commission_amount'],
                'requirements'      => trim((string)$_POST['requirements']),
                'status'            => 'active',
                'starts_at'         => null,
                'ends_at'           => null,
            ]);
            (new AdminLogger())->log((int)$admin['id'], 'campaign.create', 'campaign', $id, null, admin_client_ip());
            $flash = ['type' => 'success', 'text' => 'Campaign created.'];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not create campaign: ' . $e->getMessage()];
        }
    } elseif (isset($_POST['toggle_status'])) {
        $campaignId = (int)$_POST['campaign_id'];
        $newStatus = (string)$_POST['toggle_status'];
        try {
            $affiliate->updateCampaignStatus($campaignId, $newStatus);
            (new AdminLogger())->log((int)$admin['id'], 'campaign.status', 'campaign', $campaignId, $newStatus, admin_client_ip());
            $flash = ['type' => 'success', 'text' => "Campaign #{$campaignId} set to {$newStatus}."];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not update: ' . $e->getMessage()];
        }
    }
}

$campaigns = $affiliate->allCampaigns();

$pageTitle = 'Campaigns';
$activeNav = 'campaigns';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div><h1>Affiliate campaigns</h1><p>Every campaign here is what users see on the public marketplace.</p></div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<div class="card">
  <h2>Create a campaign</h2>
  <form method="post" style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
    <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
    <div class="field"><label>Brand name</label><input type="text" name="brand_name" required></div>
    <div class="field"><label>Campaign title</label><input type="text" name="title" required></div>
    <div class="field"><label>Category</label><input type="text" name="category" placeholder="Technology, Finance, Beauty..." required></div>
    <div class="field"><label>Commission amount (KSh)</label><input type="number" step="0.01" min="0" name="commission_amount" required></div>
    <div class="field" style="grid-column:1/-1;"><label>Description</label><input type="text" name="description"></div>
    <div class="field" style="grid-column:1/-1;"><label>Target URL (where the click redirects to)</label><input type="url" name="target_url" placeholder="https://advertiser.example.com/product" required></div>
    <div class="field" style="grid-column:1/-1;"><label>Requirements</label><input type="text" name="requirements" placeholder="e.g. Any active social or blog presence"></div>
    <div style="grid-column:1/-1;"><button type="submit" name="create" value="1" class="btn btn-primary">Create campaign</button></div>
  </form>
</div>

<div class="card">
  <h2>All campaigns</h2>
  <table class="data">
    <thead><tr><th>Brand / Title</th><th>Category</th><th>Commission</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($campaigns as $c): ?>
      <tr>
        <td><b><?= admin_e($c['brand_name']) ?></b><br><span class="text-faint"><?= admin_e($c['title']) ?></span></td>
        <td><?= admin_e($c['category']) ?></td>
        <td class="mono">KSh <?= number_format((float)$c['commission_amount'], 2) ?></td>
        <td><span class="badge <?= $c['status'] === 'active' ? 'completed' : 'pending' ?>"><?= admin_e($c['status']) ?></span></td>
        <td>
          <?php if ($c['status'] === 'active'): ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
              <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
              <button type="submit" name="toggle_status" value="paused" class="btn btn-sm btn-outline">Pause</button>
            </form>
          <?php else: ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
              <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
              <button type="submit" name="toggle_status" value="active" class="btn btn-sm btn-primary">Activate</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
