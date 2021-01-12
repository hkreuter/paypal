<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Controller;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\PayPalModule\Core\PayPalService;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;
use OxidEsales\HRPayPalModule\Model\Tools as PayPalTools;
use OxidEsales\HRPayPalModule\Model\PaypalCheckout;
use OxidEsales\HRPayPalModule\Exception\PaymentNotValidForUserCountry;
use OxidEsales\HRPayPalModule\Exception\ShippingMethodNotValid;
use OxidEsales\HRPayPalModule\Exception\OrderTotalChanged;
use OxidEsales\Eshop\Core\Exception\StandardException as EshopStandardException;

/**
 * PayPal Express Checkout Controller class
 */
class ExpressCheckoutController extends \OxidEsales\Eshop\Application\Controller\FrontendController
{
	/**
	 * Default user action for checkout process
	 *
	 * @var string
	 */
	private $userAction = "continue";
	
	public function setExpressCheckout()
    {
        $session = EshopRegistry::getSession();
        $session->setVariable("oepaypal", "2");

        try {
        	/** @var PayPalConfig $paypalConfig */
	        $paypalConfig = oxNew(PayPalConfig::class);

	        /** @var PaypalCheckout $checkoutModel */
            $checkoutModel = oxNew(
            	PaypalCheckout::class,
	            oxNew(PayPalService::class),
	            $paypalConfig,
	            oxNew(PayPalTools::class, $paypalConfig),
	            $this->getBaseUrl()
            );
            $basket = EshopRegistry::getSession()->getBasket();
            $user = EshopRegistry::getSession()->getUser() ? EshopRegistry::getSession()->getUser() : null;
	        $controllerKey = EshopRegistry::getControllerClassNameResolver()->getIdByClassName(get_class());

	        $paypalToken = $checkoutModel->setExpressCheckout($basket, $user, $controllerKey);

	        $session->setVariable("oepaypal-token", $paypalToken);
	        EshopRegistry::getSession()->setVariable('paymentid', "oxidpaypal");
	        EshopRegistry::getSession()->getBasket()->setPayment("oxidpaypal");

	        $redirectUrl = $checkoutModel->getRedirectToPayPalUrl($paypalToken, $this->userAction);
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
		/** @var PayPalConfig $paypalConfig */
		$paypalConfig = oxNew(PayPalConfig::class);

		/** @var PaypalCheckout $checkoutModel */
		$checkoutModel = oxNew(
			PaypalCheckout::class,
			oxNew(PayPalService::class),
			$paypalConfig,
			oxNew(PayPalTools::class, $paypalConfig),
			$this->getBaseUrl()
		);

		try {
			$next = $checkoutModel->getExpressCheckoutDetails(EshopRegistry::getSession()->getBasket());

		} catch (PaymentNotValidForUserCountry $exception) {
			EshopRegistry::getUtilsView()->addErrorToDisplay( 'MESSAGE_PAYMENT_SELECT_ANOTHER_PAYMENT' );
			$logger = $this->getLogger();
			$logger->log( "Shop error: PayPal payment validation by user country failed. Payment is not valid for this country." );

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

	private function getBaseUrl()
	{
		$url = EshopRegistry::getConfig()->getSslShopUrl() . "index.php?lang=" .
		       EshopRegistry::getLang()->getBaseLanguage() . "&sid=" . EshopRegistry::getSession()->getId() .
		       "&rtoken=" . EshopRegistry::getSession()->getRemoteAccessToken();
		$url .= "&shp=" . EshopRegistry::getConfig()->getShopId();

		return $url;
	}
}
