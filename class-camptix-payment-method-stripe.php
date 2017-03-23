<?php

class CampTix_Payment_Method_Stripe extends CampTix_Payment_Method {
	public $id = 'stripe';
	public $name = 'Stripe';
	public $description = 'Stripe';

	// See https://support.stripe.com/questions/which-currencies-does-stripe-support
	// Only testing with AUD and USD though.
	public $supported_currencies = array( 'AUD', 'USD' );

	public $supported_features = array(
		'refund-single' => true,
		'refund-all' => true,
	);

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	/**
	 * Runs during camptix_init, loads our options and sets some actions.
	 *
	 * @see CampTix_Addon
	 */
	function camptix_init() {
		$this->options = array_merge( array(
			'api_secret_key' => '',
			'api_public_key' => '',
			'api_predef' => '',
		), $this->get_payment_options() );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
//		add_action( 'camptix_attendee_form_before_input', array( $this, 'camptix_attendee_form_before_input' ), 10, 200 );
		add_filter( 'camptix_register_registration_info_header', array( $this, 'camptix_register_registration_info_header' ) );

		require_once __DIR__ . '/stripe-php/init.php';

		\Stripe\Stripe::setAppInfo("CampTix", "1.0", "https://github.com/dd32/CampTix-Stripe-Payment-Gateway");
		$options = array_merge( $this->options, $this->get_predefined_account( $this->options['api_predef'] ) );

		\Stripe\Stripe::setApiKey( $options['api_secret_key'] );
	}

	// Bit hacky, but it'll work.
	function camptix_register_registration_info_header( $filter ) {
		global $camptix;

		if ( ! $camptix->order['total'] ) {
			return $filter;
		}

		$description = '';
		$ticket_count = array_sum( wp_list_pluck( $camptix->order['items'], 'quantity' ) );
		foreach ( $camptix->order['items'] as $item ) {
			$description .= ( $ticket_count > 1 ?  (int)$item['quantity'] . 'x ' : '' ) . $item['name'] . "\n";
		}

		wp_register_script( 'stripe-checkout', 'https://checkout.stripe.com/checkout.js', array(), false, true );
		wp_enqueue_script( 'camptix-stripe', plugins_url( 'camptix-stripe.js', __DIR__ . '/camptix-stripe-gateway.php' ), array( 'stripe-checkout', 'jquery' ), '20170322', true );

		wp_localize_script( 'camptix-stripe', 'CampTixStripeData', array(
			'public_key'  => $this->options['api_public_key'],
			'name'        => $this->camptix_options['event_name'],
			'description' => trim( $description ),
			'amount'      => (int) $camptix->order['total'] * 100,
			'currency'    => $this->camptix_options['currency'],

			'token'       => !empty( $_POST['tix_stripe_token'] ) ? wp_unslash( $_POST['tix_stripe_token'] ) : '',
			'receipt_email' => !empty( $_POST['tix_stripe_reciept_email'] ) ? wp_unslash( $_POST['tix_stripe_reciept_email'] ) : '',
		) );

		return $filter;
	}

	/**
	 * Add payment settings fields
	 *
	 * This runs during settings field registration in CampTix for the
	 * payment methods configuration screen. If your payment method has
	 * options, this method is the place to add them to. You can use the
	 * helper function to add typical settings fields. Don't forget to
	 * validate them all in validate_options.
	 */
	function payment_settings_fields() {
		// Allow pre-defined accounts if any are defined by plugins.
		if ( count( $this->get_predefined_accounts() ) > 0 ) {
			$this->add_settings_field_helper( 'api_predef', __( 'Predefined Account', 'camptix-stripe-payment-gateway' ), array( $this, 'field_api_predef'	) );
		}

		// Settings fields are not needed when a predefined account is chosen.
		// These settings fields should *never* expose predefined credentials.
		if ( ! $this->get_predefined_account() ) {
			$this->add_settings_field_helper( 'api_secret_key', __( 'Secret Key',      'camptix-stripe-payment-gateway' ), array( $this, 'field_text'  ) );
			$this->add_settings_field_helper( 'api_public_key', __( 'Publishable Key', 'camptix-stripe-payment-gateway' ), array( $this, 'field_text'  ) );
		}
	}

	/**
	 * Predefined accounts field callback
	 *
	 * Renders a drop-down select with a list of predefined accounts
	 * to select from, as well as some js for better ux.
	 *
	 * @uses $this->get_predefined_accounts()
	 *
	 * @param array $args
	 */
	function field_api_predef( $args ) {
		$accounts = $this->get_predefined_accounts();

		if ( empty( $accounts ) ) {
			return;
		}

		?>

		<select id="camptix-predef-select" name="<?php echo esc_attr( $args['name'] ); ?>">
			<option value=""><?php _e( 'None', 'camptix-stripe-payment-gateway' ); ?></option>

			<?php foreach ( $accounts as $key => $account ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['value'], $key ); ?>>
					<?php echo esc_html( $account['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<!-- Let's disable the rest of the fields unless None is selected -->
		<script>
			jQuery( document ).ready( function( $ ) {
				var select = $('#camptix-predef-select')[0];

				$( select ).on( 'change', function() {
					$( '[name^="camptix_payment_options_stripe"]' ).each( function() {
						// Don't disable myself.
						if ( this == select ) {
							return;
						}

						$( this ).prop( 'disabled', select.value.length > 0 );
						$( this ).toggleClass( 'disabled', select.value.length > 0 );
					});
				});
			});
		</script>

		<?php
	}

	/**
	 * Get an array of predefined PayPal accounts
	 *
	 * Runs an empty array through a filter, where one might specify a list of
	 * predefined PayPal credentials, through a plugin or something.
	 *
	 * @static $predefs
	 *
	 * @return array An array of predefined accounts (or an empty one)
	 */
	function get_predefined_accounts() {
		static $predefs = false;

		if ( false === $predefs ) {
			$predefs = apply_filters( 'camptix_stripe_predefined_accounts', array() );
		}

		return $predefs;
	}

	/**
	 * Get a predefined account
	 *
	 * If the $key argument is false or not set, this function will look up the active
	 * predefined account, otherwise it'll look up the one under the given key. After a
	 * predefined account is set, PayPal credentials will be overwritten during API
	 * requests, but never saved/exposed. Useful with array_merge().
	 *
	 * @param string $key
	 *
	 * @return array An array with credentials, or an empty array if key not found.
	 */
	function get_predefined_account( $key = false ) {
		$accounts = $this->get_predefined_accounts();

		if ( false === $key ) {
			$key = $this->options['api_predef'];
		}

		if ( ! array_key_exists( $key, $accounts ) ) {
			return array();
		}

		return $accounts[ $key ];
	}

	/**
	 * Validate options
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['api_secret_key'] ) ) {
			$output['api_secret_key'] = $input['api_secret_key'];
		}

		if ( isset( $input['api_public_key'] ) ) {
			$output['api_public_key'] = $input['api_public_key'];
		}

		if ( isset( $input['api_predef'] ) ) {
			// If a valid predefined account is set, erase the credentials array.
			// We do not store predefined credentials in options, only code.
			if ( $this->get_predefined_account( $input['api_predef'] ) ) {
				$output = array_merge( $output, array(
					'api_secret_key'  => '',
					'api_public_key'  => '',
				) );
			} else {
				$input['api_predef'] = '';
			}

			$output['api_predef'] = $input['api_predef'];
		}

		return $output;
	}

	/**
	 * Watch for and process Stripe requests
	 */
	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'stripe' != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_cancel' == $_GET['tix_action'] ) {
				$this->payment_cancel();
			}

			if ( 'payment_return' == $_GET['tix_action'] ) {
				$this->payment_return();
			}

			if ( 'payment_notify' == $_GET['tix_action'] ) {
				$this->payment_notify();
			}
		}
	}

	/**
	 * Handle a canceled payment
	 *
	 * Runs when the user cancels their payment during checkout at PayPal.
	 * his will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	function payment_cancel() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$camptix->log( sprintf( 'Running payment_cancel. Request data attached.' ), null, $_REQUEST );
		$camptix->log( sprintf( 'Running payment_cancel. Server data attached.'  ), null, $_SERVER );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$paypal_token = ( isset( $_REQUEST['token'] ) ) ? trim( $_REQUEST['token'] ) : '';

		if ( ! $payment_token || ! $paypal_token ) {
			wp_die( 'empty token' );
		}

		$attendees = get_posts( array(
			'posts_per_page' => 1,
			'post_type'      => 'tix_attendee',
			'post_status'    => 'any',
			'meta_query'     => array(
				array(
					'key'     => 'tix_payment_token',
					'compare' => '=',
					'value'   => $payment_token,
					'type'    => 'CHAR',
				),
			),
		) );

		if ( ! $attendees ) {
			die( 'attendees not found' );
		}

		// Set the associated attendees to cancelled.
		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}

	/**
	 * Process a checkout request
	 *
	 * This method is the fire starter. It's called when the user initiates
	 * a checkout process with the selected payment method. In PayPal's case,
	 * if everything's okay, we redirect to the PayPal Express Checkout page with
	 * the details of our transaction. If something's wrong, we return a failed
	 * result back to CampTix immediately.
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	function payment_checkout( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( __( 'The selected currency is not supported by this payment method.', 'camptix-stripe-payment-gateway' ) );
		}


		$order = $this->get_order( $payment_token );

		// One final check before charging the user.
		if ( ! $camptix->verify_order( $order ) ) {
			$camptix->log( "Dying because couldn't verify order", $order['attendee_id'] );
			wp_die( 'Something went wrong, order is no longer available.' );
		}

		try {

			$token = \Stripe\Token::retrieve( wp_unslash( $_POST['tix_stripe_token'] ) );
			
			$description = '';
			$ticket_count = array_sum( wp_list_pluck( $camptix->order['items'], 'quantity' ) );
			foreach ( $camptix->order['items'] as $item ) {
				$description .= ( $ticket_count > 1 ?  (int)$item['quantity'] . 'x ' : '' ) . $item['name'] . "\n";
			}

			$statement_descriptor = $camptix->substr_bytes( strip_tags( $this->camptix_options['event_name'] ), 0, 22 );

			$charge = \Stripe\Charge::create( array(
				'amount'        => $camptix->order['total'] * 100,
				'currency'      => $this->camptix_options['currency'],
				'description'   => $this->camptix_options['event_name'],
				'statement_descriptor' => $statement_descriptor,
				'source'        => $token->id,
				'receipt_email' => isset( $_POST['tix_stripe_reciept_email'] ) ? wp_unslash( $_POST['tix_stripe_reciept_email'] ) : false,
			), array(
				// The payment token, to ensure that multiple charges are not made.
				'idempotency_key' => $payment_token,
			) );

		} catch( \Stripe\Error\Card $e ) {
			// Since it's a decline, \Stripe\Error\Card will be caught
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'declined',
				'raw' => $e->getJsonBody(),
			) );
		} catch( \Stripe\Error\RateLimit $e ) {
			// Too many requests made to the API too quickly
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'ratelimit',
				'raw' => $e->getJsonBody(),
			) );
		} catch( \Stripe\Error\InvalidRequest $e ) {
			// Invalid parameters were supplied to Stripe's API
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'invalid_request',
				'raw' => $e->getJsonBody(),
			) );
		} catch( \Stripe\Error\Authentication $e ) {
			// Authentication with Stripe's API failed
			// (maybe you changed API keys recently)
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'authentication_failed',
				'raw' => $e->getJsonBody(),
			) );
		} catch( \Stripe\Error\ApiConnection $e ) {
			// Network communication with Stripe failed
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'network_error',
				'raw' => $e->getJsonBody(),
			) );
		} catch( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'request_failed',
				'raw' => $e->getJsonBody(),
			) );
		} catch( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'error_code' => 'request_failed',
				'raw' => $e->getJsonBody(),
			) );
		}

		$payment_data = array(
			'transaction_id' => $charge->id,
			'transaction_details' => array(
				'raw' => array(
					'token' => $token->jsonSerialize(),
					'charge' => $charge->jsonSerialize(),
				)
			),
		);

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
	}


	/**
	 * Submits a single, user-initiated refund request to PayPal and returns the result
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	function payment_refund( $payment_token ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$result = $this->send_refund_request( $payment_token );

		if ( CampTix_Plugin::PAYMENT_STATUS_REFUNDED != $result['status'] ) {
			$error_code    = isset( $result['refund_transaction_details']['L_ERRORCODE0'] )   ? $result['refund_transaction_details']['L_ERRORCODE0']   : 0;
			$error_message = isset( $result['refund_transaction_details']['L_LONGMESSAGE0'] ) ? $result['refund_transaction_details']['L_LONGMESSAGE0'] : '';

			if ( ! empty( $error_message ) ) {
				$camptix->error( sprintf( __( 'PayPal error: %s (%d)', 'camptix-stripe-payment-gateway' ), $error_message, $error_code ) );
			}
		}

		$refund_data = array(
			'transaction_id'             => $result['transaction_id'],
			'refund_transaction_id'      => $result['refund_transaction_id'],
			'refund_transaction_details' => array(
				'raw' => $result['refund_transaction_details'],
			),
		);

		return $camptix->payment_result( $payment_token, $result['status'] , $refund_data );
	}

	/*
	 * Sends a request to PayPal to refund a transaction
	 *
	 * @param string $payment_token
	 *
	 * @return array
	 */
	function send_refund_request( $payment_token ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$result = array(
			'token'          => $payment_token,
			'transaction_id' => $camptix->get_post_meta_from_payment_token( $payment_token, 'tix_transaction_id' ),
		);

		// Craft and submit the request
		$payload = array(
			'METHOD'        => 'RefundTransaction',
			'TRANSACTIONID' => $result['transaction_id'],
			'REFUNDTYPE'    => 'Full',
		);
		$response = $this->request( $payload );

		// Process PayPal's response
		if ( is_wp_error( $response ) ) {
			// HTTP request failed, so mimic the response structure to provide a consistent response format
			$response = array(
				'ACK'            => 'Failure',
				'L_ERRORCODE0'   => 0,
				'L_LONGMESSAGE0' => __( 'Request did not complete successfully', 'camptix-stripe-payment-gateway' ),	// don't reveal the raw error message to the user in case it contains sensitive network/server/application-layer data. It will be logged instead later on.
				'raw'            => $response,
			);
		} else {
			$response = wp_parse_args( wp_remote_retrieve_body( $response ) );
		}

		if ( isset( $response['ACK'], $response['REFUNDTRANSACTIONID'] ) && 'Success' == $response['ACK'] ) {
			$result['refund_transaction_id']      = $response['REFUNDTRANSACTIONID'];
			$result['refund_transaction_details'] = $response;
			$result['status']                     = $this->get_status_from_string( $response['REFUNDSTATUS'] );
		} else {
			$result['refund_transaction_id']      = false;
			$result['refund_transaction_details'] = $response;
			$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED;

			$camptix->log( 'Error during RefundTransaction.', null, $response );
		}

		return $result;
	}


}
