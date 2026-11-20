<?php

namespace App\Controller;

use App\Security\CognitoOidcClient;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Controller for login and logout. Handling of the request is done by the firewall.
 */
class SecurityController extends AbstractController
{
    public function __construct(private readonly CognitoOidcClient $cognito)
    {
    }

    #[Route(path: '/login', name: 'app_login', methods: ['GET'])]
    public function login(\Symfony\Component\HttpFoundation\Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirect('/');
        }

        if ($error = $authenticationUtils->getLastAuthenticationError()) {
            $this->addFlash('error', 'Die Anmeldung bei Cognito ist fehlgeschlagen. Bitte erneut versuchen.');
            $authenticationUtils->getLastUsername();

            return $this->render('security/login.html.twig');
        }

        return $this->cognito->startAuthorization($request);
    }

    #[Route(path: '/auth/cognito/callback', name: 'cognito_callback', methods: ['GET'])]
    public function cognitoCallback(): Response
    {
        return $this->redirectToRoute('index');
    }

    /**
     * @throws Exception
     */
    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new Exception('This method can be blank - it will be intercepted by the logout key on your firewall');
    }
}
