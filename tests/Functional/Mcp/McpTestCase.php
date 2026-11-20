<?php

namespace App\Tests\Functional\Mcp;

use App\DataFixtures\UserFixtures;
use App\Entity\UserAdmin;
use App\Service\ApiTokenService;
use App\Tests\Functional\DatabaseWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

abstract class McpTestCase extends DatabaseWebTestCase
{
    protected ?string $token = null;
    protected ?string $sessionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseTool->loadFixtures([UserFixtures::class]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new UserAdmin(Uuid::fromInteger('4')))->setPermissions(['ADMIN_CONTENT']));
        $em->flush();
    }

    protected function createToken(int $userId = 3): string
    {
        return static::getContainer()->get(ApiTokenService::class)
            ->createToken(Uuid::fromInteger((string) $userId), 'MCP test');
    }

    protected function initialize(int $userId = 3): void
    {
        $this->token = $this->createToken($userId);
        $this->requestInitialize();
        self::assertResponseIsSuccessful();
        $this->sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotEmpty($this->sessionId);
        $this->client->request('POST', '/mcp', [], [], $this->headers(), json_encode([
            'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(202);
    }

    protected function requestInitialize(array $headers = []): void
    {
        $this->client->request('POST', '/mcp', [], [], $headers + $this->headers(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'KLMS test client', 'version' => '1.0'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function headers(): array
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        if (null !== $this->token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }
        if (null !== $this->sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->sessionId;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = '2025-03-26';
        }

        return $headers;
    }

    protected function requestTool(string $name, array $arguments = []): array
    {
        $this->client->request('POST', '/mcp', [], [], $this->headers(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $response, json_encode($response));

        return $response['result'];
    }

    protected function call(string $name, array $arguments = []): array
    {
        $result = $this->requestTool($name, $arguments);
        self::assertFalse($result['isError'] ?? false, json_encode($result));

        return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    protected function assertToolError(string $name, array $arguments, string $message): void
    {
        $result = $this->requestTool($name, $arguments);
        self::assertTrue($result['isError'] ?? false, json_encode($result));
        self::assertStringContainsString($message, $result['content'][0]['text']);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
