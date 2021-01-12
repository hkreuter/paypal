<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Service;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;


class PaypalConfiguration
{
	/** @var string */
	private $paypalFrontendUrl = 'https://www.paypal.com';

	/** @var string */
	private $paypalSandboxFrontendUrl = 'https://www.sandbox.paypal.com';

	/** @var string */
    private $paypalCapturUrlFormat = 'https://api.paypal.com/v2/checkout/orders/%s/capture';

	/** @var string */
	private $paypalSandboxCapturUrlFormat = 'https://api.sandbox.paypal.com/v2/checkout/orders/%s/capture';

	/** @var string */
	private $paypalRestApiUrl = 'https://api-m.paypal.com/v2/checkout';

	/** @var string */
	private $paypalSandboxRestApiUrl = 'https://api-m.sandbox.paypal.com/v2/checkout';

	/** @var string */
	private $paypalSandboxAuthTokenUrl = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

	/** @var string */
	private $paypalAuthTokenUrl = 'https://api-m.paypal.com/v1/oauth2/token';

	public function getPayPalRestApiUrl(): string
	{
		if ($this->isSandboxEnabled()) {
			return $this->paypalSandboxRestApiUrl;
		} else {
			return $this->paypalRestApiUrl;
		}
	}

	public function getPayPalAuthTokenUrl(): string
	{
		if ($this->isSandboxEnabled()) {
			$url = $this->paypalSandboxAuthTokenUrl;
		} else {
			$url = $this->paypalAuthTokenUrl;
		}

		return $url;
	}

	/**
	 * Returns PayPal Client Id
	 *
	 * @return string
	 */
	public function getClientId()
	{
		if ($this->isSandboxEnabled()) {
			// sandbox signature
			return EshopRegistry::getConfig()->getConfigParam('oePayPalSandboxClientId');
		}

		// test sandbox signature
		return EshopRegistry::getConfig()->getConfigParam('oePayPalClientId');
	}

	/**
	 * Returns PayPal API Secret
	 *
	 * @return string
	 */
	public function getSecret()
	{
		if ($this->isSandboxEnabled()) {
			// sandbox signature
			return EshopRegistry::getConfig()->getConfigParam('oePayPalSandboxSecret');
		}

		// test sandbox signature
		return EshopRegistry::getConfig()->getConfigParam('oePayPalSecret');
	}

	public function getSavedJWTAuthToken(): string
	{
		if ($this->isSandboxEnabled()) {
			return (string) EshopRegistry::getConfig()->getConfigParam('oePayPalSandboxJWTAuthToken');
		} else {
			return (string) EshopRegistry::getConfig()->getConfigParam('oePayPalJWTAuthToken');
		}
	}

	public function saveJWTAuthToken(string $token): void
	{
		if ($this->isSandboxEnabled()) {
			EshopRegistry::getConfig()->setConfigParam('oePayPalSandboxJWTAuthToken', $token);
			EshopRegistry::getConfig()->saveShopConfVar('str', 'oePayPalSandboxJWTAuthToken', $token);
		} else {
			EshopRegistry::getConfig()->setConfigParam('oePayPalJWTAuthToken', $token);
			EshopRegistry::getConfig()->saveShopConfVar('str', 'oePayPalJWTAuthToken', $token);
		}
	}

	public function getJWTAuthTokenValidUntil(): int
	{
		if ($this->isSandboxEnabled()) {
			return (int) EshopRegistry::getConfig()->getConfigParam('oePayPalSandboxJWTAuthTokenValidUntil');
		} else {
			return (int) EshopRegistry::getConfig()->getConfigParam('oePayPalJWTAuthTokenValidUntil');
		}
	}

	public function saveJWTAuthTokenValidUntil(int $timestamp): void
	{
		if ($this->isSandboxEnabled()) {
			EshopRegistry::getConfig()->setConfigParam( 'oePayPalSandboxJWTAuthTokenValidUntil', $timestamp );
			EshopRegistry::getConfig()->saveShopConfVar( 'str', 'oePayPalSandboxJWTAuthTokenValidUntil', (string) $timestamp );
		} else {
			EshopRegistry::getConfig()->setConfigParam('oePayPalJWTAuthTokenValidUntil', $timestamp);
			EshopRegistry::getConfig()->saveShopConfVar('str', 'oePayPalJWTAuthTokenValidUntil', (string) $timestamp);
		}
	}

	/**
	 * Returns true of sandbox mode is ON
	 *
	 * @return bool
	 */
	public function isSandboxEnabled(): bool
	{
		return EshopRegistry::getConfig()->getConfigParam('blOEPayPalSandboxMode');
	}

	public function getPayPalCheckoutNowUrl(string $userToken): string
	{
		if ($this->isSandboxEnabled()) {
			return sprintf($this->paypalSandboxFrontendUrl . '/checkoutnow?token=%s', $userToken);
		} else {
			return sprintf($this->paypalFrontendUrl .'/checkoutnow?token=%s', $userToken);
		}
	}

	public function getPayPalCaptureUrl(string $userToken): string
	{
		if ($this->isSandboxEnabled()) {
			return sprintf($this->paypalSandboxCapturUrlFormat, $userToken);
		} else {
			return sprintf($this->paypalCapturUrlFormat, $userToken, $userToken);
		}
	}
}