<?php

namespace App\Security;

use App\Repository\UserAdminsRepository;
use App\Repository\UserGamerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;


class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    private $ar;
    private $gr;

    public function __construct(UserAdminsRepository $ar, UserGamerRepository $gr)
    {
        $this->ar = $ar;
        $this->gr = $gr;
    }

    private function loadUserRoles($userGuid)
    {
        $ret = [];
        if ($this->ar->find($userGuid)) {
            array_push($ret, "ROLE_ADMIN");
        }
        $gamer = $this->gr->find($userGuid);
        if ($gamer) {
            if ($gamer->getPayed()) {
                array_push($ret, "ROLE_PAYED_USER");
            }
            // TODO check if user has seat,...
        }
        return $ret;
    }

    /**
     * Symfony calls this method if you use features like switch_user
     * or remember_me.
     *
     * If you're not using these features, you do not need to implement
     * this method.
     *
     * @param $username
     * @return UserInterface
     *
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function loadUserByUsername($username)
    {
        // Load a User object from your data source or throw UsernameNotFoundException.
        // The $username argument may not actually be a username:
        // it is whatever value is being returned by the getUsername()
        // method in your User class.
        $client = HttpClient::create();
        $response = $client->request('GET', "{$_ENV['KLMS_IDM_URL']}/api/users/{$username}", [
            'headers' => [
                'X-API-KEY' => $_ENV['KLMS_IDM_APIKEY'],
            ],
        ]);

        //TODO: improve ErrorHandling
        try {
            $content = $response->toArray();

            if ($response->getStatusCode() == 200) {
                $user = new User();
                $user->setUsername($content['data'][0]['email']);
                $user->setUuid($content['data'][0]['uuid']);
                $user->setClans([]);

                return $user;
            } else {
                throw new \Exception('Unexpected Error occurred!');
            }

        } catch (ClientException $exception) {
            throw new UsernameNotFoundException();
        }
    }

    /**
     * Refreshes the user after being reloaded from the session.
     *
     * When a user is logged in, at the beginning of each request, the
     * User object is loaded from the session and then this method is
     * called. Your job is to make sure the user's data is still fresh by,
     * for example, re-querying for fresh User data.
     *
     * If your firewall is "stateless: true" (for a pure API), this
     * method is not called.
     *
     * @return UserInterface
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', get_class($user)));
        }

        // Return a User object after making sure its data is "fresh".
        // Or throw a UsernameNotFoundException if the user no longer exists.
        // TODO implement
        return $user;
    }

    /**
     * Tells Symfony to use this provider for this User class.
     */
    public function supportsClass($class)
    {
        return User::class === $class;
    }

    /**
     * Upgrades the encoded password of a user, typically for using a better hash algorithm.
     */
    public function upgradePassword(UserInterface $user, string $newEncodedPassword): void
    {
        // TODO: when encoded passwords are in use, this method should:
        // 1. persist the new password in the user storage
        // 2. update the $user object with $user->setPassword($newEncodedPassword);
    }
}