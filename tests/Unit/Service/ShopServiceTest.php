<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShopOrder;
use App\Repository\UserRepository;
use App\Repository\ShopAddonsRepository;
use App\Repository\ShopOrderPositionRepository;
use App\Repository\ShopOrderRepository;
use App\Service\EmailService;
use App\Service\SettingService;
use App\Service\ShopService;
use App\Service\TicketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ShopServiceTest extends TestCase
{
    public function testCheckoutIdIsSaved(): void
    {
        $order = new ShopOrder();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($order);
        $entityManager->expects(self::once())->method('flush');

        $this->service($entityManager)->saveCheckoutId($order, '123e4567-e89b-42d3-a456-426614174000');

        self::assertSame('123e4567-e89b-42d3-a456-426614174000', (string) $order->getCheckoutId());
    }

    public function testCheckoutIdSaveFailureIsPropagated(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush')
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database unavailable');
        $this->service($entityManager)->saveCheckoutId(new ShopOrder(), '123e4567-e89b-42d3-a456-426614174000');
    }

    private function service(EntityManagerInterface $entityManager): ShopService
    {
        return new ShopService(
            $this->createStub(ShopOrderRepository::class),
            $this->createStub(ShopOrderPositionRepository::class),
            $this->createStub(ShopAddonsRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(SettingService::class),
            $this->createStub(TicketService::class),
            $this->createStub(EmailService::class),
            $entityManager,
            new NullLogger(),
        );
    }
}
