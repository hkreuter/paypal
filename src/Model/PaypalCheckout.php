<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Model;

use stdClass;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\Eshop\Application\Model\Basket as EshopBasketModel;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;
use OxidEsales\Eshop\Application\Model\Payment as EshopPaymentModel;
use OxidEsales\Eshop\Application\Model\DeliverySetList as EshopDeliverySetListModel;
use OxidEsales\Eshop\Core\Exception\StandardException as EshopStandardException;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;
use OxidEsales\PayPalModule\Model\OutOfStockValidator as PayPalOutOfStockValidator;
use OxidEsales\PayPalModule\Model\PaymentValidator as PayPalPaymentValidator;
use OxidEsales\PayPalModule\Core\Exception\PayPalException;
use OxidEsales\HRPayPalModule\Core\PaypalExpressUser;
use OxidEsales\HRPayPalModule\Core\PaypalExpressAddress;
use OxidEsales\HRPayPalModule\Service\Tools as PayPalTools;
use OxidEsales\HRPayPalModule\Service\PaypalConfiguration;
use OxidEsales\HRPayPalModule\Service\PaypalOrder;
use OxidEsales\HRPayPalModule\Service\PaypalOrderDetails;
use OxidEsales\HRPayPalModule\Exception\PaymentNotValidForUserCountry;
use OxidEsales\HRPayPalModule\Exception\ShippingMethodNotValid;
use OxidEsales\HRPayPalModule\Exception\OrderTotalChanged;
use OxidEsales\HRPayPalModule\Exception\OrderError;

class PaypalCheckout
{
	/** @var PaypalOrder  */
	private $paypalOrder;

	/** @var PaypalOrderDetails  */
	private $paypalOrderDetails;

	/** @var PaypalConfiguration  */
    private $paypalConfiguration;

    /** @var PayPalTools */
    private $tools;

	/** @var PaypalConfig */
	private $paypalConfig;

	public function __construct(
		PaypalConfiguration $paypalConfiguration,
		PaypalOrder $paypalOrder,
		PaypalOrderDetails $paypalOrderDetails,
		PayPalTools $tools,
		PayPalConfig $paypalConfig
	)
	{
		$this->paypalConfiguration = $paypalConfiguration;
		$this->paypalOrder = $paypalOrder;
		$this->paypalOrderDetails = $paypalOrderDetails;
		$this->tools = $tools;
		$this->paypalConfig = $paypalConfig;
	}

	public function setExpressCheckout(
		EshopBasketModel $basket,
		EshopUserModel $user = null,
	    string $requestId
	): string
    {
	    $basket = $this->prepareBasket($basket);

	    if (!$this->isValidPayPalPayment($basket, $user)) {
		    /** @var PayPalException $exception */
		    $exception = oxNew(PayPalException::class);
		    $exception->setMessage(EshopRegistry::getLang()->translateString("OEPAYPAL_PAYMENT_NOT_VALID"));
		    throw $exception;
	    }

	    $formattedTotal = sprintf("%.2f", $basket->getPrice()->getBruttoPrice());
	    $token = $this->paypalOrder->getUserToken(
	    	$requestId,
		    $formattedTotal,
		    $basket->getBasketCurrency()->name,
		    $this->getTransactionMode($basket)
	    );
	    \OxidEsales\Eshop\Core\Registry::getLogger()->error($token);

	    return $token;
    }

    public function getRedirectToPayPalUrl(string $paypalToken): string
    {
    	return $this->paypalConfiguration->getPayPalCheckoutNowUrl($paypalToken);
    }

	/**
     * Executes "GetExpressCheckoutDetails" and on SUCCESS response - saves
     * user information and redirects to order page, on failure - sets error
     * message and redirects to basket page
     *
     * @return string
     */
    public function processExpressCheckoutDetails(EshopBasketModel $basket, string $userToken): string
    {
	    $details = $this->paypalOrderDetails->getOrderDetails($userToken);

	    $this->validateExpressCheckoutDetails($details);

	    /** @var PaypalExpressAddress $paypalExpressAddress */
	    $paypalExpressAddress = new PaypalExpressAddress($details);

        /** @var PaypalExpressUser $userHandler */
        $userHandler = new PaypalExpressUser($details->payer, $paypalExpressAddress);
	    $sessionUser = EshopRegistry::getSession()->getUser();
	    $sessionUser = $sessionUser ?: null;
	    $user = $userHandler->getUser($sessionUser);

	    EshopRegistry::getSession()->setVariable('usr', $user->getId());

	    if (!$this->isPaymentValidForUserCountry($user)) {
            throw new PaymentNotValidForUserCountry();
        }

        //TODO: is there any way to chose the shipping on PP side in the PP rest api?
	    $shippingId = 'oxidstandard';

        $this->setAnonymousUser($basket, $user);
        $basket->setShipping($shippingId);
        $basket->onUpdate();
        $basket->calculateBasket(true);
        $basketPrice = $basket->getPrice()->getBruttoPrice();

        if (!$this->isPayPalPaymentValid($user, $basketPrice, $basket->getShippingId())) {
            throw new ShippingMethodNotValid();
        }

        // Checking if any additional discount was applied after we returned from PayPal.
        if ($basketPrice != $details->purchase_units[0]->amount) {
            throw new OrderTotalChanged();
        }

	    EshopRegistry::getSession()->setVariable("oepaypal-payerId", $details->payer->payer_id);
	    EshopRegistry::getSession()->setVariable("oepaypal-userId", $user->getId());
	    EshopRegistry::getSession()->setVariable("oepaypal-basketAmount", $details->purchase_units[0]->amount->value);

        $next = "order";

        if ($this->paypalConfig->finalizeOrderOnPayPalSide()) {
            $next .= "?fnc=execute";
            $next .= "&sDeliveryAddressMD5=" . $user->getEncodedDeliveryAddress();
            $next .= "&stoken=" . EshopRegistry::getSession()->getSessionChallengeToken();
        }

        return $next;
    }

    private function validateExpressCheckoutDetails(stdClass $details): void
    {
	    $status = property_exists($details, 'status') ? $details->status : 'UNKNOWN';
	    if ('APPROVED' !== $status) {
		    throw OrderError::byDetailsStatus($status);
	    }
	    if (!property_exists($details, 'payer')) {
		    throw OrderError::byDetailsPayer();
	    }
	    if (!property_exists($details, 'purchase_units') || !is_array($details->purchase_units)) {
		    throw OrderError::byDetails('purchase_units missing');
	    }
    }

	/**
     * Returns transaction mode.
     *
     * @param \OxidEsales\Eshop\Application\Model\Basket $basket
     *
     * @return string
     */
    protected function getTransactionMode($basket)
    {
        $transactionMode = $this->paypalConfig->getTransactionMode();

        if ($transactionMode == "Automatic") {
            $outOfStockValidator = new PayPalOutOfStockValidator();
            $outOfStockValidator->setBasket($basket);
            $outOfStockValidator->setEmptyStockLevel($this->paypalConfig->getEmptyStockLevel());

            $transactionMode = ($outOfStockValidator->hasOutOfStockArticles()) ? "Authorization" : "Sale";

            return $transactionMode;
        }

        return $transactionMode;
    }

    /**
     * Extracts shipping id from given parameter
     *
     * @param string                                   $shippingOptionName Shipping option name, which comes from PayPal.
     * @param \OxidEsales\Eshop\Application\Model\User $user               User object.
     *
     * @return string
     */
    protected function extractShippingId($shippingOptionName, $user)
    {
        $result = null;
        $session = EshopRegistry::getSession();

        $shippingOptionName = $this->reencodeHtmlEntities($shippingOptionName);
        $name = trim(str_replace(EshopRegistry::getLang()->translateString("OEPAYPAL_PRICE"), "", $shippingOptionName));

        $deliverySetList = $session->getVariable("oepaypal-oxDelSetList");

        if (!$deliverySetList) {
            $delSetList = $this->getDeliverySetList($user);
            $deliverySetList = $this->tools->makeUniqueNames($delSetList);
        }

        if (is_array($deliverySetList)) {
            $flipped = array_flip($deliverySetList);
            $result = $flipped[$name];
        }

        return $result;
    }

    /**
     * Checking if PayPal payment is available in user country
     *
     * @param \OxidEsales\Eshop\Application\Model\User $user User object.
     *
     * @return boolean
     */
    protected function isPaymentValidForUserCountry($user)
    {
        $payment = oxNew(\OxidEsales\Eshop\Application\Model\Payment::class);
        $payment->load("oxidpaypal");
        $paymentCountries = $payment->getCountries();

        if (!is_array($paymentCountries) || empty($paymentCountries)) {
            // not assigned to any country - valid to all countries
            return true;
        }

        return in_array($this->getUserShippingCountryId($user), $paymentCountries);
    }

    /**
     * Checks if selected delivery set has PayPal payment.
     *
     * @param string                                   $delSetId    Delivery set ID.
     * @param double                                   $basketPrice Basket price.
     * @param \OxidEsales\Eshop\Application\Model\User $user        User object.
     *
     * @return boolean
     */
    protected function isPayPalInDeliverySet($delSetId, $basketPrice, $user)
    {
        $paymentList = EshopRegistry::get(\OxidEsales\Eshop\Application\Model\PaymentList::class);
        $paymentList = $paymentList->getPaymentList($delSetId, $basketPrice, $user);

        if (is_array($paymentList) && array_key_exists("oxidpaypal", $paymentList)) {
            return true;
        }

        return false;
    }

    /**
     * Get delivery set list for PayPal callback
     *
     * @param \OxidEsales\Eshop\Application\Model\User $user User object.
     *
     * @return array
     */
    protected function getDeliverySetList($user)
    {
        $delSetList = oxNew(EshopDeliverySetListModel::class);

        return $delSetList->getDeliverySetList($user, $this->getUserShippingCountryId($user));
    }

    /**
     * Returns user shipping address country id.
     *
     * @param \OxidEsales\Eshop\Application\Model\User $user
     *
     * @return string
     */
    protected function getUserShippingCountryId($user)
    {
        if ($user->getSelectedAddressId() && $user->getSelectedAddress()) {
            $countryId = $user->getSelectedAddress()->oxaddress__oxcountryid->value;
        } else {
            $countryId = $user->oxuser__oxcountryid->value;
        }

        return $countryId;
    }

    /**
     * Checks whether PayPal payment is available
     *
     * @param \OxidEsales\Eshop\Application\Model\User $user
     * @param double                                   $basketPrice
     * @param string                                   $shippingId
     *
     * @return bool
     */
    protected function isPayPalPaymentValid($user, $basketPrice, $shippingId)
    {
        $valid = true;

        /** @var EshopPaymentModel $payPalPayment */
        $payPalPayment = oxNew(EshopPaymentModel::class);
        $payPalPayment->load('oxidpaypal');
        if (!$payPalPayment->isValidPayment(null, null, $user, $basketPrice, $shippingId)) {
            $valid = $this->isEmptyPaymentValid($user, $basketPrice, $shippingId);
        }

        return $valid;
    }

    /**
     * Checks whether Empty payment is available.
     *
     * @param \OxidEsales\Eshop\Application\Model\User $user
     * @param double                                   $basketPrice
     * @param string                                   $shippingId
     *
     * @return bool
     */
    protected function isEmptyPaymentValid($user, $basketPrice, $shippingId)
    {
        $valid = true;

        $emptyPayment = oxNew(\OxidEsales\Eshop\Application\Model\Payment::class);
        $emptyPayment->load('oxempty');
        if (!$emptyPayment->isValidPayment(null, null, $user, $basketPrice, $shippingId)) {
            $valid = false;
        }

        return $valid;
    }
	
	/**
     * PayPal express checkout might be called before user is set to basket.
     * This happens if user is not logged in to the Shop
     * and it goes to PayPal from details page or basket first step.
     *
     * @param \OxidEsales\Eshop\Application\Model\Basket $basket
     * @param \OxidEsales\Eshop\Application\Model\User   $user
     */
    private function setAnonymousUser($basket, $user)
    {
        $basket->setBasketUser($user);
    }

    /**
     * @param string $input
     */
    private function reencodeHtmlEntities($input)
    {
        $charset = $this->paypalConfig->getCharset();

        return htmlentities(html_entity_decode($input, ENT_QUOTES, $charset), ENT_QUOTES, $charset);
    }

	private function prepareBasket(EshopBasketModel $basket): EshopBasketModel
	{
		$prevOptionValue = EshopRegistry::getConfig()->getConfigParam('blCalculateDelCostIfNotLoggedIn');
		if ($this->paypalConfig->isDeviceMobile()) {
			if ($this->paypalConfig->getMobileECDefaultShippingId()) {
				EshopRegistry::getConfig()->setConfigParam('blCalculateDelCostIfNotLoggedIn', true);
				$basket->setShipping($this->paypalConfig->getMobileECDefaultShippingId());
			} else {
				EshopRegistry::getConfig()->setConfigParam('blCalculateDelCostIfNotLoggedIn', false);
			}
		}

		$basket->onUpdate();
		$basket->calculateBasket(true);
		EshopRegistry::getConfig()->setConfigParam('blCalculateDelCostIfNotLoggedIn', $prevOptionValue);

		return $basket;
	}

	private function isValidPayPalPayment(EshopBasketModel $basket, EshopUserModel $user = null): bool
	{
		$validator = oxNew(PayPalPaymentValidator::class);
		$validator->setUser($user);
		$validator->setConfig(EshopRegistry::getConfig());
		$validator->setPrice($basket->getPrice()->getPrice());
		$validator->setCheckCountry(false);

		return $validator->isPaymentValid();
	}
}
