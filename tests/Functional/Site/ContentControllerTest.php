<?php

namespace App\Tests\Functional\Site;

use App\DataFixtures\ContentFixture;
use App\Entity\Content;
use App\Tests\Functional\DatabaseWebTestCase;

class ContentControllerTest extends DatabaseWebTestCase
{
    public function testDuplicateAliasIsAValidationErrorAndNullIsAllowed(): void
    {
        $this->databaseTool->loadFixtures([ContentFixture::class]);
        $validator = static::getContainer()->get('validator');

        $duplicate = (new Content())->setAlias('info');
        $violations = $validator->validate($duplicate);
        $this->assertCount(1, $violations);
        $this->assertSame('alias', $violations[0]->getPropertyPath());

        $withoutAlias = new Content();
        $this->assertCount(0, $validator->validate($withoutAlias));
    }

    public function testContentWithSlag()
    {
        $this->databaseTool->loadFixtures([ContentFixture::class]);

        $crawler = $this->client->request('GET', '/content/info');
        $this->assertEquals(0, $this->mock->getInvalidCalls());

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('FAQ', $crawler->filter('main h1')->eq(0)->text());
        $this->assertEquals('Wer ist dieser LAN?', $crawler->filter('main h1 + div')->eq(0)->text());
        $this->assertStringContainsString('zuletzt geändert', $crawler->filter('main')->text());
    }
}