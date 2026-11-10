<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Membership state is NOT sufficient for authorization; permission must be
 * separately granted and verified by a trusted, current Portal authority.
 */
final class PortalMembership extends Model
{
    use HasUuids;

    protected $table = 'portal_memberships';
    protected $guarded = ['*'];
    public $incrementing = false;
    protected $keyType = 'string';

    /** @return BelongsTo<PortalIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'identity_id');
    }

    /** @return BelongsTo<PortalOrganization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(PortalOrganization::class, 'organization_id');
    }

    /** @return HasMany<PortalMembershipGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(PortalMembershipGrant::class, 'membership_id');
    }

    protected function casts(): array
    {
        return ['membership_revision' => 'integer'];
    }
}
