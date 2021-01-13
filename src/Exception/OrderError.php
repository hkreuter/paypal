<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Exception;

use OxidEsales\PayPalModule\Core\Exception\PayPalException;

class OrderError extends PayPalException
{
	public static function byDetailsStatus(string $status): self
	{
		return new self(sprintf("Order status is not aproved %s.", $status));
	}

	public static function byDetailsPayer(): self
	{
		return new self(sprintf("Order details contain no user information"));
	}

	public static function byDetails(string $message): self
	{
		return new self(sprintf("Order details user error: '%s'", $message));
	}
}