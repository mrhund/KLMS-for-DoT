<?php

namespace App\Controller\API;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserService;
use App\Transfer\Error;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/users', name: 'users')]
class UserController extends AbstractController
{
    private readonly UserRepository $userRepo;
    private readonly UserService $userService;

    public function __construct(UserRepository $userRepo, UserService $userService)
    {
        $this->userRepo = $userRepo;
        $this->userService = $userService;
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $search = $request->query->get('q', '');
        $limit = $request->query->getInt('limit', 10);
        $page = $request->query->getInt('page', 1);
        $items = $this->userRepo->search($search, $limit, ($page - 1) * $limit);

        // Nur Admins dürfen sensible Daten (E-Mail, Vorname, Nachname) sehen
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $result = [];
        $result['count'] = count($items);
        $result['total'] = $this->userRepo->countSearch($search);
        $result['items'] = array_map(fn (User $user) => $this->userService->user2Array($user, $isAdmin), $items);

        return new JsonResponse(json_encode($result, JSON_THROW_ON_ERROR), Response::HTTP_OK, [], true);
    }
}
