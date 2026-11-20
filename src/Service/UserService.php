<?php

namespace App\Service;

use App\Entity\Clan;
use App\Entity\User;
use App\Repository\ClanRepository;
use App\Repository\UserImageRepository;
use App\Repository\UserRepository;
use DateInterval;
use DateTime;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

class UserService
{
    private readonly UserImageRepository $imageRepo;
    private readonly UploaderHelper $uploadHelper;

    public function __construct(
        UserImageRepository $imageRepo,
        UploaderHelper $uploadHelper,
        private readonly UserRepository $userRepo,
        private readonly ClanRepository $clanRepo,
    )
    {
        $this->imageRepo = $imageRepo;
        $this->uploadHelper = $uploadHelper;
    }

    public function getUserImage(User $user): ?string
    {
        $image = $this->imageRepo->findOneByUuid($user->getUuid());
        if (empty($image) || empty($image->getImage())) {
            return '';
        }

        return $this->uploadHelper->asset($image, 'imageFile');
    }

    public function user2Array(User $user, bool $includeSensitiveData = true): array
    {
        $data = [
            'uuid' => $user->getUuid(),
            'id' => $user->getId(),
            'nickname' => $user->getNickname(),
            'image' => $this->getUserImage($user),
            'clans' => array_map(fn ($clan) => [
                'uuid' => $clan->getUuid(),
                'name' => $clan->getName(),
                'clantag' => $clan->getClantag(),
            ], $user->getClans()->toArray()),
        ];

        if ($includeSensitiveData) {
            $data['email'] = $user->getEmail();
            $data['firstname'] = $user->getFirstname();
            $data['surname'] = $user->getSurname();
        }

        return $data;
    }

    public static function array2Uuid(array $a): ?UuidInterface
    {
        return array_key_exists('uuid', $a) && Uuid::isValid($a['uuid']) ? Uuid::fromString($a['uuid']) : null;
    }

    /**
     * Preloads multiple users into the Doctrine identity map.
     */
    public function preloadUsers(array $uuids): void
    {
        $this->getUsers($uuids);
    }

    /**
     * @param UuidInterface[] $uuids
     * @param bool $assoc If true, return is an associative array with Uuid => User
     * @return User[]
     */
    public function getUsers(array $uuids, bool $assoc = false): array
    {
        $users = $this->userRepo->findByUuids($uuids);
        if (!$assoc) {
            return $users;
        } else {
            $keys = array_map(function (User $user) {
                return $user->getUuid()->toString();
            }, $users);
            return array_combine($keys, $users);
        }
    }

    /**
     * @param UuidInterface[] $uuids
     * @param bool $assoc If true, return is an associative array with Uuid => Clan
     * @return Clan[]
     */
    public function getClans(array $uuids, bool $assoc = false): array
    {
        $clans = $this->clanRepo->findByUuids($uuids);
        if (!$assoc) {
            return $clans;
        } else {
            $keys = array_map(fn (Clan $clan) => $clan->getUuid()->toString(), $clans);
            return array_combine($keys, $clans);
        }
    }

    /**
     * @param UuidInterface[] $userUuids
     * @param bool $assoc If true, return is an associative array with Uuid => Clan
     * @return Clan[]
     */
    public function getClansByUsers(array $userUuids, bool $assoc = false): array
    {
        $users = $this->getUsers($userUuids);
        $clan_uuid = [];
        foreach ($users as $user) {
            foreach ($user->getClans() as $clan) {
                $clan_uuid[] = $clan->getUuid();
            }
        }
        return $this->getClans(array_unique($clan_uuid), $assoc);
    }

    public function isUserInClan(User|UuidInterface $user, Clan|UuidInterface $clan): bool
    {
        $user = $user instanceof User ? $user : $this->userRepo->findOneByUuid($user);
        $needle = $clan instanceof Clan ? $clan->getUuid() : $clan;
        foreach ($user->getClans() as $userClan) {
            if ($needle->equals($userClan->getUuid())) return true;
        }
        return false;
    }

    /**
     * @param User|UuidInterface $user
     * @param Clan[]|UuidInterface[] $clans
     * @return bool
     */
    public function isUserInClans(User|UuidInterface $user, array $clans): bool
    {
        $user = $user instanceof User ? $user : $this->userRepo->findOneByUuid($user);
        $userClans = $user->getClans();
        $clanUuids = array_map(fn ($clan) => $clan instanceof Clan ? $clan->getUuid() : $clan, $clans);
        foreach ($clanUuids as $clanUuid) {
            foreach ($userClans as $userClan) {
                if ($clanUuid->equals($userClan->getUuid())) return true;
            }
        }
        return false;
    }

    public function userAgeAbove(UuidInterface|User $user, int $age): ?bool
    {
        $user = $user instanceof User ? $user : $this->userRepo->findOneByUuid($user);
        if (empty($user) || empty($user->getBirthdate())) {
            return null;
        }
        $limit = DateInterval::createFromDateString($age.' years');
        $birthday = $user->getBirthdate()->add($limit);
        return (new DateTime()) >= $birthday;
    }
}
