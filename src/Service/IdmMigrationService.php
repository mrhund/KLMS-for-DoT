<?php

namespace App\Service;

use App\Entity\Clan;
use App\Entity\Setting;
use App\Entity\User;
use App\Repository\ClanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class IdmMigrationService
{
    private const COGNITO_HEADERS = [
        'profile',
        'address',
        'birthdate',
        'gender',
        'preferred_username',
        'updated_at',
        'website',
        'picture',
        'email_verified',
        'phone_number_verified',
        'phone_number',
        'zoneinfo',
        'locale',
        'email',
        'given_name',
        'family_name',
        'middle_name',
        'name',
        'nickname',
        'cognito:mfa_enabled',
        'cognito:username',
        'password_hash',
    ];
    private const LEGACY_AUTH_SETTING_KEYS = [
        'email.register.subject',
        'email.register.text',
        'email.reset.subject',
        'email.reset.text',
        'email.notify.subject',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly ClanRepository $clanRepository,
    ) {
    }

    /**
     * @return array{users: int, clans: int, memberships: int}
     */
    public function import(
        string $usersCsv,
        string $clansCsv,
        string $membershipsCsv,
        string $cognitoCsv,
        bool $dryRun = false,
    ): array {
        if ($this->userRepository->count([]) !== 0 || $this->clanRepository->count([]) !== 0) {
            throw new \RuntimeException('The local User and Clan tables must be empty before the IDM migration.');
        }

        $users = $this->readCsv($usersCsv);
        $clans = $this->readCsv($clansCsv);
        $memberships = $this->readCsv($membershipsCsv);
        $this->em->beginTransaction();

        try {
            [$userById, $userByUuid] = $this->importUsers($users);
            [$clanById, $clanByUuid] = $this->importClans($clans);
            $membershipCount = $this->importMemberships(
                $memberships,
                $userById,
                $userByUuid,
                $clanById,
                $clanByUuid,
            );
            $this->removeLegacyAuthSettings();
            $this->em->flush();
            $this->writeCognitoCsv($cognitoCsv, $users);

            if ($dryRun) {
                $this->em->rollback();
            } else {
                $this->em->commit();
            }

            return [
                'users' => count($users),
                'clans' => count($clans),
                'memberships' => $membershipCount,
            ];
        } catch (\Throwable $exception) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            if (is_file($cognitoCsv)) {
                unlink($cognitoCsv);
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot read CSV file "%s".', $path));
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header === false) {
                throw new \RuntimeException(sprintf('CSV file "%s" is empty.', $path));
            }
            $header = array_map(
                static fn (string $value, int $index): string => strtolower(trim($index === 0
                    ? preg_replace('/^\xEF\xBB\xBF/', '', $value)
                    : $value)),
                $header,
                array_keys($header),
            );

            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                ++$line;
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new \RuntimeException(sprintf(
                        'CSV line %d in "%s" has %d columns; expected %d.',
                        $line,
                        $path,
                        count($values),
                        count($header),
                    ));
                }
                $rows[] = array_combine($header, array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $values,
                ));
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array{array<string, User>, array<string, User>}
     */
    private function importUsers(array $rows): array
    {
        $byId = [];
        $byUuid = [];
        $emails = [];

        foreach ($rows as $index => $row) {
            $uuidString = $this->required($row, $index, 'uuid');
            if (!Uuid::isValid($uuidString) || isset($byUuid[$uuidString])) {
                throw new \RuntimeException(sprintf('Invalid or duplicate User UUID on row %d.', $index + 2));
            }

            $email = strtolower($this->required($row, $index, 'email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException(sprintf('Invalid email on User row %d.', $index + 2));
            }
            if (isset($emails[$email])) {
                throw new \RuntimeException(sprintf('Duplicate email on User row %d.', $index + 2));
            }
            if (!$this->boolean($this->column($row, 'email_confirmed', 'emailconfirmed'), false)) {
                throw new \RuntimeException(sprintf(
                    'User row %d is not email-confirmed and cannot be imported into the Cognito password-reset flow.',
                    $index + 2,
                ));
            }

            $user = (new User())
                ->setUuid(Uuid::fromString($uuidString))
                ->setEmail($email)
                ->setEmailConfirmed(true)
                ->setNickname($this->required($row, $index, 'nickname'))
                ->setFirstname($row['firstname'] ?? '')
                ->setSurname($row['surname'] ?? '')
                ->setBirthdate($this->date($row['birthdate'] ?? null, false))
                ->setGender($row['gender'] ?: null)
                ->setInfoMails($this->boolean($row['info_mails'] ?? null, false))
                ->setPersonalDataConfirmed($this->boolean($row['personal_data_confirmed'] ?? null, false))
                ->setPersonalDataLocked($this->boolean($row['personal_data_locked'] ?? null, false))
                ->setIsSuperadmin($this->boolean($row['is_superadmin'] ?? null, false))
                ->setPostcode($row['postcode'] ?: null)
                ->setCity($row['city'] ?: null)
                ->setStreet($row['street'] ?: null)
                ->setCountry($row['country'] ?: null)
                ->setPhone($row['phone'] ?: null)
                ->setWebsite($row['website'] ?: null)
                ->setSteamAccount($row['steam_account'] ?? $row['steamaccount'] ?? null)
                ->setHardware($row['hardware'] ?: null)
                ->setStatements($row['statements'] ?: null)
                ->setRegisteredAt($this->date($row['registered_at'] ?? null, true))
                ->setModifiedAt($this->date($row['modified_at'] ?? null, true));

            $legacyId = $row['id'] ?? '';
            if ($legacyId !== '') {
                if (!ctype_digit($legacyId) || isset($byId[$legacyId])) {
                    throw new \RuntimeException(sprintf('Invalid or duplicate User id on row %d.', $index + 2));
                }
                $user->setId((int) $legacyId);
                $byId[$legacyId] = $user;
            }

            $this->em->persist($user);
            $byUuid[$uuidString] = $user;
            $emails[$email] = true;
        }

        return [$byId, $byUuid];
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array{array<string, Clan>, array<string, Clan>}
     */
    private function importClans(array $rows): array
    {
        $byId = [];
        $byUuid = [];

        foreach ($rows as $index => $row) {
            $uuidString = $this->required($row, $index, 'uuid');
            if (!Uuid::isValid($uuidString) || isset($byUuid[$uuidString])) {
                throw new \RuntimeException(sprintf('Invalid or duplicate Clan UUID on row %d.', $index + 2));
            }

            $joinPassword = $this->column($row, 'join_password', 'joinpassword', 'password') ?: null;
            $clan = (new Clan())
                ->setUuid(Uuid::fromString($uuidString))
                ->setName($this->required($row, $index, 'name'))
                ->setClantag($this->required($row, $index, 'clantag'))
                ->setWebsite($row['website'] ?: null)
                ->setDescription($row['description'] ?: null)
                ->setCreatedAt($this->date($row['created_at'] ?? $row['createdat'] ?? null, true))
                ->setModifiedAt($this->date($row['modified_at'] ?? $row['modifiedat'] ?? null, true));
            if ($joinPassword !== null) {
                if (password_get_info($joinPassword)['algoName'] === 'unknown') {
                    $clan->setPlainJoinPassword($joinPassword);
                } else {
                    $clan->setJoinPassword($joinPassword);
                }
            }

            $legacyId = $row['id'] ?? '';
            if ($legacyId !== '') {
                if (!ctype_digit($legacyId) || isset($byId[$legacyId])) {
                    throw new \RuntimeException(sprintf('Invalid or duplicate Clan id on row %d.', $index + 2));
                }
                $clan->setId((int) $legacyId);
                $byId[$legacyId] = $clan;
            }

            $this->em->persist($clan);
            $byUuid[$uuidString] = $clan;
        }

        return [$byId, $byUuid];
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @param array<string, User> $userById
     * @param array<string, User> $userByUuid
     * @param array<string, Clan> $clanById
     * @param array<string, Clan> $clanByUuid
     */
    private function importMemberships(
        array $rows,
        array $userById,
        array $userByUuid,
        array $clanById,
        array $clanByUuid,
    ): int {
        $seen = [];
        foreach ($rows as $index => $row) {
            $userValue = $this->column($row, 'gamer_uuid', 'user_uuid', 'gamer_id', 'user_id', 'gamer', 'user');
            $clanValue = $this->column($row, 'clan_uuid', 'clan_id', 'clan');
            $user = $userByUuid[$userValue] ?? $userById[$userValue] ?? null;
            $clan = $clanByUuid[$clanValue] ?? $clanById[$clanValue] ?? null;
            if (!$user instanceof User || !$clan instanceof Clan) {
                throw new \RuntimeException(sprintf('Unknown User or Clan on Gamer_Clan row %d.', $index + 2));
            }

            $key = $clan->getUuid()->toString().':'.$user->getUuid()->toString();
            $role = strtolower($this->column($row, 'role', 'status'));
            $admin = $this->boolean(
                $this->column($row, 'is_admin', 'admin', 'is_clan_admin'),
                in_array($role, ['admin', 'owner'], true),
            );
            if (isset($seen[$key]) && !$admin) {
                continue;
            }
            $admin ? $clan->addAdmin($user) : $clan->addUser($user);
            $seen[$key] = true;
        }

        return count($seen);
    }

    /**
     * @param array<int, array<string, string>> $users
     */
    private function writeCognitoCsv(string $path, array $users): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(5));
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot write Cognito CSV "%s".', $path));
        }

        try {
            fputcsv($handle, self::COGNITO_HEADERS, ',', '"', '');
            foreach ($users as $index => $row) {
                $uuid = $this->required($row, $index, 'uuid');
                $email = strtolower($this->required($row, $index, 'email'));
                if (strpbrk($uuid, ",\r\n\\") !== false || strpbrk($email, ",\r\n") !== false) {
                    throw new \RuntimeException('Cognito username or email contains unsupported CSV delimiters.');
                }
                $values = array_fill_keys(self::COGNITO_HEADERS, '');
                $values['email'] = $email;
                $values['email_verified'] = 'TRUE';
                $values['cognito:username'] = $uuid;
                $values['password_hash'] = '';
                fputcsv($handle, array_values($values), ',', '"', '');
            }
        } finally {
            fclose($handle);
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Cannot finalize Cognito CSV "%s".', $path));
        }
    }

    private function required(array $row, int $index, string $column): string
    {
        $value = $this->column($row, $column);
        if ($value === '') {
            throw new \RuntimeException(sprintf('Missing "%s" on row %d.', $column, $index + 2));
        }

        return $value;
    }

    private function removeLegacyAuthSettings(): void
    {
        $this->em->createQueryBuilder()
            ->delete(Setting::class, 's')
            ->where('s.key IN (:keys)')
            ->setParameter('keys', self::LEGACY_AUTH_SETTING_KEYS)
            ->getQuery()
            ->execute();
    }

    private function column(array $row, string ...$names): string
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $row)) {
                return $row[$name];
            }
        }

        return '';
    }

    private function boolean(?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
    }

    private function date(?string $value, bool $time): ?\DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $date = \DateTime::createFromFormat($time ? '!Y-m-d H:i:s' : '!Y-m-d', trim($value));
        if ($date === false) {
            throw new \RuntimeException(sprintf('Invalid date "%s".', $value));
        }

        return $date;
    }
}
