<?php

namespace App\Service;

use App\Entity\ShopAddon;
use App\Entity\ShopOrder;
use App\Entity\ShopOrderHistory;
use App\Entity\ShopOrderHistoryAction;
use App\Entity\ShopOrderPositionAddon;
use App\Entity\ShopOrderPositionTicket;
use App\Entity\ShopOrderStatus;
use App\Entity\User;
use App\Exception\OrderLifecycleException;
use App\Helper\EmailRecipient;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\ShopAddonsRepository;
use App\Repository\ShopOrderRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;


class ShopService
{
    private readonly ShopOrderRepository $orderRepository;
    private readonly ShopAddonsRepository $shopAddonsRepository;
    private readonly SettingService $settingService;
    private readonly EntityManagerInterface $em;
    private readonly TicketService $ticketService;
    private readonly EmailService $emailService;
    private readonly IdmRepository $userRepo;

    public const DEFAULT_TICKET_PRICE = 5000;

    public function __construct(ShopOrderRepository $orderRepository, ShopAddonsRepository $shopAddonsRepository, IdmManager $idmManager,
                                SettingService      $settingService, TicketService $ticketService, EmailService $emailService, EntityManagerInterface $em)
    {
        $this->orderRepository = $orderRepository;
        $this->shopAddonsRepository = $shopAddonsRepository;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->settingService = $settingService;
        $this->ticketService = $ticketService;
        $this->emailService = $emailService;
        $this->em = $em;
    }

    public function getAll()
    {
        return $this->orderRepository->findAll();
    }

    private function fulfillOrder(ShopOrder $order): void
    {
        $buyer = $order->getOrderer();
        $first_ticket = null;
        foreach ($order->getShopOrderPositions() as $pos) {
            if ($pos instanceof ShopOrderPositionTicket) {
                $ticket = $this->ticketService->createTicket();
                $pos->setTicket($ticket);
                if (!$first_ticket) { $first_ticket = $ticket; }
            }
        }
        // activate one ticket for buyer if they don't have a ticket yet
        if ($first_ticket && !$this->ticketService->getTicketUser($buyer)) {
            $this->ticketService->redeemTicket($first_ticket, $buyer);
        }
    }

    private function emailOrder(ShopOrder $order): void
    {
        // notify the buyer and send the codes
        $user = $this->userRepo->findOneById($order->getOrderer());
        $this->emailService->scheduleHook(EmailService::APP_HOOK_ORDER, EmailRecipient::fromUser($user), [
            'order' => $order,
            'showPaymentInfo' => $order->getStatus() == ShopOrderStatus::Created,
            'showPaymentSuccess' => $order->getStatus() == ShopOrderStatus::Paid,
        ]);
    }

    public function placeOrder(ShopOrder $order): void
    {
        if ($order->isEmpty()) {
            throw new OrderLifecycleException($order);
        }
        $result = $this->setState($order, ShopOrderStatus::Created);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function cancelOrder(ShopOrder $order): void
    {
        $result = $this->setState($order, ShopOrderStatus::Canceled);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function setOrderPaid(ShopOrder $order): void
    {
        $result = $this->setState($order, ShopOrderStatus::Paid);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function setOrderPaidUndo(ShopOrder $order): void
    {
        $result = $this->setState($order, ShopOrderStatus::Created);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    private function setState(ShopOrder $order, ShopOrderStatus $status): bool
    {
        $new_state = match ($order->getStatus()) {
            null => ShopOrderStatus::Created,
            // currently only state transfer from created to both other states are allowed.
            ShopOrderStatus::Created => $status,
            default => $order->getStatus()
        };
        if ($new_state != $order->getStatus()){
            $order->setStatus($new_state);
            $this->em->persist($order);
            $this->em->flush();
            $this->handleNewState($order);
            $this->em->flush();
            return true;
        } else {
            return false;
        }
    }

    private function handleNewState(ShopOrder $order): void
    {
        switch ($order->getStatus()) {
            case ShopOrderStatus::Created:
                $this->emailOrder($order);
                break;
            case ShopOrderStatus::Canceled:
                break;
            case ShopOrderStatus::Paid:
                $this->fulfillOrder($order);
                $this->emailOrder($order);
                break;
        }
        $order->addShopOrderHistory(
            (new ShopOrderHistory())
                ->setLoggedAt(new DateTimeImmutable())
                ->setAction(match ($order->getStatus()){
                    ShopOrderStatus::Created => ShopOrderHistoryAction::OrderCreated,
                    ShopOrderStatus::Paid => ShopOrderHistoryAction::PaymentSuccessful,
                    ShopOrderStatus::Canceled => ShopOrderHistoryAction::OrderCanceled,
                })
        );
    }

    public function hasOpenOrders(User|UuidInterface $user): bool
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->countOrders($uuid, ShopOrderStatus::Created) != 0;
    }

    public function getAddons(): array
    {
        return $this->shopAddonsRepository->findActive();
    }

    public function allocOrder(User|UuidInterface $user): ShopOrder
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return (new ShopOrder())
            ->setOrderer($uuid)
            ->setCreatedAt(new DateTimeImmutable());
    }

    public function orderAddTickets(ShopOrder $order, int $ticketCnt): void
    {
        $price = $this->settingService->get('lan.signup.price', self::DEFAULT_TICKET_PRICE);
        $discount_limit = $this->settingService->get('lan.signup.discount.limit');
        $discount_price = $this->settingService->get('lan.signup.discount.price');
        if ($discount_price && $discount_limit && $ticketCnt >= $discount_limit) {
            $price = $discount_price;
        }
        for ($i = 0; $i < $ticketCnt; $i++) {
            $order->addShopOrderPosition((new ShopOrderPositionTicket())->setPrice($price));
        }
    }

    public function orderAddAddon(ShopOrder $order, ShopAddon $addon, int $cnt): void
    {
        for ($i = 0; $i < $cnt; $i++) {
            $order->addShopOrderPosition((new ShopOrderPositionAddon())->setAddon($addon));
        }
    }

    /**
     * @param User|UuidInterface $user
     * @param ShopOrderStatus|null $status
     * @return ShopOrder[]
     */
    public function getOrderByUser(User|UuidInterface $user, ?ShopOrderStatus $status = null): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->queryOrders($uuid, $status);
    }
}