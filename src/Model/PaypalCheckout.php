<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Model;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\Eshop\Application\Model\Basket as EshopBasketModel;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;

use OxidEsales\PayPalModule\Model\PayPalRequest\GetExpressCheckoutDetailsRequestBuilder;
use OxidEsales\PayPalModule\Model\PaymentValidator as PayPalPaymentValidator;
use OxidEsales\PayPalModule\Core\Exception\PayPalException;
use OxidEsales\PayPalModule\Core\PayPalService;

use OxidEsales\HRPayPalModule\Service\PaypalConfiguration;
use OxidEsales\HRPayPalModule\Exception\PaymentNotValidForUserCountry;
use OxidEsales\HRPayPalModule\Exception\ShippingMethodNotValid;
use OxidEsales\HRPayPalModule\Exception\OrderTotalChanged;
use OxidEsales\HRPayPalModule\Service\PaypalOrder;
use OxidEsales\Eshop\Core\Exception\StandardException as EshopStandardException;

use OxidEsales\PayPalModule\Model\Response\ResponseGetExpressCheckoutDetails;
use OxidEsales\Eshop\Application\Model\Payment as EshopPaymentModel;
use OxidEsales\Eshop\Application\Model\DeliverySetList as EshopDeliverySetListModel;

use OxidEsales\HRPayPalModule\Model\Tools as PayPalTools;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;


class PaypalCheckout
{

	/** @var PaypalOrder  */
	private $paypalOrder;

	/** @var PaypalConfiguration  */
    private $paypalConfiguration;

    /** @var PayPalTools */
    private $tools;

	/** @var PaypalConfig */
	private $paypalConfig;

	public function __construct(
		PaypalConfiguration $paypalConfiguration,
		PaypalOrder $paypalOrder,
		PayPalTools $tools,
		PayPalConfig $paypalConfig
	)
	{
		$this->paypalConfiguration = $paypalConfiguration;
		$this->paypalOrder = $paypalOrder;
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
	    $token = $this->paypalOrder->getUserToken($requestId, $formattedTotal, $basket->getBasketCurrency()->name);

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
    public function getExpressCheckoutDetails(EshopBasketModel $basket): string
    {
        $payPalService = $this->paypalService;
        $builder = oxNew(GetExpressCheckoutDetailsRequestBuilder::class);
        $builder->setSession(EshopRegistry::getSession());
        $request = $builder->buildRequest();
        $details = $payPalService->getExpressCheckoutDetails($request);

        // Remove flag of "new item added" to not show "Item added" popup when returning to checkout from paypal
        $basket->isNewItemAdded();

        // creating new or using session user
        $user = $this->initializeUserData($details);

        if (!$this->isPaymentValidForUserCountry($user)) {
            throw new PaymentNotValidForUserCountry();
        }

        $shippingId = $this->extractShippingId(urldecode($details->getShippingOptionName()), $user);
        $this->setAnonymousUser($basket, $user);
        $basket->setShipping($shippingId);
        $basket->onUpdate();
        $basket->calculateBasket(true);
        $basketPrice = $basket->getPrice()->getBruttoPrice();

        if (!$this->isPayPalPaymentValid($user, $basketPrice, $basket->getShippingId())) {
            throw new ShippingMethodNotValid();
        }

        // Checking if any additional discount was applied after we returned from PayPal.
        if ($basketPrice != $details->getAmount()) {
            throw new OrderTotalChanged();
        }

	    EshopRegistry::getSession()->setVariable("oepaypal-payerId", $details->getPayerId());
	    EshopRegistry::getSession()->setVariable("oepaypal-userId", $user->getId());
	    EshopRegistry::getSession()->setVariable("oepaypal-basketAmount", $details->getAmount());

        $next = "order";

        if ($this->paypalConfig->finalizeOrderOnPayPalSide()) {
            $next .= "?fnc=execute";
            $next .= "&sDeliveryAddressMD5=" . $user->getEncodedDeliveryAddress();
            $next .= "&stoken=" . EshopRegistry::getSession()->getSessionChallengeToken();
        }

        return $next;
    }

	protected function initializeUserData(ResponseGetExpressCheckoutDetails $details): EshopUserModel
	{
		$userEmail = $details->getEmail();
		$loggedUser = EshopRegistry::getSession()->getUser();
		if ($loggedUser) {
			$userEmail = $loggedUser->oxuser__oxusername->value;
		}

		/** @var EshopUserModel $user */
		$user = oxNew(EshopUserModel::class);
		if ($userId = $user->isRealPayPalUser($userEmail)) {
			// if user exist
			$user->load($userId);

			if (!$loggedUser) {
				if (!$user->isSamePayPalUser($details)) {
					$exception = new EshopStandardException();
					$exception->setMessage('OEPAYPAL_ERROR_USER_ADDRESS');
					throw $exception;
				}
			} elseif (!$user->isSameAddressUserPayPalUser($details) || !$user->isSameAddressPayPalUser($details)) {
				// user has selected different address in PayPal (not equal with usr shop address)
				// so adding PayPal address as new user address to shop user account
				$this->createUserAddress($details, $userId);
			} else {
				// removing custom shipping address ID from session as user uses billing
				// address for shipping
				EshopRegistry::getSession()->deleteVariable('deladrid');
			}
		} else {
			$user->createPayPalUser($details);
		}

		EshopRegistry::getSession()->setVariable('usr', $user->getId());

		return $user;
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
            $outOfStockValidator = new \OxidEsales\PayPalModule\Model\OutOfStockValidator();
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
     * Creates user address and sets address id into session
     *
     * @param \OxidEsales\PayPalModule\Model\Response\ResponseGetExpressCheckoutDetails $details User address info.
     * @param string                                                                    $userId  User id.
     *
     * @return bool
     */
    protected function createUserAddress($details, $userId)
    {
        $address = oxNew(\OxidEsales\Eshop\Application\Model\Address::class);

        return $address->createPayPalAddress($details, $userId);
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

	protected function getReturnUrl(string $controllerKey): string
	{
		return EshopRegistry::getSession()->processUrl($this->baseUrl . "&cl=" . $controllerKey . "&fnc=getExpressCheckoutDetails");
	}

	protected function getCancelUrl(string $controllerKey): string
	{
		$cancelURLFromRequest = EshopRegistry::getRequest()->getRequestParameter('oePayPalCancelURL');
		$cancelUrl = EshopRegistry::getSession()->processUrl($this->baseUrl . "&cl=basket");

		if ($cancelURLFromRequest) {
			$cancelUrl = html_entity_decode(urldecode($cancelURLFromRequest));
		} elseif ($requestedControllerKey = $this->getRequestedControllerKey()) {
			$cancelUrl = EshopRegistry::getSession()->processUrl($this->baseUrl . '&cl=' . $requestedControllerKey);
		}

		return $cancelUrl;
	}

	protected function getCallBackUrl()
	{
		return EshopRegistry::getSession()->processUrl($this->baseUrl . "&cl=oepaypalcallback&fnc=processCallBack");
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
