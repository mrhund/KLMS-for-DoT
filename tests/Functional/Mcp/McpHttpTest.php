<?php

namespace App\Tests\Functional\Mcp;

use App\Entity\UserAdmin;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

class McpHttpTest extends McpTestCase
{
    public static function siteHosts(): iterable
    {
        yield 'hostname' => ['www.dot-lan.at', 'www.dot-lan.at'];
        yield 'hostname with port' => ['www.dot-lan.at:443', 'www.dot-lan.at'];
        yield 'IPv6 with port' => ['[2001:db8::1]:8080', '[2001:db8::1]'];
    }

    #[DataProvider('siteHosts')]
    public function testAllowedHostsIncludeSiteAndLocalhost(string $siteHost, string $siteHostname): void
    {
        $originalSiteHost = $_ENV['SITE_BASE_HOST'] ?? null;
        $_ENV['SITE_BASE_HOST'] = $siteHost;

        try {
            $this->token = $this->createToken();
            foreach (['localhost', '127.0.0.1', '[::1]', $siteHost, $siteHostname, 'other.example'] as $host) {
                $this->requestInitialize(['HTTP_HOST' => $host]);
                self::assertResponseStatusCodeSame('other.example' === $host ? 403 : 200);
            }
        } finally {
            if (null === $originalSiteHost) {
                unset($_ENV['SITE_BASE_HOST']);
            } else {
                $_ENV['SITE_BASE_HOST'] = $originalSiteHost;
            }
        }
    }

    public function testMissingInvalidExpiredAndRevokedTokensAreRejected(): void
    {
        $this->requestInitialize();
        self::assertResponseStatusCodeSame(401);

        $this->token = 'invalid-token';
        $this->requestInitialize();
        self::assertResponseStatusCodeSame(401);

        $valid = $this->createToken();
        [$selector, $secret] = explode('.', $valid, 2);
        $this->token = $selector.'.'.('a' === $secret[0] ? 'b' : 'a').substr($secret, 1);
        $this->requestInitialize();
        self::assertResponseStatusCodeSame(401);

        $repository = static::getContainer()->get(ApiTokenRepository::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->token = $this->createToken();
        $expired = $repository->findBySelector(substr(explode('.', $this->token, 2)[0], 5));
        $expired->setExpiresAt(new \DateTimeImmutable('-1 second'));
        $em->flush();
        $this->requestInitialize();
        self::assertResponseStatusCodeSame(401);

        $this->token = $this->createToken();
        $revoked = $repository->findBySelector(substr(explode('.', $this->token, 2)[0], 5));
        static::getContainer()->get(ApiTokenService::class)->revokeToken($revoked->getId());
        $this->requestInitialize();
        self::assertResponseStatusCodeSame(401);
    }

    public function testUserWithoutAdminRightsIsForbidden(): void
    {
        $this->token = $this->createToken(2);
        $this->requestInitialize();
        self::assertResponseStatusCodeSame(403);
    }

    public function testRevokedAdminRightsTakeEffectOnNextRequest(): void
    {
        $this->initialize(3);
        self::assertSame([], $this->call('news_list'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->find(UserAdmin::class, Uuid::fromInteger('3'));
        $admin->setPermissions([]);
        $em->flush();
        $em->clear();

        $this->requestPing();
        self::assertResponseStatusCodeSame(403);
    }

    public function testRemovingNewsPermissionRestrictsExistingSession(): void
    {
        $this->initialize(3);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->find(UserAdmin::class, Uuid::fromInteger('3'));
        $admin->setPermissions(['ADMIN_CONTENT']);
        $em->flush();
        $em->clear();

        self::assertSame([], $this->call('content_list'));
        $this->assertToolError('news_list', [], 'Keine Berechtigung');
    }

    public function testRevokedTokenCannotUseAnExistingSession(): void
    {
        $this->initialize(3);
        $selector = substr(explode('.', $this->token, 2)[0], 5);
        $token = static::getContainer()->get(ApiTokenRepository::class)->findBySelector($selector);
        static::getContainer()->get(ApiTokenService::class)->revokeToken($token->getId());

        $this->requestPing();
        self::assertResponseStatusCodeSame(401);
    }

    private function requestPing(): void
    {
        $this->client->request('POST', '/mcp', [], [], $this->headers(), json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'ping', 'arguments' => new \stdClass()],
        ], JSON_THROW_ON_ERROR));
    }

    public function testInitializeAndCallPingOverHttp(): void
    {
        $this->initialize(3);
        $result = $this->requestTool('ping');
        self::assertFalse($result['isError'] ?? false);
        self::assertSame('pong', $result['content'][0]['text']);
        self::assertSame(0, $this->mock->getInvalidCalls());
    }
}
