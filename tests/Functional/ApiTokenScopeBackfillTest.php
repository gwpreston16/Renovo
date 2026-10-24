<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\TokenScopes;
use App\Service\ApiTokenService;
use PDOStatement;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\AbstractMigration;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The Phase 42 backfill gives every token that existed before scopes exactly
 * the access it had: a read token reads everything, a write token can do
 * everything, the calendar feed's token reads the calendar — and each one's
 * requests are answered as they were.
 */
final class ApiTokenScopeBackfillTest extends ApiTestCase
{
    private const MIGRATION = '20270701000002_backfill_api_token_scopes.php';

    public function testEachAbilityMapsToItsScopes(): void
    {
        $read = $this->legacy($this->readOnlyToken, 'read');
        $write = $this->legacy($this->ownerToken, 'write');
        $feed = $this->feedToken();

        $this->migrate('up');

        self::assertSame('subscriptions:read catalogue:read calendar:read', $this->scopesOf($read));
        self::assertSame(
            'subscriptions:read subscriptions:write catalogue:read catalogue:write calendar:read',
            $this->scopesOf($write),
        );
        self::assertSame('calendar:read', $this->scopesOf($feed));
    }

    public function testBackfilledTokensAreAnsweredAsBefore(): void
    {
        $id = $this->createSubscription();
        $this->legacy($this->readOnlyToken, 'read');
        $this->legacy($this->ownerToken, 'write');

        $this->migrate('up');

        // A read token reads, the feed included, and writes nothing.
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions', $this->readOnlyToken)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/tags', $this->readOnlyToken)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/calendar.ics?token=' . $this->readOnlyToken)->getStatusCode());
        $delete = $this->api('DELETE', '/api/v1/subscriptions/' . $id, $this->readOnlyToken);
        self::assertSame(403, $delete->getStatusCode());

        // A write token writes, and is still kept out of the feed URL.
        $tag = $this->api('POST', '/api/v1/tags', $this->ownerToken, ['name' => 'Kept']);
        self::assertSame(201, $tag->getStatusCode());
        self::assertSame(204, $this->api('DELETE', '/api/v1/subscriptions/' . $id, $this->ownerToken)->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/v1/calendar.ics?token=' . $this->ownerToken)->getStatusCode());
    }

    public function testTheFeedTokenStillReadsTheFeed(): void
    {
        $token = $this->container()->get(ApiTokenService::class)
            ->replaceFeedToken($this->owner(), $this->householdId);
        $this->db->execute('UPDATE api_tokens SET scopes = NULL');

        $this->migrate('up');

        self::assertSame(200, $this->api('GET', '/api/v1/calendar.ics?token=' . $token)->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/v1/subscriptions', $token)->getStatusCode());
    }

    public function testItIsIdempotentAndRollsBack(): void
    {
        $id = $this->legacy($this->readOnlyToken, 'read');
        $this->migrate('up');
        $this->db->execute("UPDATE api_tokens SET scopes = 'catalogue:read' WHERE id = :id", ['id' => $id]);

        // A second run leaves a row that already has scopes alone.
        $this->migrate('up');
        self::assertSame('catalogue:read', $this->scopesOf($id));

        $this->migrate('down');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM api_tokens WHERE scopes IS NOT NULL'));
        $ability = $this->db->fetchValue('SELECT abilities FROM api_tokens WHERE id = :id', ['id' => $id]);
        self::assertSame('read', $ability);
    }

    public function testRollingBackRevokesTokensReadOrWriteCannotDescribe(): void
    {
        $user = $this->owner();
        $service = $this->container()->get(ApiTokenService::class);
        $service->issue($user, $this->householdId, 'Catalogue writer', TokenScopes::fromString('catalogue:write'));
        $service->issue($user, $this->householdId, 'Calendar', TokenScopes::calendarOnly());
        $feed = $service->replaceFeedToken($user, $this->householdId);

        $output = $this->migrate('down');

        $live = $this->db->fetchAll('SELECT name FROM api_tokens WHERE revoked_at IS NULL ORDER BY name');
        self::assertSame(
            ['Calendar feed', 'Editor token', 'Owner token', 'Read-only token', 'Viewer token'],
            array_column($live, 'name'),
            'The two narrow tokens are revoked; the presets and the feed link are kept.',
        );
        self::assertStringContainsString('Revoked 2 API token(s)', $output);
        self::assertNotSame('', $feed);
    }

    /**
     * Make an issued token look as it did before Phase 42: an ability, and
     * no scopes.
     */
    private function legacy(string $token, string $ability): int
    {
        $publicId = explode('_', $token)[1];
        $this->db->execute(
            'UPDATE api_tokens SET abilities = :ability, scopes = NULL WHERE public_id = :public',
            ['ability' => $ability, 'public' => $publicId],
        );

        return (int) $this->db->fetchValue(
            'SELECT id FROM api_tokens WHERE public_id = :public',
            ['public' => $publicId],
        );
    }

    private function feedToken(): int
    {
        $token = $this->container()->get(ApiTokenService::class)
            ->replaceFeedToken($this->owner(), $this->householdId);

        return $this->legacy($token, 'read');
    }

    private function owner(): \App\Domain\Entity\User
    {
        $user = (new \App\Repository\UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($user);

        return $user;
    }

    private function scopesOf(int $id): ?string
    {
        $value = $this->db->fetchValue('SELECT scopes FROM api_tokens WHERE id = :id', ['id' => $id]);

        return is_string($value) ? $value : null;
    }

    private function migrate(string $direction): string
    {
        require_once __DIR__ . '/../../migrations/' . self::MIGRATION;

        $pdo = $this->db->connection();
        $prepare = static function (string $sql, array $params) use ($pdo): PDOStatement {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        };

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('execute')->willReturnCallback(
            static fn (string $sql, array $params = []): int => $prepare($sql, $params)->rowCount(),
        );

        $migration = new \BackfillApiTokenScopes('testing', 20270701000002);
        $migration->setAdapter($adapter);
        $output = new BufferedOutput();
        $migration->setOutput($output);
        $migration->{$direction}();

        return $output->fetch();
    }
}
