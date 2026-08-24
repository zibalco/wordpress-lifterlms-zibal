<?php
/**
 * Plugin Name: افزونه پرداخت زیبال برای LifterLMS
 * Plugin URI:  https://github.com/zibalco/wordpress-lifterlms-zibal
 * Description: پرداخت دوره‌ها و عضویت‌های LifterLMS از طریق درگاه زیبال.
 * Version:     2.3.2
 * Author:      Zibal
 * Text Domain: lifterlms-zibal
 * Domain Path: /languages
 * License:     GPLv2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: lifterlms
 *
 * @package LifterLMS_Zibal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LifterLMS_zibal' ) ) {

	/**
	 * Plugin bootstrap.
	 */
	final class LifterLMS_zibal {

		const VERSION               = '2.3.2';
		const MIN_LIFTERLMS_VERSION = '3.30.0';

		/**
		 * Plugin version retained as a public property for backwards compatibility.
		 *
		 * @var string
		 */
		public $version = self::VERSION;

		/**
		 * Singleton instance.
		 *
		 * @var LifterLMS_zibal|null
		 */
		private static $instance = null;

		/**
		 * Get the singleton instance.
		 *
		 * @return LifterLMS_zibal
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->define_constants();
			add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
		}

		/**
		 * Define public plugin constants retained for backwards compatibility.
		 */
		private function define_constants() {
			if ( ! defined( 'LLMS_zibal_PLUGIN_FILE' ) ) {
				define( 'LLMS_zibal_PLUGIN_FILE', __FILE__ );
			}

			if ( ! defined( 'LLMS_zibal_PLUGIN_DIR' ) ) {
				define( 'LLMS_zibal_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
			}
		}

		/**
		 * Load the gateway after LifterLMS is available.
		 */
		public function init() {
			load_plugin_textdomain( 'lifterlms-zibal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			$lifterlms = function_exists( 'llms' ) ? llms() : ( function_exists( 'LLMS' ) ? LLMS() : false );
			if ( ! $lifterlms || ! isset( $lifterlms->version ) || version_compare( $lifterlms->version, self::MIN_LIFTERLMS_VERSION, '<' ) ) {
				add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
				return;
			}

			require_once plugin_dir_path( __FILE__ ) . 'includes/class.llms.payment.gateway.zibal.php';
			add_filter( 'lifterlms_payment_gateways', array( $this, 'register_gateway' ) );
		}

		/**
		 * Explain a missing or unsupported LifterLMS dependency to administrators.
		 */
		public function dependency_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					esc_html__( 'درگاه زیبال برای اجرا به LifterLMS نسخه %s یا جدیدتر نیاز دارد.', 'lifterlms-zibal' ),
					esc_html( self::MIN_LIFTERLMS_VERSION )
				)
			);
		}

		/**
		 * Register the gateway class.
		 *
		 * @param string[] $gateways Registered gateway classes.
		 * @return string[]
		 */
		public function register_gateway( $gateways ) {
			$gateways[] = 'LLMS_Payment_Gateway_zibal';
			return $gateways;
		}
	}
}

if ( ! function_exists( 'LLMS_Gateway_zibal' ) ) {
	/**
	 * Get the plugin instance.
	 *
	 * @return LifterLMS_zibal
	 */
	function LLMS_Gateway_zibal() {
		return LifterLMS_zibal::instance();
	}
}

LLMS_Gateway_zibal();
