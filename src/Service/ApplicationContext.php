<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Service;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;

class ApplicationContext
{
	public function getTransactionMode()
	{
		return EshopRegistry::getConfig()->getConfigParam('sOEPayPalTransactionMode');
	}

	public function getReturnUrl(string $controllerKey): string
	{
		return EshopRegistry::getSession()->processUrl(
			$this->getBaseUrl() . "&cl=" . $controllerKey . "&fnc=getExpressCheckoutDetails"
		);
	}

	public function getCancelUrl(string $controllerKey): string
	{
		$cancelURLFromRequest = EshopRegistry::getRequest()->getRequestParameter('oePayPalCancelURL');
		$cancelUrl = EshopRegistry::getSession()->processUrl($this->getBaseUrl() . "&cl=basket");

		if ($cancelURLFromRequest) {
			$cancelUrl = html_entity_decode(urldecode($cancelURLFromRequest));
		} elseif ($requestedControllerKey = $this->getRequestedControllerKey()) {
			$cancelUrl = EshopRegistry::getSession()->processUrl($this->getBaseUrl() . '&cl=' . $requestedControllerKey);
		}

		return $cancelUrl;
	}

	public function getCallBackUrl()
	{
		return EshopRegistry::getSession()->processUrl($this->getBaseUrl()  . "&cl=oepaypalcallback&fnc=processCallBack");
	}

	public function getLocaleCode(): string
	{
		return str_replace('_', '-', EshopRegistry::getLang()->translateString("OEPAYPAL_LOCALE"));
	}

	public function getPayPalLandingPage(): string
	{
		return 'LOGIN';
	}

	public function getPayPalUserAction(): string
	{
		return 'CONTINUE';
	}

	public function getPayPalShippingPreference(): string
	{
		return 'NO_SHIPPING';
	}
	
	private function getBaseUrl(): string
	{
		$url = EshopRegistry::getConfig()->getSslShopUrl() . "index.php?lang=" .
		       EshopRegistry::getLang()->getBaseLanguage() .
		       "&sid=" . EshopRegistry::getSession()->getId() .
		       "&rtoken=" . EshopRegistry::getSession()->getRemoteAccessToken() .
		       "&shp=" . EshopRegistry::getConfig()->getShopId();

		return $url;
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
		$requestedControllerKey = EshopRegistry::getRequest()->getRequestParameter('oePayPalRequestedControllerKey');
		if (!empty($requestedControllerKey) &&
		    EshopRegistry::getControllerClassNameResolver()->getClassNameById($requestedControllerKey)) {
			$return = $requestedControllerKey;
		}
		return $return;
	}
}