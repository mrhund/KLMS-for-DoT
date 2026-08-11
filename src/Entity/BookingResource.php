<?php

namespace App\Entity;

use App\Repository\BookingResourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingResourceRepository::class)]
#[ORM\Table(name: 'booking_resource')]
class BookingResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $priority = 0;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Number of identical, interchangeable units of this resource (e.g. 5 showers).
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $unitCount = 1;

    /**
     * Length of a single bookable slot in minutes.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $slotDurationMinutes = 15;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $availableFrom;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $availableUntil;

    /**
     * Maximum number of active bookings a single user may hold for this resource, null = unlimited.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxBookingsPerUser = 1;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(mappedBy: 'resource', targetEntity: Booking::class, orphanRemoval: true)]
    private Collection $bookings;

    public function __construct()
    {
        $this->availableFrom = new \DateTimeImmutable();
        $this->availableUntil = new \DateTimeImmutable();
        $this->bookings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getUnitCount(): int
    {
        return $this->unitCount;
    }

    public function setUnitCount(int $unitCount): self
    {
        $this->unitCount = $unitCount;

        return $this;
    }

    public function getSlotDurationMinutes(): int
    {
        return $this->slotDurationMinutes;
    }

    public function setSlotDurationMinutes(int $slotDurationMinutes): self
    {
        $this->slotDurationMinutes = $slotDurationMinutes;

        return $this;
    }

    public function getAvailableFrom(): \DateTimeImmutable
    {
        return $this->availableFrom;
    }

    public function setAvailableFrom(\DateTimeImmutable $availableFrom): self
    {
        $this->availableFrom = $availableFrom;

        return $this;
    }

    public function getAvailableUntil(): \DateTimeImmutable
    {
        return $this->availableUntil;
    }

    public function setAvailableUntil(\DateTimeImmutable $availableUntil): self
    {
        $this->availableUntil = $availableUntil;

        return $this;
    }

    public function getMaxBookingsPerUser(): ?int
    {
        return $this->maxBookingsPerUser;
    }

    public function setMaxBookingsPerUser(?int $maxBookingsPerUser): self
    {
        $this->maxBookingsPerUser = $maxBookingsPerUser;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }
}
