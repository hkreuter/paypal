<?php
/**
 * Copyright © hkreuter. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\HRPayPalModule\Controller;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\PayPalModule\Core\PayPalService;
use OxidEsales\PayPalModule\Core\Config as PayPalConfig;
use OxidEsales\HRPayPalModule\Model\PaypalCallback;
use OxidEsales\PayPalModule\Core\Logger as PayPalLogger;
use OxidEsales\HRPayPalModule\Model\Tools as PayPalTools;

class CallbackController extends \OxidEsales\Eshop\Application\Controller\FrontendController {
	/**
	 * Processes PayPal callback
	 */
	public function processCallBack(): void {

		$paypalLogger = oxNew(PayPalLogger::class );
		$paypalLogger->setLoggerSessionId( EshopRegistry::getSession()->getId() );
		$paypalConfig = oxNew(PayPalConfig::class);

		/** @var PaypalCallback $callbackModel */
		$callbackModel = oxNew(
			PaypalCallback::class,
			oxNew(PayPalService::class),
			$paypalConfig,
			oxNew(PayPalTools::class, $paypalConfig),
			$paypalLogger
		);

		$callbackResponse = $callbackModel->getCallbackResponse();

		EshopRegistry::getUtils()->showMessageAndExit( $callbackResponse );
	}
}
