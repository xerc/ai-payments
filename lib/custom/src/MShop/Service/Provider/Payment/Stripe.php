<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2015-2020
 * @package MShop
 * @subpackage Service
 */


namespace Aimeos\MShop\Service\Provider\Payment;

use Aimeos\MShop\Order\Item\Base as Status;


/**
 * Payment provider for Stripe.
 *
 * @package MShop
 * @subpackage Service
 */
class Stripe
	extends \Aimeos\MShop\Service\Provider\Payment\OmniPay
	implements \Aimeos\MShop\Service\Provider\Payment\Iface
{

	private $beConfig = array(
		'type' => array(
			'code' => 'type',
			'internalcode'=> 'type',
			'label'=> 'Payment provider type',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> 'Stripe_PaymentIntents',
			'required'=> true,
		),
		'apiKey' => array(
			'code' => 'apiKey',
			'internalcode'=> 'apiKey',
			'label'=> 'API key',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> true,
		),
		'publishableKey' => array(
			'code' => 'publishableKey',
			'internalcode'=> 'publishableKey',
			'label'=> 'Publishable key',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> true,
		),
	);

	protected $feConfig = array(
		'paymenttoken' => array(
			'code' => 'paymenttoken',
			'internalcode' => 'paymenttoken',
			'label' => 'Authentication token',
			'type' => 'string',
			'internaltype' => 'integer',
			'default' => '',
			'required' => true,
			'public' => false,
		),
		'setup_future_usage' => array(
			'code' => 'setup_future_usage',
			'internalcode' => 'setup_future_usage',
			'label' => 'Save card for recurring payments',
			'type' => 'string',
			'internaltype' => 'string',
			'default' => 'off_session',
			'required' => true,
			'public' => false,
		),
		'payment.cardno' => array(
			'code' => 'payment.cardno',
			'internalcode'=> 'number',
			'label'=> 'Credit card number',
			'type'=> 'container',
			'internaltype'=> 'integer',
			'default'=> '',
			'required'=> false
		),
		'payment.expiry' => array(
			'code' => 'payment.expiry',
			'internalcode'=> 'expiry',
			'label'=> 'Expiry',
			'type'=> 'container',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> false
		),
		'payment.cvv' => array(
			'code' => 'payment.cvv',
			'internalcode'=> 'cvv',
			'label'=> 'Verification number',
			'type'=> 'container',
			'internaltype'=> 'integer',
			'default'=> '',
			'required'=> false
		),
	);

	private $provider;


	/**
	 * Checks the backend configuration attributes for validity.
	 *
	 * @param array $attributes Attributes added by the shop owner in the administraton interface
	 * @return array An array with the attribute keys as key and an error message as values for all attributes that are
	 * 	known by the provider but aren't valid resp. null for attributes whose values are OK
	 */
	public function checkConfigBE( array $attributes ) : array
	{
		return array_merge( parent::checkConfigBE( $attributes ), $this->checkConfig( $this->beConfig, $attributes ) );
	}


	/**
	 * Returns the configuration attribute definitions of the provider to generate a list of available fields and
	 * rules for the value of each field in the administration interface.
	 *
	 * @return array List of attribute definitions implementing \Aimeos\MW\Common\Critera\Attribute\Iface
	 */
	public function getConfigBE() : array
	{
		$list = parent::getConfigBE();

		foreach( $this->beConfig as $key => $config ) {
			$list[$key] = new \Aimeos\MW\Criteria\Attribute\Standard( $config );
		}

		return $list;
	}


	/**
	 * Tries to get an authorization or captures the money immediately for the given order if capturing the money
	 * separately isn't supported or not configured by the shop owner.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
	 * @param array $params Request parameter if available
	 * @return \Aimeos\MShop\Common\Helper\Form\Iface|null Form object with URL, action and parameters to redirect to
	 *    (e.g. to an external server of the payment provider or to a local success page)
	 */
	public function process( \Aimeos\MShop\Order\Item\Iface $order, array $params = [] ) : ?\Aimeos\MShop\Common\Helper\Form\Iface
	{
		if( !isset( $params['paymenttoken'] ) ) {
			return $this->getPaymentForm( $order, $params );
		}

		if( $this->getConfigValue( 'createtoken' )
			&& $this->getCustomerData( $this->getContext()->getUserId(), 'customerid' ) === null
		) {
			$data = [];
			$base = $this->getOrderBase( $order->getBaseId() );

			if( $addr = current( $base->getAddress( 'payment' ) ) )
			{
				$data['description'] = $addr->getFirstName() . ' ' . $addr->getLastName();
				$data['email'] = $addr->getEmail();
			}

			$response = $this->getProvider()->createCustomer( $data )->send();

			if( $response->isSuccessful() ) {
				$this->setCustomerData( $this->getContext()->getUserId(), 'customerid', $response->getCustomerReference() );
			}
		}

		return $this->processOrder( $order, $params );
	}


	/**
	 * Updates the orders for whose status updates have been received by the confirmation page
	 *
	 * @param \Psr\Http\Message\ServerRequestInterface $request Request object with parameters and request body
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order item that should be updated
	 * @return \Aimeos\MShop\Order\Item\Iface Updated order item
	 * @throws \Aimeos\MShop\Service\Exception If updating the orders failed
	 */
	public function updateSync( \Psr\Http\Message\ServerRequestInterface $request,
		\Aimeos\MShop\Order\Item\Iface $order ) : \Aimeos\MShop\Order\Item\Iface
	{
		$response = $this->getProvider()->confirm( [
			'paymentIntentReference' => $this->getOrderData( $order, 'STRIPEINTENTREF' )
		] )->send();

		if( $response->isSuccessful() )
		{
			$status = $this->getValue( 'authorize', false ) ? Status::PAY_AUTHORIZED : Status::PAY_RECEIVED;

			$this->setOrderData( $order, ['TRANSACTIONID' => $response->getTransactionReference()] );
			$this->saveRepayData( $response, $this->getOrderBase( $order->getBaseId() )->getCustomerId() );
		}
		else
		{
			$status = Status::PAY_REFUSED;
		}

		$this->saveOrder( $order->setPaymentStatus( $status ) );

		return $order;
	}


	/**
	 * Returns the data passed to the Omnipay library
	 *
	 * @param \Aimeos\MShop\Order\Item\Base\Iface $base Basket object
	 * @param string $orderid Unique order ID
	 * @param array $params Request parameter if available
	 * @return array Associative list of key/value pairs
	 */
	protected function getData( \Aimeos\MShop\Order\Item\Base\Iface $base, string $orderid, array $params ) : array
	{
		$session = $this->getContext()->getSession();
		$data = parent::getData( $base, $orderid, $params );

		if( isset( $params['paymenttoken'] ) ) {
			$session->set( 'aimeos/stripe_token', $params['paymenttoken'] );
		}

		if( ( $token = $session->get( 'aimeos/stripe_token' ) ) !== null ) {
			$data['token'] = $token;
		}

		if( $this->getConfigValue( 'createtoken' ) &&
			$custid = $this->getCustomerData( $this->getContext()->getUserId(), 'customerid' )
		) {
			$data['customerReference'] = $custid;
		}

		$type = \Aimeos\MShop\Order\Item\Base\Service\Base::TYPE_PAYMENT;
		$serviceItem = $this->getBasketService( $base, $type, $this->getServiceItem()->getCode() );

		if( $stripeIntentsRef = $serviceItem->getAttribute( 'STRIPEINTENTREF', 'payment/omnipay' ) ) {
			$data['paymentIntentReference'] = $stripeIntentsRef;
		}

		$data['confirm'] = true;

		return $data;
	}


	/**
	 * Returns the payment form for entering payment details at the shop site.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order object
	 * @param array $params Request parameter if available
	 * @return \Aimeos\MShop\Common\Helper\Form\Iface Form helper object
	 */
	protected function getPaymentForm( \Aimeos\MShop\Order\Item\Iface $order, array $params ) : \Aimeos\MShop\Common\Helper\Form\Iface
	{
		$list = [];
		$feConfig = $this->feConfig;

		foreach( $feConfig as $key => $config ) {
			$list[$key] = new \Aimeos\MW\Criteria\Attribute\Standard( $config );
		}

		$url = $this->getConfigValue( 'payment.url-self', '' );
		return new \Aimeos\MShop\Common\Helper\Form\Standard( $url, 'POST', $list, false, $this->getStripeJs() );
	}


	/**
	 * Returns the required Javascript code for Stripe payment form
	 *
	 * @return string Stripe JS code
	 */
	protected function getStripeJs() : string
	{
		return '
<script src="https://js.stripe.com/v3/"></script>
<script type="text/javascript">

StripeProvider = {
	stripe: "",
	elements: "",
	token_element: "",
	token_selector: "#process-paymenttoken",
	errors_selector: "card-errors",
	form_selector: ".checkout-standard form",
	payment_button_id: "payment-button",

	init: function(publishableKey,elements_array){
		StripeProvider.stripe = Stripe(publishableKey);
		StripeProvider.elements = StripeProvider.stripe.elements();
		StripeProvider.createElements(elements_array);

		var button = document.getElementById(StripeProvider.payment_button_id);
		button.addEventListener("click", function (event) {
			event.preventDefault();
			StripeProvider.stripe.createToken(StripeProvider.token_element).then(function (result) {
				if (result.error) {
					document.querySelectorAll( StripeProvider.errors_selector ).value = result.error.message;
				} else {
					StripeProvider.tokenHandler(result.token);
				}
			});
		});
	},

	handleEvent: function(event){
		var displayError = document.getElementById(StripeProvider.errors_selector);
		if (event.error) {
			displayError.textContent = event.error.message;
		} else {
			displayError.textContent = "";
		}
	},

	// Creating Stripe Elements from an array
	createElements: function (elements_array) {
		var classes = {
			base: "form-item-value"
		};
		for(var x=0; x < elements_array.length; x++){
			var element = elements_array[x].element;
			element = StripeProvider.elements.create(elements_array[x].element, {classes: classes});
			element.mount(elements_array[x].selector);
			element.addEventListener("change", function (event) {
				StripeProvider.handleEvent(event);
			});
			if(elements_array[x].element === "cardNumber") StripeProvider.token_element = element;
		}
	},

	// Actions with recieved token
	tokenHandler: function (token) {
		var input = document.querySelectorAll( StripeProvider.token_selector);
		input[0].value= token.id;
		this.submitPurchaseForm();
	},

	submitPurchaseForm: function () {
		var form = document.querySelectorAll(StripeProvider.form_selector);
		form[0].submit();
	}
};

document.addEventListener("DOMContentLoaded", function() {
	StripeProvider.init("' . $this->getConfigValue( 'publishableKey', '' ) . '",
		[
			{"element": "cardNumber", "selector": "div[id=\"process-payment.cardno\"]"},
			{"element": "cardExpiry", "selector": "div[id=\"process-payment.expiry\"]"},
			{"element": "cardCvc", "selector": "div[id=\"process-payment.cvv\"]"}
		]
	);
});

</script>

<!-- Used to display Element errors -->
<div id="card-errors" role="alert"></div>';
	}


	/**
	 * Sends the given data for the order to the payment gateway
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order item which should be paid
	 * @param array $data Associative list of key/value pairs sent to the payment gateway
	 * @return \Omnipay\Common\Message\ResponseInterface Omnipay response from the payment gateway
	 */
	protected function sendRequest( \Aimeos\MShop\Order\Item\Iface $order, array $data ) : \Omnipay\Common\Message\ResponseInterface
	{
		$response = parent::sendRequest( $order, $data );
		$this->setOrderData( $order, ['STRIPEINTENTREF' => $response->getPaymentIntentReference()] );

		return $response;
	}
}
