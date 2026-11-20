<?php

namespace App\Twig;

use App\Entity\Clan;
use App\Entity\Seat;
use App\Entity\User;
use App\Repository\ClanRepository;
use App\Repository\UserRepository;
use App\Service\GroupService;
use App\Service\SeatmapService;
use App\Service\TicketService;
use App\Service\UserService;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigTest;

class UserExtension extends AbstractExtension
{
    private readonly UserRepository $userRepo;
    private readonly ClanRepository $clanRepo;
    private readonly UserService $userService;
    private readonly SeatmapService $seatmapService;
    private readonly TicketService $ticketService;

    public function __construct(UserRepository $userRepo, ClanRepository $clanRepo, UserService $userService, SeatmapService $seatmapService, TicketService $ticketService)
    {
        $this->userRepo = $userRepo;
        $this->clanRepo = $clanRepo;
        $this->userService = $userService;
        $this->seatmapService = $seatmapService;
        $this->ticketService = $ticketService;
    }

    /**
     * {@inheritdoc}
     */
    public function getTests(): array
    {
        return [
            new TwigTest('valid_user', $this->validUser(...)),
            new TwigTest('registered_user', $this->userIsRegistered(...)),
            new TwigTest('seated_user', $this->userIsSeated(...)),
            new TwigTest('in_clan', $this->userIsInClan(...)),
            new TwigTest('in_clans', $this->userIsInClans(...)),
            new TwigTest('age_below', $this->userAgeBelow(...)),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('user', $this->getUser(...)),
            new TwigFilter('clan', $this->getClan(...)),
            new TwigFilter('username', $this->getUserName(...), ['is_safe' => ['html']]),
            new TwigFilter('user_image', $this->getUserImage(...)),
            new TwigFilter('group_name', $this->getGroupName(...)),
            new TwigFilter('seat', $this->getSeat(...)),
        ];
    }

    public function getUser($userId): ?User
    {
        if (empty($userId) || !Uuid::isValid($userId)) {
            return null;
        }

        return $this->userRepo->findOneByUuid($userId);
    }

    public function getClan($clanId): ?Clan
    {
        if (empty($clanId) || !Uuid::isValid($clanId)) {
            return null;
        }

        return $this->clanRepo->findOneByUuid($clanId);
    }

    public function getUserName($userId): string
    {
        $user = $this->getUser($userId);

        if (empty($user)) {
            return '';
        }

        $nickname = $this->sanitizeTemplateDelimiters(trim((string) ($user->getNickname() ?? '')));
        $firstname = $this->sanitizeTemplateDelimiters(trim((string) ($user->getFirstname() ?? '')));
        $surname = $this->sanitizeTemplateDelimiters(trim((string) ($user->getSurname() ?? '')));

        if ($nickname !== '') {
            return $nickname;
        }

        return trim($firstname.' '.$surname);
    }

    private function sanitizeTemplateDelimiters(string $value): string
    {
        return str_replace(['{{', '}}', '{%', '%}', '{#', '#}'], '', $value);
    }

    public function getGroupName($groupUuid): string
    {
        if (empty($groupUuid) || !Uuid::isValid($groupUuid)) {
            return '';
        }

        return GroupService::getName(Uuid::fromString($groupUuid));
    }

    public function getUserImage(?User $user): string
    {
        if (empty($user)) {
            return '';
        }
        return $this->userService->getUserImage($user) ?? '';
    }

    public function validUser($userId): bool
    {
        return !empty($this->getUser($userId));
    }

    public function userIsRegistered(User|UuidInterface $user): bool
    {
        return $this->ticketService->isUserRegistered($user);
    }

    public function userIsSeated(User|UuidInterface $user): bool
    {
        return $this->seatmapService->getUserSeatCount($user) > 0;
    }

    public function userIsInClan(User|UuidInterface $user, Clan|UuidInterface $clan): bool
    {
        return $this->userService->isUserInClan($user, $clan);
    }

    /**
     * @param User|UuidInterface $user
     * @param Clan[]|UuidInterface[] $clans
     * @return bool
     */
    public function userIsInClans(User|UuidInterface $user, array $clans): bool
    {
        return $this->userService->isUserInClans($user, $clans);
    }

    public function userAgeBelow(User|UuidInterface $user, int $age): bool
    {
        return !($this->userService->userAgeAbove($user, $age) ?? true);
    }

    public function getSeat(User|UuidInterface $user): string
    {
        $seats = $this->seatmapService->getUserSeats($user);
        $names = array_map(fn (Seat $seat) => $seat->generateSeatName(), $seats);

        return implode(',', $names);
    }
}
