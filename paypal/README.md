How to use this paypal class:

(new ProcessPaypal())->SetExpressCheckOut($products, $charges, $redirectUrl.'true', $redirectUrl.'false', $testMode, $currency, $url, 
					$gatewayUser, $gatewayPasswd, $signature);


Once your application get redirected to paypal and payment is processed (partially) than to complete the transaction please call the following API
and apply the login as per the acknowlwdgement:
(new ProcessPaypal())->DoExpressCheckoutPayment($token, $payerId, $donationObj->currency, $gatewayUser, $gatewayPasswd, $signature, $testMode);
  			if ("SUCCESS" == strtoupper ( $paymentResponse ["ACK"] ) || "SUCCESSWITHWARNING" == strtoupper ($paymentResponse ["ACK"])) {
//YOUR LOGIN on successful payment
} else {
//YOUR LOGIN on Failed payment
}
