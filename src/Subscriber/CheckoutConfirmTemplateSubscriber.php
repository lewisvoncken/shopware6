<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Gateways\Gateway;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;
use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use MultiSafepay\Shopware6\Support\Tokenization;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepository;

    /**
     * @var string
     */
    private $shopwareVersion;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * CheckoutConfirmTemplateSubscriber constructor.
     *
     * @param SdkFactory $sdkFactory
     * @param EntityRepositoryInterface $languageRepository
     * @param SettingsService $settingsService
     * @param string $shopwareVersion
     */
    public function __construct(
        SdkFactory $sdkFactory,
        EntityRepositoryInterface $languageRepository,
        SettingsService $settingsService,
        string $shopwareVersion
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->languageRepository = $languageRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->settingsService = $settingsService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addMultiSafepayExtension',
            AccountEditOrderPageLoadedEvent::class => 'addMultiSafepayExtension',
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @throws Exception
     */
    public function addMultiSafepayExtension($event): void
    {
        if (!$event instanceof CheckoutConfirmPageLoadedEvent && !$event instanceof AccountEditOrderPageLoadedEvent) {
            throw new \InvalidArgumentException(
                'Please provide ' . CheckoutConfirmPageLoadedEvent::class . ' or ' .
                AccountEditOrderPageLoadedEvent::class
            );
        }
        try {
            $salesChannelContext = $event->getSalesChannelContext();
            $customer = $salesChannelContext->getCustomer();
            $sdk = $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId());
            $struct = new MultiSafepayStruct();
            $issuers = $sdk->getIssuerManager()->getIssuersByGatewayCode(Ideal::GATEWAY_CODE);
            $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'] ?? null;
        } catch (InvalidApiKeyException $invalidApiKeyException) {
            /***
             * @TODO add better logging system
             */
            return;
        } catch (ApiException $apiException) {
        }

        switch ($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier()) {
            case IdealPaymentHandler::class:
                $activeName = $this->getRealIdealName($issuers, $lastUsedIssuer);
                break;
        }

        $struct->assign([
            'api_token' => $this->getComponentsToken($salesChannelContext),
            'gateway_code' => $this->getGatewayCode($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier()),
            'env' => $this->getComponentsEnvironment($salesChannelContext),
            'locale' => $this->getLocale(
                $event->getSalesChannelContext()->getSalesChannel()->getLanguageId(),
                $event->getContext()
            ),
            'show_tokenization' => $this->showTokenization($salesChannelContext),
            'issuers' => $issuers,
            'last_used_issuer' => $lastUsedIssuer,
            'shopware_compare' => version_compare($this->shopwareVersion, '6.4', '<'),
            'payment_method_name' => $activeName ?? null,
            'current_payment_method_id' => $event->getSalesChannelContext()->getPaymentMethod()->getId(),
        ]);

        $event->getPage()->addExtension(
            MultiSafepayStruct::EXTENSION_NAME,
            $struct
        );
    }

    /**
     * @param Issuer[] $issuers
     * @param string|null $lastUsedIssuer
     * @return string
     */
    private function getRealIdealName(array $issuers, ?string $lastUsedIssuer): string
    {
        $result = 'iDEAL';

        foreach ($issuers as $issuer) {
            if ($issuer->getCode() === $lastUsedIssuer) {
                return $result . ' (' . $issuer->getDescription() . ')';
            }
        }

        return $result;
    }

    private function getComponentsToken(SalesChannelContext $salesChannelContext): ?string
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        return $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId())->getApiTokenManager()
            ->get()->getApiToken();
    }

    private function getComponentsEnvironment(SalesChannelContext $salesChannelContext): ?string
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        return $this->settingsService->isLiveMode() ? 'live' : 'test';
    }

    private function getLocale(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        $locale = $this->languageRepository->search($criteria, $context)
            ->get($languageId)->getLocale()->getCode();
        return substr($locale, 0, 2);
    }

    /**
     * @param string $paymentHandler
     * @return string|null
     */
    private function getGatewayCode(string $paymentHandler)
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $gateway */
            $gateway = new $gateway();
            if ($gateway->getPaymentHandler() === $paymentHandler) {
                return $gateway->getGatewayCode();
            }
        }

        return null;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     */
    private function showTokenization(SalesChannelContext $salesChannelContext): bool
    {
        if ($salesChannelContext->getCustomer()->getGuest()) {
            return false;
        }

        if (!in_array(Tokenization::class, class_uses($salesChannelContext->getPaymentMethod()->getHandlerIdentifier()))) {
            return false;
        }

        return (bool)$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'tokenization', false);
    }
}
