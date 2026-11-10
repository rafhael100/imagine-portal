<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Explicit membership permission; a model instance is not an allow token. */
final class PortalMembershipGrant extends Model
{
    use HasUuids;

    protected $table = 'portal_membership_grants';
    protected $guarded = ['*'];
    public $incrementing = false;
    protected $keyType = 'string';

    /** @return BelongsTo<PortalMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(PortalMembership::class, 'membership_id');
    }
}
