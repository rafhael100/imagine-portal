<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\DemoHelpCatalog;
use App\Presentation\HelpCategory;
use App\Presentation\HelpTopic;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class HelpCatalogPresentationTest extends TestCase
{
    public function test_deterministic_catalog_covers_closed_categories_and_distinct_sites_topics(): void
    {
        $catalog = DemoHelpCatalog::examples();
        self::assertEquals($catalog, DemoHelpCatalog::examples());
        self::assertCount(8, $catalog->topics);
        self::assertSame(['general', 'sites', 'performance', 'requests', 'contracts', 'crm', 'university'], array_column(HelpCategory::cases(), 'value'));
        foreach (HelpCategory::cases() as $category) {
            self::assertContains($category, array_column($catalog->topics, 'category'));
            self::assertNotSame('', $category->label());
        }
        self::assertCount(2, array_filter($catalog->topics, fn ($topic) => $topic->category === HelpCategory::Sites));
        self::assertSame(HelpCategory::General, HelpCategory::fromCode('administrator paid injected'));
        self::assertSame(HelpCategory::Crm, HelpCategory::fromCode('crm'));
    }

    public function test_reference_text_and_collection_validators_reject_invalid_data(): void
    {
        $topic = new HelpTopic('demo_help_example', HelpCategory::General, 'Exemplo', 'Fictício');
        $invalid = [
            fn () => new HelpTopic('customer_123', HelpCategory::General, 'Exemplo', 'Fictício'),
            fn () => new HelpTopic('demo_help_'.str_repeat('a', 41), HelpCategory::General, 'Exemplo', 'Fictício'),
            fn () => new HelpTopic('demo_help_example', HelpCategory::General, ' ', 'Fictício'),
            fn () => new HelpTopic('demo_help_example', HelpCategory::General, str_repeat('a', 241), 'Fictício'),
            fn () => new HelpTopic('demo_help_example', HelpCategory::General, 'Exemplo', ''),
            fn () => new HelpTopic('demo_help_example', HelpCategory::General, 'Exemplo', str_repeat('a', 2001)),
            fn () => new DemoHelpCatalog(['not a topic']),
            fn () => new DemoHelpCatalog(['key' => $topic]),
            fn () => new DemoHelpCatalog([$topic, $topic]),
            fn () => new DemoHelpCatalog(array_fill(0, 25, $topic)),
        ];
        foreach ($invalid as $construct) {
            try {
                $construct();
                self::fail('Invalid help input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_exact_text_limits_and_empty_catalog_are_supported(): void
    {
        $topic = new HelpTopic('demo_help_'.str_repeat('a', 40), HelpCategory::General, str_repeat('a', 240), str_repeat('b', 2000));
        self::assertCount(1, (new DemoHelpCatalog([$topic]))->topics);
        self::assertSame([], (new DemoHelpCatalog())->topics);
        foreach ([HelpTopic::class, DemoHelpCatalog::class] as $class) {
            self::assertTrue((new \ReflectionClass($class))->isReadOnly());
        }
    }

    public function test_arbitrary_category_strings_cannot_be_passed_as_typed_topics(): void
    {
        $this->expectException(TypeError::class);
        new HelpTopic('demo_help_example', 'administrator', 'Exemplo', 'Fictício');
    }
}
