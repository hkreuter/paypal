<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Query;

use OxidEsales\HRPayPalModule\Service\ApplicationContext;

class Order
{
	/** @var ApplicationContext  */
	private $applicationContext = '';

	public function __construct(ApplicationContext $applicationContext)
	{
		$this->applicationContext = $applicationContext;
	}

	public function getCreateOrderQuery(
		string $formattedTotal,
		string $currencyCode,
		string $transactionMode,
		bool   $paypalExpress = true
	) :string
	{
		$query = array_merge(
		         $this->getIntent($transactionMode),
		         $this->getPurchaseUnits($formattedTotal, $currencyCode),
		         $this->getApplicationContext($paypalExpress)
		);

		return json_encode($query);
	}

	private function getPurchaseUnits(string $formattedTotal, string $currencyCode): array
	{
		$purchaseUnits = [
			'purchase_units' => [
				[
					'amount' => [
						'currency_code' => $currencyCode,
						'value'         => $formattedTotal
					]
				]
			]
		];

		return $purchaseUnits;
	}

	private function getApplicationContext(bool $paypalExpress = true): array
	{
		$shippingPreference = $this->applicationContext->getPayPalExpressShippingPreference();
		if (!$paypalExpress) {
			$shippingPreference = $this->applicationContext->getPayPalStandardShippingPreference();
		}

		$applicationContext = [
			'application_context' =>
				[
					'return_url'          => $this->applicationContext->getReturnUrl('oepaypalexpresscheckoutdispatcher'),
					'cancel_url'          => $this->applicationContext->getCancelUrl('oepaypalexpresscheckoutdispatcher'),
					'locale'              => $this->applicationContext->getLocaleCode(),
					'landing_page'        => $this->applicationContext->getPayPalLandingPage(),
					'shipping_preference' => $shippingPreference,
					'user_action'         => $this->applicationContext->getPayPalUserAction()
				]
		];

		return $applicationContext;
	}

	private function getIntent(string $transactionMode): array
	{
		$intent = [
			'intent' => ('Sale' == $transactionMode) ? "CAPTURE" : "AUTHORIZE"
		];

		return $intent;
	}
}