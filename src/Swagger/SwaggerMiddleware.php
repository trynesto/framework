<?php

declare(strict_types=1);

namespace Nesto\Swagger;

use Nesto\Common\Contracts\Middleware;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Serves the OpenAPI JSON and the Swagger UI page. Registered by SwaggerModule::setup(), but usable directly:
 *
 *   $app->use(new SwaggerMiddleware('api', $document, ['customSiteTitle' => 'Cats API']));
 *   // GET /api        → Swagger UI (swagger-ui-dist from a CDN, document inlined)
 *   // GET /api-json   → the document (also /api/json)
 *
 * Options: customSiteTitle, customCss, customCssUrl, customJs (url or list), customfavIcon, swaggerOptions
 * (array merged into the SwaggerUIBundle config), jsonDocumentUrl, cdn ('cdnjs' | 'unpkg' | base URL), uiVersion.
 */
final class SwaggerMiddleware implements Middleware
{
    public const UI_VERSION = '5.17.14';

    private string $path;
    private string $jsonPath;

    /** @param array<string, mixed> $document @param array<string, mixed> $options */
    public function __construct(string $path, private readonly array $document, private readonly array $options = [])
    {
        $this->path = '/' . trim($path, '/');
        $this->jsonPath = isset($options['jsonDocumentUrl'])
            ? '/' . trim((string) $options['jsonDocumentUrl'], '/')
            : ($this->path === '/' ? '/json' : $this->path . '-json');
    }

    public function use(Request $request, \Closure $next): ResponseInterface
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }
        $path = $request->path();

        if ($path === $this->jsonPath || $path === rtrim($this->path, '/') . '/json') {
            return Response::json($this->document);
        }
        if ($path === $this->path) {
            return Response::html($this->html());
        }

        return $next($request);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function jsonPath(): string
    {
        return $this->jsonPath;
    }

    /** The Swagger UI page with the document inlined (no extra request needed). */
    public function html(): string
    {
        $title = htmlspecialchars((string) ($this->options['customSiteTitle'] ?? ($this->document['info']['title'] ?? 'API') . ' - Swagger UI'), ENT_QUOTES);
        $base = $this->cdnBase();
        $spec = json_encode($this->document, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $uiOptions = json_encode((object) ($this->options['swaggerOptions'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        $favicon = isset($this->options['customfavIcon']) ? '<link rel="icon" href="' . htmlspecialchars((string) $this->options['customfavIcon'], ENT_QUOTES) . '">' : '';
        $customCss = isset($this->options['customCss']) ? '<style>' . $this->options['customCss'] . '</style>' : '';
        $customCssUrl = isset($this->options['customCssUrl']) ? '<link rel="stylesheet" href="' . htmlspecialchars((string) $this->options['customCssUrl'], ENT_QUOTES) . '">' : '';
        $customJs = '';
        foreach ((array) ($this->options['customJs'] ?? []) as $js) {
            $customJs .= '<script src="' . htmlspecialchars((string) $js, ENT_QUOTES) . '"></script>';
        }
        $jsonUrl = htmlspecialchars($this->jsonPath, ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<link rel="stylesheet" href="$base/swagger-ui.min.css">
$favicon$customCssUrl$customCss
<style>html{box-sizing:border-box;overflow-y:scroll}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#fafafa}</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="$base/swagger-ui-bundle.min.js" crossorigin></script>
<script src="$base/swagger-ui-standalone-preset.min.js" crossorigin></script>
$customJs
<script>
window.onload = function () {
  var spec = $spec;
  var options = Object.assign({
    spec: spec,
    url: "$jsonUrl",
    dom_id: "#swagger-ui",
    deepLinking: true,
    persistAuthorization: true,
    presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
    plugins: [SwaggerUIBundle.plugins.DownloadUrl],
    layout: "StandaloneLayout"
  }, $uiOptions);
  window.ui = SwaggerUIBundle(options);
};
</script>
</body>
</html>
HTML;
    }

    private function cdnBase(): string
    {
        $version = (string) ($this->options['uiVersion'] ?? self::UI_VERSION);
        $cdn = (string) ($this->options['cdn'] ?? 'cdnjs');

        return match ($cdn) {
            'cdnjs' => "https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/$version",
            'unpkg' => "https://unpkg.com/swagger-ui-dist@$version",
            'jsdelivr' => "https://cdn.jsdelivr.net/npm/swagger-ui-dist@$version",
            default => rtrim($cdn, '/'),
        };
    }
}
