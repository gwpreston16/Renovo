<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\PdoSessionHandler;
use App\Repository\UserRepository;

/**
 * The session store itself, with no web request in front of it: a new session
 * is inserted, read back, rewritten in place, destroyed, and the expired ones
 * collected. The functional tests use an in-memory session, so without these
 * nothing would run this class.
 */
final class PdoSessionHandlerTest extends DatabaseTestCase
{
    private const LIFETIME = 3600;
    private const BROWSER_LIFETIME = 600;

    private PdoSessionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new PdoSessionHandler($this->db, self::LIFETIME, self::BROWSER_LIFETIME);
    }

    public function testANewSessionIsInsertedWithWhereItCameFromAndReadBack(): void
    {
        $userId = (new UserRepository($this->db))->create('session@example.test', 'Session', 'hash');
        $this->handler->describeClient('203.0.113.9', str_repeat('A', 400));
        $this->handler->associateUser($userId);

        self::assertTrue($this->handler->write('session-one', 'payload-one'));

        self::assertSame('payload-one', $this->handler->read('session-one'));
        $row = $this->row('session-one');
        self::assertSame('203.0.113.9', $row['ip_address']);
        self::assertSame(255, strlen((string) $row['user_agent']), 'The agent is cut to fit its column.');
        self::assertSame($userId, (int) $row['user_id']);
        self::assertEqualsWithDelta(
            time() + self::LIFETIME,
            strtotime((string) $row['expires_at']),
            5,
            'A kept session lasts the long lifetime.',
        );
    }

    public function testASessionThatEndsWithTheBrowserExpiresSooner(): void
    {
        $this->handler->setPersistent(false);

        $this->handler->write('session-browser', 'x');

        self::assertEqualsWithDelta(
            time() + self::BROWSER_LIFETIME,
            strtotime((string) $this->row('session-browser')['expires_at']),
            5,
        );
    }

    public function testWritingAgainUpdatesTheRowAndKeepsWhereItCameFrom(): void
    {
        $this->handler->describeClient('203.0.113.9', 'First browser');
        $this->handler->write('session-two', 'first');

        $this->handler->describeClient('198.51.100.1', 'Another browser');
        $this->handler->write('session-two', 'second');

        self::assertSame('second', $this->handler->read('session-two'));
        self::assertSame(1, $this->rows('session-two'), 'One row, not two.');
        $row = $this->row('session-two');
        self::assertSame('203.0.113.9', $row['ip_address'], 'A session is a device: it is not re-described.');
        self::assertSame('First browser', $row['user_agent']);
    }

    public function testRewritingIdenticalContentsIsNotAnInsertOfTheSameKey(): void
    {
        $this->handler->write('session-same', 'unchanged');

        self::assertTrue($this->handler->write('session-same', 'unchanged'));
        self::assertTrue($this->handler->updateTimestamp('session-same', 'unchanged'));
        self::assertSame(1, $this->rows('session-same'));
    }

    public function testAnUnknownOrExpiredSessionReadsAsEmpty(): void
    {
        $this->handler->write('session-old', 'stale');
        $this->expire('session-old');

        self::assertSame('', $this->handler->read('no-such-session'));
        self::assertSame('', $this->handler->read('session-old'), 'An expired row is not served, collected or not.');
        self::assertFalse($this->handler->validateId('session-old'));
    }

    public function testDestroyRemovesOnlyThatSession(): void
    {
        $this->handler->write('session-keep', 'keep');
        $this->handler->write('session-go', 'go');

        self::assertTrue($this->handler->destroy('session-go'));

        self::assertSame(0, $this->rows('session-go'));
        self::assertSame('keep', $this->handler->read('session-keep'));
        self::assertTrue($this->handler->destroy('session-go'), 'Destroying what is gone is not an error.');
    }

    public function testCollectionRemovesOnlyTheExpiredRows(): void
    {
        $this->handler->write('session-live', 'live');
        $this->handler->write('session-expired-a', 'a');
        $this->handler->write('session-expired-b', 'b');
        $this->expire('session-expired-a');
        $this->expire('session-expired-b');

        self::assertSame(2, $this->handler->gc(self::LIFETIME));

        self::assertSame(1, $this->rows('session-live'));
        self::assertSame(0, $this->rows('session-expired-a') + $this->rows('session-expired-b'));
        self::assertSame(0, $this->handler->gc(self::LIFETIME), 'Nothing left to collect.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM sessions WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);

        return $row;
    }

    private function rows(string $id): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions WHERE id = :id', ['id' => $id]);
    }

    private function expire(string $id): void
    {
        $this->db->execute(
            'UPDATE sessions SET expires_at = :at WHERE id = :id',
            ['at' => date('Y-m-d H:i:s', time() - 60), 'id' => $id],
        );
    }
}
