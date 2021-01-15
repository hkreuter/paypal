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
use OxidEsales\Eshop\Application\Model\Basket as EshopBasketModel;
use OxidEsales\HRPayPalModule\Core\PaypalExpressUser;
use OxidEsales\HRPayPalModule\Core\PaypalExpressAddress;
use OxidEsales\HRPayPalModule\Model\PaypalCheckout;

function initOrder()
{
	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalOrder::class);
	$requestId = EshopRegistry::getUtilsObject()->generateUId();
	$userToken = $caller->getUserToken($requestId, 89.70, 'EUR', 'Authorize');

	/** @var PaypalConfiguration $configuration */
	$configuration = $container->get(PaypalConfiguration::class);
	$redirectUrl = $configuration->getPayPalCheckoutNowUrl($userToken);

	var_dump($redirectUrl);
	return $userToken;
}

function handleUser(string $userToken)
{
	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalOrderDetails::class);
	#var_export($caller->getOrderDetails($userToken));
	$details = $caller->getOrderDetails($userToken);

	/** @var PaypalExpressAddress $paypalExpressAddress */
	$paypalExpressAddress = new PaypalExpressAddress($details);

	/** @var PaypalExpressUser $userHandler */
	$userHandler = new PaypalExpressUser($details->payer, $paypalExpressAddress);
	$user = $userHandler->getUser();

	return $user->getPayPalDeliveryAddressId();
}

try {
   # $userToken = initOrder();
   # exit($userToken);

	$userToken = '7CC18184SH7588500';

	$basket = oxNew(EshopBasketModel::class);
	$basket->setPayment("oxidpaypal");
	$basket->setShipping('oxidstandard');
	$basket->addToBasket('dc5ffdf380e15674b56dd562a7cb6aec', 3);

	$container = ContainerFactory::getInstance()->getContainer();
	$caller = $container->get(PaypalCheckout::class);
	$basket = $caller->processExpressCheckoutDetails($basket, $userToken);

} catch ( \Exception $exception) {
	echo PHP_EOL . 'exception' . PHP_EOL;
	var_export($exception->getMessage());
}
