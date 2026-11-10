<?php
// src/AffiliateService.php — campaigns, link generation, click
// tracking, and conversion recording.
//
// Money flow, spelled out: recordConversion() calls
// WalletService::credit(), which puts the commission in the user's
// PENDING balance and creates a transactions row. That's it — this
// class never moves anything to available_balance itself. An admin
// has to separately approve it on /admin/transactions.php (which
// calls WalletService::releasePending()). Two people/steps touch
// every commission before it's spendable: whoever recorded the
// conversion, and whoever approved it. Never the same automated
// call doing both.

declare(strict_types=1);

final class AffiliateService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function activeCampaigns(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM affiliate_campaigns WHERE status = 'active' ORDER BY created_at DESC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Platform-wide (all users combined) click/conversion counts per
     * campaign — real aggregate numbers, not per-user. Used on the
     * public marketplace to show how much activity a campaign has,
     * same as the "1,245 clicks" style figure real affiliate networks
     * show. One query each, not N+1 per campaign card.
     *
     * @return array{clicks: array<int,int>, conversions: array<int,int>} keyed by campaign_id
     */
    public function campaignAggregates(): array
    {
        $clicks = $this->db->query(
            'SELECT al.campaign_id AS cid, COUNT(*) AS c
             FROM affiliate_clicks ac JOIN affiliate_links al ON al.id = ac.link_id
             GROUP BY al.campaign_id'
        )->fetchAll();
        $conversions = $this->db->query(
            'SELECT al.campaign_id AS cid, COUNT(*) AS c
             FROM affiliate_conversions acv JOIN affiliate_links al ON al.id = acv.link_id
             GROUP BY al.campaign_id'
        )->fetchAll();

        $clickMap = [];
        foreach ($clicks as $row) { $clickMap[(int)$row['cid']] = (int)$row['c']; }
        $conversionMap = [];
        foreach ($conversions as $row) { $conversionMap[(int)$row['cid']] = (int)$row['c']; }

        // Cast to object when empty: json_encode() can't tell an empty
        // PHP array from an empty map, so it serializes [] as a JSON
        // array by default. Consumers expecting {} (like a JS lookup
        // by campaign id) would get the wrong type on an empty result.
        return [
            'clicks' => empty($clickMap) ? (object)[] : $clickMap,
            'conversions' => empty($conversionMap) ? (object)[] : $conversionMap,
        ];
    }

    public function allCampaigns(): array
    {
        $stmt = $this->db->query('SELECT * FROM affiliate_campaigns ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    public function campaign(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM affiliate_campaigns WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function createCampaign(array $input): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO affiliate_campaigns (brand_name, title, category, description, target_url, commission_amount, status, requirements, starts_at, ends_at)
             VALUES (:brand, :title, :category, :desc, :url, :commission, :status, :reqs, :starts, :ends)'
        );
        $stmt->execute([
            ':brand'      => $input['brand_name'],
            ':title'      => $input['title'],
            ':category'   => $input['category'],
            ':desc'       => $input['description'] ?? null,
            ':url'        => $input['target_url'],
            ':commission' => $input['commission_amount'],
            ':status'     => $input['status'] ?? 'active',
            ':reqs'       => $input['requirements'] ?? null,
            ':starts'     => $input['starts_at'] ?: null,
            ':ends'       => $input['ends_at'] ?: null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateCampaignStatus(int $campaignId, string $status): void
    {
        if (!in_array($status, ['active', 'paused', 'ended'], true)) {
            throw new InvalidArgumentException('Invalid campaign status.');
        }
        $stmt = $this->db->prepare('UPDATE affiliate_campaigns SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $campaignId]);
    }

    /**
     * Get this user's existing tracking link for a campaign, or
     * create one. One link per (user, campaign) — reused forever so
     * historical clicks/conversions stay attached to a stable code.
     */
    public function getOrCreateLink(int $userId, int $campaignId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM affiliate_links WHERE user_id = :uid AND campaign_id = :cid');
        $stmt->execute([':uid' => $userId, ':cid' => $campaignId]);
        $link = $stmt->fetch();
        if ($link) {
            return $link;
        }

        $campaign = $this->campaign($campaignId);
        if (!$campaign || $campaign['status'] !== 'active') {
            throw new RuntimeException('Campaign not found or not active.');
        }

        $code = $this->generateUniqueCode();
        $stmt = $this->db->prepare(
            'INSERT INTO affiliate_links (user_id, campaign_id, tracking_code) VALUES (:uid, :cid, :code)'
        );
        $stmt->execute([':uid' => $userId, ':cid' => $campaignId, ':code' => $code]);

        $stmt = $this->db->prepare('SELECT * FROM affiliate_links WHERE id = :id');
        $stmt->execute([':id' => $this->db->lastInsertId()]);
        return $stmt->fetch();
    }

    public function linkByTrackingCode(string $code): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT al.*, ac.target_url, ac.status AS campaign_status
             FROM affiliate_links al
             JOIN affiliate_campaigns ac ON ac.id = al.campaign_id
             WHERE al.tracking_code = :code'
        );
        $stmt->execute([':code' => $code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Log a click. Debounces identical (link, ip_hash) clicks within
     * 60 seconds so a page refresh or double-click doesn't inflate
     * numbers — a basic fraud/noise guard, not a full solution.
     */
    public function recordClick(int $linkId, string $ipAddress, ?string $userAgent): void
    {
        $ipHash = hash('sha256', $ipAddress . '|' . date('Y-m-d')); // salted by day, not identifying long-term
        $uaHash = $userAgent ? hash('sha256', $userAgent) : null;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM affiliate_clicks
             WHERE link_id = :link AND ip_hash = :ip AND clicked_at > datetime('now', '-60 seconds')"
        );
        if ($this->isMysql()) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM affiliate_clicks
                 WHERE link_id = :link AND ip_hash = :ip AND clicked_at > (NOW() - INTERVAL 60 SECOND)'
            );
        }
        $stmt->execute([':link' => $linkId, ':ip' => $ipHash]);
        if ((int)$stmt->fetchColumn() > 0) {
            return; // debounced
        }

        $stmt = $this->db->prepare(
            'INSERT INTO affiliate_clicks (link_id, ip_hash, user_agent_hash) VALUES (:link, :ip, :ua)'
        );
        $stmt->execute([':link' => $linkId, ':ip' => $ipHash, ':ua' => $uaHash]);
    }

    /**
     * Admin action: record a conversion for a link. Creates a PENDING
     * affiliate_commission transaction via WalletService::credit() —
     * does not release it. That's a separate, later admin approval.
     */
    public function recordConversion(int $linkId, int $adminId, ?string $note = null): int
    {
        $stmt = $this->db->prepare(
            'SELECT al.*, ac.commission_amount, ac.title
             FROM affiliate_links al JOIN affiliate_campaigns ac ON ac.id = al.campaign_id
             WHERE al.id = :id'
        );
        $stmt->execute([':id' => $linkId]);
        $link = $stmt->fetch();
        if (!$link) {
            throw new RuntimeException('Link not found.');
        }

        $wallet = new WalletService();
        $txId = $wallet->credit(
            (int)$link['user_id'],
            'affiliate_commission',
            number_format((float)$link['commission_amount'], 2, '.', ''),
            $link['tracking_code'],
            'Conversion: ' . $link['title']
        );

        $stmt = $this->db->prepare(
            'INSERT INTO affiliate_conversions (link_id, transaction_id, commission_amount, recorded_by_admin_id, note)
             VALUES (:link, :tx, :amount, :admin, :note)'
        );
        $stmt->execute([
            ':link' => $linkId, ':tx' => $txId, ':amount' => $link['commission_amount'],
            ':admin' => $adminId, ':note' => $note,
        ]);

        return $txId;
    }

    /** Stats for a single user across all their campaigns. */
    public function statsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM affiliate_clicks c JOIN affiliate_links l ON l.id = c.link_id WHERE l.user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $totalClicks = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT c.ip_hash) FROM affiliate_clicks c JOIN affiliate_links l ON l.id = c.link_id WHERE l.user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $uniqueClicks = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM affiliate_conversions ac JOIN affiliate_links l ON l.id = ac.link_id WHERE l.user_id = :uid"
        );
        $stmt->execute([':uid' => $userId]);
        $conversions = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(t.amount),0) FROM transactions t
             WHERE t.user_id = :uid AND t.type = 'affiliate_commission' AND t.status = 'pending'"
        );
        $stmt->execute([':uid' => $userId]);
        $pendingCommission = (float)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(t.amount),0) FROM transactions t
             WHERE t.user_id = :uid AND t.type = 'affiliate_commission' AND t.status = 'completed'"
        );
        $stmt->execute([':uid' => $userId]);
        $approvedCommission = (float)$stmt->fetchColumn();

        // "Rejected" here means the recorded conversion's underlying
        // transaction was rejected by an admin (see WalletService::
        // rejectPending) — not a separate status on affiliate_conversions
        // itself, since the transaction row is the single source of truth.
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM affiliate_conversions ac
             JOIN affiliate_links l ON l.id = ac.link_id
             JOIN transactions t ON t.id = ac.transaction_id
             WHERE l.user_id = :uid AND t.status = 'failed'"
        );
        $stmt->execute([':uid' => $userId]);
        $rejectedConversions = (int)$stmt->fetchColumn();

        return [
            'total_clicks'         => $totalClicks,
            'unique_clicks'        => $uniqueClicks,
            'conversions'          => $conversions,
            'conversion_rate'      => $totalClicks > 0 ? round($conversions / $totalClicks * 100, 1) : 0.0,
            'pending_commission'   => $pendingCommission,
            'approved_commission'  => $approvedCommission,
            'total_earnings'       => $pendingCommission + $approvedCommission,
            'rejected_conversions' => $rejectedConversions,
        ];
    }

    public function userLinks(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT al.*, ac.title, ac.brand_name, ac.commission_amount,
                    (SELECT COUNT(*) FROM affiliate_clicks WHERE link_id = al.id) AS clicks,
                    (SELECT COUNT(*) FROM affiliate_conversions WHERE link_id = al.id) AS conversions
             FROM affiliate_links al JOIN affiliate_campaigns ac ON ac.id = al.campaign_id
             WHERE al.user_id = :uid ORDER BY al.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** Every link, for the admin conversion-recording screen. */
    public function allLinksWithSearch(string $search = ''): array
    {
        $sql = 'SELECT al.*, ac.title AS campaign_title, ac.commission_amount, u.username
                FROM affiliate_links al
                JOIN affiliate_campaigns ac ON ac.id = al.campaign_id
                JOIN users u ON u.id = al.user_id';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE u.username LIKE :search OR al.tracking_code LIKE :search OR ac.title LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY al.created_at DESC LIMIT 50';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtolower(bin2hex(random_bytes(5))); // 10 hex chars
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM affiliate_links WHERE tracking_code = :c');
            $stmt->execute([':c' => $code]);
        } while ((int)$stmt->fetchColumn() > 0);
        return $code;
    }

    private function isMysql(): bool
    {
        $config = require __DIR__ . '/../config/config.php';
        return $config['db']['driver'] === 'mysql';
    }
}
