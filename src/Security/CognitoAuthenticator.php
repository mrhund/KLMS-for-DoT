<?php

namespace App\Security;

use App\Entity\UserIdentity;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class CognitoAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public function __construct(
        private readonly CognitoOidcClient $cognito,
        private readonly UserRepository $userRepository,
        private readonly UserIdentityRepository $identityRepository,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'cognito_callback';
    }

    public function authenticate(Request $request): Passport
    {
        $claims = $this->cognito->exchangeCode($request);
        $identity = $this->identityRepository->findByIssuerAndSubject($claims['iss'], $claims['sub']);

        if ($identity !== null) {
            $identity->touch();
            $user = $identity->getUser();
            $this->em->flush();
        } else {
            $username = $claims['cognito:username'];
            if (!Uuid::isValid($username)) {
                throw new BadCredentialsException('Cognito username is not a KLMS user UUID.');
            }

            $user = $this->userRepository->findOneByUuid($username);
            if ($user === null) {
                throw new BadCredentialsException('No imported KLMS profile exists for this Cognito user.');
            }
            if (($claims['email_verified'] ?? false) !== true
                || !is_string($claims['email'] ?? null)
                || strcasecmp($claims['email'], (string) $user->getEmail()) !== 0) {
                throw new BadCredentialsException('Cognito identity does not match the imported KLMS user.');
            }

            $identity = new UserIdentity($user, $claims['iss'], $claims['sub']);
            $this->em->persist($identity);
            $this->em->flush();
        }

        return new SelfValidatingPassport(new UserBadge($user->getUuid()->toString()));
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            $this->removeTargetPath($request->getSession(), $firewallName);

            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set(
            \Symfony\Component\Security\Http\SecurityRequestAttributes::AUTHENTICATION_ERROR,
            $exception,
        );

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
