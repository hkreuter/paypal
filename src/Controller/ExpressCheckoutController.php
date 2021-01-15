<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Controller;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\Eshop\Application\Model\Address as EshopAddressModel;
use OxidEsales\Eshop\Core\Exception\StandardException as EshopStandardException;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;
use OxidEsales\HRPayPalModule\Model\PaypalCheckout;
use OxidEsales\HRPayPalModule\Exception\PaymentNotValidForUserCountry;
use OxidEsales\HRPayPalModule\Exception\ShippingMethodNotValid;
use OxidEsales\HRPayPalModule\Exception\OrderTotalChanged;

/**
 * PayPal Express Checkout Controller class
 */
class ExpressCheckoutController extends \OxidEsales\Eshop\Application\Controller\FrontendController
{
	public function setExpressCheckout()
    {
        $session = EshopRegistry::getSession();
        $session->setVariable("oepaypal", "2");

        try {
	        $container = ContainerFactory::getInstance()->getContainer();

	        /** @var PaypalCheckout $checkoutModel */
	        $checkoutModel = $container->get(PaypalCheckout::class);

            $basket = EshopRegistry::getSession()->getBasket();
	        $basket->setPayment("oxidpaypal");
            $user = EshopRegistry::getSession()->getUser() ? EshopRegistry::getSession()->getUser() : null;

	        $requestId = EshopRegistry::getUtilsObject()->generateUId();
	        $paypalToken = $checkoutModel->setExpressCheckout($basket, $user, $requestId);

	        EshopRegistry::getSession()->setVariable('oepaypal-token', $paypalToken);
	        EshopRegistry::getSession()->setVariable('paymentid', 'oxidpaypal');

	        $redirectUrl = $checkoutModel->getRedirectToPayPalUrl($paypalToken);
	        EshopRegistry::getUtils()->redirect($redirectUrl, false);

        } catch (EshopStandardException $exception) {
            // error - unable to set order info - display error message
	        EshopRegistry::getUtilsView()->addErrorToDisplay($exception);

            // return to requested view
            $returnTo = $this->getRequestedControllerKey();
            $returnTo = !empty($returnTo) ? $returnTo : 'basket';
            return $returnTo;
        }
    }

	/**
	 * Executes "GetExpressCheckoutDetails" and on SUCCESS response - saves
	 * user information and redirects to order page, on failure - sets error
	 * message and redirects to basket page
	 *
	 * @return string
	 */
	public function getExpressCheckoutDetails()
	{
		$container = ContainerFactory::getInstance()->getContainer();

		/** @var PaypalCheckout $checkoutModel */
		$checkoutModel = $container->get(PaypalCheckout::class);

		try {
			$basket = EshopRegistry::getSession()->getBasket();
			EshopRegistry::getSession()->setVariable('deladrid', null); //delivery address is provided by PayPal

			//TODO: is there any way to chose the shipping on PP side in the PP rest api?
			//PayPal documentation looks like shipping can be configured in the PayPal account, but
			//in sandbox nothing was to be found.
			$basket->setShipping('oxidstandard');

			// Remove flag of "new item added" to not show "Item added" popup when returning to checkout from paypal
			$basket->isNewItemAdded();

			$userToken = EshopRegistry::getSession()->getVariable('oepaypal-token');
			$basket = $checkoutModel->processExpressCheckoutDetails($basket, $userToken);
			$user = $basket->getBasketUser();

			EshopRegistry::getSession()->setVariable('usr', $user->getId());
			EshopRegistry::getSession()->setVariable('deladrid', $user->getPayPalDeliveryAddressId());
			EshopRegistry::getSession()->setVariable("oepaypal-userId", $user->getId());
			EshopRegistry::getSession()->setVariable("oepaypal-payerId", $user->getPayPalPayerId());
	        EshopRegistry::getSession()->setVariable("oepaypal-basketAmount", $basket->getPayPalBasketAmount());

			$next = "order";

			$paypalConfig = $container->get(PayPalConfig::class);
			if ($paypalConfig->finalizeOrderOnPayPalSide()) {
				$deliveryAddress = oxNew(EshopAddressModel::class);
				$deliveryAddress->load((string) $user->getPayPalDeliveryAddressId());

				$next .= "?fnc=execute";
				$next .= "&sDeliveryAddressMD5=" . $user->getEncodedDeliveryAddress() .
				         $deliveryAddress->getEncodedDeliveryAddress();
				$next .= "&stoken=" . EshopRegistry::getSession()->getSessionChallengeToken();
			}

			return $next;

		} catch (PaymentNotValidForUserCountry $exception) {
			EshopRegistry::getUtilsView()->addErrorToDisplay( 'MESSAGE_PAYMENT_SELECT_ANOTHER_PAYMENT' );
			
			$next = "payment";
		} catch(ShippingMethodNotValid $exception) {
			EshopRegistry::getUtilsView()->addErrorToDisplay( "OEPAYPAL_SELECT_ANOTHER_SHIPMENT" );

			$next = "order";
		} catch (OrderTotalChanged $exception) {
			EshopRegistry::getUtilsView()->addErrorToDisplay("OEPAYPAL_SELECT_ANOTHER_SHIPMENT");

			$next = "order";
		} catch (EshopStandardException $exception) {
			EshopRegistry::getUtilsView()->addErrorToDisplay($exception);
			$logger = $this->getLogger();
			$logger->log("PayPal error: " . $exception->getMessage());

			$next = "basket";
		}

		return $next;
	}

	/**
	 * Extract requested controller key.
	 * In case the key makes sense (we find a matching class) it will be returned.
	 *
	 * @return mixed|null
	 */
	private function getRequestedControllerKey()
	{
		$return = null;
		$requestedControllerKey = $this->getRequest()->getRequestParameter('oePayPalRequestedControllerKey');
		if (!empty($requestedControllerKey) &&
		    EshopRegistry::getControllerClassNameResolver()->getClassNameById($requestedControllerKey)) {
			$return = $requestedControllerKey;
		}
		return $return;
	}
}
