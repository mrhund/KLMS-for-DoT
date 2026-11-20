<?php

namespace App\Tests\Functional\Mcp;

use App\Entity\News;
use App\Entity\UserAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\RouterInterface;

class ContentNewsToolsTest extends McpTestCase
{
    public function testContentCreateReadAndPartialUpdate(): void
    {
        $this->initialize(4);
        $created = $this->call('content_create', [
            'title' => 'Eine Seite',
            'content' => '<p>Hallo</p><script>test()</script>',
            'description' => 'Beschreibung',
            'alias' => 'eine-seite',
        ]);
        self::assertSame('Beschreibung', $created['description']);
        self::assertStringContainsString('<script>', $created['content']);
        self::assertSame(Uuid::fromInteger('4')->toString(), $created['authorId']);
        self::assertSame($created['authorId'], $created['modifierId']);
        self::assertStringContainsString('/admin/content/edit/', $created['adminEditUrl']);
        self::assertStringContainsString('/content/', $created['publicUrl']);
        self::assertSame('content_slug', static::getContainer()->get(RouterInterface::class)->match('/content/eine-seite')['_route']);

        $updated = $this->call('content_update', ['id' => $created['id'], 'fields' => [
            'title' => 'Geändert',
            'description' => null,
        ]]);
        self::assertNull($updated['description']);
        self::assertSame($created['content'], $updated['content']);
        self::assertSame('eine-seite', $updated['alias']);
        self::assertSame('Geändert', $this->call('content_get', ['alias' => 'eine-seite'])['title']);
        self::assertSame($updated, $this->call('content_get', ['id' => $created['id']]));
        $cleared = $this->call('content_update', ['id' => $created['id'], 'fields' => ['alias' => null, 'content' => null]]);
        self::assertNull($cleared['alias']);
        self::assertSame('', $cleared['content']);
        $list = $this->call('content_list');
        self::assertCount(1, $list);
        self::assertArrayNotHasKey('content', $list[0]);
    }

    public function testContentValidationErrors(): void
    {
        $this->initialize(4);
        $created = $this->call('content_create', ['title' => 'Seite', 'content' => '', 'alias' => 'belegt']);
        $this->assertToolError('content_create', ['title' => 'Neu', 'content' => '', 'alias' => 'Ungueltig'], 'alias:');
        $this->assertToolError('content_create', ['title' => 'Neu', 'content' => '', 'alias' => 'belegt'], 'alias:');
        $this->assertToolError('content_update', ['id' => $created['id'], 'fields' => ['title' => null]], 'title:');
        $this->assertToolError('content_get', ['id' => $created['id'], 'alias' => 'belegt'], 'Genau eine');
        $withoutAlias = $this->call('content_create', ['title' => 'Ohne Alias', 'content' => '']);
        self::assertNull($withoutAlias['alias']);
        self::assertNull($withoutAlias['description']);
    }

    public function testContentTracksDifferentAuthorAndModifier(): void
    {
        $this->initialize(4);
        $created = $this->call('content_create', ['title' => 'Seite', 'content' => 'Text']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->find(UserAdmin::class, Uuid::fromInteger('3'));
        $admin->addPermission('ADMIN_CONTENT');
        $em->flush();
        $em->clear();

        $this->token = $this->createToken(3);
        $updated = $this->call('content_update', ['id' => $created['id'], 'fields' => ['title' => 'Neu']]);
        self::assertSame(Uuid::fromInteger('4')->toString(), $updated['authorId']);
        self::assertSame(Uuid::fromInteger('3')->toString(), $updated['modifierId']);
    }

    public function testNewsDatesSanitizationAndImagePreservation(): void
    {
        $this->initialize(3);
        $created = $this->call('news_create', [
            'title' => 'News',
            'content' => '<p>Hallo</p><script>test()</script>',
            'publishedFrom' => '2025-01-02T12:30:45+02:00',
            'publishedTo' => '2025-01-03T12:30:00+02:00',
        ]);
        self::assertStringNotContainsString('<script>', $created['content']);
        $expected = (new \DateTimeImmutable('2025-01-02T12:30:00+02:00'))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        self::assertSame($expected->format(\DateTimeInterface::ATOM), $created['publishedFrom']);
        self::assertSame(Uuid::fromInteger('3')->toString(), $created['authorId']);
        self::assertSame($created['authorId'], $created['modifierId']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $news = $em->find(News::class, $created['id']);
        $news->getImage()->setName('example.jpg');
        $em->flush();
        $em->clear();

        $updated = $this->call('news_update', ['id' => $created['id'], 'fields' => ['publishedTo' => null]]);
        self::assertNull($updated['publishedTo']);
        self::assertSame($created['publishedFrom'], $updated['publishedFrom']);
        self::assertTrue($updated['hasImage']);
        self::assertStringEndsWith('/images/news/example.jpg', $updated['imageUrl']);
        self::assertSame($updated, $this->call('news_get', ['id' => $created['id']]));
        $list = $this->call('news_list', ['limit' => 1, 'offset' => 0]);
        self::assertCount(1, $list);
        self::assertArrayNotHasKey('content', $list[0]);
        self::assertSame([], $this->call('news_list', ['limit' => 1, 'offset' => 1]));
    }

    public function testNewsRejectsInvalidDates(): void
    {
        $this->initialize(3);
        $this->assertToolError('news_create', ['title' => 'News', 'content' => '', 'publishedFrom' => 'tomorrow'], 'publishedFrom:');
        $this->assertToolError('news_create', ['title' => 'News', 'content' => '', 'publishedFrom' => '2025-02-30T12:00:00Z'], 'publishedFrom:');
        $this->assertToolError('news_create', ['title' => 'News', 'content' => '', 'publishedFrom' => '2025-01-02T12:00:00+99:99'], 'publishedFrom:');
        $this->assertToolError('news_create', [
            'title' => 'News', 'content' => '',
            'publishedFrom' => '2025-01-02T12:00:00Z',
            'publishedTo' => '2025-01-01T12:00:00Z',
        ], 'publishedTo:');
    }

    public function testRoleIsolation(): void
    {
        $this->initialize(3);
        self::assertSame([], $this->call('news_list'));
        $this->assertToolError('content_list', [], 'Keine Berechtigung');
    }

    public function testContentRoleCannotAccessNews(): void
    {
        $this->initialize(4);
        self::assertSame([], $this->call('content_list'));
        $this->assertToolError('news_list', [], 'Keine Berechtigung');
    }

    public function testDiscoveredSchemasDescribePartialUpdates(): void
    {
        $this->initialize(4);
        $this->client->request('POST', '/mcp', [], [], $this->headers(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $tools = array_column($response['result']['tools'], null, 'name');
        foreach (['content_list', 'content_get', 'content_create', 'content_update', 'news_list', 'news_get', 'news_create', 'news_update'] as $name) {
            self::assertArrayHasKey($name, $tools);
        }
        foreach (['content_update' => 'description', 'news_update' => 'publishedTo'] as $name => $nullableField) {
            $fields = $tools[$name]['inputSchema']['properties']['fields'];
            self::assertSame('object', $fields['type']);
            self::assertSame(['string', 'null'], $fields['properties'][$nullableField]['type']);
            self::assertFalse($fields['additionalProperties']);
            self::assertArrayNotHasKey('required', $fields);
        }

        $this->client->request('POST', '/mcp', [], [], $this->headers(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'news_update', 'arguments' => [
                'id' => 1,
                'fields' => ['imageFile' => null],
            ]],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $invalid = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(-32602, $invalid['error']['code']);
    }

}
