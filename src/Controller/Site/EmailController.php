<?php

namespace App\Controller\Site;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

class EmailController extends AbstractController
{
    private readonly EmailService $mailService;
    private readonly UserRepository $userRepo;

    public function __construct(
        EmailService $mailService,
        UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    )
    {
        $this->mailService = $mailService;
        $this->userRepo = $userRepo;
    }

    #[Route(path: '/unsubscribe', name: 'email_unsubscribe')]
    public function unsubscribe(Request $request): Response
    {
        $token = strval($request->get('token', ''));
        if (empty($token)) {
            return $this->redirectToRoute('news');
        }
        $uuid = $this->mailService->handleUnsubscribeToken($token);
        if (empty($uuid)) {
            $this->addFlash('error', 'Ungültigen Token übermittelt.');

            return $this->redirectToRoute('news');
        }
        $user = $this->userRepo->findOneByUuid($uuid);
        if (empty($user)) {
            $this->addFlash('error', 'User nicht gefunden.');

            return $this->redirectToRoute('news');
        }
        $user->setInfoMails(false);
        $this->em->flush();
        $this->addFlash('success', "Newsletter für {$user->getEmail()} wurde abbestellt.");

        return $this->redirectToRoute('app_login');
    }
}
