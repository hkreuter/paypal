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

	public function getQuery(string $formattedTotal, string $currencyCode) :string
	{
		$query = array_merge(
		         $this->getIntent(),
		         $this->getPurchaseUnits($formattedTotal, $currencyCode),
		         $this->getApplicationContext()
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

	private function getApplicationContext(): array
	{
		$applicationContext = [
			'application_context' =>
				[
					'return_url'          => $this->applicationContext->getReturnUrl('oepaypalexpresscheckoutdispatcher'),
					'cancel_url'          => $this->applicationContext->getCancelUrl('oepaypalexpresscheckoutdispatcher'),
					'locale'              => $this->applicationContext->getLocaleCode(),
					'landing_page'        => $this->applicationContext->getPayPalLandingPage(),
					'shipping_preference' => $this->applicationContext->getPayPalShippingPreference(),
					'user_action'         => $this->applicationContext->getPayPalUserAction()
				]
		];

		return $applicationContext;
	}

	private function getIntent(): array
	{
		$intent = [
			'intent' => ('Sale' == $this->applicationContext->getTransactionMode()) ? "CAPTURE" : "AUTHORIZE"
		];

		return $intent;
	}
}