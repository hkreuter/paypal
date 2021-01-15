<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Core;

use stdClass;
use OxidEsales\Eshop\Application\Model\State as EshopStateModel;
use OxidEsales\Eshop\Application\Model\Country as EshopCountryModel;

class PaypalExpressAddress
{
	/** @var array */
	private $fields;

	/** @var stdClass */
	private $details;

	/** @var stdClass */
	private $name;

	public function __construct(stdClass $details)
	{
		$this->details = $details->purchase_units[0]->shipping->address;
		$this->name = $details->purchase_units[0]->shipping->name;
		$this->fields = $this->getAddressFields();
	}

	public function getFieldData(string $name): string
	{
        return array_key_exists($name, $this->fields) ? $this->fields[$name] : '';
	}

	public function getData(): array
	{
		return $this->fields;
	}

	private function getAddressFields(): array
	{
		$names = $this->getNames();
		$address = $this->getStreetAndNumber();
		$countryId = $this->getCountryId();

		$shopDigestableFields = [
			'oxcompany'   => '',
			'oxfname'     => $names['first'],
			'oxlname'     => $names['last'],
			'oxstreet'    => $address['street'],
			'oxstreetnr'  => $address['streetnr'],
			'oxaddinfo'   => $this->getAdditionalAddressInformation(),
			'oxcity'      => $this->getCity(),
			'oxcountryid' => $countryId,
			'oxstateid'   => $this->getStateId($countryId),
			'oxzip'       => $this->getPostalCode(),
			'oxfon'       => '',
			'oxfax'       => '',
			'oxsal'       => '',
		];

        return $shopDigestableFields;
	}

	private function getCountryId(): string
	{
		$countryModel = oxNew(EshopCountryModel::class);
		return (string) $countryModel->getIdByCode($this->getCountryCode());
	}

	private function getStateId(string $countryId): string
	{
		$stateId = '';
		if ($state = $this->getState()) {
			$stateModel = oxNew(EshopStateModel::class);
			$stateId = $stateModel->getIdByCode($state, $countryId);
		}

		return $stateId;
	}

	private function getNames(): array
	{
		$fullName = property_exists($this->name, 'full_name') ? (string) $this->name->full_name : '';
		$names = explode(' ', $fullName);
		$lastName = array_pop($names);

		$names = [
			'first' => implode('', $names),
			'last'  => $lastName
		];

		return $names;
	}

	private function getCountryCode()
	{
		return property_exists($this->details, 'country_code') ? (string) $this->details->country_code : '';
	}

	private function getPostalCode(): string
	{
		return property_exists($this->details, 'postal_code') ? (string) $this->details->postal_code : '';
	}

	private function getCity(): string
	{
		return property_exists($this->details, 'admin_area_2') ? (string) $this->details->admin_area_2 : '';
	}

	private function getState(): string
	{
		return property_exists($this->details, 'admin_area_1') ? (string) $this->details->admin_area_1 : '';
	}

	private function getAdditionalAddressInformation(): string
	{
		return property_exists($this->details, 'address_line_2') ? (string) $this->details->address_line_2 : '';
	}

	private function getStreetAndNumber(): array
	{
		$address = [];
		$raw = property_exists($this->details, 'address_line_1') ? (string) $this->details->address_line_1 : '';
		$shipToStreet = trim($raw);

		//search for street number at end of address_line_1
		preg_match("/(.*\S)\s+(\d+\s*\S*)$/", $shipToStreet, $address);

		// checking if street name and number was found
		if (!empty($address[1]) && $address[2]) {
			$address['street'] = $address[1];
			$address['streetnr'] = $address[2];

			return $address;
		}

		// checking if street number is at the begining of address_line_1
		preg_match("/(\d+\S*)\s+(.*)$/", $shipToStreet, $address);

		// checking if street name and number was found
		if (!empty($address[1]) && $address[2]) {
			$address['street'] = $address[2];
			$address['streetnr'] = $address[1];

			return $address;
		}

		// it is not possible to resolve address, so assign it without any parsing
		$address['street'] = $shipToStreet;
		$address['streetnr'] = "";

		return $address;
	}
}