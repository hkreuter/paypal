<?php
/**
 * Copyright © OXID eSales AG and hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Exception;

use OxidEsales\PayPalModule\Core\Exception\PayPalException;

class AuthenticationError extends PayPalException
{
	public static function byCurlCode(string $code): self
	{
		return new self(sprintf("Authentication call failed with curl errno %s, got no JWT.", $code));
	}

	public static function byJsonError(int $jsonError): self
	{
		return new self(sprintf("Authentication failed with json error %i", $jsonError));
	}

	public static function byResult(string $data): self
	{
		return new self(sprintf("Authentication failed, json response contains no token: %s", $data));
	}

	public static function byMissingToken(): self
	{
		return new self("Authentication failed, could not get a valid JWT. ");
	}
}