<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SumUp\Exception\SDKException;
use SumUp\HttpClient\HttpClientInterface;
use SumUp\SumUp;
use SumUp\Types\CheckoutCreateRequest;
use SumUp\Types\CheckoutCreateRequestCurrency;
use SumUp\Types\CheckoutSuccess;

class SumupService
{
    private readonly SettingService $settingService;
    private readonly LoggerInterface $logger;
    private readonly ?HttpClientInterface $httpClient;

    public function __construct(LoggerInterface $logger, SettingService $settingService, ?HttpClientInterface $httpClient = null)
    {
        $this->logger = $logger;
        $this->settingService = $settingService;
        $this->httpClient = $httpClient;
    }

    private function init(): ?SumUp
    {
        if ($this->settingService->get('lan.signup.payment_sumupenabled') == true) {
            $apiKey = trim((string) $this->settingService->get('lan.signup.payment_sumupapikey'));
            if ($apiKey === '') {
                $this->logger->error('SumUp is enabled but no API key is configured.');
                throw new \RuntimeException('SumUp API key is not configured.');
            }

            return new SumUp(['api_key' => $apiKey, 'client' => $this->httpClient]);
        }

        $this->logger->info('SumUp is not enabled in settings.');
        return null;
    }

    /**
     * Create a new checkout at SumUp.
     * Returns the Checkout-Id as string or throws on error.
     */
    public function createCheckout(float $amount, string $currency, string $checkoutRef, string $merchantCode, string $description): string
    {
        try {
            $sumup = $this->init();
            if ($sumup === null) {
                throw new \RuntimeException('SumUp is not enabled.');
            }
            $merchantCode = trim($merchantCode);
            if ($merchantCode === '') {
                throw new \InvalidArgumentException('SumUp merchant code is not configured.');
            }
            $currencyEnum = CheckoutCreateRequestCurrency::tryFrom(strtoupper($currency));
            if ($currencyEnum === null) {
                throw new \InvalidArgumentException('Unsupported SumUp currency: ' . $currency);
            }

            $checkout = $sumup->checkouts()->create(new CheckoutCreateRequest(
                checkoutReference: $checkoutRef,
                amount: $amount,
                currency: $currencyEnum,
                merchantCode: $merchantCode,
                description: $description,
            ));
            if ($checkout->id === null || !Uuid::isValid($checkout->id)) {
                throw new \UnexpectedValueException('SumUp returned an invalid checkout ID.');
            }
            return $checkout->id;
        } catch (SDKException $e) {
            $this->logger->error('SumUp createCheckout failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve checkout details from SumUp.
     */
    public function retrieveCheckout(string $checkoutId): CheckoutSuccess
    {
        try {
            $sumup = $this->init();
            if ($sumup === null) {
                throw new \RuntimeException('SumUp is not enabled.');
            }
            return $sumup->checkouts()->get($checkoutId);
        } catch (SDKException $e) {
            $this->logger->error('SumUp retrieveCheckout failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Deactivate a checkout at SumUp.
     */
    public function delete(string $checkoutId): void
    {
        try {
            $sumup = $this->init();
            if ($sumup !== null) {
                $sumup->checkouts()->deactivate($checkoutId);
            }
        } catch (SDKException $e) {
            $this->logger->error('SumUp delete checkout failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
