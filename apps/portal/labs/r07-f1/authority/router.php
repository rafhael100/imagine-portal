<?php

declare(strict_types=1);

/**
 * R07-F1: THROWAWAY synthetic authority PoC. Not Portal middleware/authentication.
 * Z1 knows only an opaque fake actor-A handle, never a database credential.
 * No cookie, MFA, user provisioning, real session issuer, or production endpoint.
 */
function reply(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function deny(PDO $db, int $status): never
{
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    reply($status, ['error' => $status === 401 ? 'unauthorized'
        : ($status === 409 ? 'idempotency_conflict' : 'access_denied')]);
}

function safeId(mixed $id): ?string
{
    if (! is_string($id)
        || strlen($id) === 0
        || strlen($id) > 64
        || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) !== 1) {
        return null;
    }
    return $id;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($path === '/health' && $method === 'GET') {
    reply(200, ['healthy' => true, 'synthetic' => true]);
}

if (! ($path === '/v1/resource' && $method === 'GET')
    && ! ($path === '/v1/effect' && $method === 'POST')) {
    reply(404, ['error' => 'not_found']);
}

// Any actor/tenant/role/mfa/SQL claims in request URL, headers or JSON are
// deliberately NOT read as authority.
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (! is_string($authorization)
    || preg_match('/^Bearer ([0-9a-f]{64})$/D', $authorization, $matches) !== 1) {
    reply(401, ['error' => 'unauthorized']);
}

$input = [];
if ($method === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        reply(400, ['error' => 'invalid_input']);
    }
    if (! is_array($input)) {
        reply(400, ['error' => 'invalid_input']);
    }
}
$resourceId = safeId($method === 'GET' ? ($_GET['id'] ?? null) : ($input['resource_id'] ?? null));
$effectId = $method === 'POST' ? safeId($input['effect_id'] ?? null) : null;

if ($resourceId === null || ($method === 'POST' && $effectId === null)) {
    reply(400, ['error' => 'invalid_input']);
}

try {
    $dsn = getenv('R07_DB_DSN');
    $user = getenv('R07_DB_USER');
    $password = getenv('R07_DB_PASSWORD');
    if ($dsn !== 'pgsql:host=pg;port=5432;dbname=portal_r07_f1'
        || $user !== 'r07_authority'
        || ! is_string($password)
        || strlen($password) < 20) {
        reply(503, ['error' => 'service_unavailable']);
    }

    $db = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $db->beginTransaction();

    // Preliminary actor lookup is NOT authority. Recheck AFTER acquiring
    // advisory barriers inside this transaction, prior to any real effect.
    $verifierDigest = hash('sha256', $matches[1]);
    $precheck = $db->prepare(
        "SELECT actor_id FROM r07.session WHERE verifier_sha256 = :verifier"
    );
    $precheck->execute(['verifier' => $verifierDigest]);
    $candidateActor = $precheck->fetchColumn();
    if (! is_string($candidateActor)) {
        deny($db, 401);
    }

    // Every synthetic UPDATE/DELETE writer participates through BEFORE
    // triggers in schema.sql. Lock order A -> S -> O -> M in this lab;
    // the full Portal P -> A -> S -> O -> M -> R protocol is STILL UNPROVEN.
    $lock = $db->prepare(
        "SELECT pg_advisory_xact_lock_shared(:namespace, hashtext(CAST(:key AS text)))"
    );
    $lock->execute(['namespace' => 771001, 'key' => $candidateActor]);
    $lock->execute(['namespace' => 771002, 'key' => $verifierDigest]);


    // Synthetic verifier / epoch, solely from protected PostgreSQL authority.
    $session = $db->prepare(
        "SELECT s.actor_id
         FROM r07.session s
         JOIN r07.account a ON a.actor_id = s.actor_id
         WHERE s.verifier_sha256 = :verifier
           AND s.active AND a.active
           AND s.session_epoch = a.session_epoch
           AND s.expires_at > clock_timestamp()"
    );
    $session->execute(['verifier' => hash('sha256', $matches[1])]);
    $actorId = $session->fetchColumn();
    if (! is_string($actorId)) {
        deny($db, 401);
    }

    // Derive organization from the protected resource index, NOT user claims.
    $lock->execute(['namespace' => 771003, 'key' => $resourceId]);
    $resource = $db->prepare(
        'SELECT organization_id FROM r07.resource_index WHERE resource_id = :resource_id'
    );
    $resource->execute(['resource_id' => $resourceId]);
    $organizationId = $resource->fetchColumn();
    if (! is_string($organizationId)) {
        deny($db, 403);
    }

    $lock->execute(['namespace' => 771004,
                    'key' => $actorId . ':' . $organizationId]);
    $membership = $db->prepare(
        "SELECT CASE WHEN can_read THEN 1 ELSE 0 END AS can_read,
                CASE WHEN can_write THEN 1 ELSE 0 END AS can_write
         FROM r07.membership
         WHERE actor_id = :actor AND organization_id = :organization"
    );
    $membership->execute(['actor' => $actorId, 'organization' => $organizationId]);
    $policy = $membership->fetch();
    if (! is_array($policy)
        || ($method === 'GET' && ! (bool) $policy['can_read'])
        || ($method === 'POST' && ! (bool) $policy['can_write'])) {
        deny($db, 403);
    }

    // RLS adds defense inside Z2; GUC is NEVER an identity primitive.
    // If Z2 itself is compromised, this GUC can still be forged.
    $context = $db->prepare("SELECT set_config('r07.tenant', :tenant, true)");
    $context->execute(['tenant' => $organizationId]);

    $payload = $db->prepare(
        "SELECT payload FROM r07.private_resource
         WHERE organization_id = :organization AND resource_id = :resource"
    );
    $payload->execute(['organization' => $organizationId, 'resource' => $resourceId]);
    $data = $payload->fetchColumn();
    if (! is_string($data)) {
        deny($db, 403);
    }

    if ($method === 'GET') {
        $db->commit();
        reply(200, ['payload' => $data]);
    }


    // Controlled and explicit LAB-ONLY concurrency hook after all barriers.
    // Never copy this into Portal production services or migrations.
    if (getenv('R07_LAB_HOLD_ENABLED') === '1'
        && ($_SERVER['HTTP_X_R07_LAB_HOLD_MS'] ?? '') === '1800') {
        $db->query('SELECT pg_sleep(1.8)');
    }

    // The SAME idempotency key with DIFFERENT business intent must be an
    // explicit conflict, not an apparently successful replay.
    $commandDigest = hash('sha256', json_encode([
        'domain' => 'R07-F1-SYNTHETIC-EFFECT-V2',
        'operation' => 'create_effect',
        'organization_id' => $organizationId,
        'actor_id' => $actorId,
        'resource_id' => $resourceId,
        'effect_id' => $effectId,
    ], JSON_THROW_ON_ERROR));

    $write = $db->prepare(
        "INSERT INTO r07.effect (organization_id, effect_id, resource_id, actor_id, command_sha256)
         VALUES (:organization, :effect, :resource, :actor, :digest)
         ON CONFLICT (organization_id, effect_id) DO NOTHING
         RETURNING effect_id"
    );
    $write->execute([
        'organization' => $organizationId,
        'effect' => $effectId,
        'resource' => $resourceId,
        'actor' => $actorId,
        'digest' => $commandDigest,
    ]);
    $inserted = $write->fetchColumn() !== false;
    if (! $inserted) {
        $previous = $db->prepare(
            "SELECT command_sha256 FROM r07.effect
             WHERE organization_id = :organization AND effect_id = :effect"
        );
        $previous->execute(['organization' => $organizationId, 'effect' => $effectId]);
        $savedDigest = $previous->fetchColumn();
        if (! is_string($savedDigest) || ! hash_equals($savedDigest, $commandDigest)) {
            deny($db, 409);
        }
    }
    $db->commit();
    reply($inserted ? 201 : 200, [
        'recorded' => true,
        'idempotent_replay' => ! $inserted,
    ]);
} catch (Throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    // No SQL, tenant IDs, secrets, statements or exceptions exposed in response.
    error_log('R07-F1: synthetic authority request failed (details withheld)');
    reply(503, ['error' => 'service_unavailable']);
}
