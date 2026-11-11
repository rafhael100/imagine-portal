<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Authorization\Permission;
use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PortalAccess\Authority\DenyingPortalAuthorities;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionSnapshot;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use App\Labs\F26\F26AuthorityReadRehearsal;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F26 only: cross-check F1 synthetic boundary + signed F24 identity + PG RLS.
 * Providers here are fake and cannot authorize an actual HTTP/customer read.
 */
final class F26AuthorityReadRehearsalTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            self::markTestSkipped('F26 is disabled without disposable PostgreSQL.');
        }
    }

    private function db(string $role = 'a'): PDO
    {
        $identities = [
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
        ];
        if (!isset($identities[$role])
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || preg_match('/\A[0-9]{4,5}\z/D', (string) getenv('F22_PG_PORT')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $identities[$role][1]) !== 1) {
            throw new RuntimeException('F26 requires temporary local fixture DB.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.getenv('F22_PG_PORT').';dbname=postgres;connect_timeout=3;sslmode=disable',
            $identities[$role][0],
            $identities[$role][1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function token(string $key, array $change = []): string
    {
        $claims = [
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('c', 32),
            'org' => 'org-a',
            'sid' => 'DEMO-SESSION-A',
            'srev' => 1,
            'sub' => 'DEMO-USER-A',
            'ver' => 1,
        ];
        return F24LabSessionProof::signFixture(array_replace($claims, $change), $key);
    }

    private function snapshot(
        string $actor = 'DEMO-USER-A',
        bool $active = true,
        bool $mfa = true,
        int $sessionRevision = 1,
        string $sessionOrg = 'org-a',
        int $sessionMembershipRevision = 1,
    ): PortalSessionSnapshot {
        return new PortalSessionSnapshot(
            new PortalUserId($actor),
            $active,
            $mfa,
            1800000000,
            1800000900,
            1,
            $sessionRevision,
            new OrganizationId($sessionOrg),
            $sessionMembershipRevision,
        );
    }

    private function member(
        string $actor = 'DEMO-USER-A',
        string $org = 'org-a',
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ViewServices],
        int $revision = 1,
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($org),
            OrganizationRole::Member,
            $status,
            $permissions,
            $revision,
        );
    }

    private function fixture(
        ?PortalSessionSnapshot $session = null,
        ?OrganizationMembership $membership = null,
        ?OrganizationId $owner = null,
        bool $failSession = false,
    ): object {
        return new class(
            $session ?? $this->snapshot(),
            $membership ?? $this->member(),
            $owner ?? new OrganizationId('org-a'),
            $failSession,
        ) implements PortalSessionAuthority, PortalMembershipAuthority, PortalResourceAuthority {
            public function __construct(
                private PortalSessionSnapshot $session,
                private OrganizationMembership $membership,
                private OrganizationId $owner,
                private bool $failSession,
            ) {
            }

            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                if ($this->failSession) {
                    throw new RuntimeException('synthetic session backend secret should not escape');
                }
                return $opaqueReference === 'DEMO-SESSION-A' ? $this->session : null;
            }

            public function findFor(PortalUserId $actor, OrganizationId $organization): ?OrganizationMembership
            {
                return $this->membership;
            }

            public function ownerOf(OrganizationId $selectedOrganization, string $opaqueResourceReference): ?OrganizationId
            {
                return $opaqueResourceReference === 'DEMO-SVC-ONEPAGE' ? $this->owner : null;
            }
        };
    }

    private function rehearsal(PDO $pdo, string $key, object $sources): F26AuthorityReadRehearsal
    {
        self::assertInstanceOf(PortalSessionAuthority::class, $sources);
        self::assertInstanceOf(PortalMembershipAuthority::class, $sources);
        self::assertInstanceOf(PortalResourceAuthority::class, $sources);

        return new F26AuthorityReadRehearsal(
            new PortalAuthorityBoundary($sources, $sources, $sources),
            $sources,
            new F24SyntheticAuthorizedReader($pdo, $key),
            $key,
        );
    }

    private function read(
        F26AuthorityReadRehearsal $rehearsal,
        ?string $token,
        ?string $session = 'DEMO-SESSION-A',
        ?string $org = 'org-a',
        ?string $resource = 'DEMO-SVC-ONEPAGE',
        int $now = self::NOW,
        int $limit = 10,
        ?string $after = null,
    ): DemoServiceRelationPage {
        return $rehearsal->read(
            $session, $org === null ? null : new OrganizationId($org),
            $resource, $token, $now, $limit, $after,
        );
    }

    private function assertBlocked(DemoServiceRelationPage $page, string $reason): void
    {
        self::assertSame($reason, $page->reason);
        self::assertFalse($page->isPreparedPreview());
        self::assertSame([], $page->rows);
        self::assertSame(0, $page->totalServices);
        self::assertSame(0, $page->totalRequests);
        self::assertSame(0, $page->totalContracts);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());
    }

    public function test_positive_f1_boundary_plus_f24_session_only_prepares_lab_preview(): void
    {
        $key = random_bytes(32);
        $pdo = $this->db();
        $reader = $this->rehearsal($pdo, $key, $this->fixture());
        $page = $this->read($reader, $this->token($key));
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(4, $page->totalServices);
        self::assertSame(3, $page->totalRequests);
        self::assertSame(3, $page->totalContracts);
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());
        self::assertFalse($pdo->inTransaction());
    }

    public function test_default_denying_authorities_refuse_even_valid_signed_pg_fixture(): void
    {
        $key = random_bytes(32);
        $pdo = $this->db();
        $denying = new DenyingPortalAuthorities();
        $reader = $this->rehearsal($pdo, $key, $denying);
        $this->assertBlocked($this->read($reader, $this->token($key)), 'untrusted_lab_authority');
        self::assertFalse($pdo->inTransaction());
    }

    public function test_actor_in_f1_session_must_match_subject_of_signed_lab_fixture(): void
    {
        $key = random_bytes(32);
        $reader = $this->rehearsal(
            $this->db(), $key,
            $this->fixture(session: $this->snapshot(actor: 'DEMO-USER-OTHER')),
        );
        $this->assertBlocked($this->read($reader, $this->token($key)), 'untrusted_lab_authority');
    }

    public function test_inactive_or_stale_f1_session_denies_before_pg_read(): void
    {
        $key = random_bytes(32);
        foreach ([
            $this->snapshot(active: false),
            $this->snapshot(mfa: false),
            $this->snapshot(sessionRevision: 2),
        ] as $snapshot) {
            $reader = $this->rehearsal(
                $this->db(), $key, $this->fixture(session: $snapshot),
            );
            $this->assertBlocked($this->read($reader, $this->token($key)), 'untrusted_lab_authority');
        }
    }

    public function test_f1_session_cannot_cross_tenant_even_when_f24_token_and_member_are_for_selected_org(): void
    {
        $key = random_bytes(32);
        $sources = $this->fixture(session: $this->snapshot(sessionOrg: 'org-b'));
        $reader = $this->rehearsal($this->db(), $key, $sources);
        $this->assertBlocked(
            $this->read($reader, $this->token($key)),
            'untrusted_lab_authority',
        );
    }

    public function test_older_membership_revision_denies_synthetic_pg_projection(): void
    {
        $key = random_bytes(32);
        $reader = $this->rehearsal(
            $this->db(), $key,
            $this->fixture(membership: $this->member(revision: 2)),
        );
        $this->assertBlocked(
            $this->read($reader, $this->token($key)),
            'untrusted_lab_authority',
        );
    }

    public function test_revoked_membership_missing_grant_or_wrong_resource_owner_denies(): void
    {
        $key = random_bytes(32);
        $cases = [
            $this->fixture(membership: $this->member(status: MembershipStatus::Revoked)),
            $this->fixture(membership: $this->member(permissions: [])),
            $this->fixture(membership: $this->member(permissions: [Permission::ViewBilling])),
            $this->fixture(membership: $this->member(actor: 'DEMO-USER-X')),
            $this->fixture(membership: $this->member(org: 'org-b')),
            $this->fixture(owner: new OrganizationId('org-b')),
        ];
        foreach ($cases as $fixture) {
            $reader = $this->rehearsal($this->db(), $key, $fixture);
            $this->assertBlocked($this->read($reader, $this->token($key)), 'untrusted_lab_authority');
        }
    }

    public function test_malformed_references_crm_cookie_and_mismatching_token_are_denied(): void
    {
        $key = random_bytes(32);
        $reader = $this->rehearsal($this->db(), $key, $this->fixture());
        $cases = [
            [$this->token($key), null, 'org-a', 'DEMO-SVC-ONEPAGE'],
            [$this->token($key), 'crm-cookie', 'org-a', 'DEMO-SVC-ONEPAGE'],
            [$this->token($key), 'DEMO-SESSION-A', null, 'DEMO-SVC-ONEPAGE'],
            [$this->token($key), 'DEMO-SESSION-A', 'org-b', 'DEMO-SVC-ONEPAGE'],
            [$this->token($key), 'DEMO-SESSION-A', 'org-a', null],
            [$this->token($key), 'DEMO-SESSION-A', 'org-a', '../customer'],
            [$this->token($key, ['sid' => 'DEMO-SESSION-B']), 'DEMO-SESSION-A', 'org-a', 'DEMO-SVC-ONEPAGE'],
            [$this->token($key, ['sub' => 'DEMO-USER-X']), 'DEMO-SESSION-A', 'org-a', 'DEMO-SVC-ONEPAGE'],
            ['invalid.token', 'DEMO-SESSION-A', 'org-a', 'DEMO-SVC-ONEPAGE'],
            [null, 'DEMO-SESSION-A', 'org-a', 'DEMO-SVC-ONEPAGE'],
        ];
        foreach ($cases as [$token, $session, $org, $resource]) {
            $expected = $org === null ? 'missing_organization' : 'untrusted_lab_authority';
            $this->assertBlocked($this->read($reader, $token, $session, $org, $resource), $expected);
        }
    }

    public function test_provider_exception_and_missing_resource_owner_never_reach_db(): void
    {
        $key = random_bytes(32);
        $broken = $this->rehearsal($this->db(), $key, $this->fixture(failSession: true));
        $this->assertBlocked($this->read($broken, $this->token($key)), 'untrusted_lab_authority');
        $missing = $this->rehearsal($this->db(), $key, $this->fixture());
        $this->assertBlocked(
            $this->read($missing, $this->token($key), resource: 'DEMO-SVC-UNKNOWN'),
            'untrusted_lab_authority',
        );
    }

    public function test_f1_positive_can_never_override_pg_role_tenant_mismatch(): void
    {
        $key = random_bytes(32);
        $reader = $this->rehearsal($this->db('b'), $key, $this->fixture());
        $this->assertBlocked($this->read($reader, $this->token($key)), 'untrusted_db_session');
    }

    public function test_pg_revocation_between_pages_denies_new_page_despite_f1_preview_pass(): void
    {
        $key = random_bytes(32);
        $reader = $this->rehearsal($this->db(), $key, $this->fixture());
        $token = $this->token($key);
        $first = $this->read($reader, $token, limit: 1);
        self::assertTrue($first->isPreparedPreview());
        self::assertSame('DEMO-SVC-LANDING', $first->nextCursor);

        $admin = $this->dbAdmin();
        $admin->exec(
            "UPDATE f24.session_fixture SET mock_state='revoked'
              WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'"
        );
        try {
            $this->assertBlocked(
                $this->read($reader, $token, limit: 1, after: $first->nextCursor),
                'revoked_lab_identity',
            );
        } finally {
            $admin->exec(
                "UPDATE f24.session_fixture SET mock_state='active'
                  WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'"
            );
        }
        self::assertTrue($this->read($reader, $token, limit: 1)->isPreparedPreview());
    }

    private function dbAdmin(): PDO
    {
        $pass = getenv('F22_PG_PASSWORD_ADMIN');
        if (!is_string($pass) || preg_match('/\A[a-f0-9]{64}\z/D', $pass) !== 1
            || getenv('F22_PG_HOST') !== '127.0.0.1') {
            throw new RuntimeException('F26 admin exists only in disposable container CI.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.getenv('F22_PG_PORT').';dbname=postgres;connect_timeout=3;sslmode=disable',
            'postgres',
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
