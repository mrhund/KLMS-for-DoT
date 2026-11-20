<?php

namespace App\Tests\Functional;

use App\Repository\UserRepository;
use App\Security\UserProvider;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

abstract class DatabaseWebTestCase extends WebTestCase
{
    protected readonly AbstractDatabaseTool $databaseTool;

    protected readonly KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->followRedirects();

        // don't reboot the kernel after one request to avoid trashing of in-memory db
        $this->client->disableReboot();
        $this->databaseTool = static::getContainer()->get(DatabaseToolCollection::class)->get();
    }

    protected function tearDown(): void
    {
        /** @var Filesystem $filesystem */
        $filesystem = static::getContainer()->get(Filesystem::class);
        $vich_dir = $this->client->getKernel()->getCacheDir() . "/vich_data";
        $filesystem?->remove($vich_dir);
        parent::tearDown();
    }

    protected function login(string $user): void
    {
        $repository = static::getContainer()->get(UserRepository::class);
        $localUser = $repository->findOneByEmailInsensitive($user);
        $this->assertNotNull($localUser);
        $provider = static::getContainer()->get(UserProvider::class);
        $this->client->loginUser($provider->loadUserByIdentifier($localUser->getUuid()->toString()), 'main');
        $this->client->request('GET', '/');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextNotContains('#dropdownMenuUser', "Anmelden");
    }

    protected function logout(): void
    {
        $this->client->followRedirects(false);
        $this->client->request('GET', '/logout');
        $this->assertResponseRedirects();
        $this->client->followRedirects();
        $this->client->request('GET', '/');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains('#dropdownMenuUser', "Anmelden");
    }
}