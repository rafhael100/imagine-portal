<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;
use App\Labs\F44\F44SyntheticSiteReviewWriter;
use App\Labs\F45\F45SyntheticReleaseConsistencyReader;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F45 positive results are fake editorial consistency findings, NOT a
 * customer-authenticated website release or deployment authorization.
 */
final class F45ReleaseConsistencyIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB')!=='NON_OPERATIONAL_F22'
            || getenv('F44_ENABLE_LAB')!=='SYNTHETIC_SITES_REVIEW_ONLY'
            || getenv('F45_ENABLE_LAB')!=='READ_ONLY_SITES_CONSISTENCY') {
            self::markTestSkipped('F45 requires disposable localhost PostgreSQL18.');
        }
        $this->reset();
        foreach (['a','b'] as $tenant) {
            $this->store($this->candidate($tenant));
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F45_ENABLE_LAB')==='READ_ONLY_SITES_CONSISTENCY') {
            $this->reset();
        }
        parent::tearDown();
    }

    private function db(string $identity): PDO
    {
        $users=[
            'admin'=>['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
            'writer-a'=>['f44_reviewer_a',getenv('F44_PG_PASSWORD_A')],
            'writer-b'=>['f44_reviewer_b',getenv('F44_PG_PASSWORD_B')],
            'reader-a'=>['f45_consistency_reader_a',getenv('F45_PG_PASSWORD_A')],
            'reader-b'=>['f45_consistency_reader_b',getenv('F45_PG_PASSWORD_B')],
        ];
        $login=$users[$identity] ?? null;
        $port=getenv('F22_PG_PORT');
        if ($login===null || getenv('F22_PG_HOST')!=='127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D',$port)!==1
            || (int)$port>65535
            || !is_string($login[1])
            || preg_match('/\A[a-f0-9]{64}\z/D',$login[1])!==1) {
            throw new RuntimeException('F45 refuses non-local SQL data or identities.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $login[0],$login[1],[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function reset(): void
    {
        $admin=$this->db('admin');
        $admin->exec('DELETE FROM f44.review_receipt');
        $admin->exec('DELETE FROM f44.site_review');
    }

    private function candidate(
        string $tenant='a',
        int $contentRevision=1,
        int $briefRevision=0,
        ?string $headline=null,
    ): SiteContentCandidate {
        return new SiteContentCandidate(
            new SiteBriefDraft(
                'DEMO-SITE-REQ-'.strtoupper($tenant).'1',
                new OrganizationId('org-'.$tenant),
                PortfolioProduct::Onepage,
                'Explicar os serviços com clareza para visitantes interessados.',
                'Pequenas empresas locais',
                'Solicitar uma proposta de teste',
                $briefRevision,
            ),
            $contentRevision,
            $headline ?? 'Sua página comercial em destaque',
            'Descrição comercial inteiramente fictícia de serviços e atividades para explicar a proposta ao público.',
            'Solicitar informações adicionais',
        );
    }

    private function command(
        SiteContentCandidate $candidate,
        string $id='DEMO-REVIEW-CMD-001',
    ): SiteReviewCommand {
        $tenant=$candidate->brief->organizationId->value;
        return new SiteReviewCommand(
            $id,$candidate->brief->organizationId,
            $candidate->brief->reference,$candidate->fingerprint,
            $tenant==='org-a'?'DEMO-REVIEWER-A':'DEMO-REVIEWER-B',
            0,SiteReviewDecision::Approve,1800000030,
        );
    }

    private function store(SiteContentCandidate $candidate): void
    {
        $stmt=$this->db('admin')->prepare(
            'INSERT INTO f44.site_review (
                organization_id,site_reference,content_sha256,
                brief_revision,content_revision,review_status,review_revision)
             VALUES (:org,:site,:hash,:brief,:content,:status,:review)
             ON CONFLICT (organization_id,site_reference)
             DO UPDATE SET content_sha256=EXCLUDED.content_sha256,
                 brief_revision=EXCLUDED.brief_revision,
                 content_revision=EXCLUDED.content_revision,
                 review_status=EXCLUDED.review_status,
                 review_revision=EXCLUDED.review_revision'
        );
        $stmt->execute([
            'org'=>$candidate->brief->organizationId->value,
            'site'=>$candidate->brief->reference,
            'hash'=>$candidate->fingerprint,
            'brief'=>$candidate->brief->revision,
            'content'=>$candidate->contentRevision,
            'status'=>'awaiting_review_demo',
            'review'=>0,
        ]);
    }

    private function approve(SiteContentCandidate $candidate,string $commandId='DEMO-REVIEW-CMD-001'): void
    {
        $role=$candidate->brief->organizationId->value==='org-a'?'writer-a':'writer-b';
        $result=(new F44SyntheticSiteReviewWriter($this->db($role)))
            ->apply($candidate,$this->command($candidate,$commandId));
        self::assertSame('applied_synthetic_sql',$result->reason);
        self::assertFalse($result->authorizesPublishing());
    }

    private function check(SiteContentCandidate $candidate,string $identity='reader-a')
    {
        return (new F45SyntheticReleaseConsistencyReader($this->db($identity)))
            ->check($candidate);
    }

    private function receiptCount(): int
    {
        return (int)$this->db('admin')->query('SELECT count(*) FROM f44.review_receipt')
            ->fetchColumn();
    }

    public function test_pending_candidate_is_not_consistent_or_authorized(): void
    {
        $result=$this->check($this->candidate());
        self::assertSame('not_approved',$result->reason);
        self::assertFalse($result->isConsistentInLab());
        self::assertFalse($result->mayPublishSite());
        self::assertFalse($result->isVerifiedClientApproval());
        self::assertFalse($result->mayAuthorizeContractOrCharge());
        self::assertSame(0,$this->receiptCount());
    }

    public function test_exact_fake_approval_and_receipt_are_consistent_only_in_lab(): void
    {
        $candidate=$this->candidate();
        $this->approve($candidate);
        $result=$this->check($candidate);
        self::assertSame('consistent_approved_demo',$result->reason);
        self::assertTrue($result->isConsistentInLab());
        self::assertFalse($result->mayPublishSite());
        self::assertFalse($result->isVerifiedClientApproval());
        self::assertFalse($result->mayAuthorizeContractOrCharge());
        self::assertSame(1,$this->receiptCount());
        self::assertTrue((new \ReflectionClass($result))->isReadOnly());
    }

    public function test_tenant_b_has_independent_consistency_but_a_reader_cannot_check_b(): void
    {
        $candidate=$this->candidate('b');
        $this->approve($candidate,'DEMO-REVIEW-CMD-B-001');
        self::assertSame('untrusted_sql_role',$this->check($candidate,'reader-a')->reason);
        self::assertSame('consistent_approved_demo',$this->check($candidate,'reader-b')->reason);
        self::assertSame(0,(int)$this->db('reader-a')->query(
            "SELECT count(*) FROM f44.site_review WHERE organization_id='org-b'"
        )->fetchColumn());
        self::assertFalse($this->check($candidate,'reader-b')->mayPublishSite());
    }

    public function test_admin_and_fake_writer_roles_cannot_impersonate_consistency_reader(): void
    {
        $candidate=$this->candidate();
        $this->approve($candidate);
        self::assertSame('untrusted_sql_role',$this->check($candidate,'admin')->reason);
        self::assertSame('untrusted_sql_role',$this->check($candidate,'writer-a')->reason);
    }

    public function test_old_candidate_has_changed_hash_after_new_version_is_installed(): void
    {
        $old=$this->candidate();
        $this->approve($old);
        $new=$this->candidate(contentRevision:2,headline:'Nova versão do título de demonstração');
        $this->store($new);
        self::assertSame('content_changed',$this->check($old)->reason);
        self::assertSame('not_approved',$this->check($new)->reason);
        self::assertSame(1,$this->receiptCount());
        $this->approve($new,'DEMO-REVIEW-CMD-NEW-002');
        self::assertSame('consistent_approved_demo',$this->check($new)->reason);
        self::assertSame('content_changed',$this->check($old)->reason);
        self::assertSame(2,$this->receiptCount());
    }

    public function test_same_copy_with_new_brief_revision_must_be_approved_again(): void
    {
        $old=$this->candidate();
        $this->approve($old);
        $new=$this->candidate(briefRevision:1);
        $this->store($new);
        self::assertNotSame($old->fingerprint,$new->fingerprint);
        self::assertSame('content_changed',$this->check($old)->reason);
        self::assertSame('not_approved',$this->check($new)->reason);
        self::assertFalse($this->check($old)->mayPublishSite());
    }

    public function test_admin_forged_approved_status_without_a_receipt_cannot_pass(): void
    {
        $this->db('admin')->exec(
            "UPDATE f44.site_review SET review_status='approved_demo',
                    review_revision=1 WHERE organization_id='org-a'"
        );
        self::assertSame('missing_receipt',$this->check($this->candidate())->reason);
        self::assertSame(0,$this->receiptCount());
    }

    public function test_mismatched_approval_receipt_is_not_consistent(): void
    {
        $candidate=$this->candidate();
        $this->approve($candidate);
        $this->db('admin')->exec(
            "UPDATE f44.review_receipt SET simulated_decision='reject'
              WHERE organization_id='org-a'"
        );
        self::assertSame('receipt_mismatch',$this->check($candidate)->reason);
        self::assertFalse($this->check($candidate)->mayPublishSite());
    }

    public function test_multiple_receipts_for_same_current_candidate_are_ambiguous(): void
    {
        $candidate=$this->candidate();
        $this->approve($candidate);
        $admin=$this->db('admin');
        $insert=$admin->prepare(
            "INSERT INTO f44.review_receipt (
                organization_id,site_reference,command_id,command_sha256,
                content_sha256,simulated_decision,resulting_status,
                resulting_review_revision,occurred_at
             ) VALUES ('org-a',:site,:id,:command_sha,:hash,
                       'approve','approved_demo',1,1800000030)"
        );
        $insert->execute([
            'site'=>$candidate->brief->reference,
            'id'=>'DEMO-REVIEW-CMD-EXTRA',
            'command_sha'=>str_repeat('e',64),
            'hash'=>$candidate->fingerprint,
        ]);
        self::assertSame('ambiguous_receipts',$this->check($candidate)->reason);
    }

    public function test_removed_force_rls_metadata_causes_closed_denial(): void
    {
        $candidate=$this->candidate();
        $this->approve($candidate);
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f44.review_receipt NO FORCE ROW LEVEL SECURITY');
        try {
            self::assertSame('unsafe_synthetic_schema',$this->check($candidate)->reason);
        } finally {
            $admin->exec('ALTER TABLE f44.review_receipt FORCE ROW LEVEL SECURITY');
        }
        self::assertSame('consistent_approved_demo',$this->check($candidate)->reason);
    }

    public function test_revoked_reader_sql_permission_is_unavailable_not_empty(): void
    {
        $candidate=$this->candidate();
        $this->approve($candidate);
        $admin=$this->db('admin');
        $admin->exec('REVOKE SELECT ON f44.review_receipt FROM f45_consistency_reader_a');
        try {
            self::assertSame('source_unavailable',$this->check($candidate)->reason);
        } finally {
            $admin->exec('GRANT SELECT ON f44.review_receipt TO f45_consistency_reader_a');
        }
        self::assertSame('consistent_approved_demo',$this->check($candidate)->reason);
    }

    public function test_reader_role_cannot_mutate_receipts_or_versioned_candidate(): void
    {
        $pdo=$this->db('reader-a');
        foreach ([
            "UPDATE f44.site_review SET review_status='approved_demo'",
            "INSERT INTO f44.review_receipt(organization_id) VALUES('org-a')",
            'DELETE FROM f44.review_receipt',
            'ALTER TABLE f44.site_review DISABLE ROW LEVEL SECURITY',
        ] as $statement) {
            try {
                $pdo->exec($statement);
                self::fail('F45 read-only role modified its synthetic source.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('not_approved',$this->check($this->candidate())->reason);
    }

    public function test_missing_candidate_and_absent_record_do_not_disclose_foreign_data(): void
    {
        $other=$this->candidate('b');
        self::assertSame('untrusted_sql_role',$this->check($other,'reader-a')->reason);
        $this->db('admin')->exec(
            "DELETE FROM f44.site_review WHERE organization_id='org-a'"
        );
        self::assertSame('missing_demo_candidate',$this->check($this->candidate())->reason);
    }
}
