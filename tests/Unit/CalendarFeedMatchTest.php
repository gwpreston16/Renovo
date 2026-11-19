<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CalendarFeed;
use PHPUnit\Framework\TestCase;

/**
 * How a calendar poll's `If-None-Match` is compared with the feed's ETag
 * (K259): weakly, against `*` or any tag in a comma-separated list, and
 * never matched by an empty header, which would answer 304 to a client
 * holding nothing.
 */
final class CalendarFeedMatchTest extends TestCase
{
    private const ETAG = 'W/"abc123"';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function headers(): iterable
    {
        yield 'the same weak tag' => [self::ETAG, true];
        yield 'the strong form of it' => ['"abc123"', true];
        yield 'any tag' => ['*', true];
        yield 'in a list without spaces' => ['W/"other",W/"abc123"', true];
        yield 'in a list with an empty member' => ['W/"other",,W/"abc123"', true];
        yield 'no header' => ['', false];
        yield 'only commas and spaces' => [' , ', false];
        yield 'another tag' => ['W/"other"', false];
        yield 'a tag differing in case' => ['W/"ABC123"', false];
    }

    /**
     * @dataProvider headers
     */
    public function testIfNoneMatchIsComparedWeakly(string $header, bool $matches): void
    {
        self::assertSame($matches, (new CalendarFeed('BEGIN:VCALENDAR', self::ETAG))->matches($header));
    }
}
