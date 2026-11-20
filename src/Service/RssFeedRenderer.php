<?php

namespace App\Service;

use DateTime;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Symfony\Contracts\Translation\TranslatorInterface;

class RssFeedRenderer
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param list<array{title: string, description: string, link: string, date: DateTimeInterface, enclosure: ?array{url: string, type: string, length: int}}> $items
     */
    public function render(string $title, string $description, string $link, array $items): string
    {
        $document = new DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;
        $rss = $document->appendChild($document->createElement('rss'));
        $rss->setAttribute('version', '2.0');
        $channel = $rss->appendChild($document->createElement('channel'));

        $this->appendText($document, $channel, 'title', $this->translator->trans($title));
        $this->appendText($document, $channel, 'description', $this->translator->trans($description));
        $this->appendText($document, $channel, 'link', $link);
        $this->appendText($document, $channel, 'lastBuildDate', (new DateTime())->format(DateTime::RSS));

        foreach ($items as $item) {
            $entry = $channel->appendChild($document->createElement('item'));
            foreach (['title', 'description'] as $name) {
                $element = $entry->appendChild($document->createElement($name));
                $element->appendChild($document->createCDATASection($item[$name]));
            }
            $this->appendText($document, $entry, 'link', $item['link']);
            $this->appendText($document, $entry, 'pubDate', $item['date']->format(DateTime::RSS));

            if ($item['enclosure'] !== null) {
                $enclosure = $entry->appendChild($document->createElement('enclosure'));
                $enclosure->setAttribute('url', $item['enclosure']['url']);
                $enclosure->setAttribute('type', $item['enclosure']['type']);
                $enclosure->setAttribute('length', (string) $item['enclosure']['length']);
            }
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Could not render RSS feed.');
        }

        return $xml;
    }

    private function appendText(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $parent->appendChild($document->createElement($name));
        if ($value !== '') {
            $element->appendChild($document->createTextNode($value));
        }
    }
}
