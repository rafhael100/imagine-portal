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
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SQL role isolation, atomicity and revision-bound review only.
 * No login, customer consent or publishing permissions are tested here.
 */
final class F44SyntheticSiteReviewIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB')!=='NON_OPERATIONAL_F22'
            || getenv('F44_ENABLE_LAB')!=='SYNTHETIC_SITES_REVIEW_ONLY') {
            self::markTestSkipped('F44 requires localhost disposable PostgreSQL 18.');
        }
        $this->resetFixtures();
        foreach (['a','b'] as $tenant) {
            $candidate=$this->candidate($tenant);
            $stmt=$this->db('admin')->prepare(
                'INSERT INTO f44.site_review(
                    organization_id,site_reference,content_sha256,
                    brief_revision,content_revision,review_status,review_revision)
                 VALUES(:org,:ref,:hash,:brief_rev,:content_rev,:status,:review_rev)'
            );
            $stmt->execute([
                'org'=>'org-'.$tenant,
                'ref'=>$candidate->brief->reference,
                'hash'=>$candidate->fingerprint,
                'brief_rev'=>$candidate->brief->revision,
                'content_rev'=>$candidate->contentRevision,
                'status'=>'awaiting_review_demo',
                'review_rev'=>0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F44_ENABLE_LAB')==='SYNTHETIC_SITES_REVIEW_ONLY') {
            $this->resetFixtures();
        }
        parent::tearDown();
    }

    private function db(string $role): PDO
    {
        $identities=[
            'admin'=>['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
            'a'=>['f44_reviewer_a',getenv('F44_PG_PASSWORD_A')],
            'b'=>['f44_reviewer_b',getenv('F44_PG_PASSWORD_B')],
        ];
        $identity=$identities[$role] ?? null;
        $port=getenv('F22_PG_PORT');
        if ($identity===null
            || getenv('F22_PG_HOST')!=='127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D',$port)!==1
            || (int)$port>65535
            || !is_string($identity[1])
            || preg_match('/\A[a-f0-9]{64}\z/D',$identity[1])!==1) {
            throw new RuntimeException('F44 refuses non-local disposable database credentials.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $identity[0],$identity[1],[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function resetFixtures(): void
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
        $brief=new SiteBriefDraft(
            'DEMO-SITE-REQ-'.strtoupper($tenant).'1',
            new OrganizationId('org-'.$tenant),
            PortfolioProduct::Onepage,
            'Apresentar serviços e planos com linguagem direta para pequenas empresas.',
            'Pequenas empresas brasileiras',
            'Solicitar um orçamento ilustrativo',
            $briefRevision,
        );
        return new SiteContentCandidate(
            $brief,$contentRevision,
            $headline ?? 'Uma página para seu negócio',
            'Conteúdo fictício para apresentar serviços de forma clara e objetiva a potenciais clientes.',
            'Solicitar proposta inicial',
        );
    }

    private function command(
        SiteContentCandidate $candidate,
        string $id='DEMO-REVIEW-CMD-001',
        SiteReviewDecision $decision=SiteReviewDecision::Approve,
        int $expectedRevision=0,
        ?string $hash=null,
        ?string $actor=null,
    ): SiteReviewCommand {
        $tenant=$candidate->brief->organizationId->value;
        return new SiteReviewCommand(
            $id,$candidate->brief->organizationId,$candidate->brief->reference,
            $hash ?? $candidate->fingerprint,
            $actor ?? ($tenant==='org-a'?'DEMO-REVIEWER-A':'DEMO-REVIEWER-B'),
            $expectedRevision,$decision,1800000030,
        );
    }

    private function writer(string $role='a'): F44SyntheticSiteReviewWriter
    {
        return new F44SyntheticSiteReviewWriter($this->db($role));
    }

    private function stored(string $tenant='a'): array
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT review_status,review_revision,content_sha256,content_revision
               FROM f44.site_review WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Missing F44 fixture');
        }
        return $row;
    }

    private function receipts(string $tenant='a'): int
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT count(*) FROM f44.review_receipt WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        return (int)$stmt->fetchColumn();
    }

    public function test_approval_and_rejection_each_atomically_write_only_its_tenant_fixture(): void
    {
        $a=$this->candidate();
        $b=$this->candidate('b');
        $yes=$this->writer()->apply($a,$this->command($a));
        self::assertSame('applied_synthetic_sql',$yes->reason);
        self::assertSame('approved_demo',$yes->simulatedStatus);
        self::assertSame(1,$yes->reviewRevision);
        self::assertTrue($yes->isSimulatedApproval());
        self::assertTrue($yes->wasWrittenOnlyToDisposableFixture());
        self::assertFalse($yes->authorizesPublishing());
        self::assertFalse($yes->isVerifiedConsent());
        self::assertFalse($yes->authorizesContractOrCharge());
        self::assertSame('awaiting_review_demo',$this->stored('b')['review_status']);
        self::assertSame(1,$this->receipts());

        $rejected=$this->writer('b')->apply($b,$this->command(
            $b,'DEMO-REVIEW-CMD-B-001',SiteReviewDecision::Reject,
        ));
        self::assertSame('rejected_demo',$rejected->simulatedStatus);
        self::assertFalse($rejected->isSimulatedApproval());
        self::assertSame(1,$this->receipts('b'));
        self::assertSame('approved_demo',$this->stored()['review_status']);
    }

    public function test_identical_replay_after_commit_preserves_original_result_and_single_receipt(): void
    {
        $candidate=$this->candidate();
        $command=$this->command($candidate);
        $writer=$this->writer();
        self::assertSame('applied_synthetic_sql',$writer->apply($candidate,$command)->reason);
        $replay=$writer->apply($candidate,$command);
        self::assertSame('replayed_synthetic_sql',$replay->reason);
        self::assertSame('approved_demo',$replay->simulatedStatus);
        self::assertSame(1,$replay->reviewRevision);
        self::assertSame(1,$this->receipts());
        self::assertSame(1,(int)$this->stored()['review_revision']);
        self::assertFalse($replay->authorizesPublishing());
    }

    public function test_command_id_with_conflicting_decision_is_not_a_valid_replay(): void
    {
        $candidate=$this->candidate();
        $writer=$this->writer();
        self::assertSame('applied_synthetic_sql',$writer->apply(
            $candidate,$this->command($candidate),
        )->reason);
        $conflict=$writer->apply($candidate,$this->command(
            $candidate,decision:SiteReviewDecision::RequestChanges,
        ));
        self::assertSame('idempotency_conflict',$conflict->reason);
        self::assertSame(1,$this->receipts());
        self::assertSame('approved_demo',$this->stored()['review_status']);
    }

    public function test_content_replacement_blocks_old_approval_replay_before_receipt_check(): void
    {
        $old=$this->candidate();
        $original=$this->command($old);
        self::assertSame('applied_synthetic_sql',$this->writer()->apply($old,$original)->reason);
        $new=$this->candidate(contentRevision:2,headline:'Conteúdo revisado e atualizado do exemplo');
        $admin=$this->db('admin');
        $statement=$admin->prepare(
            "UPDATE f44.site_review
                SET content_sha256=:hash, content_revision=2,
                    review_status='awaiting_review_demo',review_revision=0
              WHERE organization_id='org-a'"
        );
        $statement->execute(['hash'=>$new->fingerprint]);
        self::assertSame('content_changed',$this->writer()->apply($old,$original)->reason);
        self::assertSame('content_changed',$this->writer()->apply(
            $new,$this->command($old),
        )->reason);
        self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
        self::assertSame(1,$this->receipts());
        $next=$this->writer()->apply($new,$this->command(
            $new,id:'DEMO-REVIEW-CMD-002',
        ));
        self::assertSame('applied_synthetic_sql',$next->reason);
        self::assertSame(2,$this->receipts());
        self::assertFalse($next->authorizesPublishing());
    }

    public function test_brief_revision_change_rejects_old_review_even_with_same_copy(): void
    {
        $old=$this->candidate();
        $new=$this->candidate(briefRevision:1);
        $admin=$this->db('admin');
        $stmt=$admin->prepare(
            "UPDATE f44.site_review SET brief_revision=1,content_sha256=:hash
              WHERE organization_id='org-a'"
        );
        $stmt->execute(['hash'=>$new->fingerprint]);
        self::assertSame('content_changed',$this->writer()->apply(
            $old,$this->command($old),
        )->reason);
        self::assertSame('applied_synthetic_sql',$this->writer()->apply(
            $new,$this->command($new),
        )->reason);
        self::assertSame(1,$this->receipts());
    }

    public function test_review_status_is_terminal_per_candidate_and_stale_revision_is_denied(): void
    {
        $candidate=$this->candidate();
        $writer=$this->writer();
        self::assertSame('stale_review_revision',$writer->apply(
            $candidate,$this->command($candidate,expectedRevision:8),
        )->reason);
        self::assertSame(0,$this->receipts());
        $first=$writer->apply($candidate,$this->command(
            $candidate,decision:SiteReviewDecision::RequestChanges,
        ));
        self::assertSame('changes_requested_demo',$first->simulatedStatus);
        $next=$writer->apply($candidate,$this->command(
            $candidate,id:'DEMO-REVIEW-CMD-002',
            expectedRevision:1,
        ));
        self::assertSame('review_already_completed',$next->reason);
        self::assertSame(1,$this->receipts());
    }

    public function test_wrong_sql_identity_cannot_authorize_any_review(): void
    {
        $candidate=$this->candidate();
        self::assertSame('untrusted_sql_role',$this->writer('b')->apply(
            $candidate,$this->command($candidate),
        )->reason);
        self::assertSame('untrusted_sql_role',$this->writer('admin')->apply(
            $candidate,$this->command($candidate),
        )->reason);
        self::assertSame(0,$this->receipts());
        self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
    }

    public function test_wrong_demo_actor_or_content_hash_fails_without_sql_write(): void
    {
        $candidate=$this->candidate();
        $writer=$this->writer();
        self::assertSame('untrusted_demo_actor',$writer->apply(
            $candidate,$this->command($candidate,actor:'DEMO-REVIEWER-B'),
        )->reason);
        self::assertSame('content_changed',$writer->apply(
            $candidate,$this->command($candidate,hash:str_repeat('0',64)),
        )->reason);
        self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
        self::assertSame(0,$this->receipts());
    }

    public function test_forced_rls_removed_from_receipt_table_fails_closed(): void
    {
        $candidate=$this->candidate();
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f44.review_receipt NO FORCE ROW LEVEL SECURITY');
        try {
            $result=$this->writer()->apply($candidate,$this->command($candidate));
            self::assertSame('unsafe_synthetic_schema',$result->reason);
            self::assertSame(0,$this->receipts());
            self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
        } finally {
            $admin->exec('ALTER TABLE f44.review_receipt FORCE ROW LEVEL SECURITY');
        }
        self::assertSame('applied_synthetic_sql',$this->writer()->apply(
            $candidate,$this->command($candidate),
        )->reason);
    }

    public function test_role_bypass_rls_and_cross_tenant_access_are_denied(): void
    {
        $candidate=$this->candidate();
        $admin=$this->db('admin');
        $admin->exec('ALTER ROLE f44_reviewer_a BYPASSRLS');
        try {
            self::assertSame('untrusted_sql_role',$this->writer()->apply(
                $candidate,$this->command($candidate),
            )->reason);
        } finally {
            $admin->exec('ALTER ROLE f44_reviewer_a NOBYPASSRLS');
        }
        $a=$this->db('a');
        self::assertSame(0,(int)$a->query(
            "SELECT count(*) FROM f44.site_review WHERE organization_id='org-b'"
        )->fetchColumn());
        self::assertSame(0,$a->exec(
            "UPDATE f44.site_review SET review_status='approved_demo'
              WHERE organization_id='org-b'"
        ));
        self::assertSame('awaiting_review_demo',$this->stored('b')['review_status']);
    }

    public function test_receipt_insert_privilege_failure_rolls_back_status_revision_atomically(): void
    {
        $candidate=$this->candidate();
        $admin=$this->db('admin');
        $admin->exec('REVOKE INSERT ON f44.review_receipt FROM f44_reviewer_a');
        try {
            self::assertSame('source_unavailable',$this->writer()->apply(
                $candidate,$this->command($candidate),
            )->reason);
            self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
            self::assertSame(0,(int)$this->stored()['review_revision']);
            self::assertSame(0,$this->receipts());
        } finally {
            $admin->exec('GRANT INSERT ON f44.review_receipt TO f44_reviewer_a');
        }
        self::assertSame('applied_synthetic_sql',$this->writer()->apply(
            $candidate,$this->command($candidate),
        )->reason);
        self::assertSame(1,$this->receipts());
    }

    public function test_row_lock_timeout_preserves_status_and_can_retry(): void
    {
        $candidate=$this->candidate();
        $admin=$this->db('admin');
        $admin->beginTransaction();
        $admin->query(
            "SELECT review_revision FROM f44.site_review
              WHERE organization_id='org-a' FOR UPDATE"
        )->closeCursor();
        try {
            $blocked=$this->writer()->apply($candidate,$this->command($candidate));
            self::assertSame('source_unavailable',$blocked->reason);
            self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
            self::assertSame(0,$this->receipts());
        } finally {
            $admin->rollBack();
        }
        self::assertSame('applied_synthetic_sql',$this->writer()->apply(
            $candidate,$this->command($candidate),
        )->reason);
    }

    public function test_low_privileged_role_cannot_delete_receipts_or_modify_candidate_hash(): void
    {
        $pdo=$this->db('a');
        foreach ([
            "DELETE FROM f44.review_receipt",
            "DELETE FROM f44.site_review",
            "UPDATE f44.site_review SET content_sha256='".str_repeat('0',64)."' WHERE organization_id='org-a'",
            "ALTER TABLE f44.site_review DISABLE ROW LEVEL SECURITY",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('F44 reviewer acquired unintended SQL capabilities.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('awaiting_review_demo',$this->stored()['review_status']);
    }
}
