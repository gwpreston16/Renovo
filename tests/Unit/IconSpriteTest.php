<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\DefaultPaymentMethods;
use App\Support\IconSprite;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class IconSpriteTest extends TestCase
{
    private string $root;

    private string $index;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/renovo-icons-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->index = $this->root . '/icons.json';

        file_put_contents($this->index, (string) json_encode([
            'sprite' => 'sprite-Ab12Cd34.svg',
            'names' => ['add', 'wallet'],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->index);
        @rmdir($this->root);
    }

    public function testAKnownIconIsADecorativeUseOfTheHashedSprite(): void
    {
        $markup = (new IconSprite($this->index, true))->render('wallet', 'nav-icon');

        self::assertSame(
            '<svg class="nav-icon" aria-hidden="true" focusable="false">'
            . '<use href="/build/sprite-Ab12Cd34.svg#wallet"></use></svg>',
            $markup,
        );
    }

    public function testTheSpriteUrlIsHandedToScripts(): void
    {
        self::assertSame('/build/sprite-Ab12Cd34.svg', (new IconSprite($this->index, true))->url());
    }

    /** In development and tests, a name nobody added to icons.json fails the page. */
    public function testAnUnknownIconFailsLoudlyWhenStrict(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No icon named "walet"');

        (new IconSprite($this->index, true))->render('walet');
    }

    /** In production it is a log line and no picture, not a 500 on every page. */
    public function testAnUnknownIconIsLoggedAndOmittedInProduction(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = $level . ': ' . $message . ' ' . json_encode($context);
            }
        };

        $markup = (new IconSprite($this->index, false, $logger))->render('walet');

        self::assertSame('', $markup);
        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('walet', $logger->messages[0]);
    }

    public function testAMissingIndexNamesTheCommandThatBuildsIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');

        (new IconSprite($this->root . '/nope.json', true))->render('add');
    }

    /**
     * The names the server asks for are names the build will draw.
     *
     * Checked against the source map rather than the build, so it holds on a
     * checkout with no `npm run build`: every navigation icon and every
     * payment-method glyph is a key of `assets/theme/icons.json`.
     */
    public function testEveryIconTheApplicationNamesIsInTheMap(): void
    {
        $map = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/assets/theme/icons.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($map);
        $names = array_keys($map['icons']);

        $navigation = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/NavigationService.php');
        preg_match_all("~icon:\s*'([a-z0-9-]+)'~", $navigation, $matches);
        self::assertNotEmpty($matches[1]);

        $templates = '';
        foreach (glob(dirname(__DIR__, 2) . '/templates/{,*/,*/*/}*.twig', GLOB_BRACE) ?: [] as $file) {
            $templates .= (string) file_get_contents($file);
        }
        preg_match_all("~icon\('([a-z0-9-]+)'~", $templates, $literal);

        $destinations = array_map(
            static fn (\App\Domain\BackupDestinationType $type): string => $type->icon(),
            \App\Domain\BackupDestinationType::cases(),
        );

        foreach ([...$matches[1], ...$literal[1], ...DefaultPaymentMethods::ICONS, ...$destinations] as $icon) {
            self::assertContains($icon, $names, sprintf('"%s" is not in assets/theme/icons.json.', $icon));
        }
    }
}
