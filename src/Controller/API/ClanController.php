<?php

namespace App\Controller\API;

use App\Entity\Clan;
use App\Repository\ClanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/clans', name: 'clans')]
class ClanController extends AbstractController
{
    private readonly ClanRepository $clanRepo;

    public function __construct(ClanRepository $clanRepo)
    {
        $this->clanRepo = $clanRepo;
    }

    /**
     * @return JsonResponse
     */
    #[Route(path: '', name: '', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $search = $request->query->get('q', '');
        $limit = $request->query->getInt('limit', 10);
        $page = $request->query->getInt('page', 1);

        $items = $this->clanRepo->search($search, $limit, ($page - 1) * $limit);

        $result = [];
        $result['count'] = is_countable($items) ? count($items) : 0;
        $result['total'] = $this->clanRepo->countSearch($search);
        // TODO this is hacky, but required for front-end JS a.t.m.?
        $result['items'] = array_map(fn (Clan $clan) => [
            'uuid' => $clan->getUuid(),
            'name' => $clan->getName(),
            'clantag' => $clan->getClantag(),
        ], $items);

        return new JsonResponse(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_OK, [], true);
    }
}
