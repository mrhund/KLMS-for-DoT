<?php

namespace App\Tests\Integration\Service;

use App\Repository\ClanRepository;
use App\Repository\UserRepository;
use App\Service\IdmMigrationService;
use App\Tests\Integration\DatabaseTestCase;
use App\Entity\Setting;
use App\Repository\SettingRepository;

final class IdmMigrationServiceIntegrationTest extends DatabaseTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseTool->loadFixtures([]);
        $this->directory = sys_get_temp_dir().'/klms-idm-migration-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testImportPreservesIdsAndCreatesPasswordlessCognitoCsv(): void
    {
        $this->writeMigrationFiles();
        $service = self::getContainer()->get(IdmMigrationService::class);

        $result = $service->import(
            $this->directory.'/users.csv',
            $this->directory.'/clans.csv',
            $this->directory.'/gamer_clan.csv',
            $this->directory.'/cognito.csv',
        );

        self::assertSame(['users' => 1, 'clans' => 1, 'memberships' => 1], $result);
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmailInsensitive('user@example.test');
        $clan = self::getContainer()->get(ClanRepository::class)->findOneByUuid('5a38690d-d97a-4999-a108-972381a7018f');
        self::assertSame('858eac40-d1d9-4122-a7f3-fb5076f5096e', $user?->getUuid()->toString());
        self::assertTrue($clan?->isAdmin($user));
        self::assertTrue($clan?->verifyJoinPassword('ignored'));
        $cognitoCsv = file_get_contents($this->directory.'/cognito.csv');
        self::assertStringStartsWith('profile,address,birthdate,gender,preferred_username,updated_at,website,picture,email_verified,', $cognitoCsv);
        self::assertStringContainsString('TRUE,,,,,user@example.test,,,,,,,858eac40-d1d9-4122-a7f3-fb5076f5096e,', $cognitoCsv);
        self::assertNull(self::getContainer()->get(SettingRepository::class)->findByKey('email.reset.subject'));
    }

    public function testDryRunRollsBackLocalImport(): void
    {
        $this->writeMigrationFiles();

        self::getContainer()->get(IdmMigrationService::class)->import(
            $this->directory.'/users.csv',
            $this->directory.'/clans.csv',
            $this->directory.'/gamer_clan.csv',
            $this->directory.'/cognito.csv',
            true,
        );

        self::assertSame(0, self::getContainer()->get(UserRepository::class)->count([]));
        self::assertSame(0, self::getContainer()->get(ClanRepository::class)->count([]));
    }

    private function writeMigrationFiles(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist((new Setting('email.reset.subject'))->setText('Legacy reset'));
        $em->flush();

        file_put_contents(
            $this->directory.'/users.csv',
            "id,email,email_confirmed,info_mails,password,nickname,firstname,surname,birthdate,gender,personal_data_confirmed,personal_data_locked,is_superadmin,postcode,city,street,country,phone,website,steam_account,hardware,statements,registered_at,modified_at,last_login_at,uuid,status\n"
            ."1,user@example.test,1,1,ignored,User,Test,Person,1990-01-02,m,0,0,0,1010,Wien,,AT,,,,,,2024-01-01 10:00:00,2024-01-02 10:00:00,,858eac40-d1d9-4122-a7f3-fb5076f5096e,1\n",
        );
        file_put_contents(
            $this->directory.'/clans.csv',
            "id,uuid,name,password,created_at,modified_at,clantag,website,description\n"
            ."7,5a38690d-d97a-4999-a108-972381a7018f,Test Clan,ignored,2024-01-01 10:00:00,2024-01-02 10:00:00,TEST,,Example\n",
        );
        file_put_contents(
            $this->directory.'/gamer_clan.csv',
            "gamer_id,clan_id,is_admin\n1,7,1\n",
        );
    }
}
