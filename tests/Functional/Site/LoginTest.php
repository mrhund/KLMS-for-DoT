<?php

namespace App\Tests\Functional\Site;

use App\DataFixtures\UserFixtures;
use App\Tests\Functional\DatabaseWebTestCase;

class LoginTest extends DatabaseWebTestCase
{
    public function testCognitoAuthorizationFlowUsesPkce(): void
    {
        $this->databaseTool->loadFixtures([]);
        $this->client->followRedirects(false);

        $this->client->request('GET', '/login');

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://cognito.example.test/oauth2/authorize?', $location);
        self::assertStringContainsString('response_type=code', $location);
        self::assertStringContainsString('code_challenge_method=S256', $location);
        self::assertStringContainsString('state=', $location);
        self::assertStringContainsString('nonce=', $location);
    }

    public function testLoginHelperUsesBrowserSession(): void
    {
        $this->databaseTool->loadFixtures([UserFixtures::class]);
        $this->login('user2@localhost.local');

        $this->assertSelectorTextContains('#dropdownMenuUser', 'User 2');
    }

    public function testLoginAndLogout(): void
    {
        $this->databaseTool->loadFixtures([UserFixtures::class]);
        $this->login('user2@localhost.local');
        $this->logout();
    }

    public function testAuthenticatedUserBypassesCognito(): void
    {
        $this->databaseTool->loadFixtures([UserFixtures::class]);
        $this->login('user2@localhost.local');

        $this->client->request('GET', '/login');

        $this->assertResponseRedirects('/');
    }
}
