<?php

declare(strict_types=1);

/**
 * F27 — EXCLUSIVAMENTE EM LABORATÓRIO.
 * Une verificação Ed25519 de testemunha com consumo único do nonce no PG18.
 * Não é um endpoint Laravel, não recebe sessão HTTP e não faz publicação.
 * O PDO desta prova usa o superusuário do banco DESCARTÁVEL do CI: jamais
 * reutilizar a configuração, credenciais ou concessões em produção.
 */
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/DestinationDeliveryReceiptVerifier.php';

use App\Domain\CommercialPublishing\DestinationDeliveryReceiptVerifier;

final class VerifiedDeliveryReceiptConsumerLab
{
    /**
     * @param array<string,mixed> $envelope Recibo supostamente assinado pelo destino.
     * @param array<string,string> $trustedKeys keyId => chave pública RAW.
     * @return array<string,mixed> Escopo efetivamente consumido no laboratório.
     */
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
            throw new InvalidArgumentException('F27: invalid trusted organization or release.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('F27: PostgreSQL is required.');
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('F27: consumer must own the transaction.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        try {
            // Lock revocation first; every F7/F26 writer uses the same fence.
            $stmt=$pdo->prepare(
                'SELECT generation,enabled FROM commercial_publishing_lab.publication_fence
                 WHERE organization_id=? FOR UPDATE'
            );
            $stmt->execute([$organizationId]);
            $fence=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fence || !in_array($fence['enabled'], [true,'t','1',1], true)) {
                throw new RuntimeException('F27: publication authority missing or disabled.');
            }

            // Read trusted database state, never use claims from the incoming
            // envelope to invent an expected dispatch, target or nonce.
            $stmt=$pdo->prepare(
                'SELECT r.payload_sha256,r.dispatch_id,r.generation AS dispatch_generation,
                        b.tree_sha256,b.destination_id,b.environment,
                        l.authority_generation AS lease_generation,
                        l.attempt_number,l.lease_until,l.acknowledged_at,
                        c.authority_generation AS challenge_generation,c.nonce
                 FROM commercial_publishing_lab.dispatch_receipts r
                 JOIN commercial_publishing_lab.verified_build_targets b
                   ON b.organization_id=r.organization_id
                   AND b.release_id=r.release_id
                   AND b.payload_sha256=r.payload_sha256
                   AND b.authority_generation=r.generation
                 JOIN commercial_publishing_lab.delivery_leases l
                   ON l.organization_id=r.organization_id AND l.release_id=r.release_id
                   AND l.payload_sha256=r.payload_sha256
                 JOIN commercial_publishing_lab.delivery_receipt_challenges c
                   ON c.organization_id=r.organization_id AND c.release_id=r.release_id
                   AND c.attempt_number=l.attempt_number
                 WHERE r.organization_id=? AND r.release_id=?
                 FOR UPDATE OF l'
            );
            $stmt->execute([$organizationId,$releaseId]);
            $state=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$state || $state['acknowledged_at'] !== null) {
                throw new RuntimeException('F27: no unacknowledged challenged delivery.');
            }
            $generation=(int)$fence['generation'];
            if ((int)$state['dispatch_generation'] !== $generation ||
                (int)$state['lease_generation'] !== $generation ||
                (int)$state['challenge_generation'] !== $generation) {
                throw new RuntimeException('F27: stale authority generation.');
            }

            $expected=[
                'organizationId'=>$organizationId,
                'releaseId'=>$releaseId,
                'payloadSha256'=>$state['payload_sha256'],
                'treeSha256'=>$state['tree_sha256'],
                'dispatchId'=>$state['dispatch_id'],
                'generation'=>$generation,
                'attempt'=>(int)$state['attempt_number'],
                'destinationId'=>$state['destination_id'],
                'environment'=>$state['environment'],
                'nonce'=>$state['nonce'],
                'receiptKeyId'=>$trustedKeyId
            ];
            $verified=DestinationDeliveryReceiptVerifier::verify(
                $envelope,$expected,$trustedKeys,$now
            );
            // Same transaction: SQL checks lease expiry, nonce, revocation,
            // build hash and uniqueness again, before writing ACK + consumption.
            $sql='SELECT commercial_publishing_lab.consume_preverified_delivery_receipt(
                ?,?,?,?,?,CAST(? AS bigint),CAST(? AS bigint),?,?,?,CAST(? AS timestamptz)
            ) AS consumed';
            $stmt=$pdo->prepare($sql);
            $stmt->execute([
                $verified['organizationId'],$verified['releaseId'],
                $verified['payloadSha256'],$verified['treeSha256'],
                $verified['dispatchId'],$verified['generation'],$verified['attempt'],
                $verified['destinationId'],$verified['environment'],$verified['nonce'],
                $verified['observedAt']
            ]);
            $result=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result || !in_array($result['consumed'], [true,'t','1',1], true)) {
                throw new RuntimeException('F27: verified receipt was not consumed.');
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
