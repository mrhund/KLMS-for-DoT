<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PermissionService;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Ramsey\Uuid\Uuid;

class UserProvider implements UserProviderInterface
{
    private readonly PermissionService $permissionService;

    public function __construct(
        private readonly UserRepository $userRepo,
        PermissionService $permissionService,
    ) {
        $this->permissionService = $permissionService;
    }

    private function loadAdminRoles(LoginUser $user): void
    {
        $perm = $this->permissionService->handleLogin($user->getUser());
        if (!empty($perm)) {
            $roles = array_map(fn (string $p) => 'ROLE_'.$p, $perm);
            $roles[] = 'ROLE_ADMIN';
            $user->addRoles($roles);
        }
    }

    private function loadUserRoles(LoginUser $user): void
    {
        $roles = [];
        // add roles for Gamers, not yet used
        $user->addRoles($roles);
    }

    public function loadUserByUsername($username): UserInterface
    {
        // TODO remove me after upgrade to Symfony 6
        return $this->loadUserByIdentifier($username);
    }

    /**
     * @see UserProviderInterface
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = Uuid::isValid($identifier)
            ? $this->userRepo->findOneByUuid($identifier)
            : $this->userRepo->findOneByEmailInsensitive($identifier);
        if (empty($user)) {
            throw new UserNotFoundException();
        }

        $lu = new LoginUser($user);
        $this->loadUserRoles($lu);
        $this->loadAdminRoles($lu);

        return $lu;
    }

    /**
     * @see UserProviderInterface
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!($user instanceof LoginUser)) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', $user::class));
        }

        $refreshed = $this->userRepo->findOneByUuid($user->getUser()->getUuid());
        if (!$refreshed instanceof User) {
            throw new UserNotFoundException();
        }

        $lu = new LoginUser($refreshed);
        $this->loadUserRoles($lu);
        $this->loadAdminRoles($lu);

        // Return a User object after making sure its data is "fresh".
        // Or throw a UsernameNotFoundException if the user no longer exists.
        return $lu;
    }

    /**
     * @see UserProviderInterface
     */
    public function supportsClass($class): bool
    {
        return LoginUser::class === $class;
    }
}
