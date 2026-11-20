<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins where Coming up's rows drop the logo and the owner's face.
 *
 * The prototype draws both on every row; the stylesheet hides them only in a
 * list too narrow to hold them beside a readable name. The width is measured
 * in the browser (K269), so the test holds the measured value rather than
 * working it out again.
 */
final class ComingUpRowStylesTest extends TestCase
{
    /** The list width, in rem, below which the logo and face give way (K269). */
    private const MEASURED_THRESHOLD_REM = 26.0;

    /**
     * K269: at 30rem the side-by-side card on a 1024px screen (about 27.75rem)
     * lost the logo and face though they fit. The threshold is now the measured
     * 26rem, under 30rem, and the rule still hides exactly those two parts.
     */
    public function testComingUpHidesTheLogoOnlyInAPhoneWidthList(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/screens.css');

        $found = preg_match(
            '/@container\s+charge-list\s*\(\s*max-width:\s*([\d.]+)rem\s*\)\s*\{(.*?)\}\s*\}/s',
            $css,
            $match,
        );

        self::assertSame(1, $found, 'The charge-list container rule is missing.');
        $threshold = (float) $match[1];
        self::assertSame(self::MEASURED_THRESHOLD_REM, $threshold);
        self::assertLessThan(30.0, $threshold);
        // About 27.75rem: the Coming up list on the 1024px dashboard keeps them.
        self::assertLessThan(27.75, $threshold);
        self::assertStringContainsString('.charge-row .logo', $match[2]);
        self::assertStringContainsString('.charge-owner', $match[2]);
        self::assertStringContainsString('display: none', $match[2]);
    }
}
