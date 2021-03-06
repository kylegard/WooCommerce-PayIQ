<?php


class PayIQAPI
{
	static $service_url = 'https://secure.payiq.se/api/v2/soap/PaymentService';
	static $vsdl_url = 'https://secure.payiq.se/api/v2/soap/PaymentService?wsdl';

	protected $service_name = null; //Your registered PayIQ service name.
	protected $shared_secret = null; //Your registered PayIQ service name.
	protected $order = null;
	protected $client = null; //Your registered PayIQ service name.
	protected $myclient = null; //Your registered PayIQ service name.
	protected $debug = false;



	protected $logger = null;

	function __construct( $service_name, $shared_secret, $order = null, $debug = false ) {

		$this->service_name = $service_name;
		$this->shared_secret = $shared_secret;
		$this->order = $order;

		$this->setDebug( $debug );

		$this->logger = new WC_Logger();

		$this->client = new PayIQSoapClient(
			self::$vsdl_url, //null,
			[
				//'soap_version'  => 'SOAP_1_2',
				//'location' => get_service_url( $endpoint ),
				'uri'           => self::$vsdl_url,
				'trace'         => 1,
				'exceptions'    => 1,
				'use'           => SOAP_LITERAL,
				'encoding'      => 'utf-8',
				'keep_alive'    => true,

				'cache_wsdl'    => WSDL_CACHE_NONE,
				'stream_context' => stream_context_create(
					[
						'http' => [
							'header' => 'Content-Encoding: gzip, deflate'."\n".'Expect: 100-continue'."\n".'Connection: Keep-Alive'
						],
					 ]
				)
			]
		);

		$this->myclient = new SoapClient(
			self::$vsdl_url, //null,
			[
				//'soap_version'  => 'SOAP_1_2',
				//'location' => get_service_url( $endpoint ),
				'uri' => self::$vsdl_url,
				'trace' => 1,
				'exceptions' => 0,
				'use' => SOAP_LITERAL,
				'encoding' => 'utf-8',
				'keep_alive'    => true,

				'cache_wsdl'    => WSDL_CACHE_NONE,
				'stream_context' => stream_context_create(
					[
						'http' => [
							'header' => 'Content-Encoding: gzip, deflate'."\n".'Expect: 100-continue'."\n".'Connection: Keep-Alive'
						],
					 ]
				)
			]
		);
	}

	/**
	 * @return boolean
	 */
	public function isDebug() {

		return $this->debug;
	}

	/**
	 * @param boolean $debug
	 */
	public function setDebug( $debug ) {

		$this->debug = $debug;
	}

	function setOrder( $order ) {

		$this->order = $order;
	}

	function getChecksum( $type = 'PrepareSession' ) {

		if ( ! $this->order ) {

			return false;
		}

		switch ( $type ) {

			case 'CaptureTransaction':
			case 'ReverseTransaction':
			case 'GetTransactionLog':
			case 'GetTransactionDetails':
			case 'CreditInvoice':
			case 'ActivateInvoice':

				$transaction_id = get_post_meta( $this->order->id, 'payiq_transaction_id', true );

				$raw_sting = $this->service_name . $transaction_id . $this->shared_secret;

				break;

			case 'RefundTransaction':
			case 'AuthorizeSubscription':
			case 'GetSavedCards':
			case 'DeleteSavedCard':
			case 'AuthorizeRecurring':
			case 'CreateInvoice':
			case 'CheckSsn':

				return false;

			case 'PrepareSession':
			default:

				$raw_sting = $this->service_name . ($this->order->get_total() * 100) . $this->order->get_order_currency() . $this->get_order_ref() . $this->shared_secret;

				break;
		}

		/**
		 * Example data:
		 * ServiceName = “TestService”
		 * Amount = “15099”
		 * CurrencyCode = “SEK”
		 * OrderReference = “abc123”
		 * SharedSecret = “ncVFrw1H”
		 */

		$str = strtolower( $raw_sting );

		return md5( $str );
	}

	function validateChecksum( $post_data, $checksum ) {

		$raw_sting = $this->service_name .
			$post_data['orderreference'] .
			$post_data['transactionid'] .
			$post_data['operationtype'] .
			$post_data['authorizedamount'] .
			$post_data['settledamount'] .
			$post_data['currency'] .
			$this->shared_secret;

		$generated_checksum = md5( strtolower( $raw_sting ) );

		if ( $generated_checksum == $checksum ) {
			return true;
		}

		return [
			'generated' => $generated_checksum,
			'raw_sting' => $raw_sting
		];
	}

	function get_service_url( $endpoint ) {

		return self::$service_url . '/' . $endpoint;
	}

	/*
    function get_client( $endpoint ) {

        return new SoapClient(
            null,
            array(
                'location' => get_service_url( $endpoint ),
                'uri' => self::$vsdl_url,
                'trace' => 1,
                'use' => SOAP_LITERAL,
            )
        );
    }
    */

	function api_call( $endpoint, $data ) {

		try {

			$response = $this->client->__soapCall( $endpoint, $data );

			return $response;
		} catch (Exception $e) {

			$this->logger->add(
				'payiq',
				PHP_EOL.PHP_EOL .
				$this->client->__getLastResponseHeaders() .
				PHP_EOL .
				$this->client->__getLastResponse() .
				PHP_EOL.PHP_EOL .
				$this->client->__getLastRequestHeaders() .
				PHP_EOL .
				$this->client->__getLastRequest() .
				PHP_EOL .
				'Error: '.$e->faultstring .
				PHP_EOL.PHP_EOL
			);

		}

	}

	function get_order_ref() {

		return $this->order->id;
	}

	function get_customer_ref() {

		$customer_id = $this->order->get_user_id();

		// If guest
		if ( $customer_id == 0 ) {
			return '';
		}

		return 'customer_' . $customer_id;

		return $this->get_soap_string( 'CustomerReference', 'customer_' . $customer_id );
	}

	function get_order_items() {

		$items = $this->order->get_items();

		$order_items = [];

		foreach ( $items as $item ) {

			if ( isset( $item['variation_id'] ) && $item['variation_id'] > 0 ) {
				$product = new WC_Product( $item['variation_id'] );
			} else {
				$product = new WC_Product( $item['product_id'] );
			}

			$sku = $product->get_sku();

			// Use product ID if SKU is not set
			if ( empty( $sku ) ) {
				$sku = $product->get_id();
			}

			$order_items[] = [
				'Description'   => $product->get_title(),
				'SKU'           => $sku,
				'Quantity'      => $item['qty'],
				'UnitPrice'     => $this->format_price( ($item['line_total'] + $item['line_tax']) / $item['qty'] )
			];

		}

		//TODO: Add support for custom fees

		$fees = $this->order->get_fees();

		foreach ( $fees as $fee ) {

			$order_items[] = [
				'Description'   => $fee['name'],
				'SKU'           => '',
				'Quantity'      => 1,
				'UnitPrice'     => $this->format_price( $fee['line_total'] + $fee['line_tax'] )
			];
		}

		$shipping_methods = $this->order->get_shipping_methods();

		foreach ( $shipping_methods as $shipping_method ) {

			$tax_total = 0;
			$taxes = maybe_unserialize( $shipping_method['taxes'] );

			if ( is_array( $taxes ) ) {
				foreach ( $taxes as $tax ) {
					$tax_total += $tax;
				}
			}

			$order_items[] = [
				'Description'   => $shipping_method['name'],
				'SKU'           => $shipping_method['type'] . '_' . $shipping_method['method_id'],
				'Quantity'      => 1,
				'UnitPrice'     => $this->format_price( $shipping_method['cost'] + $tax_total )
			];
		}

		print_r( $order_items );

		return $order_items;
	}

	function get_order_description() {

		$items = $this->order->get_items();

		$order_items = [];

		foreach ( $items as $item ) {

			$order_items[] = $item['name'] . ' x ' . $item['qty'] . ' ' . $item['line_total'];
		}

		return sprintf( __( 'Order #%s.' ), $this->order->id ) . sprintf( 'Items: %s.', implode( ',', $order_items ) );
	}

	function get_transaction_settings( $options = [] ) {

		$data = [
			'AutoCapture'       => 'true',  //( isset( $options ) ? 'true' : 'false' ),
			'CallbackUrl'       => trailingslashit( site_url( '/woocommerce/payiq-callback' ) ),
			'CreateSubscription' => 'false',
			'DirectPaymentBank' => '',
			'FailureUrl'        => trailingslashit( site_url( '/woocommerce/payiq-failure' ) ),
			//Allowed values: Card, Direct, NotSet
			'PaymentMethod'     => 'NotSet',
			'SuccessUrl'        => trailingslashit( site_url( '/woocommerce/payiq-success' ) ),
		];

		return $data;
	}

	function get_order_info() {

		$data = [
			'OrderReference' => $this->get_order_ref(),
			'OrderItems' => $this->get_order_items(),
			'Currency' => $this->order->get_order_currency(),
			// Optional alphanumeric string to indicate the transaction category.
			// Enables you to group and filter the transaction log and reports based on a custom criterion of your choice.
			//'OrderCategory' => '',
			// Optional order description displayed to end‐user on the payment site.
			'OrderDescription' => '',
		];

		return $data;

		$data = [
			'a:OrderReference' => $this->get_order_ref(),
			'a:OrderItems' => $this->get_order_items(),
			'a:Currency' => $this->order->get_order_currency(),
			// Optional alphanumeric string to indicate the transaction category.
			// Enables you to group and filter the transaction log and reports based on a custom criterion of your choice.
			//'OrderCategory' => '',
			// Optional order description displayed to end‐user on the payment site.
			//'OrderDescription' => '',
		];

		return $this->get_soap_object( 'OrderInfo', $data );

	}

	function get_request_xml( $method, $data = [] ) {

		$template_file = WC_PAYIQ_PLUGIN_DIR.'xml-templates/' . $method . '.php';

		if ( ! file_exists( $template_file ) ) {
			return false;
		}

		ob_start();

		require $template_file;

		$xml = ob_get_clean();

		return $xml;
	}

	function format_price( $price ) {

		return intval( $price * 100 );

	}


	function prepareSession( $options = [] ) {

		$data = [
			'Checksum' => $this->getChecksum( 'PrepareSession' ),
			'CustomerReference' => $this->get_customer_ref(),
			'Language' => 'sv',
			'OrderInfo' => $this->get_order_info(),
			'ServiceName' => $this->service_name,
			//'TransactionSettings' => new TransactionSettings(),
			'TransactionSettings' => $this->get_transaction_settings( $options ),
		];

		$xml = $this->get_request_xml( 'PrepareSession', $data );

		$response = $this->client->__myDoRequest( $xml, 'PrepareSession' );

		$dom = new DOMDocument();
		$dom->loadXML( $response );
		$ns = 'http://schemas.wiredge.se/payment/api/v2/objects';

		$data = $this->get_xml_fields( $response, [
			'RedirectUrl'
		], $ns);

		$redirect_url = $data['RedirectUrl'];

		return $redirect_url;
	}


	function CaptureTransaction( $TransactionId ) {

		$data = [
			'Checksum'          => $this->getChecksum( 'CaptureTransaction' ),
			'ClientIpAddress'   => $this->get_customer_ref(),
			'ServiceName'       => $this->service_name,
			'TransactionId'     => $TransactionId,
		];

		$xml = $this->get_request_xml( 'CaptureTransaction', $data );

		$response = $this->client->__myDoRequest( $xml, 'CaptureTransaction' );

		$data = $this->get_xml_fields( $response, [
			'Succeeded', 'ErrorCode', 'AuthorizedAmount', 'SettledAmount'
		]);

		return $data;
	}


	function get_xml_fields( $xml, $fields = [], $namespace = 'http://schemas.wiredge.se/payment/api/v2/objects' ) {

		$xmldoc = new DOMDocument();
		$xmldoc->loadXML( $xml );

		$data = [];

		foreach ( $fields as $field ) {

			if ( $xmldoc->getElementsByTagNameNS( $namespace, $field )->length > 0 ) {
				$data[$field] = $xmldoc->getElementsByTagNameNS( $namespace, $field )->item( 0 )->nodeValue;
			}
			else {
				$data[$field] = '';
			}
		}

		return $data;
	}



	function get_payment_window_url() {

	}

	function get_soap_string( $name, $data ) {

		return new SoapVar( $data, XSD_STRING, null, null, 'a:'.$name );

	}

	function get_soap_object( $name, $data ) {

		return new SoapVar( $data, SOAP_ENC_OBJECT, null, null, 'a:'.$name );

	}
}
