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

		add_filter( 'camptix_register_registration_info_header', array( $this, 'camptix_register_registration_info_header' ) );
		add_filter( 'camptix_payment_result', array( $this, 'camptix_payment_result' ), 10, 3 );

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
		wp_enqueue_script( 'camptix-stripe', plugins_url( 'camptix-stripe.js', __DIR__ . '/camptix-stripe-gateway.php' ), array( 'stripe-checkout', 'jquery' ), '20180131', true );

		wp_localize_script( 'camptix-stripe', 'CampTixStripeData', array(
			'public_key'    => $this->options['api_public_key'],
			'name'          => $this->camptix_options['event_name'],
			'description'   => trim( $description ),
			'amount'        => round($camptix->order['total'] * 100),
			'currency'      => $this->camptix_options['currency'],
			'token'         => !empty( $_POST['tix_stripe_token'] ) ? wp_unslash( $_POST['tix_stripe_token'] ) : '',
			'receipt_email' => !empty( $_POST['tix_stripe_reciept_email'] ) ? wp_unslash( $_POST['tix_stripe_reciept_email'] ) : '',
            'ask_email'     => $this->options['ask_email'],
            'ask_billing'   => $this->options['ask_billing'],
            'logo_url'      => $this->options['logo_url'],
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

        // Ask a few questions for configuration:
		$this->add_settings_field_helper( 'ask_email', __( 'Ask for a billing email?', 'camptix-stripe-payment-gateway' ), array( $this, 'field_yesno'  ) );
		$this->add_settings_field_helper( 'ask_billing', __( 'Ask for a billing address?', 'camptix-stripe-payment-gateway' ), array( $this, 'field_yesno'  ) );
		$this->add_settings_field_helper( 'logo_url', __( 'URL for checkout logo', 'camptix-stripe-payment-gateway' ), array( $this, 'field_text'  ) );
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

		if ( isset( $input['ask_email'] ) ) {
			$output['ask_email'] = !empty($input['ask_email']);
		}

		if ( isset( $input['ask_billing'] ) ) {
			$output['ask_billing'] = !empty($input['ask_billing']);
		}

		if ( isset( $input['logo_url'] ) ) {
			$output['logo_url'] = $input['logo_url'];
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

		} catch( Exception $e ) {
			// A failure happened, since we don't expose the exact details to the user we'll catch every failure here.
			// Remvoe the POST param of the token so it's not used again.
			unset( $_POST['tix_stripe_token'] );

			$camptix->log( 'Error during Charge.', null, $e->getMessage() );

			$json_body = $e->getJsonBody();
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
				'transaction_id' => $json_body['error']['charge'],
				'transaction_details' => array(
					'raw' => $json_body,
				),
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
	 * Adds a failure reason / code to the post-payment screen whne the payment fails.
	 *
	 * @param string $payment_token
	 * @param int    $result
	 * @param mixed  $data
	 */
	function camptix_payment_result( $payment_token, $result, $data ) {
		global $camptix;

		if ( $camptix::PAYMENT_STATUS_FAILED == $result && !empty( $data['transaction_details']['raw']['error'] ) ) {

			$error_data = $data['transaction_details']['raw']['error'];

			$message = $error_data['message'];
			$code = $error_data['code'];
			if ( isset( $error_data['decline_code'] ) ) {
				$code .= ' ' . $error_data['decline_code'];
			}

			$camptix->error(
				sprintf(
					__( 'Your payment has failed: %s (%s)', 'camptix-stripe-payment-gateway' ),
					$message,
					$code
				)
			);

			// Unfortunately there's no way to remove the following failure message, but at least ours will display first:
			// A payment error has occurred, looks like chosen payment method is not responding. Please try again later.

		}
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
			$error_message = $result['refund_transaction_details'];

			if ( ! empty( $error_message ) ) {
				$camptix->error( sprintf( __( 'Stripe error: %s', 'camptix-stripe-payment-gateway' ), $error_message ) );
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
		
		try {
			$charge = \Stripe\Refund::create( array(
				'charge' => $result['transaction_id'],
			) );
			
			$result['refund_transaction_id']      = $charge->id;
			$result['refund_transaction_details'] = $charge;
			$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		} catch( Exception $e ) {
			$result['refund_transaction_id']      = false;
			$result['refund_transaction_details'] = $e->getMessage();
			$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED;

			$camptix->log( 'Error during RefundTransaction.', null, $e->getMessage() );
		}

		return $result;
	}


}
