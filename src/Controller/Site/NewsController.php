<?php

namespace App\Controller\Site;

use App\Controller\LoginUserTrait;
use App\Entity\News;
use App\Entity\NewsComment;
use App\Form\NewsCommentType;
use App\Service\NewsCommentService;
use App\Service\NewsService;
use App\Service\RssFeedRenderer;
use App\Service\SettingService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

#[Route(path: '/news', name: 'news')]
class NewsController extends AbstractController
{
    use LoginUserTrait;

    private readonly NewsService $newsService;
    private readonly UserService $userService;
    private readonly RssFeedRenderer $feedRenderer;
    private readonly RouterInterface $router;
    private readonly UploaderHelper $vich;
    private readonly SettingService $settings;
    private readonly NewsCommentService $commentService;

    private const PRELOAD_NEWS_CNT = 6;

    /**
     * NewsController constructor.
     */
    public function __construct(NewsService $newsService, UserService $userService, RssFeedRenderer $feedRenderer, RouterInterface $router, UploaderHelper $vich, SettingService $settings, NewsCommentService $commentService)
    {
        $this->newsService = $newsService;
        $this->userService = $userService;
        $this->feedRenderer = $feedRenderer;
        $this->router = $router;
        $this->vich = $vich;
        $this->settings = $settings;
        $this->commentService = $commentService;
    }

    #[Route(path: '', name: '')]
    public function index(Request $request): Response
    {
        $cnt = intval($request->get('cnt', 0));
        $cnt = max($cnt, NewsController::PRELOAD_NEWS_CNT);
        $news = $this->newsService->get(0, $cnt);
        $total = $this->newsService->count();
        $this->userService->preloadUsers(array_map(fn (News $news) => $news->getAuthorId(), $news));

        return $this->render('site/news/index.html.twig', [
            'news' => $news,
            'news_total_cnt' => $total,
        ]);
    }

    #[Route(path: '/cards', name: '_cards')]
    public function cards(Request $request): Response
    {
        $offset = $request->get('offset', NewsController::PRELOAD_NEWS_CNT);
        $count = $request->get('count', 4);

        $news = $this->newsService->get($offset, $count);

        return $this->render('site/news/_cards.html.twig', [
            'news' => $news,
        ]);
    }

    #[Route(path: '/{id}', requirements: ['id' => '\\d+'], name: '_detail', methods: ['GET', 'POST'])]
    public function byId(Request $request, News $news): Response
    {
        if (!$news->isActive() && !$this->isGranted('ROLE_ADMIN_NEWS')) {
            throw $this->createNotFoundException();
        }

        $commentFormView = null;
        /** @var NewsComment[] $comments */
        $comments = $this->commentService->getForNews($news);
        $commentAuthors = [];
        foreach ($comments as $comment) {
            $authorId = $comment->getAuthorId();
            if ($authorId) {
                $commentAuthors[$authorId->toString()] = $authorId;
            }
        }
        if ($commentAuthors !== []) {
            $commentAuthors = $this->userService->getUsers(array_values($commentAuthors), true);
        }

        if ($this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            $comment = new NewsComment();
            $form = $this->createForm(NewsCommentType::class, $comment);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                if ($form->isValid()) {
                    try {
                        $this->commentService->addComment($news, $comment, $this->requireDomainUser());
                        $this->addFlash('success', 'Kommentar wurde veröffentlicht.');

                        return $this->redirectToRoute('news_detail', [
                            'id' => $news->getId(),
                            '_fragment' => 'comments',
                        ]);
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Kommentar konnte nicht gespeichert werden.');
                    }
                } else {
                    $this->addFlash('error', 'Bitte überprüfe deinen Kommentar.');
                }
            }

            $commentFormView = $form->createView();
        }

        return $this->render('site/news/show.html.twig', [
            'content' => $news,
            'comments' => $comments,
            'commentAuthors' => $commentAuthors,
            'commentForm' => $commentFormView,
        ]);
    }

    #[Route(path: '/feed.rss', name: '_feed')]
    public function feed(Request $request): Response
    {
        $news = $this->newsService->get();
        $news = array_map(fn($n) => $this->toFeedElement($request->getSchemeAndHttpHost(), $n), $news);
        $feed = $this->feedRenderer->render(
            'News',
            "News von {$this->settings->get('site.organisation')}",
            $this->router->generate('news', [], RouterInterface::ABSOLUTE_URL),
            $news
        );
        return new Response($feed, 200, ['Content-Type' => 'application/rss+xml']);
    }

    private function toFeedElement(string $host, News $news): array
    {
        $url = $host . $this->router->generate('news_detail', ['id' => $news->getId()], RouterInterface::ABSOLUTE_PATH);
        $imageUrl = $this->vich->asset($news);
        $enclosure = null;
        if ($imageUrl !== null) {
            $image = $news->getImage();
            if ($image->getMimeType() === null || $image->getSize() === null) {
                throw new \InvalidArgumentException('News feed image must have a MIME type and size.');
            }
            $enclosure = ['url' => $host . $imageUrl, 'type' => $image->getMimeType(), 'length' => $image->getSize()];
        }
        return [
            'title' => $news->getTitle() ?? '',
            'description' => $news->getContent() ?? '',
            'link' => $url,
            'date' => $news->activeSince(),
            'enclosure' => $enclosure,
        ];
    }
}
