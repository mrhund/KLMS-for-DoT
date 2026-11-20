<?php

namespace App\Tests\Unit\Service;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class ApiTokenServiceTest extends TestCase
{
    private const TEST_SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ApiTokenRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private ApiTokenService $service;
    private ?ApiToken $persistedToken = null;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ApiTokenRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function (ApiToken $token): void {
                $this->persistedToken = $token;
            });
        $this->service = new ApiTokenService($this->repository, $this->entityManager);
    }

    public function testCreatedTokenIsStoredAsAHashAndCanBeValidated(): void
    {
        $this->entityManager->expects(self::exactly(2))->method('flush');
        $plainToken = $this->service->createToken(Uuid::uuid4(), 'test client');
        self::assertMatchesRegularExpression('/^klms_[a-f0-9]{32}\.[a-f0-9]{64}$/', $plainToken);
        self::assertNotNull($this->persistedToken);
        self::assertNotSame(explode('.', $plainToken, 2)[1], $this->persistedToken->getHash());
        self::assertSame(hash('sha256', explode('.', $plainToken, 2)[1]), $this->persistedToken->getHash());

        $this->repository
            ->expects(self::once())
            ->method('findBySelector')
            ->with($this->persistedToken->getSelector())
            ->willReturn($this->persistedToken);

        self::assertSame($this->persistedToken, $this->service->validateToken($plainToken));
        self::assertNotNull($this->persistedToken->getLastUsedAt());
    }

    public function testInvalidExpiredAndRevokedTokensAreRejected(): void
    {
        self::assertNull($this->service->validateToken('not-a-token'));

        $expiredToken = $this->newTokenWithExpiration(new DateTimeImmutable('-1 second'));
        $validToken = $this->newTokenWithExpiration(new DateTimeImmutable('+1 day'));
        $revokedToken = $this->newTokenWithExpiration(new DateTimeImmutable('+1 day'));
        $revokedToken->setRevokedAt(new DateTimeImmutable());
        $tokens = [
            $expiredToken->getSelector() => $expiredToken,
            $validToken->getSelector() => $validToken,
            $revokedToken->getSelector() => $revokedToken,
        ];
        $this->repository
            ->expects(self::exactly(3))
            ->method('findBySelector')
            ->willReturnCallback(fn (string $selector) => $tokens[$selector] ?? null);

        self::assertNull($this->service->validateToken($this->plainTokenFor($expiredToken)));
        self::assertNull($this->service->validateToken(sprintf('klms_%s.%s', $validToken->getSelector(), str_repeat('b', 64))));
        self::assertNull($this->service->validateToken($this->plainTokenFor($revokedToken)));
    }

    public function testLastUsedTimestampIsThrottledForOneMinute(): void
    {
        $token = $this->newTokenWithExpiration(new DateTimeImmutable('+1 day'));
        $token->setLastUsedAt(new DateTimeImmutable('-30 seconds'));
        $this->repository->method('findBySelector')->willReturn($token);
        $this->entityManager->expects(self::never())->method('flush');

        self::assertSame($token, $this->service->validateToken($this->plainTokenFor($token)));
    }

    private function newTokenWithExpiration(DateTimeImmutable $expiresAt): ApiToken
    {
        return (new ApiToken())
            ->setSelector(bin2hex(random_bytes(16)))
            ->setHash(hash('sha256', self::TEST_SECRET))
            ->setUserUuid(Uuid::uuid4())
            ->setName('test client')
            ->setExpiresAt($expiresAt);
    }

    private function plainTokenFor(ApiToken $token): string
    {
        return sprintf('klms_%s.%s', $token->getSelector(), self::TEST_SECRET);
    }
}
