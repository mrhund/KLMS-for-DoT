<?php

namespace App\Entity;

use App\Repository\ClanRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClanRepository::class)]
#[ORM\Table(name: 'clan')]
class Clan
{
    #[ORM\Column(type: Types::INTEGER, nullable: true, unique: true)]
    private ?int $id = null;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Assert\Uuid(strict: false)]
    #[Groups(['read'])]
    private ?UuidInterface $uuid = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 64, minMessage: 'The name must be at least {{ limit }} characters long', maxMessage: 'The name cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(min: 6, max: 128, minMessage: 'The password must be at least {{ limit }} characters long', maxMessage: 'The password cannot be longer than {{ limit }} characters')]
    #[Groups(['write'])]
    private ?string $joinPassword = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['read'])]
    private ?DateTimeInterface $modifiedAt = null;

    #[ORM\OneToMany(mappedBy: 'clan', targetEntity: ClanMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    #[ORM\Column(length: 10, unique: true)]
    #[Assert\Length(min: 1, max: 10, minMessage: 'The clantag must be at least {{ limit }} characters long', maxMessage: 'The clantag cannot be longer than {{ limit }} characters')]
    #[Assert\NotBlank]
    #[Groups(['read', 'write'])]
    private ?string $clantag = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: false)]
    #[Groups(['read', 'write'])]
    private ?string $website = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4096, maxMessage: 'The clan description cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $description = null;

    public function __construct()
    {
        $this->uuid = Uuid::uuid4();
        $this->createdAt = new \DateTime();
        $this->modifiedAt = $this->createdAt;
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): Clan
    {
        $this->id = $id;

        return $this;
    }

    public function getUuid(): ?UuidInterface
    {
        return $this->uuid;
    }

    public function setUuid(?UuidInterface $uuid): Clan
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): Clan
    {
        $this->name = $name;

        return $this;
    }

    public function getJoinPassword(): ?string
    {
        return $this->joinPassword;
    }

    public function setJoinPassword(?string $joinPassword): Clan
    {
        $this->joinPassword = $joinPassword;

        return $this;
    }

    public function setPlainJoinPassword(string $joinPassword): Clan
    {
        $this->joinPassword = password_hash($joinPassword, PASSWORD_BCRYPT);

        return $this;
    }

    public function verifyJoinPassword(string $joinPassword): bool
    {
        return $this->joinPassword !== null && password_verify($joinPassword, $this->joinPassword);
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): Clan
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getModifiedAt(): ?DateTimeInterface
    {
        return $this->modifiedAt;
    }

    public function setModifiedAt(?DateTimeInterface $modifiedAt): Clan
    {
        $this->modifiedAt = $modifiedAt;

        return $this;
    }

    public function getUsers(): Collection
    {
        return new ArrayCollection(array_map(
            static fn (ClanMembership $membership): User => $membership->getUser(),
            $this->memberships->toArray(),
        ));
    }

    public function setUsers(Collection|array $users): Clan
    {
        $desired = [];
        foreach ($users as $user) {
            if ($user instanceof User) {
                $desired[$user->getUuid()->toString()] = $user;
            }
        }

        foreach ($this->memberships as $membership) {
            $uuid = $membership->getUser()->getUuid()->toString();
            if (!isset($desired[$uuid])) {
                $this->removeMembership($membership);
            }
        }

        foreach ($desired as $user) {
            $this->addUser($user);
        }

        return $this;
    }

    public function addUser(User $user): Clan
    {
        $membership = $this->findMembership($user);
        if ($membership === null) {
            $membership = new ClanMembership($this, $user);
            $this->memberships->add($membership);
            $user->addClanMembership($membership);
        }

        return $this;
    }

    public function removeUser(User $user): Clan
    {
        if ($membership = $this->findMembership($user)) {
            $this->removeMembership($membership);
        }

        return $this;
    }

    public function getAdmins(): Collection
    {
        return new ArrayCollection(array_map(
            static fn (ClanMembership $membership): User => $membership->getUser(),
            $this->memberships->filter(
                static fn (ClanMembership $membership): bool => $membership->isAdmin(),
            )->toArray(),
        ));
    }

    public function setAdmins(Collection|array $admins): Clan
    {
        $desired = [];
        foreach ($admins as $admin) {
            if ($admin instanceof User) {
                $desired[$admin->getUuid()->toString()] = $admin;
            }
        }

        foreach ($this->memberships as $membership) {
            $membership->setAdmin(isset($desired[$membership->getUser()->getUuid()->toString()]));
            if ($membership->isAdmin()) {
                unset($desired[$membership->getUser()->getUuid()->toString()]);
            }
        }

        foreach ($desired as $admin) {
            $this->addAdmin($admin);
        }

        return $this;
    }

    public function addAdmin(User $user): Clan
    {
        $this->addUser($user);
        $this->findMembership($user)?->setAdmin(true);

        return $this;
    }

    public function removeAdmin(User $user): Clan
    {
        $this->findMembership($user)?->setAdmin(false);

        return $this;
    }

    public function isAdmin(User $user): bool
    {
        return $this->findMembership($user)?->isAdmin() ?? false;
    }

    public function getClantag(): ?string
    {
        return $this->clantag;
    }

    public function setClantag(?string $clantag): Clan
    {
        $this->clantag = $clantag;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): Clan
    {
        $this->website = $website;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): Clan
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, ClanMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    private function findMembership(User $user): ?ClanMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getUuid()->equals($user->getUuid())) {
                return $membership;
            }
        }

        return null;
    }

    private function removeMembership(ClanMembership $membership): void
    {
        $this->memberships->removeElement($membership);
        $membership->getUser()->removeClanMembership($membership);
    }
}
