<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Exception;

use OxidEsales\PayPalModule\Core\Exception\PayPalException;

class PaymentNotValidForUserCountry extends PayPalException
{
	public function __construct($message = "Payment not valid for user country", $code = 0)
	{
		parent::__construct($message, $code);
	}
}