<?php

namespace App\Tests\Unit\Service;

use App\Service\SettingService;
use App\Service\SumupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SumUp\Exception\ApiException;
use SumUp\HttpClient\HttpClientInterface;
use SumUp\HttpClient\Response;

class SumupServiceTest extends TestCase
{
    private const CHECKOUT_ID = '123e4567-e89b-42d3-a456-426614174000';

    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testCreatesCheckoutWithMerchantAndApiKey(): void
    {
        $this->httpClient->expects(self::once())
            ->method('send')
            ->with(
                'POST',
                '/v0.1/checkouts',
                [
                    'checkout_reference' => 'Order-42',
                    'amount' => 12.5,
                    'currency' => 'EUR',
                    'merchant_code' => 'MERCHANT',
                    'description' => 'Order-42',
                ],
                self::callback(fn (array $headers) => ($headers['Authorization'] ?? null) === 'Bearer test-api-key'),
                null,
            )
            ->willReturn(new Response(201, ['id' => self::CHECKOUT_ID]));

        $service = $this->service();
        self::assertSame(self::CHECKOUT_ID, $service->createCheckout(12.5, 'eur', 'Order-42', 'MERCHANT', 'Order-42'));
    }

    public function testRetrievesPaidCheckout(): void
    {
        $this->httpClient->expects(self::once())
            ->method('send')
            ->with('GET', '/v0.1/checkouts/' . self::CHECKOUT_ID, [], self::anything(), null)
            ->willReturn(new Response(200, ['id' => self::CHECKOUT_ID, 'status' => 'PAID']));

        $checkout = $this->service()->retrieveCheckout(self::CHECKOUT_ID);
        self::assertSame(self::CHECKOUT_ID, $checkout->id);
        self::assertSame('PAID', $checkout->status);
    }

    public function testDeactivatesCheckout(): void
    {
        $this->httpClient->expects(self::once())
            ->method('send')
            ->with('DELETE', '/v0.1/checkouts/' . self::CHECKOUT_ID, [], self::anything(), null)
            ->willReturn(new Response(200, ['id' => self::CHECKOUT_ID]));

        $this->service()->delete(self::CHECKOUT_ID);
    }

    public function testApiErrorIsLoggedAndRethrown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('SumUp retrieveCheckout failed:'));
        $this->httpClient->expects(self::once())->method('send')
            ->willReturn(new Response(401, ['title' => 'Unauthorized']));

        $this->expectException(ApiException::class);
        $this->service(logger: $logger)->retrieveCheckout(self::CHECKOUT_ID);
    }

    public function testMissingApiKeyFailsBeforeSendingRequest(): void
    {
        $this->httpClient->expects(self::never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SumUp API key is not configured.');
        $this->service(apiKey: '')->createCheckout(12.5, 'EUR', 'Order-42', 'MERCHANT', 'Order-42');
    }

    public function testMissingMerchantCodeFailsBeforeSendingRequest(): void
    {
        $this->httpClient->expects(self::never())->method('send');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SumUp merchant code is not configured.');
        $this->service()->createCheckout(12.5, 'EUR', 'Order-42', ' ', 'Order-42');
    }

    public function testUnsupportedCurrencyFailsBeforeSendingRequest(): void
    {
        $this->httpClient->expects(self::never())->method('send');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->createCheckout(12.5, 'XXX', 'Order-42', 'MERCHANT', 'Order-42');
    }

    public function testMissingCheckoutIdIsRejected(): void
    {
        $this->httpClient->expects(self::once())->method('send')
            ->willReturn(new Response(201, []));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('SumUp returned an invalid checkout ID.');
        $this->service()->createCheckout(12.5, 'EUR', 'Order-42', 'MERCHANT', 'Order-42');
    }

    public function testInvalidCheckoutIdIsRejected(): void
    {
        $this->httpClient->expects(self::once())->method('send')
            ->willReturn(new Response(201, ['id' => 'not-a-uuid']));

        $this->expectException(\UnexpectedValueException::class);
        $this->service()->createCheckout(12.5, 'EUR', 'Order-42', 'MERCHANT', 'Order-42');
    }

    public function testDisabledSumupDoesNotSendRequests(): void
    {
        $this->httpClient->expects(self::never())->method('send');
        $service = $this->service(enabled: false);
        $service->delete(self::CHECKOUT_ID);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SumUp is not enabled.');
        $service->createCheckout(12.5, 'EUR', 'Order-42', 'MERCHANT', 'Order-42');
    }

    private function service(bool $enabled = true, string $apiKey = 'test-api-key', ?LoggerInterface $logger = null): SumupService
    {
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($enabled, $apiKey) {
            return match ($key) {
                'lan.signup.payment_sumupenabled' => $enabled,
                'lan.signup.payment_sumupapikey' => $apiKey,
                default => throw new \LogicException('Unexpected setting: ' . $key),
            };
        });

        return new SumupService($logger ?? new NullLogger(), $settings, $this->httpClient);
    }
}
