<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger;

use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Swagger\DocumentBuilder;
use Nesto\Swagger\SwaggerModule;
use Nesto\Tests\Swagger\Fixtures\ListUsersQuery;
use Nesto\Tests\Swagger\Fixtures\SwaggerAppModule;
use PHPUnit\Framework\TestCase;

final class SwaggerDocumentTest extends TestCase
{
    private static array $doc;

    public static function setUpBeforeClass(): void
    {
        $app = NestoFactory::create(SwaggerAppModule::class);
        $config = (new DocumentBuilder)
            ->setTitle('Nesto test API')->setDescription('desc')->setVersion('2.0')
            ->addTag('users', 'User management')->addServer('https://api.test', 'prod')
            ->addBearerAuth()->addApiKey(['name' => 'X-Api-Key', 'in' => 'header'], 'api-key')->addBasicAuth()
            ->setContact('Team', 'https://nesto.test', 'team@nesto.test')->setLicense('MIT', 'https://mit.test')
            ->build();
        self::$doc = SwaggerModule::createDocument($app, $config, ['extraModels' => [ListUsersQuery::class]]);
    }

    public function testInfoAndComponents(): void
    {
        $doc = self::$doc;
        self::assertSame('3.0.3', $doc['openapi']);
        self::assertSame(['title' => 'Nesto test API', 'description' => 'desc', 'version' => '2.0', 'contact' => ['name' => 'Team', 'url' => 'https://nesto.test', 'email' => 'team@nesto.test'], 'license' => ['name' => 'MIT', 'url' => 'https://mit.test']], $doc['info']);
        self::assertSame([['url' => 'https://api.test', 'description' => 'prod']], $doc['servers']);
        self::assertSame([['name' => 'users', 'description' => 'User management']], $doc['tags']);
        self::assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $doc['components']['securitySchemes']['bearer']);
        self::assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'], $doc['components']['securitySchemes']['api-key']);
        self::assertSame(['type' => 'http', 'scheme' => 'basic'], $doc['components']['securitySchemes']['basic']);
        self::assertArrayHasKey('ErrorDto', $doc['components']['schemas'], 'ApiExtraModels');
        self::assertArrayHasKey('ListUsersQuery', $doc['components']['schemas'], 'extraModels option');
    }

    public function testAuthRoutesFromTheAuthFixtureApp(): void
    {
        $paths = self::$doc['paths'];
        $login = $paths['/auth/login']['post'];
        self::assertSame(['auth'], $login['tags']);
        self::assertSame('Log in', $login['summary']);
        self::assertSame('Returns a JWT access token', $login['description']);
        self::assertSame('AuthController_login', $login['operationId']);
        self::assertSame(['$ref' => '#/components/schemas/LoginDto'], $login['requestBody']['content']['application/json']['schema']);
        self::assertTrue($login['requestBody']['required']);
        self::assertSame('Token issued', $login['responses']['201']['description']);
        self::assertSame('Bad credentials', $login['responses']['401']['description']);
        self::assertArrayNotHasKey('security', $login);

        $loginDto = self::$doc['components']['schemas']['LoginDto'];
        self::assertSame('Credentials for POST /auth/login.', $loginDto['description']);
        self::assertSame(['type' => 'string', 'example' => 'alice', 'minLength' => 2], $loginDto['properties']['username']);
        self::assertSame(['type' => 'string', 'example' => 'secret', 'format' => 'password'], $loginDto['properties']['password']);
        self::assertSame(['username', 'password'], $loginDto['required']);

        $profile = $paths['/profile']['get'];
        self::assertSame([['bearer' => []]], $profile['security']);
        self::assertSame('OK', $profile['responses']['200']['description']);
        self::assertArrayNotHasKey('parameters', $profile, '#[CurrentUser] is not an HTTP parameter');
    }

    public function testParametersInference(): void
    {
        $list = self::$doc['paths']['/users']['get'];
        self::assertSame(['users'], $list['tags']);
        self::assertSame([['api-key' => []]], $list['security'], 'class-level ApiSecurity');
        $byName = [];
        foreach ($list['parameters'] as $p) {
            $byName[$p['in'] . ':' . $p['name']] = $p;
        }
        self::assertSame(['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'description' => 'Page number'], $byName['query:page']);
        self::assertSame(['type' => 'string', 'nullable' => true], $byName['query:search']['schema']);
        self::assertFalse($byName['query:search']['required']);
        self::assertSame(['type' => 'string', 'default' => 'id'], $byName['query:sort']['schema']);
        self::assertFalse($byName['query:sort']['required']);
        self::assertSame('header', $byName['header:x-tenant']['in']);
        self::assertFalse($byName['header:x-tenant']['required']);

        $show = self::$doc['paths']['/users/{id}']['get'];
        self::assertSame([['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'User id']], $show['parameters']);
        self::assertSame(['$ref' => '#/components/schemas/CreateUserDto'], $show['responses']['200']['content']['application/json']['schema']);
        self::assertSame('No such user', $show['responses']['404']['description']);

        $addRole = self::$doc['paths']['/users/{id}/roles']['post'];
        self::assertTrue($addRole['deprecated']);
        self::assertSame('Give a user a role.', $addRole['description'], 'inline @tag stripped');
        self::assertSame(200, array_key_first($addRole['responses']), '#[HttpCode(200)] respected');
        $notify = array_values(array_filter($addRole['parameters'], fn ($p) => $p['name'] === 'notify'))[0];
        self::assertSame(['type' => 'boolean'], $notify['schema']);
        self::assertSame('Send an email', $notify['description']);
        self::assertSame([
            'type' => 'object',
            'properties' => ['role' => ['type' => 'string'], 'expires' => ['type' => 'integer', 'nullable' => true]],
            'required' => ['role'],
        ], $addRole['requestBody']['content']['application/json']['schema']);

        $remove = self::$doc['paths']['/users/{id}']['delete'];
        self::assertSame(['204' => ['description' => 'No Content']], $remove['responses']);

        $create = self::$doc['paths']['/users']['post'];
        self::assertSame(['$ref' => '#/components/schemas/CreateUserDto'], $create['requestBody']['content']['application/json']['schema']);
        self::assertSame(['$ref' => '#/components/schemas/CreateUserDto'], $create['responses']['201']['content']['application/json']['schema'], 'return type inferred');
    }

    public function testExclusions(): void
    {
        self::assertArrayNotHasKey('/users/secret', self::$doc['paths']);
        self::assertArrayNotHasKey('/internal', self::$doc['paths']);
    }

    public function testDtoSchemaGeneration(): void
    {
        $schema = self::$doc['components']['schemas']['CreateUserDto'];
        self::assertSame('A user to create.', $schema['description']);
        $p = $schema['properties'];
        self::assertSame(['type' => 'string', 'format' => 'email', 'description' => 'Login email', 'example' => 'a@b.c'], $p['email']);
        self::assertSame(['type' => 'string', 'maxLength' => 50], $p['name']);
        self::assertSame(['type' => 'integer', 'minimum' => 18], $p['age']);
        self::assertSame(['type' => 'string'], $p['nickname']);
        self::assertSame(['type' => 'boolean', 'nullable' => true, 'default' => false], $p['newsletter']);
        self::assertSame(['type' => 'string', 'enum' => ['active', 'blocked'], 'default' => 'active'], $p['status']);
        self::assertSame(['type' => 'string', 'enum' => ['active', 'blocked']], $p['previousStatus']);
        self::assertSame(['type' => 'string', 'format' => 'date-time'], $p['birthday']);
        self::assertSame(['$ref' => '#/components/schemas/AddressDto'], $p['address']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AddressDto'], 'default' => []], $p['otherAddresses']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string'], 'default' => []], $p['tags']);
        self::assertSame(['type' => 'string', 'format' => 'uri'], $p['website']);
        self::assertArrayNotHasKey('internal', $p);
        self::assertSame(['email', 'name', 'age', 'previousStatus', 'birthday', 'address'], $schema['required']);

        $address = self::$doc['components']['schemas']['AddressDto'];
        self::assertSame(['street' => ['type' => 'string'], 'zip' => ['type' => 'string', 'nullable' => true]], $address['properties']);
        self::assertSame(['street'], $address['required']);
    }

    public function testSetupServesUiAndJson(): void
    {
        $app = NestoFactory::create(SwaggerAppModule::class);
        $document = SwaggerModule::createDocument($app, (new DocumentBuilder)->setTitle('Docs </script>')->build());
        SwaggerModule::setup('api/docs', $app, $document, ['customSiteTitle' => 'My Docs', 'swaggerOptions' => ['docExpansion' => 'none']]);

        $json = $app->handle(Request::create('GET', '/api/docs-json'));
        self::assertSame(200, $json->getStatusCode());
        self::assertStringContainsString('application/json', $json->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $json->getBody(), true);
        self::assertSame('Docs </script>', $decoded['info']['title']);
        self::assertArrayHasKey('/users', $decoded['paths']);

        $html = $app->handle(Request::create('GET', '/api/docs'));
        self::assertSame(200, $html->getStatusCode());
        self::assertStringContainsString('text/html', $html->getHeaderLine('Content-Type'));
        $body = (string) $html->getBody();
        self::assertStringContainsString('<title>My Docs</title>', $body);
        self::assertStringContainsString('cdnjs.cloudflare.com/ajax/libs/swagger-ui/', $body);
        self::assertStringContainsString('SwaggerUIBundle(', $body);
        self::assertStringContainsString('"docExpansion":"none"', $body);
        self::assertStringNotContainsString('</script>"', $body, 'inlined document is HTML-safe');
        self::assertStringContainsString('</script>', $body);

        // other routes still work; unknown paths still 404
        self::assertSame(404, $app->handle(Request::create('GET', '/api/docs/nope'))->getStatusCode());
        self::assertSame(401, $app->handle(Request::create('GET', '/profile'))->getStatusCode());
    }

    public function testDocumentBuilderDefaults(): void
    {
        $doc = (new DocumentBuilder)->build();
        self::assertSame(['openapi' => '3.0.3', 'info' => ['title' => '', 'version' => '1.0.0'], 'paths' => [], 'components' => []], $doc);
        $withSec = (new DocumentBuilder)->addBearerAuth()->addSecurityRequirements('bearer')->build();
        self::assertSame([['bearer' => []]], $withSec['security']);
    }

    public function testIgnoreGlobalPrefixMovesThePrefixIntoServersSoTryItOutCallsTheRightUrl(): void
    {
        $app = NestoFactory::create(SwaggerAppModule::class);
        $app->setGlobalPrefix('api', ['exclude' => ['profile']]);

        // without the option: real paths, no servers needed
        $plain = SwaggerModule::createDocument($app, (new DocumentBuilder)->build());
        self::assertArrayHasKey('/api/users', $plain['paths']);
        self::assertArrayHasKey('/profile', $plain['paths']);
        self::assertArrayNotHasKey('servers', $plain);

        // ignoreGlobalPrefix: true (Nest's boolean) uses the app's prefix; it moves into servers, excluded routes keep the root
        $doc = SwaggerModule::createDocument($app, (new DocumentBuilder)->build(), ['ignoreGlobalPrefix' => true]);
        self::assertArrayHasKey('/users', $doc['paths']);
        self::assertArrayNotHasKey('/api/users', $doc['paths']);
        self::assertSame([['url' => '/api']], $doc['servers']);
        self::assertArrayNotHasKey('servers', $doc['paths']['/users']);
        self::assertSame([['url' => '/']], $doc['paths']['/profile']['servers']);
        self::assertArrayHasKey('get', $doc['paths']['/profile']);

        // configured servers get the prefix appended (once, even when it was added by hand as a workaround)
        $config = (new DocumentBuilder)->addServer('https://api.test', 'prod')->addServer('/api', 'local')->build();
        $doc = SwaggerModule::createDocument($app, $config, ['ignoreGlobalPrefix' => 'api']);
        self::assertSame([['url' => 'https://api.test/api', 'description' => 'prod'], ['url' => '/api', 'description' => 'local']], $doc['servers']);
        self::assertSame([['url' => 'https://api.test', 'description' => 'prod'], ['url' => '/', 'description' => 'local']], $doc['paths']['/profile']['servers']);
    }

    public function testOAuth2RequiresFlowsAndCookieAuthDefaultsToThePhpSessionCookie(): void
    {
        $flows = ['password' => ['tokenUrl' => '/auth/token', 'scopes' => ['read' => 'Read access']]];
        $doc = (new DocumentBuilder)->addOAuth2(['flows' => $flows])->build();
        self::assertSame(['type' => 'oauth2', 'flows' => $flows], $doc['components']['securitySchemes']['oauth2']);

        $doc = (new DocumentBuilder)->addCookieAuth()->build();
        self::assertSame(['type' => 'apiKey', 'in' => 'cookie', 'name' => 'PHPSESSID'], $doc['components']['securitySchemes']['cookie']);
        self::assertSame('token', (new DocumentBuilder)->addCookieAuth('token')->build()['components']['securitySchemes']['cookie']['name']);

        // `flows: {}` is not a valid OAuth2 scheme: fail early instead of generating a broken document
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('flows');
        (new DocumentBuilder)->addOAuth2();
    }
}
