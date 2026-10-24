<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Role;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A subscription's logo is only ever a file uploaded for it (K1).
 *
 * The edit form used to save any `logo_path` it was posted when no file came
 * with it, so a member could point every household page at a third-party
 * image, or copy a co-member's logo path onto their own row and delete that
 * row to delete the co-member's file.
 */
final class LogoPathTest extends ApiTestCase
{
    private const MEMBERS_LOGO = 'assets/logos/0123456789abcdef0123456789abcdef.png';

    private int $contributorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contributorId = (new UserRepository($this->db))
            ->create('contributor@example.test', 'Contributor', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $this->contributorId, Role::Contributor);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function postedPaths(): array
    {
        return [
            'a third-party image' => ['/attacker.example/p.png'],
            'a protocol-relative URL' => ['//attacker.example/p.png'],
            'an absolute URL' => ['https://attacker.example/p.png'],
            "a co-member's stored logo" => [self::MEMBERS_LOGO],
        ];
    }

    /**
     * @dataProvider postedPaths
     */
    public function testAnEditorsPostedPathIsNotSaved(string $posted): void
    {
        $subscriptionId = $this->createSubscription();

        $this->signIn($this->editorId);
        $response = $this->postEdit($subscriptionId, $posted);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->logoOf($subscriptionId));
    }

    /**
     * @dataProvider postedPaths
     */
    public function testAContributorsPostedPathIsNotSavedOnTheirOwnRow(string $posted): void
    {
        $this->signIn($this->contributorId);
        $create = $this->web('POST', '/subscriptions', $this->form(['owner_user_id' => (string) $this->contributorId]));
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $subscriptionId = (int) $this->db->fetchValue('SELECT MAX(id) FROM subscriptions');

        $response = $this->postEdit($subscriptionId, $posted, $this->contributorId);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->logoOf($subscriptionId));
    }

    public function testAPathPostedOnCreateIsNotSavedEither(): void
    {
        $this->signIn($this->editorId);

        $this->web('POST', '/subscriptions', $this->form(['logo_path' => self::MEMBERS_LOGO]));

        self::assertNull($this->db->fetchValue('SELECT logo_path FROM subscriptions'));
    }

    public function testTheStoredLogoSurvivesAnEditThatPostsAnotherPath(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->db->execute(
            'UPDATE subscriptions SET logo_path = :path WHERE id = :id',
            ['path' => self::MEMBERS_LOGO, 'id' => $subscriptionId],
        );

        $this->signIn($this->editorId);
        $this->postEdit($subscriptionId, '/attacker.example/p.png');

        self::assertSame(self::MEMBERS_LOGO, $this->logoOf($subscriptionId));
    }

    private function postEdit(int $subscriptionId, string $logoPath, ?int $ownerId = null): ResponseInterface
    {
        return $this->web('POST', '/subscriptions/' . $subscriptionId, $this->form([
            'logo_path' => $logoPath,
            'owner_user_id' => (string) ($ownerId ?? $this->ownerId),
        ]));
    }

    private function logoOf(int $subscriptionId): ?string
    {
        $path = $this->db->fetchValue('SELECT logo_path FROM subscriptions WHERE id = :id', ['id' => $subscriptionId]);

        return $path === null ? null : (string) $path;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '10.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => '1',
            'visibility' => 'household',
            'owner_user_id' => (string) $this->ownerId,
        ];
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, string> $body
     */
    private function web(string $method, string $path, array $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body)
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
    }
}
