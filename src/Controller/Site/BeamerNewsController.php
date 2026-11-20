<?php

namespace App\Controller\Site;

use App\Entity\BeamerNews;
use App\Service\BeamerNewsService;
use App\Service\RssFeedRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

#[Route(path: '/beamer', name: 'beamer')]
class BeamerNewsController extends AbstractController
{
    private readonly BeamerNewsService $beamerNewsService;
    private readonly RssFeedRenderer $feedRenderer;
    private readonly StorageInterface $storage;
    private readonly RouterInterface $router;

    public function __construct(BeamerNewsService $beamerNewsService, RssFeedRenderer $feedRenderer, StorageInterface $storage, RouterInterface $router)
    {
        $this->beamerNewsService = $beamerNewsService;
        $this->feedRenderer = $feedRenderer;
        $this->storage = $storage;
        $this->router = $router;
    }

    #[Route(path: '/feed.rss', name: '_feed')]
    public function feed(Request $request): Response
    {
        $news = $this->beamerNewsService->getActive();

        $baseUrl = $request->getSchemeAndHttpHost();
        $feedItems = array_map(fn(BeamerNews $n) => $this->toFeedElement($n, $baseUrl), $news);

        $feed = $this->feedRenderer->render(
            'Beamer News',
            'KLMS Beamer News',
            $this->router->generate('beamer_feed', [], RouterInterface::ABSOLUTE_URL),
            $feedItems
        );

        return new Response($feed, 200, [
            'Content-Type' => 'application/rss+xml',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function toFeedElement(BeamerNews $beamerNews, string $baseUrl): array
    {
        $enclosure = null;

        if ($beamerNews->getImage() && $beamerNews->getImage()->getName()) {
            $path = $this->storage->resolveUri($beamerNews, 'imageFile');
            if ($path) {
                $enclosure = [
                    'url' => $baseUrl . $path,
                    'type' => $beamerNews->getImage()->getMimeType() ?? 'image/jpeg',
                    'length' => $beamerNews->getImage()->getSize() ?? 0,
                ];
            }
        }

        return [
            'title' => $beamerNews->getTitle() ?? '',
            'description' => $beamerNews->getContent() ?? '',
            'link' => '',
            'date' => $beamerNews->activeSince(),
            'enclosure' => $enclosure,
        ];
    }
}
