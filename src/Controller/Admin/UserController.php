<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserImage;
use App\Form\UserAdminType;
use App\Repository\UserRepository;
use App\Repository\UserImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    private readonly UserRepository $userRepo;
    private readonly EntityManagerInterface $em;
    private readonly UserImageRepository $userImgRepo;

    public function __construct(UserRepository $userRepo, EntityManagerInterface $em, UserImageRepository $userImgRepo)
    {
        $this->em = $em;
        $this->userImgRepo = $userImgRepo;
        $this->userRepo = $userRepo;
    }

    #[Route(path: '/user', name: 'user', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN_USER')]
    public function index(Request $request): Response
    {
        $search = $request->query->get('q', '');
        $limit = $request->query->getInt('limit', 10);
        $page = $request->query->getInt('page', 1);

        $users = $this->userRepo->search($search, $limit, ($page - 1) * $limit);

        if (empty($users)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route(path: '/user/{uuid}', name: 'user_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(string $uuid): Response
    {
        $user = $this->userRepo->findOneByUuid($uuid);

        if (empty($user)) {
            throw $this->createNotFoundException('User not found');
        }

        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route(path: '/user/{uuid}/edit', name: 'user_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN_USER')]
    public function edit(string $uuid, Request $request): Response
    {
        $user = $this->userRepo->findOneByUuid($uuid);

        if (empty($user)) {
            throw $this->createNotFoundException('User not found');
        }

        $image = $this->userImgRepo->findOneByUser($user) ?? new UserImage($user->getUuid());
        $form = $this->createForm(UserAdminType::class, $user, ['disable_on_lock' => false, 'with_image' => true]);
        $form->get('image')->setData($image);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $form->getData();
                $this->em->flush();

                $image = $form->get('image')->getData();
                if ($image->isEmpty()) {
                    $this->em->remove($image);
                } else {
                    $this->em->persist($image);
                }
                $this->em->flush();
                $this->addFlash('success', 'User erfolgreich bearbeitet!');

                $returnUrl = $request->query->get('return');
                if (is_string($returnUrl) && str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
                    return $this->redirect($returnUrl);
                }

                return $this->redirectToRoute('admin_user');
            } catch (DBALException) {
                $this->addFlash('error', 'Benutzer konnte nicht gespeichert werden.');
            }
        }

        return $this->render('admin/user/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
