<?php

namespace App\Security;

use App\Entity\UserIdentity;
use App\Entity\User;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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
                $user = $this->provisionUser($claims);
            } else {
                $user = $this->userRepository->findOneByUuid($username);
                if ($user === null) {
                    $user = $this->provisionUser($claims, Uuid::fromString($username));
                }
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

    private function provisionUser(array $claims, ?\Ramsey\Uuid\UuidInterface $uuid = null): User
    {
        $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : '';
        if (($claims['email_verified'] ?? false) !== true || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadCredentialsException('Cognito did not provide a verified email for user provisioning.');
        }
        if ($this->userRepository->findOneByEmailInsensitive($email) !== null) {
            throw new BadCredentialsException('Cognito account email is already linked to another KLMS profile.');
        }

        $uuid ??= Uuid::uuid4();
        $nickname = $this->uniqueNickname(
            $claims['preferred_username'] ?? $claims['nickname'] ?? strstr($email, '@', true) ?: 'user-'.$uuid->toString(),
        );
        $name = is_string($claims['name'] ?? null) ? trim($claims['name']) : '';
        $givenName = is_string($claims['given_name'] ?? null) ? trim($claims['given_name']) : $name;
        $familyName = is_string($claims['family_name'] ?? null) ? trim($claims['family_name']) : '';
        $now = new \DateTime();

        $user = (new User())
            ->setUuid($uuid)
            ->setEmail($email)
            ->setEmailConfirmed(true)
            ->setInfoMails(false)
            ->setNickname($nickname)
            ->setFirstname($givenName)
            ->setSurname($familyName)
            ->setPersonalDataConfirmed(false)
            ->setPersonalDataLocked(false)
            ->setIsSuperadmin(false)
            ->setRegisteredAt($now)
            ->setModifiedAt($now);
        $this->em->persist($user);

        return $user;
    }

    private function uniqueNickname(mixed $candidate): string
    {
        $base = is_string($candidate) ? trim($candidate) : '';
        $base = trim($base) !== '' ? mb_substr($base, 0, 64) : 'user';
        $nickname = $base;
        $counter = 2;
        while ($this->userRepository->findOneByNicknameInsensitive($nickname) !== null) {
            $suffix = '-'.$counter++;
            $nickname = mb_substr($base, 0, 64 - strlen($suffix)).$suffix;
        }

        return $nickname;
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
        $this->logger->warning('Cognito authentication failed.', [
            'exception_class' => $exception::class,
            'reason' => $exception->getMessage(),
            'previous_class' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
            'previous_reason' => $exception->getPrevious()?->getMessage(),
            'cognito_error' => $request->query->get('error'),
        ]);

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
