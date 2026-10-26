<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;

/**
 * K61: an empty cell is `common.none_symbol`, never a literal dash, and the
 * figures the known-issues log lists carry the `.num` class.
 */
final class FigureStyleTest extends TestCase
{
    private const FIGURE_TEMPLATES = [
        'cancellations/index.twig',
        'import/preview.twig',
        'subscriptions/_attachments.twig',
        'subscriptions/money.twig',
        'dashboard/cards/overview/totals.twig',
        'dashboard/cards/household/next_30_days.twig',
        'audit/_entries.twig',
        'stats/index.twig',
    ];

    public function testListedFiguresTakeNumAndEmptyCellsTakeTheSymbol(): void
    {
        foreach (self::FIGURE_TEMPLATES as $name) {
            $source = $this->template($name);

            self::assertSame(
                0,
                preg_match('~[\'"]—[\'"]|>\\s*—\\s*<~u', $source),
                $name . " writes a literal dash for an empty cell; use t('common.none_symbol').",
            );
        }

        $expected = [
            'cancellations/index.twig' => ['<strong class="num">{{ row.deadline', '<td class="num">'],
            'import/preview.twig' => [
                '<td class="num">{{ row.input.price',
                '<td class="num">{{ row.input.next_payment_date',
            ],
            'subscriptions/_attachments.twig' => ['<td class="muted num">{{ attachment.humanSize()'],
            'dashboard/cards/overview/totals.twig' => ['<span class="num">{{ total.total_minor'],
            'dashboard/cards/household/next_30_days.twig' => ['<span class="num">{% include'],
            'subscriptions/money.twig' => ['<strong class="num">{{ subscription.priceAfterConversion'],
        ];
        foreach ($expected as $name => $needles) {
            $source = $this->template($name);
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $source, $name);
            }
        }

        self::assertGreaterThan(0, preg_match_all('~none_symbol~', $this->template('audit/_entries.twig')));
    }

    private function template(string $name): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/' . $name);
        self::assertIsString($source);

        return $source;
    }
}
