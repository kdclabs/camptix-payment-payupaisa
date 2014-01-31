<?php

/**
 * CampTix PayU Money Payment Method
 *
 * This class handles all PayU Money integration for CampTix
 *
 * @since		1.0
 * @package		CampTix
 * @category	Class
 * @author 		Vachan Kudmule (_KDC-Labs)
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class CampTix_Payment_Method_PayuPaisa extends CampTix_Payment_Method {
	public $id = 'payupaisa';
	public $name = 'PayU Money';
	public $description = 'Redefining Payments, Simplifying Lives! Empowering any business to collect money online within minutes.';
	public $supported_currencies = array( 'INR' );

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	function camptix_init() {
		$this->options = array_merge( array(
			'merchant_key' => '',
			'merchant_salt' => '',
			'sandbox' => true
		), $this->get_payment_options() );

		// IPN Listener
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_key', 'Merchant KEY', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'merchant_salt', 'Merchant SALT', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'camptix' ), array( $this, 'field_yesno' ),
			__( "The Test Mode is a way to test payments. Any amount debited from your account will be re-credited within Five (5) working days.", 'camptix' )
		);
	}

	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_key'] ) )
			$output['merchant_key'] = $input['merchant_key'];
		if ( isset( $input['merchant_salt'] ) )
			$output['merchant_salt'] = $input['merchant_salt'];

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

	/**
	 * Runs when PayU Money sends an ITN signal.
	 * Verify the payload and use $this->payment_result
	 * to signal a transaction result back to CampTix.
	 */
	function payment_notify() {
		global $camptix;

		$this->log( sprintf( 'Running payment_notify. Request data attached.' ), null, $_REQUEST );
		$this->log( sprintf( 'Running payment_notify. Server data attached.' ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$payload = stripslashes_deep( $_REQUEST );

		$merchant_key = $this->options['merchant_key'];
		$merchant_salt = $this->options['merchant_salt'];
		$hash = $_REQUEST['hash'];
		$status = $_REQUEST['status'];
		$checkhash = hash('sha512', "$merchant_salt|$_REQUEST[status]||||||||||$_REQUEST[udf1]|$_REQUEST[email]|$_REQUEST[firstname]|$_REQUEST[productinfo]|$_REQUEST[amount]|$_REQUEST[txnid]|$merchant_key");

		$data_string = '';
		$data_array = array();

		// Dump the submitted variables and calculate security signature
		foreach ( $payload as $key => $val ) {
			//if ( $key != 'signature' ) {
				$data_string .= $key .'='. urlencode( $val ) .'&';
				$data_array[$key] = $val;
			//}
		}
		$data_string = substr( $data_string, 0, -1 );
		$signature = md5( $data_string );

		if ( $hash == $checkhash ) {
			if ( $payload['status'] != "" ) {
				switch ( $payload['status'] ) {
					case "success" :
						$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED );
						break;
					case "failed" :
						$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
						break;
					case "pending" :
						$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
						break;
				}
			} else {
				$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING );
			}
		} else {
			$this->log( sprintf( 'Request failed, hash mismatch: %s', $payload ) );
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
		
		
		$merchant_key = $this->options['merchant_key'];
		$merchant_salt = $this->options['merchant_salt'];
		$productinfo = 'Ticket for Order: '.$payment_token;
		$order_amount = $order['total'];
		$order_id = $payment_token;

		$str = "$merchant_key|$payment_token|$order_amount|$productinfo|FirstName|email@domain.ext|$payment_token||||||||||$merchant_salt";
		$hash = strtolower(hash('sha512', $str));

		$payload = array(
			'key' 			=> $merchant_key,
			'hash' 			=> $hash,
			'txnid' 		=> $payment_token,
			'amount' 		=> $order_amount,
			'firstname'		=> 'FirstName',
			'email' 		=> 'email@domain.ext',
			'phone' 		=> '1234567890',
			'productinfo'	=> $productinfo,
			'surl' 			=> $return_url,
			'furl' 			=> $notify_url,
			'lastname' 		=> 'LastName',
			'address1' 		=> 'Address1',
			'address2' 		=> 'Address2',
			'city' 			=> 'city',
			'state' 		=> 'state',
			'country' 		=> 'country',
			'zipcode' 		=> 'postcode',
			'curl'			=> $cancel_url,
			'pg' 			=> 'NB',
			'udf1' 			=> $payment_token,
			'service_provider'	=> 'payu_paisa' // must be "payu_paisa"
		);
		if ( $this->options['sandbox'] ) {
			$payload['merchant_key'] = 'JBZaLc';
			$payload['merchant_salt'] = 'GQs7yium';
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