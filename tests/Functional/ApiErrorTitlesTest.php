<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\I18n\CatalogLoader;
use App\Repository\UserRepository;
use Slim\Psr7\Factory\ServerRequestFactory;

use function DI\factory;

/**
 * The API's 400 and 401 errors take their titles from the catalogue, as every
 * other status already did (K195), so an account reading the API in another
 * language is not handed Slim's English "400 Bad Request".
 *
 * A second locale is a fixture: the application ships `en` only, so the test
 * points the loader at a directory holding the real English catalogue and a
 * German one with the two new titles.
 */
final class ApiErrorTitlesTest extends ApiTestCase
{
    private ?string $catalogDir = null;

    protected function tearDown(): void
    {
        if ($this->catalogDir !== null) {
            foreach (glob($this->catalogDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->catalogDir);
        }

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        $directory = $this->catalogDir ??= sys_get_temp_dir() . '/renovo-titles-' . bin2hex(random_bytes(4));
        if (!is_dir($directory)) {
            mkdir($directory);
            $english = dirname(__DIR__, 2) . '/translations/en.php';
            file_put_contents($directory . '/en.php', '<?php return require ' . var_export($english, true) . ';');
            file_put_contents($directory . '/de.php', <<<'PHP'
<?php return [
    'error.page.bad_request_title' => 'Ungültige Anfrage',
    'error.page.unauthorized_title' => 'Nicht autorisiert',
];
PHP);
        }

        return [
            CatalogLoader::class => factory(static fn (): CatalogLoader => new CatalogLoader($directory)),
        ];
    }

    public function testAnUnauthenticatedRequestIsA401WithTheCataloguesTitle(): void
    {
        $response = $this->api('GET', '/api/v1/subscriptions');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Unauthorized', $this->decode($response)['error']['title']);
    }

    public function testAnUnreadableBodyIsA400WithTheCataloguesTitle(): void
    {
        $response = $this->apiRaw('POST', '/api/v1/subscriptions', $this->ownerToken, '{"name": ');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->decode($response)['error'];
        self::assertSame('Bad request', $error['title']);
        self::assertNotSame('', $error['message'], 'the message is still the throw site\'s own');
    }

    public function testTheTitlesFollowTheAccountsLanguage(): void
    {
        (new UserRepository($this->db))->updatePreferences($this->ownerId, ['locale' => 'de']);

        $unreadable = $this->apiRaw('POST', '/api/v1/subscriptions', $this->ownerToken, '{"name": ');
        self::assertSame(400, $unreadable->getStatusCode(), (string) $unreadable->getBody());
        self::assertSame('Ungültige Anfrage', $this->decode($unreadable)['error']['title']);
    }

    public function testA401FollowsTheClientsAcceptLanguage(): void
    {
        // A 401 is answered before anyone is known, so the language is the one
        // the client asked for.
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/v1/subscriptions', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept-Language', 'de');

        $response = $this->app->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Nicht autorisiert', $this->decode($response)['error']['title']);
    }

    public function testATokenThatIsNotValidIsA401WithTheCataloguesTitle(): void
    {
        $response = $this->api('GET', '/api/v1/subscriptions', 'not-a-token');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Unauthorized', $this->decode($response)['error']['title']);
    }
}
