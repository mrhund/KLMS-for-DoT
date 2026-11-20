<?php

namespace App\Controller\Site;

use App\Controller\LoginUserTrait;
use App\Entity\Clan;
use App\Entity\User;
use App\Form\ClanType;
use App\Repository\ClanRepository;
use App\Repository\UserRepository;
use App\Service\SettingService;
use App\Service\TicketService;
use App\Service\TicketState;
use App\Service\UserService;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception as DBALException;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class ClanController extends AbstractController
{
    use LoginUserTrait;

    private const CSRF_TOKEN_DELETE = 'clanDeleteToken';
    private const CSRF_TOKEN_MEMBER_EDIT = 'clanMemberEditToken';
    private const CSRF_TOKEN_MEMBER_LEAVE = 'clanMemberLeaveToken';

    private readonly ClanRepository $clanRepo;
    private readonly UserRepository $userRepo;
    private readonly UserService $userService;
    private readonly SettingService $settingService;
    private readonly TicketService $ticketService;

    public function __construct(
        ClanRepository $clanRepo,
        UserRepository $userRepo,
        UserService $userService,
        SettingService $settingService,
        TicketService $ticketService,
        private readonly EntityManagerInterface $em,
    )
    {
        $this->clanRepo = $clanRepo;
        $this->userRepo = $userRepo;
        $this->userService = $userService;
        $this->settingService = $settingService;
        $this->ticketService = $ticketService;
    }

    private const SHOW_LIMIT = 10;

    #[Route(path: '/clan', name: 'clan', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->settingService->get('community.enabled', false)) {
            throw $this->createNotFoundException();
        }

        $search = $request->query->get('q', '');
        $page = $request->query->getInt('page', 1);
        $page = max($page, 1);

        if ($this->settingService->get('community.all', false)) {
            $clans = $this->clanRepo->search($search, self::SHOW_LIMIT, ($page - 1) * self::SHOW_LIMIT);
            $count = $this->clanRepo->countSearch($search);
        } else {
            $uuids = $this->ticketService->queryUserUuids(TicketState::REDEEMED);
            $clans = $this->userService->getClansByUsers($uuids);
            if (!empty($search)) {
                $clans = array_filter($clans, fn (Clan $u) => stripos($u->getClantag(), (string) $search) !== false || stripos($u->getName(), (string) $search) !== false);
            }
            usort($clans, fn (Clan $a, Clan $b) => $a->getName() <=> $b->getName());
            $count = count($clans);
            $clans = array_slice($clans, ($page - 1) * self::SHOW_LIMIT, self::SHOW_LIMIT);
        }

        return $this->render('site/clan/list.html.twig', [
            'search' => $search,
            'clans' => $clans,
            'page' => $page,
            'total' => $count,
            'limit' => self::SHOW_LIMIT,
        ]);
    }

    private function createJoinForm(Clan $clan): FormInterface
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('clan_join', ['uuid' => $clan->getUuid()]))
            ->add('password', PasswordType::class, ['label' => 'Passwort', 'attr' => ['autofocus' => 'autofocus']])
            ->getForm();
    }

    #[Route(path: '/clan/{uuid}/leave', name: 'clan_leave', methods: ['POST'])]
    public function leave(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (empty($clan)) {
            throw $this->createNotFoundException('Clan not found');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_MEMBER_LEAVE, $token)) {
            throw $this->createAccessDeniedException('The CSRF token is invalid.');
        }

        $user = $this->requireDomainUser();

        $this->removeUserFromClan($clan, $user);

        return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
    }

    #[Route(path: '/clan/{uuid}/member', name: 'clan_member_edit', methods: ['POST'])]
    public function memberEdit(string $uuid, Request $request): RedirectResponse
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (empty($clan)) {
            throw $this->createNotFoundException('Clan not found');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_MEMBER_EDIT, $token)) {
            throw $this->createAccessDeniedException('The CSRF token is invalid.');
        }

        $admin = $this->requireDomainUser();
        if (!$clan->isAdmin($admin)) {
            $this->addFlash('error', 'Nur Admins dürfen das.');

            return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
        }
        $user = $this->userRepo->findOneByUuid($request->request->get('user_uuid'));
        if (empty($user)) {
            $this->addFlash('error', 'User nicht gefunden.');

            return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
        }
        $action = $request->request->get('action');
        match ($action) {
            'kick' => $this->removeUserFromClan($clan, $user),
            'promote' => $this->setUserAdmin($clan, $user, true),
            'demote' => $this->setUserAdmin($clan, $user, false),
            default => $this->addFlash('error', 'Aktion nicht möglich.'),
        };

        return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
    }

    private function removeUserFromClan(Clan $clan, User $user): void
    {
        if ($clan->isAdmin($user) && (is_countable($clan->getAdmins()) ? count($clan->getAdmins()) : 0) === 1) {
            $this->addFlash('warning', 'Der letzte Admin kann den Clan nicht verlassen.
             Anderen Admin festlegen oder Clan löschen!');

            return;
        }
        try {
            $clan->removeUser($user);
            $this->em->flush();
            $this->addFlash('success', 'Clan erfolgreich verlassen!');
        } catch (DBALException) {
            $this->addFlash('error', 'Unbekannter Fehler beim Verlassen!');
        }
    }

    private function setUserAdmin(Clan $clan, User $user, bool $admin): void
    {
        if (!$admin && $clan->isAdmin($user) && (is_countable($clan->getAdmins()) ? count($clan->getAdmins()) : 0) === 1) {
            $this->addFlash('warning', 'Der letzte Admin muss Admin bleiben.
             Anderen Admin festlegen oder Clan löschen!');

            return;
        }
        try {
            if ($admin) {
                $clan->addAdmin($user);
            } else {
                $clan->removeAdmin($user);
            }
            $this->em->flush();
            $this->addFlash('success', 'Userstatus erfolgreich geändert!');
        } catch (DBALException) {
            $this->addFlash('error', 'Unbekannter Fehler Ändern des Userstatus!');
        }
    }

    #[Route(path: '/clan/{uuid}/join', name: 'clan_join', methods: ['POST'])]
    public function join(string $uuid, Request $request): RedirectResponse
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);
        if (empty($clan)) {
            throw $this->createNotFoundException('Clan not found');
        }

        $user = $this->requireDomainUser();

        foreach ($clan->getUsers() as $u) {
            if ($user === $u) {
                $this->addFlash('info', "User {$user->getNickname()} ist schon in Clan {$clan->getName()}");

                return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
            }
        }

        $form = $this->createJoinForm($clan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($clan->verifyJoinPassword($form['password']->getData())) {
                    if (is_countable($clan->getUsers()) ? count($clan->getUsers()) : 0) {
                        $clan->addUser($user);
                    } else {
                        $clan->addAdmin($user);
                    }
                    $this->em->flush();
                    $this->addFlash('success', 'Clan erfolgreich beigetreten!');
                } else {
                    $this->addFlash('error', 'Falsches Passwort eingegeben!');
                }
            } catch (DBALException) {
                $this->addFlash('error', 'Unbekannter Fehler beim Beitreten!');
            }
        }

        return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
    }

    #[Route(path: '/clan/create', name: 'clan_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $clan = new Clan();
        $form = $this->createForm(ClanType::class, $clan, ['require_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clan = $form->getData();
            try {
                $admin = $this->requireDomainUser();
                $plainPassword = $form->get('joinPassword')->getData();
                if ($plainPassword !== null) {
                    $clan->setPlainJoinPassword($plainPassword);
                }
                $clan->addAdmin($admin);
                $this->em->persist($clan);
                $this->em->flush();
                $this->addFlash('success', 'Clan erfolgreich angelegt!');

                return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
            } catch (DBALException) {
                $this->addFlash('error', 'Clan konnte nicht gespeichert werden.');
            }
        }

        return $this->render('site/clan/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/clan/{uuid}', name: 'clan_show', methods: ['GET'])]
    public function show(string $uuid): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);

        $this->throwOnClanNotFound($clan);

        return $this->render('site/clan/show.html.twig', [
            'clan' => $clan,
            'form_join' => $this->createJoinForm($clan)->createView(),
            'csrf_token_member_leave' => self::CSRF_TOKEN_MEMBER_LEAVE,
            'csrf_token_member_edit' => self::CSRF_TOKEN_MEMBER_EDIT,
        ]);
    }

    private function throwOnUserNotAdminOfClan(Clan $clan): void
    {
    $admin = $this->requireDomainUser();
    if (!$clan->isAdmin($admin)) {
            throw $this->createAccessDeniedException('Nur Admins können den Clan bearbeiten!');
        }
    }

    private function throwOnClanNotFound(?Clan $clan): void
    {
        if (empty($clan)) {
            throw $this->createNotFoundException('Clan wurde nicht gefunden.');
        }
    }

    #[Route(path: '/clan/{uuid}/edit', name: 'clan_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);

        $this->throwOnClanNotFound($clan);
        $this->throwOnUserNotAdminOfClan($clan);

        $form = $this->createForm(ClanType::class, $clan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clan = $form->getData();
            try {
                $plainPassword = $form->get('joinPassword')->getData();
                if ($plainPassword !== null && $plainPassword !== '') {
                    $clan->setPlainJoinPassword($plainPassword);
                }
                $clan->setModifiedAt(new \DateTime());
                $this->em->flush();
                $this->addFlash('info', 'Clan erfolgreich bearbeitet!');

                return $this->redirectToRoute('clan_show', ['uuid' => $clan->getUuid()]);
            } catch (DBALException) {
                $this->addFlash('error', 'Clan konnte nicht gespeichert werden.');
            }
        }

        return $this->render('site/clan/edit.html.twig', [
            'form' => $form->createView(),
            'clan' => $clan,
            'csrf_token_delete' => self::CSRF_TOKEN_DELETE,
        ]);
    }

    #[Route(path: '/clan/{uuid}/delete', name: 'clan_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepo->findOneByUuid($uuid);

        $this->throwOnClanNotFound($clan);
        $this->throwOnUserNotAdminOfClan($clan);

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

        return $this->redirectToRoute('clan');
    }
}
