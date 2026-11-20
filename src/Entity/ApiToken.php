<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Index(columns: ['user_uuid'], name: 'api_token_user_uuid_idx')]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private ?string $selector = null;

    #[ORM\Column(length: 64)]
    private ?string $hash = null;

    #[ORM\Column(type: 'uuid')]
    private ?UuidInterface $userUuid = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeInterface $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeInterface $revokedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelector(): ?string
    {
        return $this->selector;
    }

    public function setSelector(string $selector): self
    {
        $this->selector = $selector;

        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    public function getUserUuid(): ?UuidInterface
    {
        return $this->userUuid;
    }

    public function setUserUuid(UuidInterface $userUuid): self
    {
        $this->userUuid = $userUuid;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getExpiresAt(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getLastUsedAt(): ?DateTimeInterface
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeInterface $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getRevokedAt(): ?DateTimeInterface
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?DateTimeInterface $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        return null === $this->expiresAt || $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }
}
