<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Service;

use stdClass;
use OxidEsales\HRPayPalModule\Exception\RequestError;

if (!defined('CURL_SSLVERSION_TLSV1_2')) {
	define('CURL_SSLVERSION_TLSV1_2', 6);
}

class PayPalOrderDetails
{
	const API_ROUTE = '/orders/';

	/** @var PaypalBearerAuthentication  */
	private $paypalBearer;

	/** @var PaypalConfiguration  */
	private $paypalConfiguration;

	public function __construct(
		PaypalBearerAuthentication $paypalBearer,
     	PaypalConfiguration $paypalConfiguration
	)
	{
		$this->paypalBearer = $paypalBearer;
		$this->paypalConfiguration = $paypalConfiguration;
	}

	public function getOrderDetails(string $userToken): stdClass
	{
        $raw = $this->getFromPayPal($userToken);
        $result = $this->decodeResponse($raw);

		if (!array_key_exists('id', $result)) {
			throw RequestError::byResult($raw);
		}

		return $result;
	}

	private function decodeResponse(string $raw): stdClass
	{
		$json = json_decode($raw);
		if (JSON_ERROR_NONE !== json_last_error()) {
			throw RequestError::byJsonError((int) json_last_error());
		}

		return $json;
	}

	private function getHeaders(): array
	{
		$headers = [
			'Accept: application/json',
			'Accept-Language: en_US',
			'Prefer: return=representation',
			'Content-Type: application/json',
            'Authorization: Bearer ' . $this->paypalBearer->getToken()
		];

		return $headers;
	}

	private function getFromPayPal(string $token): string
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->paypalConfiguration->getPayPalRestApiUrl() . self::API_ROUTE . $token);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());

		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSV1_2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

		$response = curl_exec($ch);
		$curlError = curl_errno($ch);

		curl_close($ch);

		if ($curlError) {
			throw RequestError::byCurlCode((string) $curlError);
		}

		return $response;
	}
}