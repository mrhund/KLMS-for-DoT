<?php

namespace App\Controller\Site;

use App\Controller\LoginUserTrait;
use App\Entity\User;
use App\Entity\UserImage;
use App\Form\UserType;
use App\Helper\EmailRecipient;
use App\Repository\UserRepository;
use App\Repository\UserImageRepository;
use App\Service\EmailService;
use App\Service\SettingService;
use App\Service\TicketService;
use App\Service\TicketState;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    use LoginUserTrait;

    private readonly EmailService $emailService;
    private readonly SettingService $settingService;
    private readonly TicketService $ticketService;
    private readonly LoggerInterface $logger;
    private readonly EntityManagerInterface $entityManager;
    private readonly UserImageRepository $userImgRepo;
    private readonly UserRepository $userRepo;

    public function __construct(
                                UserRepository $userRepo,
                                EmailService $emailService,
                                SettingService $settingService,
                                TicketService $ticketService,
                                LoggerInterface $logger,
                                EntityManagerInterface $entityManager,
                                UserImageRepository $userImgRepo)
    {
        $this->userRepo = $userRepo;
        $this->emailService = $emailService;
        $this->settingService = $settingService;
        $this->ticketService = $ticketService;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->userImgRepo = $userImgRepo;
    }

    private const SHOW_LIMIT = 20;

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/user', name: 'user')]
    public function index(Request $request): Response
    {
        if (!$this->settingService->get('community.enabled', false)) {
            throw $this->createNotFoundException();
        }

        $search = $request->query->get('q', '');
        $page = $request->query->getInt('page', 1);
        $page = max($page, 1);

        if ($this->settingService->get('community.all', false)) {
            $users = $this->userRepo->search($search, self::SHOW_LIMIT, ($page - 1) * self::SHOW_LIMIT);
            $count = $this->userRepo->countSearch($search);
        } else {
            $uuids = $this->ticketService->queryUserUuids(TicketState::REDEEMED);
            $users = $this->userRepo->findByUuids($uuids);
            if (!empty($search)) {
                $users = array_filter($users, fn (User $u) => stripos($u->getNickname(), (string) $search) !== false || stripos($u->getFirstname(), (string) $search) !== false);
            }
            usort($users, fn (User $a, User $b) => $a->getNickname() <=> $b->getNickname());
            $count = count($users);
            $users = array_slice($users, ($page - 1) * self::SHOW_LIMIT, self::SHOW_LIMIT);
        }

        return $this->render('site/user/list.html.twig', [
            'search' => $search,
            'users' => $users,
            'page' => $page,
            'total' => $count,
            'limit' => self::SHOW_LIMIT,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/user/profile', name: 'user_profile')]
    public function userProfile(): Response
    {
    $user = $this->requireDomainUser();

        return $this->render('site/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route(path: '/user/{uuid}', name: 'user_show', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function userShow(string $uuid): Response
    {
        $user = $this->userRepo->findOneByUuid($uuid);

        if ($this->isGranted('IS_AUTHENTICATED_REMEMBERED')
            && $user && $user === $this->getDomainUser()) {
            return $this->redirectToRoute('user_profile');
        }

        return $this->render('site/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route(path: '/user/profile/edit', name: 'user_profile_edit')]
    public function userProfileEdit(Request $request): Response
    {
        $user = $this->requireDomainUser();

        $image = $this->userImgRepo->findOneByUser($user) ?? new UserImage($user->getUuid());
        $form = $this->createForm(UserType::class, $user, ['with_image' => true]);
        if ($form->has('image')) {
            $form->get('image')->setData($image);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // TODO: add Support for changing the EMail
            $user = $form->getData();
            try {
                $this->entityManager->flush();

                if ($form->has('image')) {
                    $image = $form->get('image')->getData();
                    if ($image instanceof UserImage) {
                        if ($image->isEmpty()) {
                            if ($this->entityManager->contains($image)) {
                                $this->entityManager->remove($image);
                            }
                        } else {
                            $image->setUuid($user->getUuid());
                            $this->entityManager->persist($image);
                        }
                        $this->entityManager->flush();
                    }
                }

                return $this->redirectToRoute('user_profile');
            } catch (DBALException $e) {
                $this->logger->error('User profile update failed.', ['exception' => $e]);
                $this->addFlash('error', 'Benutzer konnte nicht gespeichert werden.');
            }
        }

        return $this->render('site/user/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
