<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\DemoNotice;
use App\Presentation\DemoNoticeCatalog;
use App\Presentation\NoticeCategory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class NoticeCatalogPresentationTest extends TestCase
{
    public function test_catalog_is_deterministic_and_covers_closed_categories_with_neutral_fallback(): void
    {
        $catalog = DemoNoticeCatalog::examples();
        self::assertEquals($catalog, DemoNoticeCatalog::examples());
        self::assertCount(8, $catalog->notices);
        self::assertSame(['general', 'sites', 'requests', 'performance', 'media_credit', 'university', 'crm'], array_column(NoticeCategory::cases(), 'value'));
        foreach (NoticeCategory::cases() as $category) {
            self::assertContains($category, array_column($catalog->notices, 'category'));
            self::assertNotSame('', $category->label());
        }
        self::assertSame(NoticeCategory::General, NoticeCategory::fromCode('administrator injected'));
        self::assertSame(NoticeCategory::MediaCredit, NoticeCategory::fromCode('media_credit'));
    }

    public function test_invalid_reference_text_and_collection_inputs_are_rejected(): void
    {
        $notice = new DemoNotice('demo_notice_example', NoticeCategory::General, 'Exemplo', 'Fictício');
        foreach ([
            fn () => new DemoNotice('recipient_123', NoticeCategory::General, 'Exemplo', 'Fictício'),
            fn () => new DemoNotice('demo_notice_'.str_repeat('a', 49), NoticeCategory::General, 'Exemplo', 'Fictício'),
            fn () => new DemoNotice('demo_notice_example', NoticeCategory::General, ' ', 'Fictício'),
            fn () => new DemoNotice('demo_notice_example', NoticeCategory::General, str_repeat('a', 181), 'Fictício'),
            fn () => new DemoNotice('demo_notice_example', NoticeCategory::General, 'Exemplo', ''),
            fn () => new DemoNotice('demo_notice_example', NoticeCategory::General, 'Exemplo', str_repeat('a', 1601)),
            fn () => new DemoNoticeCatalog(['untyped']),
            fn () => new DemoNoticeCatalog(['key' => $notice]),
            fn () => new DemoNoticeCatalog([$notice, $notice]),
            fn () => new DemoNoticeCatalog(array_fill(0, 33, $notice)),
        ] as $construct) {
            try {
                $construct();
                self::fail('Invalid notice presentation input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_boundary_lengths_empty_and_maximum_catalog_are_supported(): void
    {
        $notice = new DemoNotice('demo_notice_'.str_repeat('a', 48), NoticeCategory::General, str_repeat('a', 180), str_repeat('b', 1600));
        self::assertCount(1, (new DemoNoticeCatalog([$notice]))->notices);
        self::assertSame([], (new DemoNoticeCatalog())->notices);
        $notices = [];
        for ($index = 0; $index < 32; $index++) {
            $notices[] = new DemoNotice('demo_notice_'.$index, NoticeCategory::General, 'Exemplo', 'Fictício');
        }
        self::assertCount(32, (new DemoNoticeCatalog($notices))->notices);
        self::assertTrue((new \ReflectionClass(DemoNotice::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(DemoNoticeCatalog::class))->isReadOnly());
        self::assertSame(['reference', 'category', 'title', 'body'], array_map(fn ($property) => $property->name, (new \ReflectionClass(DemoNotice::class))->getProperties()));
    }

    public function test_arbitrary_string_cannot_be_a_typed_notice_category(): void
    {
        $this->expectException(TypeError::class);
        new DemoNotice('demo_notice_example', 'delivered', 'Exemplo', 'Fictício');
    }
}
