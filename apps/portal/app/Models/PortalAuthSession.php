<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PortalAccess\Authority\PortalSessionPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hashed bearer row, NOT an authenticated principal or grant.
 * A verified Portal-only authority must check a matching membership,
 * current MFA and revocation floor independently before any use.
 */
final class PortalAuthSession extends Model
{
    use HasUuids;

    protected $table = 'portal_auth_sessions';
    protected $guarded = ['*'];
    protected $hidden = ['session_sha256'];
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

    protected function casts(): array
    {
        return [
            'identity_revision' => 'integer',
            'membership_revision' => 'integer',
            // F76/F113: legacy NULL stays untrusted, recognized values
            // hydrate to the exact domain enum, never plain free text.
            'organization_revision' => 'integer',
            'session_purpose' => PortalSessionPurpose::class,
            'mfa_verified_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
