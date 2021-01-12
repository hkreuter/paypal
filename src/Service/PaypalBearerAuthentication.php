<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\HRPayPalModule\Service;

use OxidEsales\HRPayPalModule\Exception\AuthenticationError;

if (!defined('CURL_SSLVERSION_TLSV1_2')) {
	define('CURL_SSLVERSION_TLSV1_2', 6);
}

class PaypalBearerAuthentication
{
	/** @var PaypalConfiguration  */
	private $paypalConfiguration;

	public function __construct(PaypalConfiguration $paypalConfiguration)
	{
		$this->paypalConfiguration = $paypalConfiguration;
	}

	public function getToken(): string
	{
        if (!$this->isValidToken()) {
	        $this->fetchNewToken();
        }

        if (!$this->isValidToken()) {
	        throw AuthenticationError::byMissingToken();
        }

        return $this->paypalConfiguration->getSavedJWTAuthToken();
	}

	private function isValidToken(): bool
	{
		return ($this->paypalConfiguration->getSavedJWTAuthToken()
		    && (time() < $this->paypalConfiguration->getJWTAuthTokenValidUntil()));
	}

	private function fetchNewToken(): void
	{
		$response = $this->queryPayPal();
		$decodedResponse = $this->decodeResponse($response);

        if (array_key_exists('access_token', $decodedResponse) &&
            array_key_exists('expires_in', $decodedResponse) &&
            array_key_exists('token_type', $decodedResponse) &&
            ('bearer' === strtolower($decodedResponse['token_type']))
        ) {
            $this->paypalConfiguration->saveJWTAuthToken(trim($decodedResponse['access_token']));
            $this->paypalConfiguration->saveJWTAuthTokenValidUntil(time() + (int) $decodedResponse['expires_in']);
        } else {
        	throw AuthenticationError::byResult($response);
        }
	}
	
	private function decodeResponse(string $raw): array
	{
    	$json = json_decode($raw, true);
    	if (JSON_ERROR_NONE !== json_last_error()) {
		    throw AuthenticationError::byJsonError((int) json_last_error());
	    }

	    return $json;
	}

	private function queryPayPal(): string
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->paypalConfiguration->getPayPalAuthTokenUrl());
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: en_US']);
		curl_setopt($ch, CURLOPT_USERPWD, $this->paypalConfiguration->getClientId() . ':' . $this->paypalConfiguration->getSecret());
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
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
			throw AuthenticationError::byCurlCode((string) $curlError);
		}

		return $response;
	}
}