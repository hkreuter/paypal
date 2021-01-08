<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Model;

use OxidEsales\Eshop\Application\Model\Basket as EshopBasketModel;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\PayPalModule\Core\PayPalService;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;
use OxidEsales\PayPalModule\Core\Logger as PayPalLogger;
use OxidEsales\HRPayPalModule\Model\Tools as PayPalTools;

class PaypalCallback
{
	/** @var PayPalService  */
    private $paypalService;

    /** @var PayPalConfig  */
    private $paypalConfig;

    /** @var PayPalLogger  */
    private $paypalLogger;

	/** @var PayPalTools */
	private $tools;

	public function __construct(
		PayPalService $paypalService,
		PayPalConfig $paypalConfig,
		PayPalTools $tools,
		PayPalLogger $paypalLogger)
	{
		$this->paypalService = $paypalService;
		$this->paypalConfig = $paypalConfig;
		$this->paypalLogger = $paypalLogger;
		$this->tools = $tools;
	}

	public function getCallbackResponse(): string
	{
		$this->setParamsForCallbackResponse($this->paypalService);

		return $this->paypalService->callbackResponse();
	}

    /**
     *  Initialize new user from user data.
     *
     * @param array $data User data array.
     *
     * @return \OxidEsales\Eshop\Application\Model\User
     */
    protected function getCallBackUser($data)
    {
        // simulating user object
        $user = oxNew(EshopUserModel::class);
        $user->initializeUserForCallBackPayPalUser($data);

        return $user;
    }

    /**
     * Sets parameters to PayPal callback
     *
     * @param \OxidEsales\PayPalModule\Core\PayPalService $payPalService PayPal service
     *
     * @return null
     */
    protected function setParamsForCallbackResponse($payPalService)
    {
        //logging request from PayPal
        $logger = $this->paypalLogger;
        $logger->setTitle("CALLBACK REQUEST FROM PAYPAL");
        $logger->log(http_build_query($_REQUEST, "", "&"));

        // initializing user..
        $user = $this->getCallBackUser($_REQUEST);

        // unknown country?
        if (!$this->tools->getUserShippingCountryId($user)) {
            $logger = $this->paypalLogger;
            $logger->log("Callback error: NO SHIPPING COUNTRY ID");

            // unknown country - no delivery
            $this->setPayPalIsNotAvailable($payPalService);

            return;
        }

        //basket
        $session = EshopRegistry::getSession();
        $basket = $session->getBasket();

        // get possible delivery sets
        $delSetList = $this->getDeliverySetList($user);

        //no shipping methods for user country
        if (empty($delSetList)) {
            $logger = $this->paypalLogger;
            $logger->log("Callback error: NO DELIVERY LIST SET");

            $this->setPayPalIsNotAvailable($payPalService);

            return;
        }

        $deliverySetList = $this->makeUniqueNames($delSetList);

        // checking if PayPal is valid payment for selected user country
        if (!$this->isPaymentValidForUserCountry($user)) {
            $logger->log("Callback error: NOT VALID COUNTRY ID");

            // PayPal payment is not possible for user country
            $this->setPayPalIsNotAvailable($payPalService);

            return;
        }

        $session->setVariable('oepaypal-oxDelSetList', $deliverySetList);

        $totalDeliveries = $this->setDeliverySetListForCallbackResponse($payPalService, $deliverySetList, $user, $basket);

        // if none of deliveries contain PayPal - disabling PayPal
        if ($totalDeliveries == 0) {
            $logger->log("Callback error: DELIVERY SET LIST HAS NO PAYPAL");

            $this->setPayPalIsNotAvailable($payPalService);

            return;
        }

        $payPalService->setParameter("OFFERINSURANCEOPTION", "false");
    }

    /**
     * Sets delivery sets parameters to PayPal callback
     *
     * @param \OxidEsales\PayPalModule\Core\PayPalService $payPalService   PayPal service.
     * @param array                                       $deliverySetList Delivery list.
     * @param \OxidEsales\Eshop\Application\Model\User    $user            User object.
     * @param \OxidEsales\Eshop\Application\Model\Basket  $basket          Basket object.
     *
     * @return int Total amount of deliveries
     */
    protected function setDeliverySetListForCallbackResponse($payPalService, $deliverySetList, $user, $basket)
    {
        $maxDeliveryAmount = $this->paypalConfig->getMaxPayPalDeliveryAmount();
        $cur = EshopRegistry::getConfig()->getActShopCurrencyObject();
        $basketPrice = $basket->getPriceForPayment() / $cur->rate;
        $actShipSet = $basket->getShippingId();
        $hasActShipSet = false;
        $cnt = 0;

        // VAT for delivery will be calculated always
        $delVATPercent = $basket->getAdditionalServicesVatPercent();

        foreach ($deliverySetList as $delSetId => $delSetName) {
            // checking if PayPal is valid payment for selected delivery set
            if (!$this->isPayPalInDeliverySet($delSetId, $basketPrice, $user)) {
                continue;
            }

            $deliveryListProvider = oxNew(\OxidEsales\Eshop\Application\Model\DeliveryList::class);
            $deliveryList = array();

            // list of active delivery costs
            if ($deliveryListProvider->hasDeliveries($basket, $user, $this->getUserShippingCountryId($user), $delSetId)) {
                $deliveryList = $deliveryListProvider->getDeliveryList($basket, $user, $this->getUserShippingCountryId($user), $delSetId);
            }

            if (is_array($deliveryList) && !empty($deliveryList)) {
                $price = 0;

                if (EshopRegistry::getConfig()->getConfigParam('bl_perfLoadDelivery')) {
                    foreach ($deliveryList as $delivery) {
                        $price += $delivery->getDeliveryPrice($delVATPercent)->getBruttoPrice();
                    }
                }

                if ($price <= $maxDeliveryAmount) {
                    $payPalService->setParameter("L_SHIPPINGOPTIONNAME{$cnt}", \OxidEsales\Eshop\Core\Str::getStr()->html_entity_decode($delSetName));
                    $payPalService->setParameter("L_SHIPPINGOPTIONLABEL{$cnt}", EshopRegistry::getLang()->translateString("OEPAYPAL_PRICE"));
                    $payPalService->setParameter("L_SHIPPINGOPTIONAMOUNT{$cnt}", $this->formatFloat($price));

                    //setting active delivery set
                    if ($delSetId == $actShipSet) {
                        $hasActShipSet = true;
                        $payPalService->setParameter("L_SHIPPINGOPTIONISDEFAULT{$cnt}", "true");
                    } else {
                        $payPalService->setParameter("L_SHIPPINGOPTIONISDEFAULT{$cnt}", "false");
                    }

                    if ($basket->isCalculationModeNetto()) {
                        $payPalService->setParameter("L_TAXAMT{$cnt}", $this->formatFloat($basket->getPayPalBasketVatValue()));
                    } else {
                        $payPalService->setParameter("L_TAXAMT{$cnt}", $this->formatFloat(0));
                    }
                }

                $cnt++;
            }
        }

        //checking if active delivery set was set - if not, setting first in the list
        if ($cnt > 0 && !$hasActShipSet) {
            $payPalService->setParameter("L_SHIPPINGOPTIONISDEFAULT0", "true");
        }

        return $cnt;
    }


	/**
	 * Returns PayPal user
	 *
	 * @return \OxidEsales\Eshop\Application\Model\User
	 */
	protected function getPayPalUser()
	{
		$user = oxNew(\OxidEsales\Eshop\Application\Model\User::class);
		if (!$user->loadUserPayPalUser()) {
			$user = $this->getUser();
		}

		return $user;
	}

	/**
	 * Disables PayPal payment in PayPal side
	 *
	 * @param \OxidEsales\PayPalModule\Core\PayPalService $payPalService PayPal service.
	 */
	protected function setPayPalIsNotAvailable($payPalService)
	{
		// "NO_SHIPPING_OPTION_DETAILS" works only in version 61, so need to switch version
		$payPalService->setParameter("CALLBACKVERSION", "61.0");
		$payPalService->setParameter("NO_SHIPPING_OPTION_DETAILS", "1");
	}

}
