<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F67: Portal-only organization; a row alone is not an entitlement. */
final class PortalOrganization extends Model
{
    use HasUuids;

    protected $table = 'portal_organizations';
    protected $guarded = ['*'];
    public $incrementing = false;
    protected $keyType = 'string';

    /** @return HasMany<PortalMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(PortalMembership::class, 'organization_id');
    }

    protected function casts(): array
    {
        return ['security_revision' => 'integer'];
    }
}
