<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

require_once dirname(__FILE__) . "/../../../../bootstrap.php";

use OxidEsales\HRPayPalModule\Service\PayPalBearerAuthentication;
use OxidEsales\HRPayPalModule\Service\PayPalOrder;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;

try {
	$paypalConfig = oxNew(PayPalConfig::class);
	$auth = new PayPalBearerAuthentication($paypalConfig);
    $caller = new PayPalOrder($auth, $paypalConfig);
    $caller->setAmount(100, 'EUR');
    $result = $caller->getUserToken('some_request_id');
	#$result = $auth->getToken();
	var_export($result);
} catch ( \Exception $exception) {
	var_export($exception->getMessage());
}
