<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * F67: first-party Portal identity persistence ONLY, independent from CRM.
 * No authentication routes, registration, recovery, MFA or guards are enabled.
 */
final class PortalIdentity extends Authenticatable
{
    use HasUuids;

    protected $table = 'portal_identities';
    protected $guarded = ['*'];
    protected $hidden = ['password_hash', 'mfa_secret_ciphertext'];
    public $incrementing = false;
    protected $keyType = 'string';

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** @return HasMany<PortalMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(PortalMembership::class, 'identity_id');
    }

    protected function casts(): array
    {
        return [
            'security_revision' => 'integer',
            'email_verified_at' => 'immutable_datetime',
            'mfa_confirmed_at' => 'immutable_datetime',
        ];
    }
}
