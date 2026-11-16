<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeUpload;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `GET /api/v1/subscriptions/{id}` reads the same row the list does (K189).
 *
 * The list ran the catch-up and the single-row read did not, so a monthly row
 * 40 days overdue read 2026-09-21 (-40 days) by id and 2026-11-01 (1 day) from
 * the list a moment later.
 */
final class ApiSubscriptionShowCatchUpTest extends ApiTestCase
{
    private const NOW = '2026-10-31 10:00:00';

    private FrozenClock $clock;

    protected function containerOverrides(): array
    {
        $this->clock ??= FrozenClock::at(self::NOW);

        return [Clock::class => $this->clock];
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        parent::tearDown();
    }

    public function testAnOverdueRowReadsTheSameByIdAndFromTheList(): void
    {
        $id = $this->overdueMonthlyRow();

        // By id first: it must not depend on the list having caught the row up.
        $byId = $this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken);
        self::assertSame(200, $byId->getStatusCode(), (string) $byId->getBody());
        $shown = $this->decode($byId)['data'];

        $list = $this->api('GET', '/api/v1/subscriptions', $this->ownerToken);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $listed = null;
        foreach ($this->decode($list)['data'] as $row) {
            if ((int) $row['id'] === $id) {
                $listed = $row;
            }
        }
        self::assertIsArray($listed);

        self::assertSame('2026-11-01', $shown['next_payment_date']);
        self::assertSame(1, $shown['days_until_next_payment']);
        self::assertSame($listed['next_payment_date'], $shown['next_payment_date']);
        self::assertSame($listed['days_until_next_payment'], $shown['days_until_next_payment']);
    }

    public function testAReadOnlyTokenStillReadsWhatTheLastCatchUpWrote(): void
    {
        $id = $this->overdueMonthlyRow();

        $response = $this->api('GET', '/api/v1/subscriptions/' . $id, $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('2026-09-21', $this->decode($response)['data']['next_payment_date']);
    }

    /**
     * K244: the logo upload returns the row as GET by id would, caught up,
     * where it used to answer 2026-09-21 (-40 days).
     */
    public function testAnUploadedLogoReturnsTheRowCaughtUp(): void
    {
        $id = $this->overdueMonthlyRow();

        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                'http://localhost/api/v1/subscriptions/' . $id . '/logo',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )
            ->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->withUploadedFiles(['logo' => FakeUpload::png()]);
        $uploaded = $this->app->handle($request);
        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $returned = $this->decode($uploaded)['data'];

        $shown = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];

        self::assertSame('2026-11-01', $returned['next_payment_date']);
        self::assertSame(1, $returned['days_until_next_payment']);
        self::assertSame($shown['next_payment_date'], $returned['next_payment_date']);
        self::assertSame($shown['days_until_next_payment'], $returned['days_until_next_payment']);

        // The two dates that passed are in the ledger: the overdue one, then
        // the row's billing day (the 1st, from its first payment date).
        $dues = array_map(
            static fn (array $row): string => substr((string) $row['due_date'], 0, 10),
            $this->db->fetchAll(
                'SELECT due_date FROM subscription_charges WHERE subscription_id = :id ORDER BY due_date',
                ['id' => $id],
            ),
        );
        self::assertSame(['2026-09-21', '2026-10-01'], $dues);
    }

    private function overdueMonthlyRow(): int
    {
        $id = $this->createSubscription(['name' => 'Overdue']);
        $this->db->execute(
            'UPDATE subscriptions SET next_payment_date = :date WHERE id = :id',
            ['date' => '2026-09-21', 'id' => $id],
        );

        return $id;
    }
}
