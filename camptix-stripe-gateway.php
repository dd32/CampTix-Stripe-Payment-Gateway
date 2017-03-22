<?php
/**
 * Plugin Name: CampTix Stripe Payment Gateway
 * Plugin URI: https://github.com/dd32/CampTix-Stripe-Payment-Gateway
 * Description: Stripe Payment Gateway Support for CampTix
 * Author: Dion Hulse
 * Author URI: https://dd32.id.au/
 * Version: 0.1beta
 * License: GPLv2 or later
 * Text Domain: camptix-stripe-payment-gateway
 */

class CampTix_Stripe {
	protected static $instance = null;

	private function __construct() {
		if ( ! class_exists( 'CampTix_Payment_Method' ) ) {
			// Just bail.
			return;
		}

		add_action( 'camptix_load_addons', array( $this, 'camptix_load_addons' ) );
	}

	public static function get_instance() {
		return self::$instance ?
				self::$instance :
				( self::$instance = new self );
	}

	public function camptix_load_addons() {
		require_once __DIR__ . '/class-camptix-payment-method-stripe.php';

		camptix_register_addon( 'CampTix_Payment_Method_Stripe' );
	}
}

add_action( 'plugins_loaded', array( 'CampTix_Stripe', 'get_instance' ) );
