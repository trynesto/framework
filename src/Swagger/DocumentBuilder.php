<?php

declare(strict_types=1);

namespace Nesto\Swagger;

/**
 * Fluent builder for the static part of an OpenAPI 3.0.3 document (info, servers, tags, security schemes).
 *
 *   $config = (new DocumentBuilder)
 *       ->setTitle('Cats API')->setDescription('The cats API')->setVersion('1.0')
 *       ->addTag('cats')->addServer('https://api.example.com', 'production')
 *       ->addBearerAuth()                         // scheme name "bearer"  → #[ApiBearerAuth]
 *       ->addApiKey(['name' => 'X-Api-Key', 'in' => 'header'], 'api-key')
 *       ->setContact('Team', 'https://example.com', 'team@example.com')
 *       ->setLicense('MIT', 'https://opensource.org/licenses/MIT')
 *       ->build();
 *   $document = SwaggerModule::createDocument($app, $config);
 */
final class DocumentBuilder
{
    /** @var array<string, mixed> */
    private array $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => '', 'description' => '', 'version' => '1.0.0'],
        'servers' => [],
        'tags' => [],
        'paths' => [],
        'components' => [],
        'security' => [],
    ];

    public function setTitle(string $title): self
    {
        $this->document['info']['title'] = $title;

        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->document['info']['description'] = $description;

        return $this;
    }

    public function setVersion(string $version): self
    {
        $this->document['info']['version'] = $version;

        return $this;
    }

    public function setTermsOfService(string $url): self
    {
        $this->document['info']['termsOfService'] = $url;

        return $this;
    }

    public function setContact(string $name, ?string $url = null, ?string $email = null): self
    {
        $this->document['info']['contact'] = array_filter(['name' => $name, 'url' => $url, 'email' => $email], fn ($v) => $v !== null);

        return $this;
    }

    public function setLicense(string $name, ?string $url = null): self
    {
        $this->document['info']['license'] = array_filter(['name' => $name, 'url' => $url], fn ($v) => $v !== null);

        return $this;
    }

    public function setExternalDoc(string $description, string $url): self
    {
        $this->document['externalDocs'] = ['description' => $description, 'url' => $url];

        return $this;
    }

    /** @param array<string, array{default: string, enum?: list<string>, description?: string}> $variables */
    public function addServer(string $url, ?string $description = null, array $variables = []): self
    {
        $this->document['servers'][] = array_filter(
            ['url' => $url, 'description' => $description, 'variables' => $variables ?: null],
            fn ($v) => $v !== null
        );

        return $this;
    }

    /** @param array{description: string, url: string}|null $externalDocs */
    public function addTag(string $name, string $description = '', ?array $externalDocs = null): self
    {
        $tag = ['name' => $name];
        if ($description !== '') {
            $tag['description'] = $description;
        }
        if ($externalDocs !== null) {
            $tag['externalDocs'] = $externalDocs;
        }
        $this->document['tags'][] = $tag;

        return $this;
    }

    /**
     * Register any security scheme object under components.securitySchemes.
     *
     * @param array<string, mixed> $scheme e.g. ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']
     */
    public function addSecurity(string $name, array $scheme): self
    {
        $this->document['components']['securitySchemes'][$name] = $scheme;

        return $this;
    }

    /** Apply a scheme to every operation (operations can still add their own). @param list<string> $scopes */
    public function addSecurityRequirements(string $name, array $scopes = []): self
    {
        $this->document['security'][] = [$name => $scopes];

        return $this;
    }

    /** @param array<string, mixed> $options merged into the scheme (bearerFormat, description...) */
    public function addBearerAuth(array $options = [], string $name = 'bearer'): self
    {
        return $this->addSecurity($name, array_merge(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $options));
    }

    /** @param array<string, mixed> $options name (header/query/cookie name), in ('header'|'query'|'cookie') */
    public function addApiKey(array $options = [], string $name = 'api_key'): self
    {
        return $this->addSecurity($name, array_merge(['type' => 'apiKey', 'in' => 'header', 'name' => $name], $options));
    }

    /** @param array<string, mixed> $options */
    public function addBasicAuth(array $options = [], string $name = 'basic'): self
    {
        return $this->addSecurity($name, array_merge(['type' => 'http', 'scheme' => 'basic'], $options));
    }

    /**
     * $cookieName defaults to PHPSESSID, PHP's session cookie (Nest's default "connect.sid" is Express' cookie).
     *
     * @param array<string, mixed> $options
     */
    public function addCookieAuth(string $cookieName = 'PHPSESSID', array $options = [], string $name = 'cookie'): self
    {
        return $this->addSecurity($name, array_merge(['type' => 'apiKey', 'in' => 'cookie', 'name' => $cookieName], $options));
    }

    /**
     * An OAuth2 scheme needs at least one flow (an empty `flows` is invalid OpenAPI), so `flows` is required:
     * ['flows' => ['password' => ['tokenUrl' => '/auth/token', 'scopes' => []]]]
     * (implicit: authorizationUrl; authorizationCode: authorizationUrl + tokenUrl; clientCredentials: tokenUrl).
     *
     * @param array<string, mixed> $options
     */
    public function addOAuth2(array $options = [], string $name = 'oauth2'): self
    {
        if (! isset($options['flows']) || ! is_array($options['flows']) || $options['flows'] === []) {
            throw new \InvalidArgumentException("addOAuth2() needs at least one flow, e.g. ['flows' => ['password' => ['tokenUrl' => '/auth/token', 'scopes' => []]]].");
        }

        return $this->addSecurity($name, array_merge(['type' => 'oauth2'], $options));
    }

    /** Arbitrary extension / override on the root document (e.g. 'x-logo'). */
    public function setExtension(string $key, mixed $value): self
    {
        $this->document[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $doc = $this->document;
        foreach (['servers', 'tags', 'security'] as $key) {
            if ($doc[$key] === []) {
                unset($doc[$key]);
            }
        }
        if ($doc['info']['description'] === '') {
            unset($doc['info']['description']);
        }

        return $doc;
    }
}
