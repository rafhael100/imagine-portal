<?php

declare(strict_types=1);

/**
 * F28 LAB ONLY. Simulates a restricted DB service in the SAME transaction
 * as Ed25519 verification. The CI connects as PostgreSQL fixture owner then
 * SET LOCAL ROLE to a NOLOGIN/no-table-grants role: do not copy into prod.
 */
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/DestinationDeliveryReceiptVerifier.php';

use App\Domain\CommercialPublishing\DestinationDeliveryReceiptVerifier;

final class RestrictedVerifiedDeliveryReceiptConsumerLab
{
    public static function consume(
        PDO $pdo,
        array $envelope,
        array $trustedKeys,
        string $trustedKeyId,
        string $organizationId,
        string $releaseId,
        DateTimeImmutable $now,
    ): array {
        if (!preg_match('/\A[a-z][a-z0-9_-]{5,79}\z/D', $organizationId) ||
            !preg_match('/\A[a-z][a-z0-9_-]{5,79}\z/D', $releaseId)) {
            throw new InvalidArgumentException('F28: invalid trusted organization or release.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
            $pdo->inTransaction()) {
            throw new RuntimeException('F28: requires own PostgreSQL transaction.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        try {
            // CI-only privilege reduction. Never let an HTTP request choose
            // the role or borrow the fixture owner's connection.
            $pdo->exec('SET LOCAL ROLE commercial_lab_receipt_consumer');
            $role=$pdo->query('SELECT current_user')->fetchColumn();
            if ($role !== 'commercial_lab_receipt_consumer') {
                throw new RuntimeException('F28: failed to enter restricted DB role.');
            }
            $stmt=$pdo->prepare(
                'SELECT commercial_publishing_lab.read_current_delivery_scope(?,?)'
            );
            $stmt->execute([$organizationId,$releaseId]);
            $json=$stmt->fetchColumn();
            if (!is_string($json) || $json==='') {
                throw new RuntimeException('F28: no trusted scope for challenged release.');
            }
            $scope=json_decode($json,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($scope)) {
                throw new RuntimeException('F28: invalid scope from read function.');
            }
            // key ID is configured by the caller's trusted destination keyring.
            $expected=$scope+['receiptKeyId'=>$trustedKeyId];
            $verified=DestinationDeliveryReceiptVerifier::verify(
                $envelope,$expected,$trustedKeys,$now
            );
            // The role has EXECUTE, but NOT SELECT, INSERT, UPDATE or DELETE
            // on tables. The wrapper is SECURITY DEFINER and rechecks state.
            $stmt=$pdo->prepare(
                'SELECT commercial_publishing_lab.commit_verified_delivery_receipt(
                    ?,?,?,?,?,CAST(? AS bigint),CAST(? AS bigint),?,?,?,
                    CAST(? AS timestamptz)
                )'
            );
            $stmt->execute([
                $verified['organizationId'],$verified['releaseId'],
                $verified['payloadSha256'],$verified['treeSha256'],
                $verified['dispatchId'],$verified['generation'],$verified['attempt'],
                $verified['destinationId'],$verified['environment'],$verified['nonce'],
                $verified['observedAt']
            ]);
            $committed=$stmt->fetchColumn();
            if (!in_array($committed,[true,'t','1',1],true)) {
                throw new RuntimeException('F28: verified receipt not consumed.');
            }
            $pdo->commit();
            return $verified;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
