<?php

namespace App\Entity;

use App\Repository\UserIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserIdentityRepository::class)]
#[ORM\Table(name: 'user_identity')]
#[ORM\UniqueConstraint(name: 'uniq_user_identity_subject', columns: ['issuer', 'subject'])]
#[ORM\UniqueConstraint(name: 'uniq_user_identity_user', columns: ['user_uuid', 'issuer'])]
class UserIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 500)]
    private string $issuer;

    #[ORM\Column(length: 500)]
    private string $subject;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastLoginAt;

    public function __construct(User $user, string $issuer, string $subject)
    {
        $this->user = $user;
        $this->issuer = $issuer;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastLoginAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): \DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function touch(): self
    {
        $this->lastLoginAt = new \DateTimeImmutable();

        return $this;
    }
}
