<?php

declare(strict_types=1);

namespace Nesto\Swagger;

use Nesto\Core\NestoApplication;

/**
 * Nest's SwaggerModule.
 *
 *   $config = (new DocumentBuilder)->setTitle('Cats')->setVersion('1.0')->addBearerAuth()->build();
 *   $document = SwaggerModule::createDocument($app, $config);               // OpenAPI 3.0.3 array
 *   SwaggerModule::setup('api', $app, $document, ['customSiteTitle' => 'Cats docs']);
 *   // GET /api → Swagger UI, GET /api-json → the document
 *
 * createDocument() options: include (module classes), extraModels (DTO classes), operationIdFactory
 * (callable(string $controller, string $method): string), ignoreGlobalPrefix (true, or the prefix string: paths are
 * documented without it and it moves into `servers`, so "Try it out" still calls the right URL).
 * setup() options: see SwaggerMiddleware (customSiteTitle, swaggerOptions, customCss, customJs, jsonDocumentUrl, cdn...).
 *
 * The UI routes are served by a global middleware (app->use()), so they work without a controller class.
 */
final class SwaggerModule
{
    /**
     * @param array<string, mixed> $config  from DocumentBuilder::build()
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function createDocument(NestoApplication $app, array $config, array $options = []): array
    {
        $generator = new DocumentGenerator($options + ['globalPrefix' => $app->globalPrefix()], $app->reflector());

        return $generator->generate($app->router()->routes(), $config);
    }

    /**
     * Serve Swagger UI at `/{path}` and the raw document at `/{path}-json`.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $options
     */
    public static function setup(string $path, NestoApplication $app, array $document, array $options = []): SwaggerMiddleware
    {
        $middleware = new SwaggerMiddleware($path, $document, $options);
        $app->use($middleware);

        return $middleware;
    }
}
