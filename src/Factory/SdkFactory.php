<?php
declare(strict_types=1);
/**
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

namespace MultiSafepay\Shopware6\Factory;

use GuzzleHttp\Client;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Service\SettingsService;
use Nyholm\Psr7\Factory\Psr17Factory;
use MultiSafepay\Shopware6\Sources\Settings\EnvironmentSource;

class SdkFactory
{
    /**
     * @var SettingsService
     */
    private $config;

    /**
     * SdkFactory constructor.
     *
     * @param SettingsService $config
     */
    public function __construct(
        SettingsService $config
    ) {
        $this->config = $config;
    }

    /**
     * @param string|null $salesChannelId
     * @return Sdk
     */
    public function create(?string $salesChannelId = null): Sdk
    {
        return $this->get($salesChannelId);
    }

    /**
     * @param string $apiKey
     * @param string $environment
     * @return Sdk
     */
    public function createWithData(string $apiKey, string $environment): Sdk
    {
        return new Sdk(
            $apiKey,
            $environment === EnvironmentSource::LIVE_ENVIRONMENT,
            new Client(),
            new Psr17Factory(),
            new Psr17Factory()
        );
    }

    /**
     * @param string|null $salesChannelId
     * @return Sdk
     */
    private function get(?string $salesChannelId = null): Sdk
    {
        return new Sdk(
            $this->config->getApiKey($salesChannelId),
            $this->config->isLiveMode($salesChannelId),
            new Client(),
            new Psr17Factory(),
            new Psr17Factory()
        );
    }
}
