<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->apiTokenService->validateToken($accessToken);
        if (null === $token || null === $token->getUserUuid()) {
            throw new BadCredentialsException('Invalid access token.');
        }

        $user = $this->userRepository->findOneByUuid($token->getUserUuid());
        if (!$user instanceof User || null === $user->getEmail() || '' === $user->getEmail()) {
            throw new BadCredentialsException('Invalid access token.');
        }

        return new UserBadge($user->getUuid()->toString());
    }
}
