<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

require_once dirname(__FILE__) . "/../../../../bootstrap.php";

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\HRPayPalModule\Service\PaypalOrder;
use OxidEsales\HRPayPalModule\Service\PaypalConfiguration;

try {
	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalOrder::class);
	$caller->setAmount(100, 'EUR');
    $userToken = $caller->getUserToken('some_request_id');

    /** @var PaypalConfiguration $configuration */
    $configuration = $container->get(PaypalConfiguration::class);
	$redirectUrl = $configuration->getPayPalCheckoutNowUrl($userToken);

	var_dump($redirectUrl);
} catch ( \Exception $exception) {
	var_export($exception->getMessage());
}
