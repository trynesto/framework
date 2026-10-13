<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger;

use Nesto\Core\NestoFactory;
use Nesto\Swagger\DocumentBuilder;
use Nesto\Swagger\SwaggerModule;
use Nesto\Testing\Test;
use Nesto\Tests\Swagger\Fixtures\Blog\BlogController;
use Nesto\Tests\Swagger\Fixtures\Blog\BlogSwaggerModule;
use Nesto\Validation\ValidationPipe;
use PHPUnit\Framework\TestCase;

/** Schemas follow what the serializer sends and what the validator accepts (blog-shaped fixtures). */
final class SchemaInferenceTest extends TestCase
{
    private static array $doc;

    public static function setUpBeforeClass(): void
    {
        $doc = SwaggerModule::createDocument(NestoFactory::create(BlogSwaggerModule::class), (new DocumentBuilder)->setTitle('t')->build());
        // compare what is actually served: {} vs [] matters for "any" schemas
        self::$doc = json_decode((string) json_encode($doc), true);
        self::$doc['__json'] = json_encode($doc);
    }

    private static function schema(string $name): array
    {
        return self::$doc['components']['schemas'][$name];
    }

    public function testTransformerExcludeHidesPropertiesAndExposeShapesThem(): void
    {
        $author = self::schema('Author');
        self::assertArrayNotHasKey('password', $author['properties'], '#[Exclude] is never serialized');
        self::assertArrayNotHasKey('displayName', $author['properties']);
        self::assertSame(['type' => 'string'], $author['properties']['display_name'], '#[Expose(name:)] is the key the serializer uses');
        self::assertSame(['type' => 'string', 'writeOnly' => true], $author['properties']['pin'], 'Exclude(toPlainOnly): accepted, never sent');
        self::assertSame(['type' => 'integer', 'default' => 0, 'readOnly' => true], $author['properties']['postCount'], 'Exclude(toClassOnly): sent, never accepted');
        self::assertArrayHasKey('email', $author['properties']);
        self::assertSame(['id', 'display_name', 'pin'], $author['required'], 'grouped properties are only in some responses');
        self::assertArrayNotHasKey('image', self::schema('BlogPost')['properties']);
    }

    public function testExposedMethodsAreDocumentedAndAcceptApiProperty(): void
    {
        $p = self::schema('BlogPost')['properties'];
        self::assertSame(['type' => 'string', 'readOnly' => true, 'description' => 'Public URL of the image', 'format' => 'uri'], $p['imageUrl']);
        self::assertSame(['type' => 'string', 'nullable' => true, 'readOnly' => true], $p['excerpt'], 'getExcerpt() is serialized as "excerpt"');
        self::assertSame(['type' => 'integer', 'readOnly' => true], $p['wordCount']);
        self::assertArrayNotHasKey('hidden', $p, '#[ApiHideProperty] works on methods');
        self::assertArrayNotHasKey('notExposed', $p);
        $required = self::schema('BlogPost')['required'];
        self::assertContains('imageUrl', $required);
        self::assertNotContains('excerpt', $required);
        self::assertNotContains('wordCount', $required, 'only in the "detail" group');
    }

    public function testDocblockTypesResolveUseImportsAndGenericSyntaxes(): void
    {
        $p = self::schema('BlogPost')['properties'];
        $tag = ['$ref' => '#/components/schemas/Tag'];
        self::assertSame(['$ref' => '#/components/schemas/Author'], $p['author']);
        self::assertSame(['type' => 'array', 'items' => $tag], $p['tags'], 'list<Tag> with a group use import');
        self::assertSame(['type' => 'object', 'additionalProperties' => $tag], $p['tagsByName']);
        self::assertSame(['type' => 'array', 'items' => $tag, 'default' => []], $p['related']);
        self::assertSame(['$ref' => '#/components/schemas/Author', 'nullable' => true], $p['editor'], '?Writer through an alias');
        self::assertSame($tag + ['nullable' => true], $p['mainTag']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'count' => ['type' => 'integer']], 'required' => ['name']], 'default' => []], $p['stats']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Comment'], 'default' => []], $p['comments'], 'same-namespace class');
        self::assertSame(['type' => 'array', 'items' => $tag, 'default' => []], $p['promoted'], 'promoted property docblock');
        self::assertStringNotContainsString('"type":"Tag"', self::$doc['__json']);

        $update = self::schema('UpdatePostInput')['properties'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string'], 'nullable' => true], $update['tags'], 'list<string>|null');
    }

    public function testEachValidatorsDescribeTheItems(): void
    {
        $tags = self::$doc['paths']['/blog/posts']['post']['requestBody']['content']['multipart/form-data']['schema']['properties']['tags[]'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 50], 'default' => [], 'maxItems' => 5], $tags);
        $ids = self::$doc['paths']['/blog/posts']['post']['requestBody']['content']['multipart/form-data']['schema']['properties']['ids[]'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer'], 'default' => []], $ids);
    }

    public function testIsOptionalWinsWhateverTheAttributeOrder(): void
    {
        $update = self::schema('UpdatePostInput');
        self::assertArrayNotHasKey('required', $update, 'IsOptional before or after IsNotEmpty');
    }

    public function testPaginationIsDocumentedAsDataAndMeta(): void
    {
        $pagination = self::schema('Pagination');
        self::assertSame(['data', 'meta'], array_keys($pagination['properties']));
        self::assertSame(['type' => 'array', 'items' => []], $pagination['properties']['data']);
        self::assertStringContainsString('"items":{}', self::$doc['__json'], 'any item: {} not []');
        self::assertSame(['total', 'page', 'perPage', 'lastPage'], array_keys($pagination['properties']['meta']['properties']));
        self::assertSame(['data', 'meta'], $pagination['required']);

        $list = self::$doc['paths']['/blog/posts']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['$ref' => '#/components/schemas/Pagination'], $list);

        // Nest's allOf + getSchemaPath() pattern; class names in $ref stand in for getSchemaPath()
        $typed = self::$doc['paths']['/blog/posts/typed']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['allOf' => [
            ['$ref' => '#/components/schemas/Pagination'],
            ['properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BlogPost']]]],
        ]], $typed);
        self::assertArrayHasKey('BlogPost', self::$doc['components']['schemas']);
    }

    public function testAResponseWithoutTypeUsesTheReturnTypeAndItsDocblock(): void
    {
        $stats = self::$doc['paths']['/blog/stats']['get']['responses']['200'];
        self::assertSame('Tags with their number of posts', $stats['description']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'posts' => ['type' => 'integer']], 'required' => ['name', 'posts']]], $stats['content']['application/json']['schema']);
    }

    public function testMultipartArraysAreSentAsRepeatedBracketFields(): void
    {
        $content = self::$doc['paths']['/blog/posts']['post']['requestBody']['content']['multipart/form-data'];
        self::assertSame(['title', 'image', 'tags[]', 'ids[]'], array_keys($content['schema']['properties']));
        self::assertSame(['title', 'image'], $content['schema']['required']);
        self::assertSame(['tags[]' => ['style' => 'form', 'explode' => true], 'ids[]' => ['style' => 'form', 'explode' => true]], $content['encoding']);

        // what Swagger UI then sends (tags[]=php, tags[]=orm) is what the DTO receives
        $module = Test::createTestingModule(['controllers' => [BlogController::class]])->compile();
        $module->app()->useGlobalPipes(new ValidationPipe(whitelist: true, transform: true));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $module->request()->post('/blog/posts')->field('title', 'Hello')->attach('image', $png, 'a.png')
            ->field('tags[]', 'php')->field('tags[]', 'orm')
            ->expect(201)->assertJson(['title' => 'Hello', 'tags' => ['php', 'orm'], 'ids' => []]);
    }
}
