<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

require_once dirname(__FILE__) . "/../../../../bootstrap.php";

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\HRPayPalModule\Service\PaypalOrder;
use OxidEsales\HRPayPalModule\Service\PaypalConfiguration;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;

try {
	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalOrder::class);
	$requestId = EshopRegistry::getUtilsObject()->generateUId();
    $userToken = $caller->getUserToken($requestId, 100, 'EUR');

    /** @var PaypalConfiguration $configuration */
    $configuration = $container->get(PaypalConfiguration::class);
	$redirectUrl = $configuration->getPayPalCheckoutNowUrl($userToken);

	var_dump($redirectUrl);
} catch ( \Exception $exception) {
	var_export($exception->getMessage());
}
