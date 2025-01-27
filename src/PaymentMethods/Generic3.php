<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler3;

class Generic3 extends Generic
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Generic gateway 3';
    }

    public function getPaymentHandler(): string
    {
        return GenericPaymentHandler3::class;
    }
}
