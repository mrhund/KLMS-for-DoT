<?php

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'community_user')]
#[ORM\Index(name: 'idx_community_user_nickname', columns: ['nickname'])]
#[ORM\Index(name: 'idx_community_user_email', columns: ['email'])]
class User
{
    #[ORM\Column(type: Types::INTEGER, nullable: true, unique: true)]
    private ?int $id = null;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Assert\Uuid(strict: false)]
    #[Groups(['read'])]
    private ?UuidInterface $uuid = null;

    #[ORM\Column(length: 300, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 300, maxMessage: 'The email cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $email = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['read', 'write'])]
    private ?bool $emailConfirmed = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['read', 'write'])]
    private ?bool $infoMails = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\Length(min: 1, max: 64, minMessage: 'The nickname must be at least {{ limit }} characters long', maxMessage: 'The nickname cannot be longer than {{ limit }} characters')]
    #[Assert\NotBlank]
    #[Groups(['read', 'write'])]
    private ?string $nickname = null;

    #[ORM\Column(length: 250)]
    #[Groups(['read', 'write'])]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 250, maxMessage: 'The firstname cannot be longer than {{ limit }} characters')]
    private ?string $firstname = null;

    #[ORM\Column(length: 250)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 250, maxMessage: 'The surname cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $surname = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Assert\NotBlank]
    #[Groups(['read', 'write'])]
    private ?DateTimeInterface $birthdate = null;

    #[ORM\Column(length: 1, nullable: true)]
    #[Assert\Choice(choices: ['m', 'f', 'x'])]
    #[Groups(['read', 'write'])]
    private ?string $gender = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['read', 'write'])]
    private ?bool $personalDataConfirmed = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['read', 'write'])]
    private ?bool $personalDataLocked = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['read'])]
    private ?bool $isSuperadmin = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(min: 1, max: 10, minMessage: 'The postcode must be at least {{ limit }} characters long', maxMessage: 'The postcode cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $postcode = null;

    #[ORM\Column(length: 250, nullable: true)]
    #[Assert\Length(max: 250, maxMessage: 'The city cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $city = null;

    #[ORM\Column(length: 250, nullable: true)]
    #[Assert\Length(max: 250, maxMessage: 'The street cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $street = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country]
    #[Groups(['read', 'write'])]
    private ?string $country = null;

    #[ORM\Column(length: 250, nullable: true)]
    #[Groups(['read', 'write'])]
    #[Assert\Length(max: 250, maxMessage: 'The phone number cannot be longer than {{ limit }} characters')]
    #[Assert\Regex('/^[+]?\d([ \/()]?\d)*$/', message: 'Invalid phone number format.')]
    private ?string $phone = null;

    #[ORM\Column(length: 250, nullable: true)]
    #[Assert\Url(requireTld: false)]
    #[Assert\Length(max: 250, maxMessage: 'The website url cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $website = null;

    #[ORM\Column(length: 250, nullable: true)]
    #[Assert\Length(max: 250, maxMessage: 'The discord tag cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $steamAccount = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['read'])]
    private ?DateTimeInterface $registeredAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['read'])]
    private ?DateTimeInterface $modifiedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000, maxMessage: 'The hardware description cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $hardware = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000, maxMessage: 'The statement cannot be longer than {{ limit }} characters')]
    #[Groups(['read', 'write'])]
    private ?string $statements = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ClanMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $clanMemberships;

    public function __construct()
    {
        $this->emailConfirmed = false;
        $this->infoMails = false;
        $this->personalDataConfirmed = false;
        $this->personalDataLocked = false;
        $this->isSuperadmin = false;
        $this->clanMemberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): User
    {
        $this->id = $id;

        return $this;
    }

    public function getUuid(): ?UuidInterface
    {
        return $this->uuid;
    }

    public function setUuid(?UuidInterface $uuid): User
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): self
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    public function getBirthdate(): ?DateTimeInterface
    {
        return $this->birthdate;
    }

    public function setBirthdate(?DateTimeInterface $birthdate): self
    {
        $this->birthdate = $birthdate;

        return $this;
    }

    public function getPersonalDataConfirmed(): ?bool
    {
        return $this->personalDataConfirmed;
    }

    public function setPersonalDataConfirmed(?bool $personalDataConfirmed): User
    {
        $this->personalDataConfirmed = $personalDataConfirmed;

        return $this;
    }

    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    public function setPostcode(?string $postcode): self
    {
        $this->postcode = $postcode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): self
    {
        $this->street = $street;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): self
    {
        $this->gender = $gender;

        return $this;
    }

    public function getEmailConfirmed(): ?bool
    {
        return $this->emailConfirmed;
    }

    public function setEmailConfirmed(?bool $emailConfirmed): self
    {
        $this->emailConfirmed = $emailConfirmed;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $this->nickname = $nickname;

        return $this;
    }

    public function getPersonalDataLocked(): ?bool
    {
        return $this->personalDataLocked;
    }

    public function setPersonalDataLocked(?bool $personalDataLocked): User
    {
        $this->personalDataLocked = $personalDataLocked;

        return $this;
    }

    public function getIsSuperadmin(): ?bool
    {
        return $this->isSuperadmin;
    }

    public function setIsSuperadmin(?bool $isSuperadmin): self
    {
        $this->isSuperadmin = $isSuperadmin;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }

    public function getSteamAccount(): ?string
    {
        return $this->steamAccount;
    }

    public function setSteamAccount(?string $steamAccount): self
    {
        $this->steamAccount = $steamAccount;

        return $this;
    }

    public function getRegisteredAt(): ?DateTimeInterface
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(?DateTimeInterface $registeredAt): self
    {
        $this->registeredAt = $registeredAt;

        return $this;
    }

    public function getModifiedAt(): ?DateTimeInterface
    {
        return $this->modifiedAt;
    }

    public function setModifiedAt(?DateTimeInterface $modifiedAt): self
    {
        $this->modifiedAt = $modifiedAt;

        return $this;
    }

    public function getHardware(): ?string
    {
        return $this->hardware;
    }

    public function setHardware(?string $hardware): self
    {
        $this->hardware = $hardware;

        return $this;
    }

    public function getInfoMails(): ?bool
    {
        return $this->infoMails;
    }

    public function setInfoMails(?bool $infoMails): self
    {
        $this->infoMails = $infoMails;

        return $this;
    }

    public function getStatements(): ?string
    {
        return $this->statements;
    }

    public function setStatements(?string $statements): self
    {
        $this->statements = $statements;

        return $this;
    }

    public function getClans(): Collection
    {
        return new ArrayCollection(array_map(
            static fn (ClanMembership $membership): Clan => $membership->getClan(),
            $this->clanMemberships->toArray(),
        ));
    }

    public function setClans(Collection|array $clans): self
    {
        $desired = [];
        foreach ($clans as $clan) {
            if ($clan instanceof Clan) {
                $desired[$clan->getUuid()->toString()] = $clan;
            }
        }

        foreach ($this->clanMemberships->toArray() as $membership) {
            $uuid = $membership->getClan()->getUuid()->toString();
            if (!isset($desired[$uuid])) {
                $membership->getClan()->removeUser($this);
            } else {
                unset($desired[$uuid]);
            }
        }

        foreach ($desired as $clan) {
            $clan->addUser($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, ClanMembership>
     */
    public function getClanMemberships(): Collection
    {
        return $this->clanMemberships;
    }

    public function addClanMembership(ClanMembership $membership): self
    {
        if (!$this->clanMemberships->contains($membership)) {
            $this->clanMemberships->add($membership);
        }

        return $this;
    }

    public function removeClanMembership(ClanMembership $membership): self
    {
        $this->clanMemberships->removeElement($membership);

        return $this;
    }
}
