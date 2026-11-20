<?php

namespace App\EventSubscriber;

use App\Security\CognitoOidcClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class CognitoLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CognitoOidcClient $cognito)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse($this->cognito->createLogoutRedirect());
    }
}
