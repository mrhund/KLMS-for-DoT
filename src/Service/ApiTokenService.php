<?php

namespace App\Service;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Ramsey\Uuid\UuidInterface;

class ApiTokenService
{
    private const SELECTOR_BYTES = 16;
    private const SECRET_BYTES = 32;
    private const LAST_USED_UPDATE_INTERVAL = 60;

    public function __construct(
        private readonly ApiTokenRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function createToken(UuidInterface $userUuid, string $name, int $expiresInDays = 90): string
    {
        $name = trim($name);
        if ('' === $name || strlen($name) > 255) {
            throw new InvalidArgumentException('Token name must contain between 1 and 255 characters.');
        }
        if ($expiresInDays < 1) {
            throw new InvalidArgumentException('Token expiration must be at least one day.');
        }

        $now = new DateTimeImmutable();
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $token = (new ApiToken())
            ->setSelector($selector)
            ->setHash(hash('sha256', $secret))
            ->setUserUuid($userUuid)
            ->setName($name)
            ->setCreatedAt($now)
            ->setExpiresAt($now->add(new DateInterval(sprintf('P%dD', $expiresInDays))));

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return sprintf('klms_%s.%s', $selector, $secret);
    }

    public function validateToken(string $plainToken): ?ApiToken
    {
        if (!preg_match('/^klms_([a-f0-9]{32})\.([a-f0-9]{64})$/D', $plainToken, $matches)) {
            return null;
        }

        $token = $this->repository->findBySelector($matches[1]);
        if (null === $token || $token->isRevoked() || $token->isExpired()) {
            return null;
        }
        if (!hash_equals((string) $token->getHash(), hash('sha256', $matches[2]))) {
            return null;
        }

        $now = new DateTimeImmutable();
        $lastUsedAt = $token->getLastUsedAt();
        if (null === $lastUsedAt || $lastUsedAt <= $now->sub(new DateInterval(sprintf('PT%dS', self::LAST_USED_UPDATE_INTERVAL)))) {
            $token->setLastUsedAt($now);
            $this->entityManager->flush();
        }

        return $token;
    }

    public function revokeToken(int $id): bool
    {
        $token = $this->repository->find($id);
        if (null === $token) {
            return false;
        }

        if (!$token->isRevoked()) {
            $token->setRevokedAt(new DateTimeImmutable());
            $this->entityManager->flush();
        }

        return true;
    }

    /**
     * @return ApiToken[]
     */
    public function listTokens(?UuidInterface $userUuid = null): array
    {
        return $this->repository->findForUser($userUuid);
    }
}
