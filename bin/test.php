<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

require_once dirname(__FILE__) . "/../../../../bootstrap.php";

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\HRPayPalModule\Service\PaypalOrder;
use OxidEsales\HRPayPalModule\Service\PaypalOrderDetails;
use OxidEsales\HRPayPalModule\Service\PaypalConfiguration;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;

function initOrder()
{
	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalOrder::class);
	$requestId = EshopRegistry::getUtilsObject()->generateUId();
	$userToken = $caller->getUserToken($requestId, 100, 'EUR', 'Authorize');

	/** @var PaypalConfiguration $configuration */
	$configuration = $container->get(PaypalConfiguration::class);
	$redirectUrl = $configuration->getPayPalCheckoutNowUrl($userToken);

	var_dump($redirectUrl);
	return $userToken;
}

try {
	#$userToken = initOrder();
    $userToken = '3TF0547591077022U';

	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalOrderDetails::class);
    #var_export($caller->getOrderDetails($userToken));
    $details = $caller->getOrderDetails($userToken);

    #var_export($details->purchase_units[0]->shipping->address);
    var_export($details->payer);

} catch ( \Exception $exception) {
	echo PHP_EOL . 'exception' . PHP_EOL;
	var_export($exception->getMessage());
}
