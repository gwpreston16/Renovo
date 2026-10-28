<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\SessionInterface;
use App\Service\EstimateMarks;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Views\Twig;

/**
 * Which per-currency figures are drawn "≈" (Phase 65, K87), through the real
 * Twig environment and every partial that draws one.
 *
 * A varying £58 energy bill beside a fixed €12.99 streaming row, with a rate:
 * the £ subtotal and the combined figure hold an estimate; the € subtotal is
 * exact and is not marked. A total of fixed rows only is not marked at all.
 */
final class SpendFiguresTest extends DatabaseTestCase
{
    /** The partials that draw a per-currency figure, as each screen calls them. */
    private const PARTIALS = [
        'spend' => "{% import 'partials/spend.twig' as figure %}{{ figure.spend(f, '') }}",
        'charge total' => "{% include 'dashboard/_charge_total.twig' with {'figures': f} only %}",
        'calendar inline' => "{% import 'calendar/_figures.twig' as figures %}{{ figures.inline(f) }}",
    ];

    /**
     * A day cell's corner: one figure. Its combined figure is always "≈",
     * because it is converted (`dashboard.approx`); the other shapes follow
     * the rule.
     */
    private const COMPACT = "{% import 'calendar/_figures.twig' as figures %}{{ figures.compact(f) }}";

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => new ArraySession(),
            Clock::class => FrozenClock::at('2026-10-28 09:00:00'),
        ]);
    }

    public function testOnlyTheVaryingCurrencyIsMarked(): void
    {
        $figures = $this->figures(['GBP' => 5800, 'EUR' => 1299], 6930, ['GBP']);

        foreach (self::PARTIALS as $name => $template) {
            $html = $this->render($template, $figures);

            self::assertStringContainsString('≈ £58.00', $html, $name);
            self::assertStringContainsString('€12.99', $html, $name);
            self::assertStringNotContainsString('≈ €12.99', $html, $name);
        }
    }

    public function testTheCombinedFigureIsMarkedWhenAnyRowVaries(): void
    {
        $figures = $this->figures(['GBP' => 5800, 'EUR' => 1299], 6930, ['GBP']);

        foreach (self::PARTIALS as $name => $template) {
            self::assertStringContainsString('≈ £69.30', $this->render($template, $figures), $name);
        }

        // The € is the varying one: still the combined figure, and the € alone.
        $figures = $this->figures(['GBP' => 5800, 'EUR' => 1299], 6930, ['EUR']);
        foreach (self::PARTIALS as $name => $template) {
            $html = $this->render($template, $figures);
            self::assertStringContainsString('≈ £69.30', $html, $name);
            self::assertStringContainsString('≈ €12.99', $html, $name);
            self::assertStringNotContainsString('≈ £58.00', $html, $name);
        }
    }

    public function testFixedRowsOnlyAreNotMarked(): void
    {
        foreach (
            [
                $this->figures(['GBP' => 5800, 'EUR' => 1299], 6930, []),
                $this->figures(['GBP' => 5800], 5800, []),
                // No rate for the €: the subtotals alone, the fixed one unmarked.
                $this->figures(['GBP' => 5800, 'EUR' => 1299], null, []),
            ] as $figures
        ) {
            foreach (self::PARTIALS as $name => $template) {
                self::assertStringNotContainsString('≈', $this->render($template, $figures), $name);
            }
        }
    }

    public function testWithoutARateOnlyTheVaryingSubtotalIsMarked(): void
    {
        $figures = $this->figures(['GBP' => 5800, 'EUR' => 1299], null, ['GBP']);

        foreach (self::PARTIALS as $name => $template) {
            $html = $this->render($template, $figures);
            self::assertStringContainsString('≈ £58.00', $html, $name);
            self::assertStringNotContainsString('≈ €12.99', $html, $name);
        }
    }

    public function testOneVaryingCurrencyAloneIsMarked(): void
    {
        foreach (self::PARTIALS as $name => $template) {
            $html = $this->render($template, $this->figures(['GBP' => 5800], 5800, ['GBP']));
            self::assertStringContainsString('≈ £58.00', $html, $name);
        }
    }

    public function testADayCellFollowsTheRule(): void
    {
        $varying = $this->figures(['GBP' => 5800], 5800, ['GBP']);
        self::assertSame('≈ £58.00', trim($this->render(self::COMPACT, $varying)));
        self::assertSame('£58.00', trim($this->render(self::COMPACT, $this->figures(['GBP' => 5800], 5800, []))));

        // No rate: the subtotals stacked, only the varying one marked.
        $html = $this->render(self::COMPACT, $this->figures(['GBP' => 5800, 'EUR' => 1299], null, ['GBP']));
        self::assertStringContainsString('≈ £58.00', $html);
        self::assertStringNotContainsString('≈ €12.99', $html);
        self::assertStringContainsString('€12.99', $html);
    }

    /**
     * The scenario's "costs more": a single currency's net asks about that
     * currency, so a fixed € rise is not marked though £ holds a varying bill
     * elsewhere; a combined net is marked when any currency varies.
     */
    public function testACostlierScenarioFollowsTheRule(): void
    {
        $template = "{% import 'forecast/_scenario_results.twig' as s %}{{ s.saving(f, 'GBP', m) }}";
        $euroRise = [
            'totals' => [['currency' => 'EUR', 'amount_minor' => -500]],
            'combined' => ['currency' => 'GBP', 'amount_minor' => -250, 'unconvertible' => []],
            'net' => ['currency' => 'EUR', 'amount_minor' => -500],
        ];
        $render = fn (array $figures, array $marks): string => (string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(
                $this->environment()->createTemplate($template)->render(['f' => $figures, 'm' => $marks]),
            ),
        );

        self::assertStringNotContainsString('≈', $render($euroRise, EstimateMarks::keys(['GBP'])));
        self::assertStringContainsString('≈ €5.00', $render($euroRise, EstimateMarks::keys(['EUR'])));

        $combined = [
            'totals' => [['currency' => 'EUR', 'amount_minor' => -500], ['currency' => 'GBP', 'amount_minor' => -100]],
            'combined' => ['currency' => 'GBP', 'amount_minor' => -350, 'unconvertible' => []],
            'net' => ['currency' => 'GBP', 'amount_minor' => -350],
        ];
        self::assertStringContainsString('≈ £3.50', $render($combined, EstimateMarks::keys(['GBP'])));
        self::assertStringNotContainsString('≈', $render($combined, EstimateMarks::keys()));
    }

    public function testTheRuleItself(): void
    {
        $marks = EstimateMarks::keys(['GBP'], ['EUR', 'GBP'], []);
        self::assertSame(['estimate' => true, 'estimate_currencies' => ['EUR', 'GBP']], $marks);
        self::assertSame(['estimate' => false, 'estimate_currencies' => []], EstimateMarks::keys());

        self::assertTrue(EstimateMarks::marks($marks));
        self::assertTrue(EstimateMarks::marks($marks, 'EUR'));
        self::assertFalse(EstimateMarks::marks($marks, 'USD'));
        // A figure with no marks, or none at all, is exact.
        self::assertFalse(EstimateMarks::marks(['estimate' => true]));
        self::assertFalse(EstimateMarks::marks(null, 'GBP'));
        self::assertFalse(EstimateMarks::marks(['estimate_currencies' => 'GBP'], 'GBP'));
    }

    /**
     * @param array<string, int> $byCurrency
     * @param list<string> $varying
     * @return array<string, mixed>
     */
    private function figures(array $byCurrency, ?int $combinedMinor, array $varying): array
    {
        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => $currency, 'amount_minor' => $amount];
        }

        return [
            'totals' => $totals,
            'combined' => [
                'currency' => 'GBP',
                'amount_minor' => $combinedMinor,
                'unconvertible' => $combinedMinor === null ? ['EUR'] : [],
            ],
        ] + EstimateMarks::keys($varying);
    }

    private function environment(): \Twig\Environment
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container->get(Twig::class)->getEnvironment();
    }

    /**
     * @param array<string, mixed> $figures
     */
    private function render(string $template, array $figures): string
    {
        $html = $this->environment()->createTemplate($template)->render(['f' => $figures]);

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode($html));
    }
}
