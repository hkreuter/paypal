<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Core;

use stdClass;
use OxidEsales\Eshop\Core\Str as EshopCoreString;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;
use OxidEsales\HRPayPalModule\Exception\OrderError;

class PaypalExpressUser
{
	/** @var  stdClass */
	private $details;

	/** @var PaypalExpressAddress */
	private $address;

	public function __construct(stdClass $details, PaypalExpressAddress $address)
	{
		$this->details = $details;
		$this->address = $address;
	}

	/**
     * The following cases are possible:
	 * - we have no session and
	 *   - paypal payer is not found in oxuser table
	 *     -> create user, use paypal shipping address as invoice
	 *        TODO: what happens if ship to name does not match payer?
	 *   - paypal payer is found in oxuser table
	 *     -> check if ship to address matches any address related to this user, if not create address and
	 *        use as delivery address
	 * - we have a logged in (session or jwt) user
	 *   - session user email is used disregarding of paypal payer email
	 *   - if ship to name and address match invoice, use invice address
	 *   - if not create new address and use for delivery
	 */
	public function getUser(EshopUserModel $sessionUser = null): EshopUserModel
	{
		//TODO: user might use different email address in paypal and eshop

		//in case no session user is found, we need to load user from PayPal details
		if (!$sessionUser) {
			/** @var EshopUserModel $user */
			$userEmail = $this->details->email_address;
			$user = oxNew(EshopUserModel::class);
			$userId = $user->isRealPayPalUser($userEmail);
			$user = $user->load($userId) ?: null;
		} else {
			$user = $sessionUser;
		}

		if ($user) {
            $this->handleExistingUser($user);
		} else {
			$user = $this->createUserFromDetails();
			//TODO: what happens if payer details differ from ship to name?
		}

		return $user;
	}

	private function createUserFromDetails(): EshopUserModel
	{
		$newUser = oxNew(EshopUserModel::class);
		$newUser->assign($this->address->getData());

		$newUser->assign(
			[
				'oxactive'    => '1',
				'oxusername'  => $this->details->email_address,
				'oxfname'     => $this->details->name->given_name,
				'oxlname'     => $this->details->name->surname,
				'oxpassword'  => '',
				'oxbirthdate' => ''
			]
		);

		if ($newUser->save()) {
			$newUser->ensureAutoGroups($newUser->getFieldData('oxcountryid'));
			$newUser->addToGroup("oxidnotyetordered");
		}

		return $newUser;
	}

	private function handleExistingUser(EshopUserModel $user)
	{
		//no logged in (session) user found, user found in oxuser table and we have data mismatch
		if (!$this->isSamePayPalUser($user) && !$this->isSameAddressPayPalUser($user)) {
		    throw OrderError::byDetails('OEPAYPAL_ERROR_USER_ADDRESS');
		}

		if (!$this->userNameEqualsPayPalShipToName($user) || !$this->isSameAddressPayPalUser($user)) {
			// user has selected different address in PayPal (not equal with usr shop address)
			// so adding PayPal address as new user address to shop user account
			$this->createUserAddress($user);
		} else {
			// removing custom shipping address ID from session as user uses billing
			// address for shipping
			//TODO
			EshopRegistry::getSession()->deleteVariable('deladrid');
		}
	}

	private function createUserAddress(EshopUserModel $user): void
	{
		//TODO
	}

	/**
	 * Check if the ship to user name is the same in shop and PayPal.
	 */
	private function userNameEqualsPayPalShipToName(EshopUserModel $user): bool
	{
		$fullUserName = EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxfname')) . ' ' .
		                EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxlname'));

		return $fullUserName == $this->address->getFieldData('oxfname') . ' ' . $this->address->getFieldData('oxlname');
	}

	private function isSamePayPalUser(EshopUserModel $user): bool
	{
		$userData = [];
		$userData[] = EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxfname'));
		$userData[] = EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxlname'));

		$compareData = [];
		$compareData[] = $this->details->given_name;
		$compareData[] = $this->details->surname;

		return (($userData == $compareData) && $this->isSameAddressPayPalUser($user));
	}

	private function isSameAddressPayPalUser(EshopUserModel $user)
	{
		$userData = [];
		$userData[] = EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxstreet'));
		$userData[] = EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxstreetnr'));
		$userData[] = EshopCoreString::getStr()->html_entity_decode($user->getFieldData('oxcity'));

		$compareData = [];
		$compareData[] = $this->address->getFieldData('oxstreet');
		$compareData[] = $this->address->getFieldData('oxstreetnr');
		$compareData[] = $this->address->getFieldData('oxstreet');

		return $userData == $compareData;
	}
}