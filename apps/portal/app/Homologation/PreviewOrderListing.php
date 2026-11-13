<?php

declare(strict_types=1);
namespace App\Homologation;
use PDO;
final class PreviewOrderListing
{
    public function __construct(private PDO $db) { new RoleBoundTestPurchase($db); }
    public function rows(): array
    {
        return $this->db->query('SELECT id, product, payment, revision, (briefing IS NOT NULL) AS briefing_saved FROM purchase_rehearsal.orders ORDER BY id LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }
}
