<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Controllers\StoreApi;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractRoute
{
    abstract public function getDecorated(): AbstractRoute;

    abstract public function load(Request $request, SalesChannelContext $context, CustomerEntity $customer);
}
