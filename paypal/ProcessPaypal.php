<?php namespace App\Transform\paypal;
use Redirect;
use Illuminate\Http\Request;
use App\Http\Controllers\sep\api\fundraising\controller\CommonHandler;
use App\Http\Controllers\sep\api\fundraising\controller\Constants;
use Illuminate\Support\Facades\Cache;


class ProcessPaypal {
	
	function GetItemTotalPrice($item) {
		// (Item Price x Quantity = Total) Get total amount of product;
		return $item ['ItemPrice'] * $item ['ItemQty'];
	}
	/**
	 * 
	 * @param unknown $products
	 * @return number
	 */
	function GetProductsTotalAmount($products) {
		$ProductsTotalAmount = 0;
		foreach ( $products as $p => $item ) {
			$ProductsTotalAmount = $ProductsTotalAmount + $this->GetItemTotalPrice ( $item );
		}
		return $ProductsTotalAmount;
	}
	/**
	 * 
	 * @param unknown $products
	 * @param unknown $charges
	 * @return number
	 */
	function GetGrandTotal($products, $charges) {
		// Grand total including all tax, insurance, shipping cost and discount
		$GrandTotal = $this->GetProductsTotalAmount ( $products );
		foreach ( $charges as $charge ) {
			$GrandTotal = $GrandTotal + $charge;
		}
		return $GrandTotal;
	}
	
	/**
	 * 
	 * @param unknown $products
	 * @param unknown $charges
	 * @param string $noshipping
	 */
	function SetExpressCheckout($products, $charges, $successPath, $cancelPath, $testMode, $currency, $url, $user, $password, $signature) {
		// Parameters for SetExpressCheckout, which will be sent to PayPal
		$padata = '&METHOD=SetExpressCheckout';
		$padata .= '&RETURNURL=' . urlencode ( $successPath );
		$padata .= '&CANCELURL=' . urlencode ( $cancelPath );
		$padata .= '&PAYMENTREQUEST_0_PAYMENTACTION=' . urlencode ( "SALE" );
		foreach ( $products as $p => $item ) {
			$padata .= '&L_PAYMENTREQUEST_0_NAME' . $p . '=' . urlencode ( $item ['ItemName'] );
			$padata .= '&L_PAYMENTREQUEST_0_NUMBER' . $p . '=' . urlencode ( $item ['ItemNumber'] );
			$padata .= '&L_PAYMENTREQUEST_0_DESC' . $p . '=' . urlencode ( $item ['ItemDesc'] );
			$padata .= '&L_PAYMENTREQUEST_0_AMT' . $p . '=' . urlencode ( $item ['ItemPrice'] );
			$padata .= '&L_PAYMENTREQUEST_0_QTY' . $p . '=' . urlencode ( $item ['ItemQty'] );
		}
		$padata .= '&NOSHIPPING=' . 1; // set 1 to hide buyer's shipping address, in-case products that does not require shipping
		$padata .= '&PAYMENTREQUEST_0_ITEMAMT=' . urlencode ( $this->GetProductsTotalAmount ( $products ) );
		$padata .= '&PAYMENTREQUEST_0_TAXAMT=' . urlencode ( $charges ['TotalTaxAmount'] );
		$padata .= '&PAYMENTREQUEST_0_SHIPPINGAMT=' . urlencode ( $charges ['ShippinCost'] );
		$padata .= '&PAYMENTREQUEST_0_HANDLINGAMT=' . urlencode ( $charges ['HandalingCost'] );
		$padata .= '&PAYMENTREQUEST_0_SHIPDISCAMT=' . urlencode ( $charges ['ShippinDiscount'] );
		$padata .= '&PAYMENTREQUEST_0_INSURANCEAMT=' . urlencode ( $charges ['InsuranceCost'] );
		$padata .= '&PAYMENTREQUEST_0_AMT=' . urlencode ( $this->GetGrandTotal ( $products, $charges ) );
		$padata .= '&PAYMENTREQUEST_0_CURRENCYCODE=' . urlencode ($currency);
		$padata .= '&LOCALECODE=EN'; // PayPal pages to match the language on your website;
		//$padata .= '&LOGOIMG=http://www.sanwebe.com/wp-content/themes/sanwebe/img/logo.png'; // site logo
		$padata .= '&CARTBORDERCOLOR=FFFFFF'; // border color of cart
		$padata .= '&ALLOWNOTE=1';
		$_SESSION ['ppl_products'] = $products;
		$_SESSION ['ppl_charges'] = $charges;
		Cache::put('ppl_products', $products, Constants::getCacheTimeToLive());
		Cache::put('ppl_charges', $charges, Constants::getCacheTimeToLive());
		
		$httpParsedResponseAr = $this->PPHttpPost('SetExpressCheckout', $padata, $user, $password, $signature, $testMode);
		// Respond according to message we receive from Paypal
		if ("SUCCESS" == strtoupper ( $httpParsedResponseAr ["ACK"] ) || "SUCCESSWITHWARNING" == strtoupper ( $httpParsedResponseAr ["ACK"] )) {
			$paypalmode = ($testMode == true) ? '.sandbox' : '';
			$paypalurl = 'https://www' . $paypalmode . '.paypal.com/cgi-bin/webscr?SOLUTIONTYPE=Sole&cmd=_express-checkout&token=' . $httpParsedResponseAr ["TOKEN"];
			header("Location: ".$paypalurl); /* Redirect browser */
			exit();
		} else {
			$url = CommonHandler::getServerRootPath($url);
			$url = $url.'error/'.$httpParsedResponseAr["L_LONGMESSAGE0"];
			header("Location: ".$url); /* Redirect browser */
			exit();
		}
	}
	
	/**
	 * 
	 * @param unknown $currency
	 */
	function DoExpressCheckoutPayment($token, $PayerID, $currency, $user, $password, $signature, $testMode){
		session_start();
		if (Cache::has('ppl_products') && Cache::has('ppl_charges')) {
			$products=Cache::get('ppl_products');
			$charges=Cache::get('ppl_charges');
		} else if(isset($_SESSION['ppl_products']) && isset($_SESSION['ppl_charges'])){
			$products=$_SESSION['ppl_products'];
			$charges=$_SESSION['ppl_charges'];
		} else {
			return $this->GetTransactionDetails($token, $user, $password, $signature, $testMode);
		}
		
		if ($products != null && $charges != null) {
			$padata  = 	'&TOKEN='.urlencode($token);
			$padata .= 	'&PAYERID='.urlencode($PayerID);
			$padata .= 	'&PAYMENTREQUEST_0_PAYMENTACTION='.urlencode("SALE");
			//set item info here, otherwise we won't see product details later
			foreach($products as $p => $item){
				$padata .=	'&L_PAYMENTREQUEST_0_NAME'.$p.'='.urlencode($item['ItemName']);
				$padata .=	'&L_PAYMENTREQUEST_0_NUMBER'.$p.'='.urlencode($item['ItemNumber']);
				$padata .=	'&L_PAYMENTREQUEST_0_DESC'.$p.'='.urlencode($item['ItemDesc']);
				$padata .=	'&L_PAYMENTREQUEST_0_AMT'.$p.'='.urlencode($item['ItemPrice']);
				$padata .=	'&L_PAYMENTREQUEST_0_QTY'.$p.'='. urlencode($item['ItemQty']);
			}
			$padata .= 	'&PAYMENTREQUEST_0_ITEMAMT='.urlencode($this -> GetProductsTotalAmount($products));
			$padata .= 	'&PAYMENTREQUEST_0_TAXAMT='.urlencode($charges['TotalTaxAmount']);
			$padata .= 	'&PAYMENTREQUEST_0_SHIPPINGAMT='.urlencode($charges['ShippinCost']);
			$padata .= 	'&PAYMENTREQUEST_0_HANDLINGAMT='.urlencode($charges['HandalingCost']);
			$padata .= 	'&PAYMENTREQUEST_0_SHIPDISCAMT='.urlencode($charges['ShippinDiscount']);
			$padata .= 	'&PAYMENTREQUEST_0_INSURANCEAMT='.urlencode($charges['InsuranceCost']);
			$padata .= 	'&PAYMENTREQUEST_0_AMT='.urlencode($this->GetGrandTotal($products, $charges));
			$padata .= 	'&PAYMENTREQUEST_0_CURRENCYCODE='.urlencode($currency);
			Cache::put('padata', $padata, Constants::getCacheTimeToLive());
			//We need to execute the "DoExpressCheckoutPayment" at this point to Receive payment from user.
			$httpParsedResponseAr = $this->PPHttpPost('DoExpressCheckoutPayment', $padata, $user, $password, $signature, $testMode);
			return $httpParsedResponseAr;
		}
	}
	
	/**
	 * 
	 */
	function GetTransactionDetails($token, $user, $password, $signature, $testMode) {
		$padata = '&TOKEN=' . urlencode ($token);
		$httpParsedResponseAr = $this->PPHttpPost('GetExpressCheckoutDetails', $padata, $user, $password, $signature, $testMode);
		return $httpParsedResponseAr;
	}
	
	/**
	 * 
	 * @param unknown $methodName_
	 * @param unknown $nvpStr_
	 * @return mixed[]
	 */
	function PPHttpPost($methodName_, $nvpStr_, $user, $password, $signature, $testMode) {
		// Set up your API credentials, PayPal end point, and API version.
		$API_UserName = urlencode ($user);
		$API_Password = urlencode ($password);
		$API_Signature = urlencode ($signature);
		$paypalmode = ($testMode == true || $testMode == '1') ? '.sandbox' : '';
		$API_Endpoint = "https://api-3t" . $paypalmode . ".paypal.com/nvp";
		$version = urlencode ('109.0');
		// Set the curl parameters.
		$ch = curl_init ();
		curl_setopt ( $ch, CURLOPT_URL, $API_Endpoint );
		curl_setopt ( $ch, CURLOPT_VERBOSE, 1 );
		// curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'TLSv1');
		// Turn off the server and peer verification (TrustManager Concept).
		curl_setopt ( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
		curl_setopt ( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
		curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt ( $ch, CURLOPT_POST, 1 );
		// Set the API operation, version, and API signature in the request.
		$nvpreq = "SOLUTIONTYPE=Sole&METHOD=$methodName_&VERSION=$version&PWD=$API_Password&USER=$API_UserName&SIGNATURE=$API_Signature$nvpStr_";
		// Set the request as a POST FIELD for curl.
		curl_setopt ( $ch, CURLOPT_POSTFIELDS, $nvpreq );
		// Get response from the server.
		$httpResponse = curl_exec ( $ch );
		if (! $httpResponse) {
			exit ( "$methodName_ failed: " . curl_error ( $ch ) . '(' . curl_errno ( $ch ) . ')' );
		}
		// Extract the response details.
		$httpResponseAr = explode ( "&", $httpResponse );
		$httpParsedResponseAr = array ();
		foreach ( $httpResponseAr as $i => $value ) {
			$tmpAr = explode ( "=", $value );
			if (sizeof ( $tmpAr ) > 1) {
				$httpParsedResponseAr [$tmpAr [0]] = $tmpAr [1];
			}
		}
		if ((0 == sizeof ( $httpParsedResponseAr )) || ! array_key_exists ( 'ACK', $httpParsedResponseAr )) {
			exit ( "Invalid HTTP Response for POST request($nvpreq) to $API_Endpoint." );
		}
		return $httpParsedResponseAr;
	}
}