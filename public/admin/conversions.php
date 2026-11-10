<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$affiliate = new AffiliateService();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Form expired, please retry.'];
    } else {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note === '') {
            $flash = ['type' => 'error', 'text' => 'A note describing the source of this conversion confirmation is required (e.g. "Advertiser dashboard, order #4821").'];
        } else {
            try {
                $txId = $affiliate->recordConversion($linkId, (int)$admin['id'], $note);
                (new AdminLogger())->log((int)$admin['id'], 'conversion.record', 'link', $linkId, $note, admin_client_ip());
                $flash = ['type' => 'success', 'text' => "Conversion recorded — transaction #{$txId} created as pending. Approve it on the Approvals page to release funds."];
            } catch (Throwable $e) {
                $flash = ['type' => 'error', 'text' => 'Could not record: ' . $e->getMessage()];
            }
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$links = $affiliate->allLinksWithSearch($search);

$pageTitle = 'Record Conversion';
$activeNav = 'conversions';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Record a conversion</h1>
    <p>This is the manual stand-in for an advertiser's confirmed-sale webhook. Recording a conversion creates a <b>pending</b> transaction — it still needs approval on the Approvals page before the user can withdraw it.</p>
  </div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<div class="card">
  <form method="get" class="search-row">
    <input type="text" name="q" value="<?= admin_e($search) ?>" placeholder="Search by username, tracking code, or campaign...">
    <button type="submit" class="btn btn-outline">Search</button>
  </form>
</div>

<div class="card">
  <table class="data">
    <thead><tr><th>User</th><th>Campaign</th><th>Tracking code</th><th>Commission</th><th>Clicks</th><th>Conversions so far</th><th>Record one</th></tr></thead>
    <tbody>
      <?php foreach ($links as $l): ?>
      <tr>
        <td><a href="/admin/user_detail.php?id=<?= (int)$l['user_id'] ?>"><?= admin_e($l['username']) ?></a></td>
        <td><?= admin_e($l['campaign_title']) ?></td>
        <td class="mono text-faint"><?= admin_e($l['tracking_code']) ?></td>
        <td class="mono">KSh <?= number_format((float)$l['commission_amount'], 2) ?></td>
        <td><?= (int)$l['clicks'] ?></td>
        <td><?= (int)$l['conversions'] ?></td>
        <td>
          <details>
            <summary class="btn btn-sm btn-primary" style="cursor:pointer; display:inline-flex;">Record</summary>
            <form method="post" style="margin-top:10px; max-width:280px;">
              <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
              <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
              <div class="field">
                <label>Confirmation source</label>
                <input type="text" name="note" placeholder="e.g. Advertiser dashboard, order #4821" required>
                <div class="hint">Where this confirmation came from — required for the audit trail.</div>
              </div>
              <button type="submit" class="btn btn-sm btn-primary">Confirm conversion</button>
            </form>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($links)): ?>
        <tr><td colspan="7" class="text-faint">No tracking links yet — a link is created the first time a user clicks "Promote" on a campaign.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
