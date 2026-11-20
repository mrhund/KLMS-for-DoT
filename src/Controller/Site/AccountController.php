<?php

namespace App\Controller\Site;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccountController extends AbstractController
{
    #[Route(path: '/reset', name: 'app_reset', methods: ['GET'])]
    #[Route(path: '/reset_pw', name: 'reset_pw', methods: ['GET'])]
    public function reset(): Response
    {
        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/resend', name: 'app_register_resend', methods: ['GET'])]
    #[Route(path: '/confirm', name: 'app_register_confirm', methods: ['GET'])]
    #[Route(path: '/register', name: 'register', methods: ['GET'])]
    public function registrationEntryPoint(): Response
    {
        return $this->redirectToRoute('app_login');
    }
}
