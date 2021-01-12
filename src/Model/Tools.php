<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Model;

use OxidEsales\PayPalModule\Core\Config as PayPalConfig;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;

class Tools
{
	/** @var PayPalConfig  */
	private $paypalConfig;

	public function __construct(PayPalConfig $paypalConfig)
	{
		$this->paypalConfig = $paypalConfig;
	}

	public function makeUniqueNames(array $deliverySetList): array
	{
		$result = array();
		$nameCounts = array();

		foreach ($deliverySetList as $deliverySet) {
			$deliverySetName = trim($deliverySet->oxdeliveryset__oxtitle->value);

			if (isset($nameCounts[$deliverySetName])) {
				$nameCounts[$deliverySetName] += 1;
			} else {
				$nameCounts[$deliverySetName] = 1;
			}

			$suffix = ($nameCounts[$deliverySetName] > 1) ? " (" . $nameCounts[$deliverySetName] . ")" : '';
			$result[$deliverySet->oxdeliveryset__oxid->value] = $this->reencodeHtmlEntities($deliverySetName . $suffix);
		}

		return $result;
	}

	public function getUserShippingCountryId(EshopUserModel $user):string
	{
		if ($user->getSelectedAddressId() && $user->getSelectedAddress()) {
			$countryId = $user->getSelectedAddress()->oxaddress__oxcountryid->value;
		} else {
			$countryId = $user->oxuser__oxcountryid->value;
		}

		return $countryId;
	}

	private function reencodeHtmlEntities(string $input): string
	{
		$charset = $this->paypalConfig->getCharset();

		return htmlentities(html_entity_decode($input, ENT_QUOTES, $charset), ENT_QUOTES, $charset);
	}

}