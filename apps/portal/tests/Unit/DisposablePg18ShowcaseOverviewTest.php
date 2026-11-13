<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposablePg18ShowcaseOverview;

final class DisposablePg18ShowcaseOverviewTest extends TestCase
{
    /** @return list<list<string>> */
    private static function projects(): array
    {
        return [
            ['Sites Imagine · Onepage','DEMO-PROJECT-ONEPAGE','Rascunho de exemplo','3/3 itens revisados','Sem itens pendentes no exemplo'],
            ['Sites Imagine · Landing Page','DEMO-PROJECT-LANDING','Rascunho de exemplo','1/3 itens revisados','Ainda ilustrativos: content, consent'],
            ['Performance AI','DEMO-PROJECT-PERFORMANCE','Rascunho de exemplo','2/4 itens revisados','Ainda ilustrativos: creatives, budget'],
        ];
    }

    /** @return list<list<string>> */
    private static function requests(): array
    {
        return [
            ['DEMO-REQ-ONEPAGE','DEMO-PROJECT-ONEPAGE','Rascunho','Revisão fictícia 1'],
            ['DEMO-REQ-LANDING','DEMO-PROJECT-LANDING','Enviada','Revisão fictícia 2'],
            ['DEMO-REQ-PERFORMANCE','DEMO-PROJECT-PERFORMANCE','Em análise','Revisão fictícia 3'],
        ];
    }

    public function test_counts_match_project_and_request_projections(): void
    {
        self::assertSame([
            'projects'=>3,
            'requests'=>3,
            'checkpoints_reviewed'=>6,
            'checkpoints_total'=>10,
        ], DisposablePg18ShowcaseOverview::fromRows(
            self::projects(), self::requests()
        ));
    }

    public function test_foreign_project_reference_fails_closed(): void
    {
        $requests=self::requests();
        $requests[2][1]='DEMO-PROJECT-FOREIGN';
        $this->expectException(RuntimeException::class);
        DisposablePg18ShowcaseOverview::fromRows(self::projects(),$requests);
    }

    public function test_duplicate_request_identifier_fails_closed(): void
    {
        $requests=self::requests();
        $requests[2][0]=$requests[0][0];
        $this->expectException(RuntimeException::class);
        DisposablePg18ShowcaseOverview::fromRows(self::projects(),$requests);
    }

    public function test_duplicate_project_reference_fails_closed(): void
    {
        $projects=self::projects();
        $projects[1][1]=$projects[0][1];
        $this->expectException(RuntimeException::class);
        DisposablePg18ShowcaseOverview::fromRows($projects,self::requests());
    }

    public function test_inconsistent_checkpoint_progress_fails_closed(): void
    {
        $projects=self::projects();
        $projects[1][3]='4/3 itens revisados';
        $this->expectException(RuntimeException::class);
        DisposablePg18ShowcaseOverview::fromRows($projects,self::requests());
    }

    public function test_completed_label_requires_completed_count(): void
    {
        $projects=self::projects();
        $projects[1][4]='Sem itens pendentes no exemplo';
        $this->expectException(RuntimeException::class);
        DisposablePg18ShowcaseOverview::fromRows($projects,self::requests());
    }
}
