<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Presentation\DemoClientOverview;
use App\Presentation\DemoRequestInbox;
use App\Presentation\DemoServicePreparation;
use PHPUnit\Framework\TestCase;

final class DemoServicePreparationTest extends TestCase
{
    public function test_all_three_products_have_independent_preparation_and_no_activation(): void
    {
        $rows=DemoServicePreparation::rows();
        self::assertCount(3,$rows);
        $byProduct=[];
        foreach($rows as $row){
            self::assertCount(5,$row);
            self::assertStringStartsWith('DEMO-REQ-',$row[1]);
            self::assertNotSame('',$row[4]);
            $byProduct[$row[0]]=$row;
        }
        self::assertCount(3,$byProduct);
        self::assertStringContainsString('Domínio e publicação não configurados',
            $byProduct['Sites Imagine · Onepage'][4]);
        self::assertStringContainsString('consentimento',
            $byProduct['Sites Imagine · Landing Page'][4]);
        self::assertStringContainsString('Google Ads e Meta',
            $byProduct['Performance AI'][4]);
        self::assertFalse(DemoServicePreparation::authorizesSitePublication());
        self::assertFalse(DemoServicePreparation::authorizesAdCampaign());
        self::assertFalse(DemoServicePreparation::authorizesUniversityAccess());
        self::assertCount(4,DemoServicePreparation::safeguards());
    }

    public function test_missing_wrong_tenant_or_repeated_work_item_hides_everything(): void
    {
        $summary=DemoClientOverview::summary();
        $org=DemoRequestInbox::organization();
        $examples=DemoRequestInbox::examples();
        self::assertCount(3,DemoServicePreparation::fromSources($summary,$examples,$org));
        self::assertSame([],DemoServicePreparation::fromSources($summary,array_slice($examples,0,2),$org));
        $duplicate=$examples;
        $duplicate[1]=$examples[0];
        self::assertSame([],DemoServicePreparation::fromSources($summary,$duplicate,$org));

        $foreign=$examples;
        $foreign[0]=new ServiceRequestSnapshot(
            'DEMO-REQ-PERFORMANCE',new OrganizationId('DEMO-OTHER-TENANT'),
            'DEMO-SVC-PERFORMANCE',ServiceRequestStatus::Resolved,5,
        );
        self::assertSame([],DemoServicePreparation::fromSources($summary,$foreign,$org));
        $wrongStatus=$examples;
        $wrongStatus[0]=new ServiceRequestSnapshot(
            'DEMO-REQ-PERFORMANCE',$org,'DEMO-SVC-PERFORMANCE',
            ServiceRequestStatus::Submitted,5,
        );
        self::assertSame([],DemoServicePreparation::fromSources($summary,$wrongStatus,$org));
    }

    public function test_readiness_is_stable_independent_of_fixture_input_order(): void
    {
        $summary=DemoClientOverview::summary();
        $org=DemoRequestInbox::organization();
        $requests=DemoRequestInbox::examples();
        self::assertSame(
            DemoServicePreparation::fromSources($summary,$requests,$org),
            DemoServicePreparation::fromSources($summary,array_reverse($requests),$org)
        );
    }
}
