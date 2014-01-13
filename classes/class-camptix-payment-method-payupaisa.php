<?php

/**
 * CampTix PayU Paisa Payment Method
 *
 * This class handles all PayU Paisa integration for CampTix
 *
 * @since		1.0
 * @package		CampTix
 * @category	Class
 * @author 		Vachan Kudmule (_KDC-Labs)
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class CampTix_Payment_Method_PayuPaisa extends CampTix_Payment_Method {
	public $id = 'payupaisa';
	public $name = 'PayU Paisa';
	public $description = 'Redefining Payments, Simplifying Lives! Empowering any business to collect money online within minutes.';
	public $supported_currencies = array( 'INR' );

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	function camptix_init() {
		$this->options = array_merge( array(
			'merchant_id' => '',
			'merchant_key' => '',
			'sandbox' => true
		), $this->get_payment_options() );

		// IPN Listener
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_id', 'Merchant ID', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'merchant_key', 'Merchant Key', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'camptix' ), array( $this, 'field_yesno' ),
			__( "The Test Mode is a way to test payments. Any amount debited from your account will be re-credited within Five (5) working days.", 'camptix' )
		);
	}

	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_id'] ) )
			$output['merchant_id'] = $input['merchant_id'];
		if ( isset( $input['merchant_key'] ) )
			$output['merchant_key'] = $input['merchant_key'];

		if ( isset( $input['sandbox'] ) )
			$output['sandbox'] = (bool) $input['sandbox'];

		return $output;
	}

	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'payupaisa' != $_REQUEST['tix_payment_method'] )
			return;

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_cancel' == $_GET['tix_action'] )
				$this->payment_cancel();

			if ( 'payment_return' == $_GET['tix_action'] )
				$this->payment_return();

			if ( 'payment_notify' == $_GET['tix_action'] )
				$this->payment_notify();
		}
	}

	function payment_return() {
		global $camptix;

		$this->log( sprintf( 'Running payment_return. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_return. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		if ( empty( $payment_token ) )
			return;

		$attendees = get_posts(
			array(
				'posts_per_page' => 1,
				'post_type' => 'tix_attendee',
				'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
				'meta_query' => array(
					array(
						'key' => 'tix_payment_token',
						'compare' => '=',
						'value' => $payment_token,
						'type' => 'CHAR',
					),
				),
			)
		);

		if ( empty( $attendees ) )
			return;

		$attendee = reset( $attendees );

		if ( 'draft' == $attendee->post_status ) {
			return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
		} else {
			$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
			$url = add_query_arg( array(
				'tix_action' => 'access_tickets',
				'tix_access_token' => $access_token,
			), $camptix->get_tickets_url() );

			wp_safe_redirect( esc_url_raw( $url . '#tix' ) );
			die();
		}
	}

	function validate_response_data( $data ) {
		$url = $this->options['sandbox'] ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment';

		$response = wp_remote_post( $url, array(
			'method' => 'POST',
			'body' => $data,
			'timeout' => 70,
			'sslverify' => true,
			'user-agent' => 'WordCamp-CampTix-Plugin'
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'There was a problem connecting to the payment gateway. Response data attached.' ), null, $response );
			return false;
		}

		if ( empty( $response['body'] ) ) {
			$this->log( sprintf( 'Empty PayU Paisa response. Response data attached.' ), null, $response );
			return false;
		}

		parse_str( $response['body'], $parsed_response );

		$response = $parsed_response;

		// Interpret Response
		if ( is_array( $response ) && in_array( 'VALID', array_keys( $response ) ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Runs when PayU Paisa sends an ITN signal.
	 * Verify the payload and use $this->payment_result
	 * to signal a transaction result back to CampTix.
	 */
	function payment_notify() {
		global $camptix;

		$this->log( sprintf( 'Running payment_notify. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_notify. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$payload = stripslashes_deep( $_POST );

		$data_string = '';
		$data_array = array();

		// Dump the submitted variables and calculate security signature
		foreach ( $payload as $key => $val ) {
			if ( $key != 'signature' ) {
				$data_string .= $key .'='. urlencode( $val ) .'&';
				$data_array[$key] = $val;
			}
		}
		$data_string = substr( $data_string, 0, -1 );
		$signature = md5( $data_string );

		$pfError = false;
		if ( 0 != strcmp( $signature, $payload['signature'] ) ) {
			$pfError = true;
			$this->log( sprintf( 'ITN request failed, signature mismatch: %s', $payload ) );
		}

		// Verify IPN came from PayU Paisa
		if ( ! $pfError && $this->validate_response_data( $data_array ) ) {
			switch ( $payload['payment_status'] ) {
				case "COMPLETE" :
					$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED );
					break;
				case "FAILED" :
					$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
					break;
				case "PENDING" :
					$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
					break;
			}
		} else {
			$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
		}
	}

	public function payment_checkout( $payment_token ) {

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			die( __( 'The selected currency is not supported by this payment method.', 'camptix' ) );

		$return_url = add_query_arg( array(
			'tix_action' => 'payment_return',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'payupaisa',
		), $this->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action' => 'payment_cancel',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'payupaisa',
		), $this->get_tickets_url() );

		$notify_url = add_query_arg( array(
			'tix_action' => 'payment_notify',
			'tix_payment_token' => $payment_token,
			'tix_payment_method' => 'payupaisa',
		), $this->get_tickets_url() );

		$order = $this->get_order( $payment_token );

		$payload = array(
			// Merchant details
			'merchant_id' => $this->options['merchant_id'],
			'merchant_key' => $this->options['merchant_key'],
			'return_url' => $return_url,
			'cancel_url' => $cancel_url,
			'notify_url' => $notify_url,

			// Item details
			'm_payment_id' => $payment_token,
			'amount' => $order['total'],
			'item_name' => get_bloginfo( 'name' ) .' purchase, Order ' . $payment_token,
			'item_description' => sprintf( __( 'New order from %s', 'woothemes' ), get_bloginfo( 'name' ) ),

			// Custom strings
			'custom_str1' => $payment_token,
			'source' => 'WordCamp-CampTix-Plugin'
		);
		if ( $this->options['sandbox'] ) {
			$payload['merchant_id'] = '10000100';
			$payload['merchant_key'] = '46f0cd694581a';
		}

		$payupaisa_args_array = array();
		foreach ( $payload as $key => $value ) {
			$payupaisa_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
		$url = $this->options['sandbox'] ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment';

		echo '<div id="tix">
					<form action="' . $url . '" method="post" id="payupaisa_payment_form">
						' . implode( '', $payupaisa_args_array ) . '
						<script type="text/javascript">
							document.getElementById("payupaisa_payment_form").submit();
						</script>
					</form>
				</div>';
		return;
	}

	/**
	 * Runs when the user cancels their payment during checkout at PayPal.
	 * his will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 */
	function payment_cancel() {
		global $camptix;

		$this->log( sprintf( 'Running payment_cancel. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_cancel. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		if ( ! $payment_token )
			die( 'empty token' );
		// Set the associated attendees to cancelled.
		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}
}
?>