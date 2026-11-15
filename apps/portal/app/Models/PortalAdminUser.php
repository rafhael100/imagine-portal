<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Admin account belongs only to the Portal; it is NOT CRM identity, customer
 * tenancy, GSC property ownership or evidence of a business authorization.
 */
final class PortalAdminUser extends Authenticatable
{
    protected $table='portal_admin_users';
    protected $guarded=['*'];

    protected $hidden=[
        'password_hash','totp_secret_ciphertext','last_totp_counter'
    ];

    protected $casts=[
        'is_active'=>'boolean',
        'last_totp_counter'=>'integer',
        'session_version'=>'integer',
    ];

    public function getAuthPasswordName():string
    {
        return 'password_hash';
    }

    public function getAuthPassword():string
    {
        return (string)$this->password_hash;
    }
}
