<?php

namespace App\Entity;

use App\Repository\ClanMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClanMembershipRepository::class)]
#[ORM\Table(name: 'clan_membership')]
#[ORM\UniqueConstraint(name: 'uniq_clan_membership', columns: ['clan_uuid', 'user_uuid'])]
class ClanMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Clan::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'clan_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private Clan $clan;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'clanMemberships')]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $admin = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct(Clan $clan, User $user, bool $admin = false)
    {
        $this->clan = $clan;
        $this->user = $user;
        $this->admin = $admin;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClan(): Clan
    {
        return $this->clan;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): self
    {
        $this->admin = $admin;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
