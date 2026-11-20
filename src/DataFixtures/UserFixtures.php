<?php

namespace App\DataFixtures;

use App\Entity\Clan;
use App\Entity\User;
use App\Entity\UserAdmin;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;
use Ramsey\Uuid\Uuid as StandardUuid;

class UserFixtures extends Fixture
{
    public const USERS = 20;
    private const SURNAMES = [
        'Null', 'Eins', 'Zwei', 'Drei', 'Vier', 'Fünf', 'Sechs', 'Sieben',
        'Acht', 'Neun', 'Zehn', 'Elf', 'Zwölf', 'Dreizehn', 'Vierzehn',
        'Fünfzehn', 'Sechzehn', 'Siebzehn', 'Achtzehn', 'Neunzehn',
    ];

    public function load(ObjectManager $manager): void
    {
        $users = [];
        for ($i = 0; $i < self::USERS; ++$i) {
            $user = (new User())
                ->setId(1000 + $i)
                ->setUuid(Uuid::fromInteger($i))
                ->setEmail(sprintf('user%d@localhost.local', $i))
                ->setEmailConfirmed($i !== 11 && $i !== 16)
                ->setInfoMails(in_array($i, [0, 5, 10, 15], true))
                ->setNickname(sprintf('User %d', $i))
                ->setFirstname('User')
                ->setSurname(self::SURNAMES[$i])
                ->setBirthdate(new \DateTime('1990-01-01'))
                ->setGender($i % 2 === 0 ? 'm' : 'f')
                ->setPersonalDataConfirmed($i % 3 !== 0)
                ->setPersonalDataLocked($i % 5 === 0)
                ->setIsSuperadmin(false)
                ->setRegisteredAt(new \DateTime('2023-04-10 19:39:31'))
                ->setModifiedAt(new \DateTime('2023-04-10 19:39:31'));
            $manager->persist($user);
            $users[$i] = $user;
        }

        $adminUser = (new User())
            ->setId(9000)
            ->setUuid(StandardUuid::fromString('52e25be3-cb48-4178-97a8-cb8376b0e120'))
            ->setEmail('admin@localhost.local')
            ->setEmailConfirmed(true)
            ->setInfoMails(false)
            ->setNickname('Admin')
            ->setFirstname('Ali')
            ->setSurname('Admin')
            ->setBirthdate(new \DateTime('1990-01-01'))
            ->setGender('m')
            ->setPersonalDataConfirmed(true)
            ->setPersonalDataLocked(false)
            ->setIsSuperadmin(true)
            ->setRegisteredAt(new \DateTime('2023-04-10 19:39:31'))
            ->setModifiedAt(new \DateTime('2023-04-10 19:39:31'));
        $manager->persist($adminUser);

        $clan1 = (new Clan())
            ->setId(123)
            ->setUuid(Uuid::fromInteger(9))
            ->setName('Clan 1')
            ->setClantag('CL1')
            ->setWebsite('http://localhost')
            ->setDescription('Fixture clan 1')
            ->setPlainJoinPassword('password')
            ->addAdmin($users[1])
            ->addUser($users[2]);
        $clan2 = (new Clan())
            ->setId(2161)
            ->setUuid(Uuid::fromInteger(0x3EA))
            ->setName('Clan 2')
            ->setClantag('CL2')
            ->setWebsite('http://localhost2')
            ->setDescription('Fixture clan 2')
            ->setPlainJoinPassword('password')
            ->addUser($users[2])
            ->addAdmin($users[3])
            ->addUser($users[4]);
        $clan3 = (new Clan())
            ->setId(2162)
            ->setUuid(Uuid::fromInteger(0x3EF))
            ->setName('Clan 3')
            ->setClantag('CL3')
            ->setWebsite('http://localhost3')
            ->setDescription('Fixture clan 3')
            ->setPlainJoinPassword('password');

        $manager->persist($clan1);
        $manager->persist($clan2);
        $manager->persist($clan3);

        $admin = (new UserAdmin(Uuid::fromInteger(3)))
            ->setPermissions(['ADMIN_NEWS']);
        $manager->persist($admin);

        $admin = (new UserAdmin(Uuid::fromInteger(7)))
            ->setPermissions(['ADMIN_TOURNEY']);
        $manager->persist($admin);

        $manager->flush();
    }
}
