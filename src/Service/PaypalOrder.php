<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Service;

use OxidEsales\HRPayPalModule\Query\Order as OrderQuery;
use OxidEsales\HRPayPalModule\Exception\RequestError;

if (!defined('CURL_SSLVERSION_TLSV1_2')) {
	define('CURL_SSLVERSION_TLSV1_2', 6);
}

class PaypalOrder
{
	const API_ROUTE = '/orders';

	/** @var PaypalBearerAuthentication  */
	private $paypalBearer;

	/** @var PaypalConfiguration  */
	private $paypalConfiguration;

	/** @var OrderQuery  */
	private $orderQuery = '';

	public function __construct(
		PaypalBearerAuthentication $paypalBearer,
     	PaypalConfiguration $paypalConfiguration,
		OrderQuery $orderQuery
	)
	{
		$this->paypalBearer = $paypalBearer;
		$this->paypalConfiguration = $paypalConfiguration;
		$this->orderQuery = $orderQuery;
	}

	public function getUserToken(
		string $requestId,
	    string $formattedTotal,
	    string $currencyCode
	): string
	{
		$query = $this->orderQuery->getCreateOrderQuery($formattedTotal, $currencyCode);
        $raw = $this->queryPayPal($requestId, $query);
        $result = $this->decodeResponse($raw);

        if (!array_key_exists('id', $result)) {
        	throw RequestError::byResult($raw);
        }

        return $result['id'];
	}

	private function decodeResponse(string $raw): array
	{
		$json = json_decode($raw, true);
		if (JSON_ERROR_NONE !== json_last_error()) {
			throw RequestError::byJsonError((int) json_last_error());
		}

		return $json;
	}

	private function getHeaders(string $requestId): array
	{
		$headers = [
			'Accept: application/json',
			'Accept-Language: en_US',
			'Prefer: return=minimal',
			'Content-Type: application/json',
			'PayPal-Request-Id: ' . $requestId,
            'Authorization: Bearer ' . $this->paypalBearer->getToken()
		];

		return $headers;
	}

	private function queryPayPal(string $requestId, string $query): string
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->paypalConfiguration->getPayPalRestApiUrl() . self::API_ROUTE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders($requestId));

		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSV1_2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

		curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

		$response = curl_exec($ch);
		$curlError = curl_errno($ch);

		curl_close($ch);

		if ($curlError) {
			throw RequestError::byCurlCode((string) $curlError);
		}

		return $response;
	}
}