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
    /**
     * The list width, in rem, below which the logo and face give way: K269's
     * 26rem, re-measured at 26.5rem when the logo became K275's 34px tile.
     */
    private const MEASURED_THRESHOLD_REM = 26.5;

    /**
     * K269: at 30rem the side-by-side card on a 1024px screen (about 27.75rem)
     * lost the logo and face though they fit. The threshold is the measured
     * value, which leaves that card its tile and face, and the rule still
     * hides exactly those two parts.
     */
    public function testComingUpHidesTheLogoOnlyInAPhoneWidthList(): void
    {
        $css = self::stylesheet();

        $found = preg_match(
            '/@container\s+charge-list\s*\(\s*max-width:\s*([\d.]+)rem\s*\)\s*\{(.*?)\}\s*\}/s',
            $css,
            $match,
        );

        self::assertSame(1, $found, 'The charge-list container rule is missing.');
        $threshold = (float) $match[1];
        self::assertSame(self::MEASURED_THRESHOLD_REM, $threshold);
        // About 27.75rem: the Coming up list on the 1024px dashboard keeps them.
        self::assertLessThan(27.75, $threshold);
        self::assertStringContainsString('.charge-row .logo', $match[2]);
        self::assertStringContainsString('.charge-owner', $match[2]);
        self::assertStringContainsString('display: none', $match[2]);
    }

    /**
     * K278: the rule above can only apply if the list is a size container of
     * that name. Without the declaration, or without the class on Coming up's
     * list, the logo and face would never give way, and the rule's own text
     * would still pass the test above.
     */
    public function testTheChargeListIsAContainer(): void
    {
        self::assertMatchesRegularExpression(
            '/\.charge-list\s*\{[^}]*container:\s*charge-list\s*\/\s*inline-size\s*;/',
            self::stylesheet(),
        );

        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/dashboard/cards/overview/coming_up.twig',
        );
        self::assertMatchesRegularExpression('/<ul class="[^"]*\bcharge-list\b[^"]*">/', $template);
    }

    /**
     * K276: the whole row opens the subscription. The name link's ::after is
     * stretched over the row, which must be positioned so the layer stops at
     * the row rather than covering the card; the row shows a hover, from a
     * token, as the prototype draws it.
     */
    public function testTheWholeRowOpensTheSubscription(): void
    {
        $css = self::stylesheet();

        self::assertMatchesRegularExpression('/\.charge-row\s*\{[^}]*position:\s*relative\s*;/', $css);
        self::assertMatchesRegularExpression(
            '/\.charge-row:hover\s*\{[^}]*background:\s*var\(--[a-z0-9-]+\)\s*;/',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.charge-name a::after\s*\{[^}]*position:\s*absolute\s*;[^}]*inset:\s*0\s*;/',
            $css,
        );
    }

    /** The stylesheet the dashboard's rows are drawn with. */
    private static function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/screens.css');
    }
}
