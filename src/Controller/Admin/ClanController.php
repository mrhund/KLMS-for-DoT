<?php

namespace App\Controller\Admin;

use App\Entity\Clan;
use App\Entity\User;
use App\Form\ClanType;
use App\Repository\ClanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[IsGranted('ROLE_ADMIN_USER')]
class ClanController extends AbstractController
{
    // TODO: Better Exception/Error Handling see https://github.com/KRRUg/KLMS/blob/feature/admin-mgmt/src/Controller/BaseController.php and Admin/PermissionController.php
    private const CSRF_TOKEN_DELETE = 'clanDeleteToken';
    private const CSRF_TOKEN_MEMBER_EDIT = 'clanMemberAddToken';

    private readonly ClanRepository $clanRepo;
    private readonly UserRepository $userRepo;

    public function __construct(
        ClanRepository $clanRepo,
        UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    )
    {
        $this->clanRepo = $clanRepo;
        $this->userRepo = $userRepo;
    }

    #[Route(path: '/clan', name: 'clan', methods: ['GET'])]
    public function index(): Response
    {
        $clans = $this->clanRepo->findAll();

        return $this->render('admin/clan/index.html.twig', [
            'clans' => $clans,
            'csrf_token_delete' => self::CSRF_TOKEN_DELETE,
        ]);
    }

    #[Route(path: '/clan/create', name: 'clan_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $form = $this->createForm(ClanType::class, null, ['require_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clan = $form->getData();
            try {
                $clan->setPlainJoinPassword($form->get('joinPassword')->getData());
                $this->em->persist($clan);
                $this->em->flush();

                $this->addFlash('success', 'Clan erfolgreich angelegt!');

                return $this->redirectToRoute('admin_clan');
            } catch (DBALException) {
                $this->addFlash('error', 'Clan konnte nicht gespeichert werden.');
            }
        }

        return $this->render('admin/clan/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/clan/{uuid}/edit', name: 'clan_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (is_null($clan)) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ClanType::class, $clan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clan = $form->getData();
            $plainPassword = $form->get('joinPassword')->getData();
            if ($plainPassword !== null && $plainPassword !== '') {
                $clan->setPlainJoinPassword($plainPassword);
            }
            $clan->setModifiedAt(new \DateTime());
            $this->em->flush();

            $this->addFlash('success', 'Clan erfolgreich bearbeitet!');

            return $this->redirectToRoute('admin_clan');
        }

        return $this->render('admin/clan/edit.html.twig', [
            'clan' => $clan,
            'form' => $form->createView(),
            'csrf_token_delete' => self::CSRF_TOKEN_DELETE,
        ]);
    }

    #[Route(path: '/clan/{uuid}', name: 'clan_show', methods: ['GET'])]
    public function show(string $uuid): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (is_null($clan)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/clan/show.html.twig', [
            'clan' => $clan,
        ]);
    }

    #[Route(path: '/clan/{uuid}/member', name: 'clan_member', methods: ['GET'])]
    public function member(string $uuid): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (is_null($clan)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/clan/member.html.twig', [
            'clan' => $clan,
            'csrf_token_member_edit' => self::CSRF_TOKEN_MEMBER_EDIT,
        ]);
    }

    #[Route(path: '/clan/{uuid}/member/edit', name: 'clan_member_edit', methods: ['POST'])]
    public function editMember(string $uuid, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_MEMBER_EDIT, $token)) {
            throw $this->createAccessDeniedException('The CSRF token is invalid.');
        }

        $clan = $this->clanRepo->findOneByUuid($uuid);
        $user = $this->userRepo->findOneByUuid($request->request->get('user_uuid'));

        if (empty($clan)) {
            throw $this->createNotFoundException();
        }
        if (empty($user)) {
            $this->addFlash('error', 'Ungültiger User ausgewählt.');

            return $this->redirectToRoute('admin_clan_member', ['uuid' => $clan->getUuid()]);
        }

        match ($request->request->get('action')) {
            'add' => $this->memberAdd($clan, $user),
            'kick' => $this->memberRemove($clan, $user),
            'promote' => $this->setUserAdmin($clan, $user, true),
            'demote' => $this->setUserAdmin($clan, $user, false),
            default => throw $this->createNotFoundException('User supplied in POST not found or invalid'),
        };

        return $this->redirectToRoute('admin_clan_member', ['uuid' => $clan->getUuid()]);
    }

    private function memberAdd(Clan $clan, User $user): void
    {
        try {
            $clan->addUser($user);
            $this->em->flush();
            $this->addFlash('info', "User {$user->getNickname()} erfolgreich zum Clan hinzugefügt!");
        } catch (DBALException) {
            $this->addFlash('error', 'Es ist ein unerwarteter Fehler aufgetreten');
        }
    }

    private function memberRemove(Clan $clan, User $user): void
    {
        try {
            $clan->removeUser($user);
            $this->em->flush();
            $this->addFlash('info', "User {$user->getNickname()} erfolgreich vom Clan entfernt!");
        } catch (DBALException) {
            $this->addFlash('error', 'Es ist ein unerwarteter Fehler aufgetreten');
        }
    }

    private function setUserAdmin(Clan $clan, User $user, bool $admin): void
    {
        try {
            if ($admin) {
                $clan->addAdmin($user);
            } else {
                $clan->removeAdmin($user);
            }
            $this->em->flush();
            $this->addFlash('success', 'Userstatus erfolgreich geändert!');
        } catch (DBALException) {
            $this->addFlash('error', 'Es ist ein unerwarteter Fehler aufgetreten');
        }
    }

    #[Route(path: '/clan/{uuid}/delete', name: 'clan_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (is_null($clan)) {
            throw $this->createNotFoundException();
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_DELETE, $token)) {
            throw $this->createAccessDeniedException('The CSRF token is invalid.');
        }

        try {
            $this->em->remove($clan);
            $this->em->flush();
            $this->addFlash('info', 'Clan erfolgreich gelöscht!');
        } catch (DBALException) {
            $this->addFlash('error', 'Es ist ein unerwarteter Fehler aufgetreten');
        }

        return $this->redirectToRoute('admin_clan');
    }
}
