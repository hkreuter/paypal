<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Exception;

use OxidEsales\PayPalModule\Core\Exception\PayPalException;

class OrderTotalChanged extends PayPalException
{
	public function __construct($message = "Order total did change", $code = 0)
	{
		parent::__construct($message, $code);
	}
}