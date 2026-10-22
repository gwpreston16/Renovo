<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The iCalendar feed: what it contains, and who may fetch it.
 *
 * The authentication half matters as much as the contents. This is the one
 * endpoint that takes its credential in the URL, because a calendar client
 * cannot send a header, and a URL-borne credential ends up in proxy logs and
 * browser history. The compensating rule is that it accepts read-only tokens
 * and nothing else, enforced by which middleware the route is given rather than
 * by a condition some later route could be added on the wrong side of.
 */
final class CalendarFeedTest extends ApiTestCase
{
    public function testAFeedNeedsAToken(): void
    {
        self::assertSame(401, $this->api('GET', '/api/v1/calendar.ics')->getStatusCode());
        self::assertSame(401, $this->api('GET', '/api/v1/calendar.ics?token=rubbish')->getStatusCode());
    }

    public function testAWriteCapableTokenIsRefusedRatherThanDowngraded(): void
    {
        $response = $this->api('GET', '/api/v1/calendar.ics?token=' . $this->ownerToken);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testABearerHeaderIsNotAcceptedHere(): void
    {
        // The feed route is wired to the query-parameter middleware only, so a
        // header carries no weight on it. Belt and braces: it proves the two
        // middlewares really are separate.
        self::assertSame(
            401,
            $this->api('GET', '/api/v1/calendar.ics', $this->readOnlyToken)->getStatusCode(),
        );
    }

    public function testTheFeedIsAWellFormedCalendar(): void
    {
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        $response = $this->api('GET', '/api/v1/calendar.ics?token=' . $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/calendar', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        $body = (string) $response->getBody();

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
        self::assertStringContainsString('VERSION:2.0', $body);

        // Every line is CRLF-terminated, and none exceeds the 75-octet limit
        // before folding. Clients are forgiving about both until one is not.
        foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), sprintf('Line too long: %s', $line));
        }

        self::assertSame(
            substr_count($body, 'BEGIN:VEVENT'),
            substr_count($body, 'END:VEVENT'),
            'Every VEVENT must be closed.',
        );
    }

    public function testRenewalsAppearAsWholeDayEvents(): void
    {
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        $body = $this->feed();

        self::assertStringContainsString('DTSTART;VALUE=DATE:20261201', $body);
        // DTEND is exclusive, so a one-day event ends the following day. Give a
        // client the same date at both ends and it displays nothing at all.
        self::assertStringContainsString('DTEND;VALUE=DATE:20261202', $body);
        self::assertStringContainsString('Streaming', $body);
    }

    public function testAFixedTermsPaymentsStopAtItsLastPayment(): void
    {
        $this->createSubscription([
            'name' => 'Phone finance',
            'next_payment_date' => '2026-12-01',
            'payments_end_on' => '2027-01-15',
            'notice_period_amount' => 10,
            'notice_period_unit' => 'days',
        ]);

        $body = $this->feed();

        self::assertStringContainsString('DTSTART;VALUE=DATE:20261201', $body);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20270101', $body);
        self::assertStringNotContainsString('DTSTART;VALUE=DATE:20270201', $body);
        self::assertSame(2, substr_count($this->unfold($body), 'SUMMARY:Phone finance'));
    }

    public function testATrialConversionAppears(): void
    {
        $this->createSubscription([
            'name' => 'Trial service',
            'is_trial' => true,
            'trial_end_date' => '2026-11-15',
            'converts_to_price_minor' => 1500,
            'next_payment_date' => null,
        ]);

        $body = $this->feed();

        self::assertStringContainsString('20261115', $body);
        self::assertStringContainsString('trial ends', $this->unfold($body));
    }

    public function testACancelByDeadlineAppears(): void
    {
        // A month's notice on a payment due on the 1st of December means the
        // last useful day to act is the 1st of November — the fact this feed
        // exists to put in somebody's diary.
        $this->createSubscription([
            'name' => 'Gym',
            'next_payment_date' => '2026-12-01',
            'notice_period_amount' => 1,
            'notice_period_unit' => 'months',
        ]);

        $body = $this->unfold($this->feed());

        self::assertStringContainsString('Last day to cancel Gym', $body);
        self::assertStringContainsString('20261101', $body);
    }

    public function testEventIdentifiersAreStableAcrossFetches(): void
    {
        // A calendar client refetches on a timer. Unstable ids would mean a
        // duplicate of every event, every time.
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        self::assertSame(
            $this->uids($this->feed()),
            $this->uids($this->feed()),
        );
    }

    public function testTextIsEscapedRatherThanBreakingTheFormat(): void
    {
        // A comma and a semicolon are field separators in iCalendar. A name
        // containing one would otherwise truncate the summary or corrupt the
        // event.
        $this->createSubscription([
            'name' => 'Books, papers; and things',
            'next_payment_date' => '2026-12-01',
        ]);

        $body = $this->unfold($this->feed());

        self::assertStringContainsString('Books\\, papers\; and things', $body);
    }

    private function feed(): string
    {
        return (string) $this->api('GET', '/api/v1/calendar.ics?token=' . $this->readOnlyToken)->getBody();
    }

    /**
     * Undo the 75-octet folding so assertions can look at whole values.
     */
    private function unfold(string $body): string
    {
        return str_replace("\r\n ", '', $body);
    }

    /**
     * @return list<string>
     */
    private function uids(string $body): array
    {
        preg_match_all('/^UID:(.+)$/m', $this->unfold($body), $matches);

        $uids = array_map(static fn (string $uid): string => trim($uid), $matches[1]);
        sort($uids);

        return $uids;
    }
}
