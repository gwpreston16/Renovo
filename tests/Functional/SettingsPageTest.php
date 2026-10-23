<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\KeyRing;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Settings as three tabs (Phase 28), through the real stack: who may open
 * which tab, the sections each draws for whom, the category and tag
 * management on General, and the old screens' paths still arriving.
 */
final class SettingsPageTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private InstanceSettingsService $instance;
    private int $adminId;
    private int $ownerId;
    private int $editorId;
    private int $contributorId;
    private int $viewerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->instance = $container->get(InstanceSettingsService::class);
        $this->instance->setIsolationMode(IsolationMode::Shared);
        $this->instance->markSetupComplete('2026-01-01 00:00:00');
        $this->instance->setBaseCurrency('GBP');
        $this->instance->setRegistrationAllowed(false);
        $this->instance->setDemoMode(false);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();

        // The instance administrator belongs to the household as a Viewer:
        // what they may do on the Instance tab comes from the flag, not from
        // the household role.
        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, $now);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->contributorId = $users->create('contributor@example.test', 'Contributor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);

        $this->householdId = $households->create('Home', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
        $memberships->create($this->householdId, $this->adminId, Role::Viewer);
    }

    // ------------------------------------------------------------------
    // The Instance tab
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function householdRoles(): array
    {
        return [
            'owner' => ['owner'],
            'editor' => ['editor'],
            'contributor' => ['contributor'],
            'viewer' => ['viewer'],
        ];
    }

    /**
     * @dataProvider householdRoles
     */
    public function testTheInstanceTabIsRefusedToEveryHouseholdRole(string $who): void
    {
        $this->signIn($this->idOf($who));

        self::assertSame(403, $this->request('GET', '/settings/instance')->getStatusCode());
        self::assertSame(403, $this->request('POST', '/settings/instance', ['isolation_mode' => 'isolated'])
            ->getStatusCode());
        self::assertSame(IsolationMode::Shared, $this->instance->isolationMode());

        // Nor is it offered.
        $general = $this->body($this->request('GET', '/settings'));
        self::assertStringNotContainsString('href="/settings/instance"', $general);
    }

    public function testTheInstanceTabIsAnInstanceAdministrators(): void
    {
        $this->signIn($this->adminId);

        $response = $this->request('GET', '/settings/instance');
        self::assertSame(200, $response->getStatusCode());
        $page = $this->body($response);

        self::assertMatchesRegularExpression('~href="/settings/instance"\s+aria-current="page"~', $page);
        self::assertStringContainsString('id="data-isolation"', $page);
        self::assertStringContainsString('id="trusted-hosts"', $page);
        self::assertStringContainsString('id="instance-status"', $page);
        self::assertStringContainsString('name="demo_mode"', $page);
        self::assertStringContainsString('name="allow_registration"', $page);
    }

    public function testInstanceStatusWarnsWhileSecretsShareTheSessionKey(): void
    {
        $page = $this->instancePageWith(new KeyRing('session-key', [], fromSecretsKey: false));

        self::assertStringContainsString('Shared with sessions', $page);
        self::assertStringContainsString('SECRETS_KEY', $page);
    }

    public function testInstanceStatusSaysWhenSecretsHaveAKeyOfTheirOwn(): void
    {
        $page = $this->instancePageWith(new KeyRing('secrets-key'));

        self::assertStringContainsString('Own key', $page);
        self::assertStringNotContainsString('Shared with sessions', $page);
    }

    private function instancePageWith(KeyRing $keys): string
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            KeyRing::class => $keys,
        ]);
        $this->signIn($this->adminId);

        return $this->body($this->request('GET', '/settings/instance'));
    }

    public function testTheMailRelayIsDescribedAndItsPasswordIsNot(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        /** @var array{mail: array{host: string, password: string}} $settings */
        $settings = $container->get('settings');

        $this->signIn($this->adminId);
        $page = $this->body($this->request('GET', '/settings/instance'));

        self::assertStringContainsString($settings['mail']['host'], $page);
        if ($settings['mail']['password'] !== '') {
            self::assertStringNotContainsString($settings['mail']['password'], $page);
        }
    }

    /**
     * The isolation mode is changed on the Instance tab, and that form names
     * only its own settings: saving it leaves the currency, the provider and
     * registration where they were unless it says otherwise.
     */
    public function testTheInstanceFormChangesIsolationAndLeavesTheCurrencyAlone(): void
    {
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/instance', [
            'isolation_mode' => 'isolated',
            'allow_registration' => '1',
            'demo_mode' => '0',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/settings/instance', $response->getHeaderLine('Location'));
        self::assertSame(IsolationMode::Isolated, $this->instance->isolationMode());
        self::assertTrue($this->instance->registrationAllowed());
        self::assertSame('GBP', $this->instance->baseCurrency());
    }

    public function testTheCurrencyFormLeavesRegistrationAndDemoModeAlone(): void
    {
        $this->instance->setRegistrationAllowed(true);
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/currency', ['base_currency' => 'EUR']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/settings#household', $response->getHeaderLine('Location'));
        self::assertSame('EUR', $this->instance->baseCurrency());
        self::assertTrue($this->instance->registrationAllowed(), 'a setting not posted is not turned off');
        self::assertFalse($this->instance->isDemoMode());
    }

    public function testTheBaseCurrencySelectNamesEachCurrencyAndLeadsWithTheCommonThree(): void
    {
        $this->signIn($this->adminId);

        $page = $this->body($this->request('GET', '/settings'));

        preg_match_all('~<select id="base_currency".*?</select>~s', $page, $select);
        self::assertNotEmpty($select[0], 'No base-currency select on the page.');
        preg_match_all('~<option value="([A-Z]{3})"[^>]*>([^<]*)</option>~', $select[0][0], $options);

        self::assertSame(['GBP', 'EUR', 'USD'], array_slice($options[1], 0, 3));
        self::assertSame('GBP - Pounds sterling', trim($options[2][0]));
        self::assertSame('EUR - Euro', trim($options[2][1]));
    }

    // ------------------------------------------------------------------
    // General
    // ------------------------------------------------------------------

    /**
     * The currency and the rates are drawn for everybody and changed only by
     * an instance administrator — a household Owner included, because they
     * are the instance's, not the household's.
     */
    public function testAHouseholdOwnerSeesTheCurrencyAndRatesButCannotChangeThem(): void
    {
        $this->signIn($this->ownerId);

        $page = $this->body($this->request('GET', '/settings'));
        self::assertStringContainsString('id="exchange-rates"', $page);
        self::assertStringContainsString('It is set by the instance administrator.', $page);
        self::assertStringNotContainsString('action="/settings/currency"', $page);
        self::assertStringNotContainsString('action="/settings/rates/refresh"', $page);
        // And the household's own name is theirs to change.
        self::assertStringContainsString('action="/settings/household"', $page);

        self::assertSame(403, $this->request('POST', '/settings/currency', ['base_currency' => 'EUR'])
            ->getStatusCode());
        self::assertSame(403, $this->request('POST', '/settings/rates/refresh')->getStatusCode());
        self::assertSame('GBP', $this->instance->baseCurrency());
    }

    /**
     * An Owner who is also the instance administrator changes the name and the
     * currency together: one form, one button, one post.
     */
    public function testAnOwnerWhoAdministersTheInstanceSavesTheNameAndCurrencyTogether(): void
    {
        $adminOwnerId = (new UserRepository($this->db))
            ->create('admin-owner@example.test', 'Admin Owner', 'hash', true, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $adminOwnerId, Role::OwnerAdmin);
        $this->signIn($adminOwnerId);

        $page = $this->body($this->request('GET', '/settings'));
        $card = substr($page, (int) strpos($page, 'id="household"'));
        $card = substr($card, 0, (int) strpos($card, '</section>'));

        self::assertSame(1, substr_count($card, '<form'));
        self::assertSame(1, substr_count($card, 'type="submit"'));
        self::assertMatchesRegularExpression(
            '~action="/settings/household".*name="name".*name="base_currency"~s',
            $card,
        );
        self::assertStringContainsString('Save household', $card);
        self::assertStringNotContainsString('Save currency', $card);

        $response = $this->request('POST', '/settings/household', ['name' => 'Flat', 'base_currency' => 'EUR']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/settings#household', $response->getHeaderLine('Location'));
        self::assertSame('Flat', (new HouseholdRepository($this->db))->findById($this->householdId)?->name);
        self::assertSame('EUR', $this->instance->baseCurrency());
    }

    /**
     * The household route takes a currency only from an instance
     * administrator, and a refused post renames nothing either.
     */
    public function testAnOwnerCannotSetTheCurrencyThroughTheHouseholdForm(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('POST', '/settings/household', ['name' => 'Flat', 'base_currency' => 'EUR']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Home', (new HouseholdRepository($this->db))->findById($this->householdId)?->name);
        self::assertSame('GBP', $this->instance->baseCurrency());
    }

    public function testTheRateProviderKeyIsNeverRenderedBack(): void
    {
        $this->instance->setRateProviderKey('stored-secret-key-123');
        $this->signIn($this->adminId);

        $page = $this->body($this->request('GET', '/settings'));

        self::assertStringContainsString('name="rate_provider_key"', $page);
        self::assertStringNotContainsString('stored-secret-key-123', $page);
    }

    public function testTheRateProvidersAreOfferedAsOptionCards(): void
    {
        $this->signIn($this->adminId);

        $page = (string) preg_replace('/\s+/', ' ', $this->body($this->request('GET', '/settings')));

        self::assertMatchesRegularExpression(
            '~<label class="role-option"> <input type="radio" name="rate_provider" value="frankfurter"~',
            $page,
        );
    }

    public function testTheIsolationModesAreOfferedAsTheSameOptionCards(): void
    {
        $this->signIn($this->adminId);

        $page = (string) preg_replace('/\s+/', ' ', $this->body($this->request('GET', '/settings/instance')));

        self::assertStringContainsString('<fieldset class="role-options" id="data-isolation">', $page);
        foreach (['shared', 'isolated'] as $mode) {
            self::assertMatchesRegularExpression(
                '~<label class="role-option"> <input type="radio" name="isolation_mode" value="' . $mode . '"~',
                $page,
            );
        }
    }

    public function testAViewerSeesTheListsButNoFormsForThem(): void
    {
        $scope = $this->ownerScope();
        (new CategoryRepository($this->db))->create($scope, 'Streaming', null);
        (new TagRepository($this->db))->create($scope, 'family');

        $this->signIn($this->viewerId);
        $page = $this->body($this->request('GET', '/settings'));

        self::assertStringContainsString('Streaming', $page);
        self::assertStringContainsString('family', $page);
        self::assertStringNotContainsString('action="/categories', $page);
        self::assertStringNotContainsString('action="/tags', $page);
        self::assertStringNotContainsString('action="/settings/household"', $page);
    }

    public function testEachCategoryAndTagCarriesItsSubscriptionCount(): void
    {
        $this->signIn($this->ownerId);
        $streaming = (new CategoryRepository($this->db))->create($this->ownerScope(), 'Streaming', null);
        $this->createSubscription('Netflix', ['category_id' => (string) $streaming, 'tags' => 'tv, family']);
        $this->createSubscription('Disney+', ['category_id' => (string) $streaming, 'tags' => 'tv']);
        $this->createSubscription('Gym', ['tags' => 'family']);

        $page = (string) preg_replace('/\s+/', ' ', $this->body($this->request('GET', '/settings')));

        self::assertMatchesRegularExpression(
            '~setting-chip-name">Streaming</span> <span class="setting-chip-count num">2<~',
            $page,
        );
        self::assertMatchesRegularExpression(
            '~setting-chip-name">tv</span> <span class="setting-chip-count num">2<~',
            $page,
        );
        self::assertMatchesRegularExpression(
            '~setting-chip-name">family</span> <span class="setting-chip-count num">2<~',
            $page,
        );
    }

    public function testACategoryRenamedInlinePersistsAndItsDeleteUnassignsWithoutDeleting(): void
    {
        $this->signIn($this->ownerId);
        $categories = new CategoryRepository($this->db);
        $id = $categories->create($this->ownerScope(), 'Streaming', '#112233');
        $subscription = $this->createSubscription('Netflix', ['category_id' => (string) $id]);

        $response = $this->request('POST', '/categories/' . $id, ['name' => 'Video', 'colour' => '#112233']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/settings#categories', $response->getHeaderLine('Location'));
        self::assertSame('Video', $categories->find($this->ownerScope(), $id)?->name);

        self::assertSame(302, $this->request('POST', '/categories/' . $id . '/delete')->getStatusCode());
        self::assertNull($categories->find($this->ownerScope(), $id));

        // The subscription is still there, and no longer filed under anything.
        $row = $this->db->fetchOne(
            'SELECT ' . $this->db->platform()->quoteIdentifier('category_id')
            . ' FROM ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['id' => $subscription],
        );
        self::assertNotNull($row);
        self::assertNull($row['category_id']);
    }

    public function testARenameThatFailsComesBackOpenBesideItsRow(): void
    {
        $this->signIn($this->ownerId);
        $categories = new CategoryRepository($this->db);
        $categories->create($this->ownerScope(), 'Streaming', null);
        $id = $categories->create($this->ownerScope(), 'Video', null);

        $response = $this->request('POST', '/categories/' . $id, ['name' => 'streaming']);

        self::assertSame(422, $response->getStatusCode());
        $page = (string) preg_replace('/\s+/', ' ', $this->body($response));
        self::assertMatchesRegularExpression('~<details class="setting-chip" open>~', $page);
        self::assertStringContainsString('A category with that name already exists.', $page);
        self::assertSame('Video', $categories->find($this->ownerScope(), $id)?->name);
    }

    public function testATagIsAddedRenamedAndDeletedFromItsSection(): void
    {
        $this->signIn($this->ownerId);
        $tags = new TagRepository($this->db);

        $response = $this->request('POST', '/tags', ['name' => 'family']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/settings#tags', $response->getHeaderLine('Location'));
        $tag = $tags->findAll($this->ownerScope())[0];
        self::assertSame('family', $tag->name);

        // A duplicate, in any case, is refused on the form.
        self::assertSame(422, $this->request('POST', '/tags', ['name' => 'Family'])->getStatusCode());

        $subscription = $this->createSubscription('Netflix', ['tags' => 'family']);

        self::assertSame(302, $this->request('POST', '/tags/' . $tag->id, ['name' => 'household'])->getStatusCode());
        self::assertSame('household', $tags->find($this->ownerScope(), $tag->id)?->name);

        self::assertSame(302, $this->request('POST', '/tags/' . $tag->id . '/delete')->getStatusCode());
        self::assertSame([], $tags->findAll($this->ownerScope()));
        self::assertNotNull($this->db->fetchOne(
            'SELECT 1 FROM ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['id' => $subscription],
        ), 'deleting a tag deletes no subscription');
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function listWrites(): array
    {
        return [
            'add a category' => ['POST', '/categories', ['name' => 'Nope']],
            'rename a category' => ['POST', '/categories/{category}', ['name' => 'Nope']],
            'delete a category' => ['POST', '/categories/{category}/delete', []],
            'add a tag' => ['POST', '/tags', ['name' => 'nope']],
            'rename a tag' => ['POST', '/tags/{tag}', ['name' => 'nope']],
            'delete a tag' => ['POST', '/tags/{tag}/delete', []],
        ];
    }

    /**
     * @dataProvider listWrites
     * @param array<string, string> $body
     */
    public function testAViewerCannotManageCategoriesOrTags(string $method, string $path, array $body): void
    {
        $category = (new CategoryRepository($this->db))->create($this->ownerScope(), 'Streaming', null);
        $tag = (new TagRepository($this->db))->create($this->ownerScope(), 'family');

        $this->signIn($this->viewerId);
        $path = str_replace(['{category}', '{tag}'], [(string) $category, (string) $tag], $path);

        self::assertSame(403, $this->request($method, $path, $body)->getStatusCode());
        self::assertSame('Streaming', (new CategoryRepository($this->db))->find($this->ownerScope(), $category)?->name);
        self::assertSame('family', (new TagRepository($this->db))->find($this->ownerScope(), $tag)?->name);
    }

    // ------------------------------------------------------------------
    // Data & integrations
    // ------------------------------------------------------------------

    public function testEveryMemberMayOpenDataAndIntegrationsAndSeesOnlyTheirSections(): void
    {
        $this->signIn($this->ownerId);
        $owner = $this->body($this->request('GET', '/settings/data'));
        foreach (['import', 'backup', 'export', 'api-tokens', 'recent-activity'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $owner, 'An Owner should see ' . $id);
        }
        self::assertStringContainsString('href="/audit"', $owner);
        self::assertStringNotContainsString('id="calendar-feed-link"', $owner);

        $this->signIn($this->viewerId);
        $response = $this->request('GET', '/settings/data');
        self::assertSame(200, $response->getStatusCode());
        $viewer = $this->body($response);
        self::assertStringContainsString('id="api-tokens"', $viewer);
        foreach (['import', 'backup', 'recent-activity'] as $id) {
            self::assertStringNotContainsString('id="' . $id . '"', $viewer, 'A Viewer should not see ' . $id);
        }
    }

    public function testRecentActivityIsTheFiveLatestEntries(): void
    {
        $this->signIn($this->ownerId);
        foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $name) {
            $this->request('POST', '/settings/household', ['name' => 'Home ' . $name]);
        }

        $page = $this->body($this->request('GET', '/settings/data'));
        $section = substr($page, (int) strpos($page, 'id="recent-activity"'));
        $section = substr($section, 0, (int) strpos($section, '</section>'));

        self::assertSame(5, substr_count($section, 'class="account-row"'));
    }

    public function testTheSubscriptionsExportAsJsonUnderTheCsvHeadings(): void
    {
        $this->signIn($this->ownerId);
        $this->createSubscription('Netflix', []);

        $response = $this->request('GET', '/subscriptions/export.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('.json"', $response->getHeaderLine('Content-Disposition'));

        /** @var list<array<string, string>> $rows */
        $rows = json_decode($this->body($response), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $rows);
        self::assertSame('Netflix', $rows[0]['name']);
        // Money as a decimal string, never a float.
        self::assertSame('10.99', $rows[0]['price']);
        self::assertSame('GBP', $rows[0]['currency']);
    }

    // ------------------------------------------------------------------
    // What the Phase 19 link row pointed at
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function movedScreens(): array
    {
        return [
            'categories' => ['/categories', '/settings#categories'],
            'payment methods' => ['/payment-methods', '/settings#payment-methods'],
            'backup' => ['/settings/backup', '/settings/data#backup'],
            'api tokens' => ['/settings/api-tokens', '/settings/data#api-tokens'],
        ];
    }

    /**
     * @dataProvider movedScreens
     */
    public function testAMovedScreensPathRedirectsToItsSection(string $old, string $new): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('GET', $old);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($new, $response->getHeaderLine('Location'));
    }

    /**
     * Every destination the interim link row offered — categories, payment
     * methods, import, backup, the audit log, API tokens — is on a tab now,
     * as a section or as a link, and the row itself is gone.
     */
    public function testEveryDestinationOfThePhase19LinkRowIsReachableFromATab(): void
    {
        $this->signIn($this->ownerId);

        $general = $this->body($this->request('GET', '/settings'));
        $data = $this->body($this->request('GET', '/settings/data'));

        self::assertStringNotContainsString('settings-links', $general);
        self::assertStringContainsString('id="categories"', $general);
        self::assertStringContainsString('id="tags"', $general);
        self::assertStringContainsString('id="payment-methods"', $general);
        self::assertStringContainsString('href="/import"', $data);
        self::assertStringContainsString('id="backup"', $data);
        self::assertStringContainsString('href="/audit"', $data);
        self::assertStringContainsString('id="api-tokens"', $data);

        // And the tabs link to each other.
        foreach (['/settings', '/settings/data'] as $tab) {
            self::assertStringContainsString('href="' . $tab . '"', $general);
            self::assertStringContainsString('href="' . $tab . '"', $data);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $extra
     */
    private function createSubscription(string $name, array $extra): int
    {
        $response = $this->request('POST', '/subscriptions', [
            'name' => $name,
            'price' => '10.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => '1',
            ...$extra,
        ]);
        self::assertSame(302, $response->getStatusCode(), $this->body($response));

        return (int) $this->db->fetchValue(
            'SELECT MAX(' . $this->db->platform()->quoteIdentifier('id') . ') FROM '
            . $this->db->platform()->quoteIdentifier('subscriptions'),
        );
    }

    private function idOf(string $who): int
    {
        return match ($who) {
            'owner' => $this->ownerId,
            'editor' => $this->editorId,
            'contributor' => $this->contributorId,
            default => $this->viewerId,
        };
    }

    private function ownerScope(): Scope
    {
        return Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
