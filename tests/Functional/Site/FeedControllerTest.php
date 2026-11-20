<?php

namespace App\Tests\Functional\Site;

use App\DataFixtures\NewsFixtures;
use App\DataFixtures\SettingsFixture;
use App\Entity\BeamerNews;
use App\Entity\News;
use App\Tests\Functional\DatabaseWebTestCase;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use DOMXPath;
use Ramsey\Uuid\Uuid;

class FeedControllerTest extends DatabaseWebTestCase
{
    public function testNewsFeed(): void
    {
        $this->databaseTool->loadFixtures([NewsFixtures::class, SettingsFixture::class]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $news = $em->getRepository(News::class)->findOneBy(['title' => 'Wichtig']);
        self::assertNotNull($news);
        $news->getImage()->setName('feed.jpg');
        $news->getImage()->setMimeType('image/jpeg');
        $news->getImage()->setSize(1234);
        $em->flush();

        $this->client->request('GET', '/news/feed.rss');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('application/rss+xml', $this->client->getResponse()->headers->get('Content-Type'));
        $xml = $this->feedXml();
        self::assertSame('News', $xml->evaluate('string(/rss/channel/title)'));
        self::assertSame('News von KLMS Team', $xml->evaluate('string(/rss/channel/description)'));
        self::assertSame('http://localhost/news', $xml->evaluate('string(/rss/channel/link)'));
        self::assertSame(0.0, $xml->evaluate('count(/rss/channel/item[title="Outdated News"])'));
        self::assertSame('<p>Eine wichtige Nachricht.</p>', $xml->evaluate('string(/rss/channel/item[title="Wichtig"]/description)'));
        self::assertSame('http://localhost/news/' . $news->getId(), $xml->evaluate('string(/rss/channel/item[title="Wichtig"]/link)'));
        self::assertSame($news->activeSince()->format(DateTime::RSS), $xml->evaluate('string(/rss/channel/item[title="Wichtig"]/pubDate)'));
        self::assertSame('http://localhost/images/news/feed.jpg', $xml->evaluate('string(/rss/channel/item[title="Wichtig"]/enclosure/@url)'));
        self::assertSame('image/jpeg', $xml->evaluate('string(/rss/channel/item[title="Wichtig"]/enclosure/@type)'));
        self::assertSame('1234', $xml->evaluate('string(/rss/channel/item[title="Wichtig"]/enclosure/@length)'));
        self::assertSame(0.0, $xml->evaluate('count(/rss/channel/item[title="Schnitzel"]/enclosure)'));
        self::assertSame(
            ['title', 'description', 'link', 'pubDate', 'enclosure'],
            array_map(fn ($node) => $node->nodeName, iterator_to_array($xml->query('/rss/channel/item[title="Wichtig"]/*')))
        );
        self::assertInstanceOf(\DOMCdataSection::class, $xml->query('/rss/channel/item[title="Wichtig"]/title')->item(0)->firstChild);
    }

    public function testBeamerFeed(): void
    {
        $this->databaseTool->loadFixtures([NewsFixtures::class, SettingsFixture::class]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $news = (new BeamerNews())->setTitle('Beamer & News')->setContent('<p>Hallo &amp; willkommen</p>');
        $news->setAuthorId(Uuid::fromInteger(1))->setModifierId(Uuid::fromInteger(1));
        $news->setCreated(new DateTime('2020-07-21 10:00:00'));
        $news->getImage()->setName('beamer.jpg');
        $news->getImage()->setMimeType('image/jpeg');
        $news->getImage()->setSize(456);
        $hidden = (new BeamerNews())->setTitle('Hidden')->setContent('Hidden')->setVisible(false);
        $hidden->setAuthorId(Uuid::fromInteger(1))->setModifierId(Uuid::fromInteger(1));
        $withoutImage = (new BeamerNews())->setTitle('Ohne Bild')->setContent('Text');
        $withoutImage->setAuthorId(Uuid::fromInteger(1))->setModifierId(Uuid::fromInteger(1));
        $withoutImage->setCreated(new DateTime('2019-01-01'));
        $em->persist($news);
        $em->persist($hidden);
        $em->persist($withoutImage);
        $em->flush();

        $this->client->request('GET', '/beamer/feed.rss');
        self::assertResponseIsSuccessful();
        self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertStringStartsWith('application/rss+xml', $this->client->getResponse()->headers->get('Content-Type'));
        $xml = $this->feedXml();
        self::assertSame('Beamer News', $xml->evaluate('string(/rss/channel/title)'));
        self::assertSame('KLMS Beamer News', $xml->evaluate('string(/rss/channel/description)'));
        self::assertSame('http://localhost/beamer/feed.rss', $xml->evaluate('string(/rss/channel/link)'));
        self::assertSame(2.0, $xml->evaluate('count(/rss/channel/item)'));
        self::assertSame('Beamer & News', $xml->evaluate('string(/rss/channel/item/title)'));
        self::assertSame('<p>Hallo &amp; willkommen</p>', $xml->evaluate('string(/rss/channel/item/description)'));
        self::assertSame(1.0, $xml->evaluate('count(/rss/channel/item[title="Beamer & News"]/link)'));
        self::assertSame('', $xml->evaluate('string(/rss/channel/item/link)'));
        self::assertSame($news->activeSince()->format(DateTime::RSS), $xml->evaluate('string(/rss/channel/item/pubDate)'));
        self::assertSame('http://localhost/images/beamernews/beamer.jpg', $xml->evaluate('string(/rss/channel/item/enclosure/@url)'));
        self::assertSame('image/jpeg', $xml->evaluate('string(/rss/channel/item/enclosure/@type)'));
        self::assertSame('456', $xml->evaluate('string(/rss/channel/item/enclosure/@length)'));
        self::assertSame(0.0, $xml->evaluate('count(/rss/channel/item[title="Ohne Bild"]/enclosure)'));
    }

    private function feedXml(): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($this->client->getResponse()->getContent()));
        self::assertSame('2.0', $document->documentElement->getAttribute('version'));

        return new DOMXPath($document);
    }
}
