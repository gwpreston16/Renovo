<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use PHPUnit\Framework\TestCase;

/**
 * The Payments card's labels for a charge (K81): a hint beside the date for
 * the sources that need one, a badge for each status, every one of them a
 * string the catalogue holds — and none it holds that nobody sees.
 */
final class ChargeLabelTest extends TestCase
{
    public function testOnlyTheSourcesThatNeedAHintHaveOne(): void
    {
        self::assertNull(ChargeSource::CatchUp->labelKey());
        self::assertNull(ChargeSource::OneOff->labelKey());
        self::assertSame('charge.source.manual', ChargeSource::Manual->labelKey());
        self::assertSame('charge.source.reconstructed', ChargeSource::Reconstructed->labelKey());
        self::assertSame('charge.source.paused', ChargeSource::Paused->labelKey());
    }

    public function testEveryLabelIsInTheCatalogueAndNoOtherIs(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__, 2) . '/translations/en.php';

        $keys = [];
        foreach (ChargeSource::cases() as $source) {
            if ($source->labelKey() !== null) {
                $keys[] = $source->labelKey();
            }
        }
        foreach (ChargeStatus::cases() as $status) {
            $keys[] = $status->labelKey();
        }

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $catalogue);
        }

        $held = array_values(array_filter(
            array_keys($catalogue),
            static fn (string $key): bool => str_starts_with($key, 'charge.source.')
                || str_starts_with($key, 'charge.status.'),
        ));
        sort($held);
        sort($keys);
        self::assertSame($keys, $held);
    }
}
