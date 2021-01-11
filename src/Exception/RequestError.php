<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Exception;

use OxidEsales\PayPalModule\Core\Exception\PayPalException;

class RequestError extends PayPalException
{
	public static function byCurlCode(string $code): self
	{
		return new self(sprintf("Request failed with curl errno %s, got no JWT.", $code));
	}

	public static function byResult(string $data): self
	{
		return new self(sprintf("Response misses expected fields %s", $data));
	}

	public static function byJsonError(int $jsonError): self
	{
		return new self(sprintf("Request failed with json error %i", $jsonError));
	}
}