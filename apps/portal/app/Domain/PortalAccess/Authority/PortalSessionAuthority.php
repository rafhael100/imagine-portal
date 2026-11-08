<?php

namespace App\Domain\PortalAccess\Authority;

/**
 * Future PORTAL-ONLY session source contract.
 *
 * No production adapter exists in F1. An interface implementation or a
 * PortalSessionSnapshot object is NOT evidence of issuer, MFA, revocation,
 * integrity or session ownership. Never wire an arbitrary test fake to HTTP.
 */
interface PortalSessionAuthority
{
    public function find(string $opaqueReference): ?PortalSessionSnapshot;
}
