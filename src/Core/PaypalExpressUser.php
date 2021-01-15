<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Core;

use stdClass;
use Doctrine\DBAL\Query\QueryBuilder;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\Eshop\Core\Str as EshopCoreString;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;
use OxidEsales\Eshop\Application\Model\Address as EshopAddressModel;
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
	 *        Question: what happens if ship to name does not match payer?
	 *        -> create invoice address with payer name, shipping address with delivery name
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
		//in case no session user is found, we need to load user from PayPal details
		if (!$sessionUser) {
			/** @var EshopUserModel $user */
			$userEmail = $this->details->email_address;
			$userId = $this->searchUserId($userEmail);
			$user = oxNew(EshopUserModel::class);
			$loaded = $user->load($userId);
			$user = $loaded? $user : null;
		} else {
			$user = $sessionUser;
		}

		if (!$user) {
			//if no user was found, create a new one
			$user = $this->createUserFromDetails();
		}

		if ($user) {
            $deliveryAddressId = $this->getDeliverAddressId($user);
			$user->setPayPalDeliveryAddressId($deliveryAddressId);
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

	private function getDeliverAddressId(EshopUserModel $user): ?string
	{
		//no logged in (session) user found, user found in oxuser table and we have data mismatch
		if (!$this->isSamePayPalUser($user) && !$this->isSameAddressPayPalUser($user)) {
		    throw OrderError::byDetails('OEPAYPAL_ERROR_USER_ADDRESS');
		}

		$addressId = null;
		if (!$this->userNameEqualsPayPalShipToName($user) || !$this->isSameAddressPayPalUser($user)) {
			// user has selected different address in PayPal (not equal with user shop address)
			// so adding PayPal address as new user address to shop user account
			$addressId = $this->handleDeliveryAddress($user);
		}

		return $addressId;
	}

	private function handleDeliveryAddress(EshopUserModel $user): string
	{
        $addressId = $this->getExistingAddressId($user);
        if (!$addressId) {
        	$addressId = $this->createDeliveryAddress($user);
        }

        return $addressId;
	}

	private function createDeliveryAddress(EshopUserModel $user): string
	{
		$addressModel = oxNew(EshopAddressModel::class);
		$addressModel->assign(
			[
				'oxuserid' => $user->getId(),
				'oxaddressuserid' => $user->getId()
			]
		);
		$addressModel->assign($this->address->getData());
		$addressModel->save();

		return (string) $addressModel->getId();
	}

	private function getExistingAddressId(EshopUserModel $user): string
	{
		$addressId = '';
		$queryBuilder = $this->getQueryBuilder();

		$queryBuilder->select('oxid')
		             ->from('oxaddress')
		             ->where('oxuserid = :oxuserid')
			         ->setParameter(':oxuserid', $user->getId());

        foreach ($this->address->getData() as $key => $value) {
	        $queryBuilder->andWhere($key . ' = :' . $key)
		                 ->setParameter(':' . $key, $value);
        }

		/** @var \Doctrine\DBAL\Statement $result */
		$result = $queryBuilder->execute()
		         ->fetch();

		if (is_array($result) && array_key_exists('oxid', $result)) {
			$addressId = $result['oxid'];
		}

		return $addressId;
	}

	private function searchUserId(string $userEmail): string
    {
	    $queryBuilder = $this->getQueryBuilder();

	    $queryBuilder->select('oxid')
	                 ->from('oxuser')
	                 ->where('oxusername = :email')
	                 ->setParameter(':email', $userEmail);

	    if (!EshopRegistry::getConfig()->getConfigParam('blMallUsers')) {
		    $queryBuilder->andWhere('oxshopid = :oxshopid')
            		     ->setParameter(':oxshopid', EshopRegistry::getConfig()->getShopId());
	    }

	    $result = $queryBuilder->execute()
		    ->fetch();

	    return array_key_exists('oxid', $result) ? $result['oxid'] : '';
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
		$compareData[] = $this->details->name->given_name;
		$compareData[] = $this->details->name->surname;

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
		$compareData[] = $this->address->getFieldData('oxcity');

		return $userData == $compareData;
	}

	private function getQueryBuilder(): QueryBuilder
	{
		$queryBuilderFactory = ContainerFactory::getInstance()
		                                       ->getContainer()
		                                       ->get(QueryBuilderFactoryInterface::class);

		return $queryBuilderFactory->create();
	}
}