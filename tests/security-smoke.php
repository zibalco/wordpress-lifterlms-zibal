<?php
/**
 * Standalone security smoke tests for the Zibal gateway.
 *
 * Run with: php tests/security-smoke.php
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class Audit_Stop extends RuntimeException {}

$GLOBALS['audit_meta']       = array();
$GLOBALS['audit_options']    = array();
$GLOBALS['audit_orders']     = array();
$GLOBALS['audit_order_objects'] = array();
$GLOBALS['audit_post_status_db'] = array();
$GLOBALS['audit_post_status_cache'] = array();
$GLOBALS['audit_object_cache'] = array();
$GLOBALS['audit_uuid_counter'] = 0;
$GLOBALS['audit_response']   = array();
$GLOBALS['audit_remote_hit'] = 0;
$GLOBALS['audit_last_remote_args'] = array();
$GLOBALS['audit_notices']    = array();
$GLOBALS['audit_blocked_meta_writes'] = array();
$GLOBALS['audit_remote_callback'] = null;
$GLOBALS['audit_actions'] = array();
$GLOBALS['audit_filters'] = array();
$GLOBALS['audit_llms_version'] = '10.0.0';

function __( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text ) { return esc_attr( $text ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $value ) { return trim( strip_tags( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( str_replace( "\r", '', (string) $value ) ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return stripslashes( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function add_filter( $hook ) { $GLOBALS['audit_filters'][] = $hook; }
function add_action( $hook ) { $GLOBALS['audit_actions'][] = $hook; }
function add_shortcode() {}
function do_action() {}
function is_admin() { return false; }
function is_singular() { return true; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function has_shortcode( $content, $tag ) { return false !== strpos( $content, '[' . $tag ); }
function shortcode_atts( $defaults, $attributes ) { return array_merge( $defaults, $attributes ); }
function get_current_user_id() { return 1; }
function current_user_can() { return false; }
function plugins_url( $path ) { return 'https://example.test/wp-content/plugins/zibal/includes' . $path; }
function home_url() { return 'https://example.test/'; }
function get_bloginfo( $show ) { return 'version' === $show ? '6.8.2' : ''; }
function llms() { return (object) array( 'version' => $GLOBALS['audit_llms_version'] ); }
function wp_salt( $scheme ) { return 'audit-salt-' . $scheme; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_post( $url, $args ) {
	++$GLOBALS['audit_remote_hit'];
	$GLOBALS['audit_last_remote_args'] = $args;
	if ( is_callable( $GLOBALS['audit_remote_callback'] ) ) {
		call_user_func( $GLOBALS['audit_remote_callback'], $url, $args );
	}
	return $GLOBALS['audit_response'];
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function llms_confirm_payment_url( $key ) { return 'https://example.test/confirm?order=' . rawurlencode( $key ); }
function wp_generate_password() { return 'AbCdEfGhIjKlMnOpQrStUvWxYz012345'; }
function wp_generate_uuid4() { ++$GLOBALS['audit_uuid_counter']; return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['audit_uuid_counter'] ); }
function add_query_arg( $key, $value, $url ) { return $url . '&' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function llms_cancel_payment_url() { return 'https://example.test/cancel'; }
function llms_add_notice( $message, $type ) { $GLOBALS['audit_notices'][] = array( $type, $message ); }
function llms_redirect_and_exit( $url ) { throw new Audit_Stop( 'redirect:' . $url ); }
function wp_safe_redirect( $url ) { throw new Audit_Stop( 'cancel:' . $url ); }
function wp_redirect( $url ) { throw new Audit_Stop( 'redirect:' . $url ); }
function llms_get_order_by_key( $key ) { return $GLOBALS['audit_orders'][ $key ] ?? false; }
function get_post_meta( $id, $key ) { return $GLOBALS['audit_meta'][ $id ][ $key ] ?? ''; }
function get_post_status( $id ) {
	if ( ! array_key_exists( $id, $GLOBALS['audit_post_status_cache'] ) ) {
		$GLOBALS['audit_post_status_cache'][ $id ] = $GLOBALS['audit_post_status_db'][ $id ] ?? false;
	}
	return $GLOBALS['audit_post_status_cache'][ $id ];
}
function clean_post_cache( $id ) { unset( $GLOBALS['audit_post_status_cache'][ $id ] ); }
function update_post_meta( $id, $key, $value ) {
	$write_key = $id . '|' . $key . '|' . $value;
	if ( ! empty( $GLOBALS['audit_blocked_meta_writes'][ $write_key ] ) ) {
		return false;
	}
	$GLOBALS['audit_meta'][ $id ][ $key ] = $value;
	return true;
}
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['audit_options'] ) ) { return false; } $GLOBALS['audit_options'][ $key ] = $value; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['audit_options'][ $key ] ?? $default; }
function delete_option( $key ) { unset( $GLOBALS['audit_options'][ $key ] ); return true; }
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['audit_object_cache'][ $group ][ $key ] ); return true; }
function wp_cache_get( $key, $group = '' ) { return $GLOBALS['audit_object_cache'][ $group ][ $key ] ?? false; }
function wp_cache_set( $key, $value, $group = '' ) { $GLOBALS['audit_object_cache'][ $group ][ $key ] = $value; return true; }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_basename( $file ) { return basename( $file ); }
function load_plugin_textdomain() { return true; }

class Audit_WPDB {
	public $options = 'wp_options';
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function query( $prepared ) {
		$args = $prepared['args'];
		if ( 0 === strpos( $prepared['query'], 'INSERT IGNORE ' ) ) {
			list( $name, $value ) = $args;
			if ( array_key_exists( $name, $GLOBALS['audit_options'] ) ) {
				return 0;
			}
			$GLOBALS['audit_options'][ $name ] = $value;
			return 1;
		}
		if ( 0 === strpos( $prepared['query'], 'UPDATE ' ) ) {
			list( $new_value, $name, $old_value ) = $args;
			if ( isset( $GLOBALS['audit_options'][ $name ] ) && $old_value === $GLOBALS['audit_options'][ $name ] ) {
				$GLOBALS['audit_options'][ $name ] = $new_value;
				return 1;
			}
			return 0;
		}
		if ( 0 === strpos( $prepared['query'], 'DELETE ' ) ) {
			list( $name, $owner_value ) = $args;
			if ( isset( $GLOBALS['audit_options'][ $name ] ) && $owner_value === $GLOBALS['audit_options'][ $name ] ) {
				unset( $GLOBALS['audit_options'][ $name ] );
				return 1;
			}
			return 0;
		}
		return 0;
	}
	public function get_var( $prepared ) {
		$name = $prepared['args'][0];
		return $GLOBALS['audit_options'][ $name ] ?? null;
	}
}

$GLOBALS['wpdb'] = new Audit_WPDB();

class LLMS_Payment_Gateway {
	public $id;
	public $icon;
	public $admin_description;
	public $admin_title;
	public $title;
	public $description;
	public $supports;
	public $audit_settings = array( 'MerchantID' => 'merchant_123', 'currency_unit' => 'rial' );
	public function get_option( $key ) { return $this->audit_settings[ $key ] ?? ''; }
	public function get_option_name( $key ) { return 'llms_gateway_zibal_' . $key; }
	public function log() {}
	public function complete_transaction() { throw new Audit_Stop( 'complete' ); }
	protected function get_complete_transaction_redirect_url( $order ) { return 'https://example.test/thank-you?order-complete=' . rawurlencode( $order->get( 'order_key' ) ); }
	public function get_supported_features() { return $this->supports; }
	public function supports( $feature ) { return ! empty( $this->get_supported_features()[ $feature ] ); }
}

class Audit_Plan {
	private $recurring;
	public function __construct( $recurring ) { $this->recurring = $recurring; }
	public function is_recurring() { return $this->recurring; }
}

class Audit_Order {
	public $data;
	public $notes = array();
	public $transactions = array();
	public $set_status_calls = 0;
	public function __construct( $id, $key, $total = 1000, $currency = 'IRR' ) {
		$this->data = array( 'id' => $id, 'order_key' => $key, 'user_id' => 1, 'total' => $total, 'currency' => $currency, 'payment_gateway' => 'zibal', 'status' => 'llms-pending' );
		$GLOBALS['audit_order_objects'][ $id ] = $this;
		$GLOBALS['audit_post_status_db'][ $id ] = 'llms-pending';
		unset( $GLOBALS['audit_post_status_cache'][ $id ] );
	}
	public function get( $key ) { return $this->data[ $key ] ?? ''; }
	public function get_price() { return $this->data['total']; }
	public function add_note( $note ) { $this->notes[] = $note; }
	public function record_transaction( $data ) { $this->transactions[] = $data; return (object) $data; }
	public function set_status( $status ) {
		++$this->set_status_calls;
		$this->data['status'] = 'llms-' . $status;
		$GLOBALS['audit_post_status_db'][ $this->data['id'] ] = $this->data['status'];
		clean_post_cache( $this->data['id'] );
	}
}

require dirname( __DIR__ ) . '/includes/class.llms.payment.gateway.zibal.php';

function audit_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

function audit_response( $data, $status = 200 ) {
	$GLOBALS['audit_response'] = array( 'response' => array( 'code' => $status ), 'body' => json_encode( $data ) );
}

function audit_seed_attempt( $order, $track_id, $state = 'requested' ) {
	$order_id  = $order->get( 'id' );
	$order_key = $order->get( 'order_key' );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_TRACK_ID, (string) $track_id );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_TRANSACTION_ID, (string) $track_id );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_REQUESTED_AMOUNT, (string) $order->get_price() );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE, $state );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_ORDER_BINDING, hash_hmac( 'sha256', $order_key, wp_salt( 'auth' ) ) );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_CALLBACK_TOKEN_HASH, hash_hmac( 'sha256', 'AbCdEfGhIjKlMnOpQrStUvWxYz012345', wp_salt( 'auth' ) ) );
	update_post_meta( $order_id, LLMS_Payment_Gateway_zibal::META_MERCHANT_FINGERPRINT, hash_hmac( 'sha256', 'merchant_123', wp_salt( 'secure_auth' ) ) );
}

function audit_set_native_order_status( $order, $status, $update_loaded_object = true, $clear_local_cache = true ) {
	$order_id = $order->get( 'id' );
	$GLOBALS['audit_post_status_db'][ $order_id ] = 'llms-' . $status;
	if ( $update_loaded_object ) {
		$order->data['status'] = 'llms-' . $status;
	}
	if ( $clear_local_cache ) {
		clean_post_cache( $order_id );
	}
}

$gateway = new LLMS_Payment_Gateway_zibal();
$order   = new Audit_Order( 10, 'order-safe-key', 1000 );
$GLOBALS['audit_orders']['order-safe-key'] = $order;

audit_assert( $gateway->supports( 'single_payments' ), 'gateway explicitly supports one-time access plans' );
audit_assert( ! $gateway->supports( 'recurring_payments' ), 'gateway does not advertise unsupported recurring payments' );
audit_assert( $gateway->can_process_access_plan( new Audit_Plan( false ) ), 'gateway can process a one-time access plan' );
audit_assert( ! $gateway->can_process_access_plan( new Audit_Plan( true ) ), 'gateway rejects a recurring access plan' );

audit_response( array( 'result' => 100, 'trackId' => 123456 ) );
try {
	$gateway->handle_pending_order( $order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:https://gateway.zibal.ir/start/123456' ), 'redirect uses only the trusted Zibal URL and validated track ID' );
}

audit_assert( '123456' === get_post_meta( 10, LLMS_Payment_Gateway_zibal::META_TRACK_ID ), 'track ID is stored before redirect' );
audit_assert( '123456' === get_post_meta( 10, LLMS_Payment_Gateway_zibal::META_TRANSACTION_ID ), 'track ID is immediately available as the order transaction number' );
audit_assert( '1000' === get_post_meta( 10, LLMS_Payment_Gateway_zibal::META_REQUESTED_AMOUNT ), 'requested amount is stored before redirect' );
audit_assert( 'requested' === get_post_meta( 10, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'local payment state is stored before redirect' );
ob_start();
$gateway->render_order_payment_meta_box( (object) array( 'ID' => 10 ) );
$pending_payment_details = ob_get_clean();
audit_assert( false !== strpos( $pending_payment_details, 'در انتظار پرداخت' ), 'requested payment is shown as pending instead of failed in the order box' );
audit_assert( false === strpos( $pending_payment_details, '>ناموفق<' ), 'pending payment is never mislabeled as failed' );
audit_assert( false !== strpos( $pending_payment_details, '123456' ), 'requested track ID is shown in the order transaction field' );
audit_assert( 20 === $GLOBALS['audit_last_remote_args']['timeout'], 'gateway API requests have a bounded timeout' );
audit_assert( 0 === $GLOBALS['audit_last_remote_args']['redirection'], 'gateway API requests do not follow redirects' );
audit_assert( 1048576 === $GLOBALS['audit_last_remote_args']['limit_response_size'], 'gateway API responses have a bounded maximum size' );
audit_assert( 'wordpress-lifterlms/2.3.2' === $GLOBALS['audit_last_remote_args']['headers']['X-Zibal-Plugin'], 'plugin identifier header contains the integration and plugin version' );
$user_agent = $GLOBALS['audit_last_remote_args']['user-agent'];
audit_assert( 0 === strpos( $user_agent, 'Zibal-WordPress-LifterLMS/2.3.2 ' ), 'User-Agent identifies the LifterLMS integration and version' );
audit_assert( false !== strpos( $user_agent, 'WordPress/6.8.2' ), 'User-Agent reports the WordPress version' );
audit_assert( false !== strpos( $user_agent, 'LifterLMS/10.0.0' ), 'User-Agent reports the LifterLMS version' );
audit_assert( false !== strpos( $user_agent, 'PHP/' . PHP_VERSION ), 'User-Agent reports the PHP version' );
audit_assert( false === strpos( $user_agent, 'example.test' ), 'User-Agent does not disclose the site address' );
audit_assert( 1 !== preg_match( '/[\r\n]/', $user_agent ), 'User-Agent cannot inject additional HTTP headers' );
audit_assert( ! isset( $GLOBALS['audit_options']['llms_zibal_request_lock_10'] ), 'request lock is owner-safely released before redirect' );

$first_attempt_meta = $GLOBALS['audit_meta'][10];
$first_attempt_notes = count( $order->notes );
$before_reuse_remote = $GLOBALS['audit_remote_hit'];
try {
	$gateway->handle_pending_order( $order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 'redirect:https://gateway.zibal.ir/start/123456' === $stop->getMessage(), 'repeated checkout submission reuses the active Zibal track ID' );
}
audit_assert( $before_reuse_remote === $GLOBALS['audit_remote_hit'], 'repeated checkout submission does not create a second Zibal request' );
audit_assert( $first_attempt_meta === $GLOBALS['audit_meta'][10], 'reusing an active attempt does not overwrite payment metadata or the callback token hash' );
audit_assert( $first_attempt_notes === count( $order->notes ), 'reusing an active attempt does not add a duplicate order note' );

$locked_order = new Audit_Order( 11, 'order-locked-key', 1000 );
$GLOBALS['audit_options']['llms_zibal_request_lock_11'] = 'another-owner|' . ( time() + 120 );
$before_locked_remote = $GLOBALS['audit_remote_hit'];
$gateway->handle_pending_order( $locked_order, null, null );
audit_assert( $before_locked_remote === $GLOBALS['audit_remote_hit'], 'a concurrently owned request lock blocks a second Zibal request' );
audit_assert( '' === get_post_meta( 11, LLMS_Payment_Gateway_zibal::META_TRACK_ID ), 'request-lock contention cannot create or overwrite payment metadata' );

$mismatched_attempt = new Audit_Order( 12, 'order-active-mismatch-key', 1000 );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE, 'requested' );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_TRACK_ID, '121212' );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_TRANSACTION_ID, '121212' );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_REQUESTED_AMOUNT, '999' );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_ORDER_BINDING, hash_hmac( 'sha256', 'order-active-mismatch-key', wp_salt( 'auth' ) ) );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_CALLBACK_TOKEN_HASH, 'existing-token-hash' );
update_post_meta( 12, LLMS_Payment_Gateway_zibal::META_MERCHANT_FINGERPRINT, hash_hmac( 'sha256', 'merchant_123', wp_salt( 'secure_auth' ) ) );
$before_mismatched_attempt_remote = $GLOBALS['audit_remote_hit'];
$gateway->handle_pending_order( $mismatched_attempt, null, null );
audit_assert( $before_mismatched_attempt_remote === $GLOBALS['audit_remote_hit'], 'mismatched active-attempt metadata is blocked before the request API' );
audit_assert( '121212' === get_post_meta( 12, LLMS_Payment_Gateway_zibal::META_TRACK_ID ), 'a mismatched active attempt can never be overwritten by a new track ID' );

$toman_gateway = new LLMS_Payment_Gateway_zibal();
$toman_gateway->audit_settings['currency_unit'] = 'auto';
$toman_order = new Audit_Order( 30, 'order-toman-key', 125, 'IRT' );
audit_response( array( 'result' => 100, 'trackId' => 303030 ) );
try {
	$toman_gateway->handle_pending_order( $toman_order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'Toman order reaches the trusted redirect flow' );
}
audit_assert( '1250' === get_post_meta( 30, LLMS_Payment_Gateway_zibal::META_REQUESTED_AMOUNT ), 'automatic Toman detection converts the amount to Rial' );

$unsupported_order = new Audit_Order( 40, 'order-usd-key', 100, 'USD' );
$before_unsupported_remote = $GLOBALS['audit_remote_hit'];
$gateway->handle_pending_order( $unsupported_order, null, null );
audit_assert( $before_unsupported_remote === $GLOBALS['audit_remote_hit'], 'unsupported currency is rejected before any payment API call' );
audit_assert( '' === get_post_meta( 40, LLMS_Payment_Gateway_zibal::META_TRACK_ID ), 'unsupported currency cannot create a local payment attempt' );

$overflow_order = new Audit_Order( 41, 'order-overflow-key', PHP_INT_MAX, 'IRT' );
$before_overflow_remote = $GLOBALS['audit_remote_hit'];
$toman_gateway->handle_pending_order( $overflow_order, null, null );
audit_assert( $before_overflow_remote === $GLOBALS['audit_remote_hit'], 'overflowing gateway amount is rejected before any payment API call' );
audit_assert( '' === get_post_meta( 41, LLMS_Payment_Gateway_zibal::META_TRACK_ID ), 'overflowing amount cannot create a local payment attempt' );

$request_failure = new Audit_Order( 42, 'order-request-failure-key', 1000 );
$provider_request_message = 'merchant not found - پیام مستقیم زیبال';
audit_response( array( 'result' => 102, 'message' => $provider_request_message ), 422 );
$gateway->handle_pending_order( $request_failure, null, null );
$request_failure_note = end( $request_failure->notes );
audit_assert( false !== strpos( $request_failure_note, $provider_request_message ), 'failed payment request stores the exact Zibal message in the order note' );
audit_assert( false !== strpos( $request_failure_note, 'کد نتیجه زیبال: 102' ), 'failed payment request stores the Zibal result code' );

$request_transport_failure = new Audit_Order( 43, 'order-request-transport-key', 1000 );
$transport_message = 'cURL error 28: Operation timed out after 20000 milliseconds';
$GLOBALS['audit_response'] = new WP_Error( 'http_request_failed', $transport_message );
$gateway->handle_pending_order( $request_transport_failure, null, null );
$request_transport_note = end( $request_transport_failure->notes );
$request_transport_notice = end( $GLOBALS['audit_notices'] );
audit_assert( false !== strpos( $request_transport_note, 'http_request_failed' ), 'request transport failure stores the exact WordPress error code in the admin order note' );
audit_assert( false !== strpos( $request_transport_note, $transport_message ), 'request transport failure stores the exact diagnostic message in the admin order note' );
audit_assert( false === strpos( $request_transport_notice[1], $transport_message ), 'request transport diagnostics are not exposed in the customer notice' );
audit_assert( ! isset( $GLOBALS['audit_options']['llms_zibal_request_lock_43'] ), 'request lock is released after a transport failure' );

$_GET = array( 'order' => 'order-safe-key', 'trackId' => '"><script>audit()</script>', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
ob_start();
$gateway->after_payment_method_details();
$xss_output = ob_get_clean();
audit_assert( '' === $xss_output, 'malicious callback track ID cannot produce reflected HTML' );

$_GET = array( 'order' => 'order-safe-key', 'trackId' => '123456', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
ob_start();
$gateway->after_payment_method_details();
$safe_output = ob_get_clean();
audit_assert( false !== strpos( $safe_output, 'value="123456"' ), 'stored callback track ID is escaped into the confirmation form' );
audit_assert( false !== strpos( $safe_output, 'name="llms_zibal_cb"' ), 'cryptographic callback token is preserved through the confirmation form' );

audit_response(
	array(
		'result'     => 100,
		'orderId'    => 'order-safe-key',
		'amount'     => 1000,
		'refNumber'  => 778899,
		'paidAt'     => '2026-07-22T14:15:16.123000',
		'cardNumber' => '610433******1234',
		'message'    => 'provider success message must not be stored',
	)
);
$_GET['success'] = '1';
try {
	$gateway->maybe_process_zibal_return();
} catch ( Audit_Stop $stop ) {
	audit_assert( 'complete' === $stop->getMessage(), 'valid Zibal browser return is verified automatically and completes the order' );
}
audit_assert( 1 === count( $order->transactions ), 'matching payment records exactly one transaction' );
audit_assert( '778899' === (string) $order->transactions[0]['transaction_id'], 'official refNumber is used as the successful transaction number' );
audit_assert( '610433******1234' === $order->transactions[0]['source_description'], 'masked Zibal card number is stored without exposing a full card number' );
audit_assert( '2026-07-22 14:15:16.123000' === $order->transactions[0]['completed_date'], 'provider payment time is stored in the native LifterLMS transaction' );
audit_assert( 'llms-completed' === $order->get( 'status' ), 'successful one-time payment explicitly completes the native LifterLMS order' );
audit_assert( 'paid' === get_post_meta( 10, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'successful verification updates local payment state' );
audit_assert( ! isset( $GLOBALS['audit_options']['llms_zibal_verify_lock_10'] ), 'verification lock is released after success' );
$success_note = end( $order->notes );
audit_assert( false !== strpos( $success_note, 'وضعیت: موفق' ), 'successful order note records the successful status' );
audit_assert( false !== strpos( $success_note, 'تاریخ و ساعت تراکنش: 2026-07-22 14:15:16.123000' ), 'successful order note records the provider payment date and time' );
audit_assert( false !== strpos( $success_note, 'شماره تراکنش: 778899' ), 'successful order note records the Zibal reference number' );
audit_assert( false !== strpos( $success_note, 'شماره کارت: 610433******1234' ), 'successful order note records the masked card number' );
audit_assert( false === strpos( $success_note, 'provider success message must not be stored' ), 'successful order note excludes the direct Zibal success message' );

$_GET = array( 'order-complete' => 'order-safe-key' );
$success_result = $gateway->render_payment_result_shortcode();
audit_assert( false !== strpos( $success_result, 'موفق' ), 'customer result shows successful order status' );
audit_assert( false !== strpos( $success_result, '778899' ), 'customer result shows the Zibal reference number' );
audit_assert( false !== strpos( $success_result, '2026-07-22 14:15:16.123000' ), 'customer result shows the provider payment time' );
audit_assert( false !== strpos( $success_result, '610433******1234' ), 'customer result shows only the masked card number' );
audit_assert( false === strpos( $success_result, 'provider success message must not be stored' ), 'customer success result excludes the direct provider success message' );
$admin_order_post = (object) array( 'ID' => 10 );
ob_start();
$gateway->render_order_payment_meta_box( $admin_order_post );
$admin_payment_details = ob_get_clean();
audit_assert( false !== strpos( $admin_payment_details, 'جزئیات' ) || false !== strpos( $admin_payment_details, 'وضعیت پرداخت' ), 'administrative order payment box renders Zibal payment fields' );
audit_assert( false !== strpos( $admin_payment_details, '778899' ), 'administrative order payment box shows the Zibal reference number' );
audit_assert( false !== strpos( $admin_payment_details, '610433******1234' ), 'administrative order payment box shows only the masked card number' );
audit_assert( false !== strpos( $gateway->append_payment_result( '<p>Thanks</p>' ), '[llms_zibal_payment_result]' ), 'result shortcode is appended automatically to the checkout redirect page' );
$order->data['user_id'] = 2;
audit_assert( '' === $gateway->render_payment_result_shortcode(), 'payment result is hidden from users who do not own the order' );
$order->data['user_id'] = 1;

$before_duplicate_remote = $GLOBALS['audit_remote_hit'];
$_GET = array( 'order' => 'order-safe-key', 'trackId' => '123456', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
try {
	$gateway->confirm_pending_order( $order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 'complete' === $stop->getMessage(), 'duplicate callback for a locally paid transaction is idempotent' );
}
audit_assert( 1 === count( $order->transactions ), 'duplicate callback does not record another transaction' );
audit_assert( $before_duplicate_remote === $GLOBALS['audit_remote_hit'], 'duplicate paid callback does not call verify again' );

$before_missing_token_remote = $GLOBALS['audit_remote_hit'];
$before_missing_token_meta = $GLOBALS['audit_meta'][10];
$before_missing_token_notes = count( $order->notes );
$_GET = array( 'order' => 'order-safe-key', 'trackId' => '123456' );
try {
	$gateway->confirm_pending_order( $order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'callback without the cryptographic token is rejected' );
}
audit_assert( $before_missing_token_remote === $GLOBALS['audit_remote_hit'], 'missing callback token is rejected before any API call' );
audit_assert( $before_missing_token_meta === $GLOBALS['audit_meta'][10], 'missing callback token cannot mutate a paid order result or payment details' );
audit_assert( $before_missing_token_notes === count( $order->notes ), 'missing callback token cannot add an untrusted order note' );

$_GET = array( 'order' => 'order-safe-key', 'trackId' => '654321', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
$before_wrong_paid_track_remote = $GLOBALS['audit_remote_hit'];
try {
	$gateway->confirm_pending_order( $order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'wrong track ID on a paid order is rejected without changing the order' );
}
audit_assert( $before_wrong_paid_track_remote === $GLOBALS['audit_remote_hit'], 'wrong paid-order track is rejected before verify' );
audit_assert( $before_missing_token_meta === $GLOBALS['audit_meta'][10], 'wrong track ID cannot demote a paid result or erase historical payment details' );

$_GET = array( 'order-complete' => 'order-safe-key', 'llms_zibal_status' => 'callback-rejected' );
$rejected_callback_result = $gateway->render_payment_result_shortcode();
audit_assert( false !== strpos( $rejected_callback_result, 'اطلاعات بازگشت پرداخت معتبر نیست' ), 'rejected callback displays a transient customer-safe explanation' );
audit_assert( $before_missing_token_meta === $GLOBALS['audit_meta'][10], 'rendering a rejected callback does not persist a failed result' );

$terminal_statuses = array( 'refunded', 'cancelled', 'expired', 'failed', 'on-hold' );
foreach ( $terminal_statuses as $index => $terminal_status ) {
	$terminal_order = new Audit_Order( 90 + $index, 'order-terminal-' . $terminal_status, 1000 );
	audit_set_native_order_status( $terminal_order, $terminal_status );
	audit_seed_attempt( $terminal_order, (string) ( 900000 + $index ), 'paid' );
	update_post_meta( $terminal_order->get( 'id' ), LLMS_Payment_Gateway_zibal::META_RESULT_STATUS, 'success' );
	update_post_meta( $terminal_order->get( 'id' ), LLMS_Payment_Gateway_zibal::META_PAID_AT, '2026-07-22 12:00:00' );
	$terminal_payment_meta = $GLOBALS['audit_meta'][ $terminal_order->get( 'id' ) ];
	$_GET = array(
		'order'         => $terminal_order->get( 'order_key' ),
		'trackId'       => (string) ( 900000 + $index ),
		'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345',
	);
	$before_terminal_remote = $GLOBALS['audit_remote_hit'];
	try {
		$gateway->confirm_pending_order( $terminal_order );
	} catch ( Audit_Stop $stop ) {
		audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'paid callback replay is blocked for native ' . $terminal_status . ' orders' );
	}
	audit_assert( 'llms-' . $terminal_status === $terminal_order->get( 'status' ), 'callback replay preserves the native ' . $terminal_status . ' order status' );
	audit_assert( 0 === $terminal_order->set_status_calls, 'callback replay never calls set_status for a ' . $terminal_status . ' order' );
	audit_assert( 0 === count( $terminal_order->transactions ), 'callback replay never records another transaction for a ' . $terminal_status . ' order' );
	audit_assert( $before_terminal_remote === $GLOBALS['audit_remote_hit'], 'paid callback replay never verifies again for a ' . $terminal_status . ' order' );
	audit_assert( $terminal_payment_meta === $GLOBALS['audit_meta'][ $terminal_order->get( 'id' ) ], 'paid callback replay preserves historical payment metadata for a ' . $terminal_status . ' order' );
}

$cancelled_requested = new Audit_Order( 96, 'order-requested-cancelled', 1000 );
audit_set_native_order_status( $cancelled_requested, 'cancelled' );
audit_seed_attempt( $cancelled_requested, '969696', 'requested' );
$_GET = array( 'order' => 'order-requested-cancelled', 'trackId' => '969696', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
$before_cancelled_requested_remote = $GLOBALS['audit_remote_hit'];
try {
	$gateway->confirm_pending_order( $cancelled_requested );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'a requested callback cannot complete an order cancelled before verification' );
}
audit_assert( 'llms-cancelled' === $cancelled_requested->get( 'status' ), 'pre-verification cancellation remains unchanged' );
audit_assert( $before_cancelled_requested_remote === $GLOBALS['audit_remote_hit'], 'cancelled order is rejected before the verify API call' );
audit_assert( 0 === count( $cancelled_requested->transactions ), 'cancelled order cannot record a payment transaction' );

$changed_during_verify = new Audit_Order( 97, 'order-status-race', 1000 );
audit_seed_attempt( $changed_during_verify, '979797', 'requested' );
$_GET = array( 'order' => 'order-status-race', 'trackId' => '979797', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
audit_response( array( 'result' => 100, 'trackId' => 979797, 'orderId' => 'order-status-race', 'amount' => 1000, 'refNumber' => 979798 ) );
$GLOBALS['audit_remote_callback'] = function () use ( $changed_during_verify ) {
	audit_set_native_order_status( $changed_during_verify, 'refunded', false, false );
};
try {
	$gateway->confirm_pending_order( $changed_during_verify );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'status changed during server-side verification is caught before recording' );
}
$GLOBALS['audit_remote_callback'] = null;
audit_assert( 'llms-refunded' === get_post_status( 97 ), 'refund during verify is never replaced with completed or on-hold' );
audit_assert( 0 === count( $changed_during_verify->transactions ), 'refund during verify prevents transaction recording' );
audit_assert( ! isset( $GLOBALS['audit_options']['llms_zibal_verify_lock_97'] ), 'verification lock is released when the native status changes' );

$mismatch = new Audit_Order( 20, 'order-mismatch-key', 9000 );
$GLOBALS['audit_orders']['order-mismatch-key'] = $mismatch;
update_post_meta( 20, LLMS_Payment_Gateway_zibal::META_TRACK_ID, '999999' );
update_post_meta( 20, LLMS_Payment_Gateway_zibal::META_REQUESTED_AMOUNT, '9000' );
update_post_meta( 20, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE, 'requested' );
update_post_meta( 20, LLMS_Payment_Gateway_zibal::META_ORDER_BINDING, hash_hmac( 'sha256', 'order-mismatch-key', wp_salt( 'auth' ) ) );
update_post_meta( 20, LLMS_Payment_Gateway_zibal::META_CALLBACK_TOKEN_HASH, hash_hmac( 'sha256', 'AbCdEfGhIjKlMnOpQrStUvWxYz012345', wp_salt( 'auth' ) ) );
update_post_meta( 20, LLMS_Payment_Gateway_zibal::META_MERCHANT_FINGERPRINT, hash_hmac( 'sha256', 'merchant_123', wp_salt( 'secure_auth' ) ) );
$_GET = array( 'order' => 'order-mismatch-key', 'trackId' => '999999', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
audit_response( array( 'result' => 100, 'trackId' => 999999, 'orderId' => 'order-mismatch-key', 'amount' => 1000 ) );
try {
	$gateway->confirm_pending_order( $mismatch );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'amount mismatch stops the payment flow' );
}
audit_assert( 0 === count( $mismatch->transactions ), 'amount mismatch never records a transaction' );
audit_assert( 'manual-review' === get_post_meta( 20, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'amount mismatch is moved to manual review' );
audit_assert( 'llms-on-hold' === $mismatch->get( 'status' ), 'suspicious order is placed on hold' );

$before_manual_review_remote = $GLOBALS['audit_remote_hit'];
$_GET = array( 'order' => 'order-mismatch-key', 'trackId' => '999999', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
try {
	$gateway->confirm_pending_order( $mismatch );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'manual-review order cannot be automatically verified again' );
}
audit_assert( $before_manual_review_remote === $GLOBALS['audit_remote_hit'], 'manual-review callback is stopped before another verify API call' );

$changed_order = new Audit_Order( 50, 'order-changed-key', 1000 );
$GLOBALS['audit_orders']['order-changed-key'] = $changed_order;
audit_response( array( 'result' => 100, 'trackId' => 505050 ) );
try {
	$gateway->handle_pending_order( $changed_order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'order-change scenario creates the initial payment request' );
}
$changed_order->data['total'] = 2000;
$_GET = array( 'order' => 'order-changed-key', 'trackId' => '505050', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
audit_response( array( 'result' => 100, 'trackId' => 505050, 'orderId' => 'order-changed-key', 'amount' => 1000 ) );
$before_changed_remote = $GLOBALS['audit_remote_hit'];
try {
	$gateway->confirm_pending_order( $changed_order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'changed order amount stops the callback flow' );
}
audit_assert( $before_changed_remote === $GLOBALS['audit_remote_hit'], 'changed order amount is rejected before the verify API call' );
audit_assert( 0 === count( $changed_order->transactions ), 'changed order amount never records a transaction' );
audit_assert( 'manual-review' === get_post_meta( 50, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'changed order amount is moved to manual review' );
audit_assert( 'llms-on-hold' === $changed_order->get( 'status' ), 'changed order is placed on hold' );

$verify_failure = new Audit_Order( 60, 'order-verify-failure-key', 1000 );
$GLOBALS['audit_orders']['order-verify-failure-key'] = $verify_failure;
audit_response( array( 'result' => 100, 'trackId' => 606060 ) );
try {
	$gateway->handle_pending_order( $verify_failure, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'verify-failure scenario creates the initial payment request' );
}
$_GET = array( 'order' => 'order-verify-failure-key', 'trackId' => '606060', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
$provider_verify_message = 'payment not successful - پیام مستقیم زیبال';
audit_response( array( 'result' => 202, 'message' => $provider_verify_message ) );
try {
	$gateway->confirm_pending_order( $verify_failure );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'failed verify response stops the payment flow' );
}
$verify_failure_note = end( $verify_failure->notes );
audit_assert( false !== strpos( $verify_failure_note, $provider_verify_message ), 'failed verification stores the exact Zibal message in the order note' );
audit_assert( false !== strpos( $verify_failure_note, 'کد نتیجه زیبال: 202' ), 'failed verification stores the Zibal result code' );
$_GET = array( 'order-complete' => 'order-verify-failure-key' );
$failure_result = $gateway->render_payment_result_shortcode();
audit_assert( false !== strpos( $failure_result, 'ناموفق' ), 'customer result shows failed order status' );
audit_assert( false === strpos( $failure_result, '202' ), 'customer result does not expose the exact Zibal result code' );
audit_assert( false === strpos( $failure_result, $provider_verify_message ), 'customer result does not expose the exact failed Zibal response message' );
audit_assert( false !== strpos( $failure_result, 'پشتیبانی' ), 'customer receives a generic support message for a failed verification' );
audit_assert( false === strpos( $failure_result, 'شماره کارت' ), 'failed result does not show success-only card data' );
audit_assert( 'failed' === get_post_meta( 60, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'definitive unpaid result marks the attempt as terminally retryable' );

$_GET = array();
$before_terminal_retry_remote = $GLOBALS['audit_remote_hit'];
audit_response( array( 'result' => 100, 'trackId' => 606061 ) );
try {
	$gateway->handle_pending_order( $verify_failure, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 'redirect:https://gateway.zibal.ir/start/606061' === $stop->getMessage(), 'a definitive unpaid attempt may create one new Zibal request' );
}
audit_assert( $before_terminal_retry_remote + 1 === $GLOBALS['audit_remote_hit'], 'terminal retry creates exactly one new request' );
audit_assert( '606061' === get_post_meta( 60, LLMS_Payment_Gateway_zibal::META_TRACK_ID ), 'terminal retry replaces the old track only after a definitive provider failure' );

$new_attempt_meta = $GLOBALS['audit_meta'][60];
$new_attempt_notes = count( $verify_failure->notes );
$before_old_callback_remote = $GLOBALS['audit_remote_hit'];
$_GET = array( 'order' => 'order-verify-failure-key', 'trackId' => '606060', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
try {
	$gateway->confirm_pending_order( $verify_failure );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'callback from an older attempt is rejected after a terminal retry' );
}
audit_assert( $before_old_callback_remote === $GLOBALS['audit_remote_hit'], 'older attempt callback cannot reach verify for the replacement attempt' );
audit_assert( $new_attempt_meta === $GLOBALS['audit_meta'][60], 'older attempt callback cannot corrupt replacement-attempt metadata' );
audit_assert( $new_attempt_notes === count( $verify_failure->notes ), 'older attempt callback cannot add an untrusted note to the replacement attempt' );

$verify_transport_failure = new Audit_Order( 61, 'order-verify-transport-key', 1000 );
$GLOBALS['audit_orders']['order-verify-transport-key'] = $verify_transport_failure;
audit_response( array( 'result' => 100, 'trackId' => 616161 ) );
try {
	$gateway->handle_pending_order( $verify_transport_failure, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'verify transport scenario creates the initial payment request' );
}
$_GET = array( 'order' => 'order-verify-transport-key', 'trackId' => '616161', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
$verify_transport_message = 'cURL error 28: verify timed out';
$GLOBALS['audit_response'] = new WP_Error( 'http_request_failed', $verify_transport_message );
try {
	$gateway->confirm_pending_order( $verify_transport_failure );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'verify transport failure stops with a generic customer redirect' );
}
$verify_transport_note = end( $verify_transport_failure->notes );
audit_assert( false !== strpos( $verify_transport_note, 'http_request_failed' ), 'verify transport failure stores the exact error code in the admin order note' );
audit_assert( false !== strpos( $verify_transport_note, $verify_transport_message ), 'verify transport failure stores the exact diagnostic message in the admin order note' );
$_GET = array( 'order-complete' => 'order-verify-transport-key' );
$verify_transport_result = $gateway->render_payment_result_shortcode();
audit_assert( false === strpos( $verify_transport_result, $verify_transport_message ), 'verify transport diagnostics are hidden from the customer result' );
audit_assert( false !== strpos( $verify_transport_result, 'پشتیبانی' ), 'verify transport failure gives the customer a generic support message' );

$empty_card_order = new Audit_Order( 70, 'order-empty-card-key', 1000 );
$GLOBALS['audit_orders']['order-empty-card-key'] = $empty_card_order;
audit_response( array( 'result' => 100, 'trackId' => 707070 ) );
try {
	$gateway->handle_pending_order( $empty_card_order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'empty-card scenario creates the initial payment request' );
}
$_GET = array( 'order' => 'order-empty-card-key', 'trackId' => '707070', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
audit_response( array( 'result' => 100, 'orderId' => 'order-empty-card-key', 'amount' => 1000, 'refNumber' => 707071, 'paidAt' => '2026-07-22T15:00:00', 'cardNumber' => '', 'message' => 'success' ) );
try {
	$gateway->confirm_pending_order( $empty_card_order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 'complete' === $stop->getMessage(), 'successful payment with an empty card number still completes safely' );
}
$empty_card_note = end( $empty_card_order->notes );
audit_assert( 'llms-completed' === $empty_card_order->get( 'status' ), 'test-merchant payment with no card still completes the native order' );
audit_assert( '-' === $empty_card_order->transactions[0]['source_description'], 'native transaction uses a dash when test merchant returns no card number' );
audit_assert( false !== strpos( $empty_card_note, 'شماره کارت: -' ), 'successful payment always includes a card field with dash fallback' );
audit_assert( false === strpos( $empty_card_note, 'پیام زیبال: success' ), 'successful payment never stores the direct Zibal success message' );
ob_start();
$gateway->render_order_payment_meta_box( (object) array( 'ID' => 70 ) );
$empty_card_admin_details = ob_get_clean();
audit_assert( false !== strpos( $empty_card_admin_details, '<td style="direction:ltr;text-align:left;word-break:break-word">-</td>' ), 'administrative order box uses a dash for a missing test card number' );

$recording_failure_order = new Audit_Order( 80, 'order-recording-failure-key', 1000 );
$GLOBALS['audit_orders']['order-recording-failure-key'] = $recording_failure_order;
audit_response( array( 'result' => 100, 'trackId' => 808080 ) );
try {
	$gateway->handle_pending_order( $recording_failure_order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'recording-state failure scenario creates the initial payment request' );
}
$_GET = array( 'order' => 'order-recording-failure-key', 'trackId' => '808080', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
audit_response( array( 'result' => 100, 'orderId' => 'order-recording-failure-key', 'amount' => 1000, 'refNumber' => 808081, 'paidAt' => '2026-07-22T16:00:00' ) );
$GLOBALS['audit_blocked_meta_writes']['80|' . LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE . '|recording'] = true;
try {
	$gateway->confirm_pending_order( $recording_failure_order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'failed recording-state persistence stops the payment flow' );
}
unset( $GLOBALS['audit_blocked_meta_writes']['80|' . LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE . '|recording'] );
audit_assert( 0 === count( $recording_failure_order->transactions ), 'transaction is not recorded when the idempotency state cannot be persisted' );
audit_assert( 'manual-review' === get_post_meta( 80, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'recording-state persistence failure is moved to manual review' );

$paid_state_failure_order = new Audit_Order( 81, 'order-paid-state-failure-key', 1000 );
$GLOBALS['audit_orders']['order-paid-state-failure-key'] = $paid_state_failure_order;
audit_response( array( 'result' => 100, 'trackId' => 818181 ) );
try {
	$gateway->handle_pending_order( $paid_state_failure_order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'redirect:' ), 'paid-state failure scenario creates the initial payment request' );
}
$_GET = array( 'order' => 'order-paid-state-failure-key', 'trackId' => '818181', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
audit_response( array( 'result' => 100, 'orderId' => 'order-paid-state-failure-key', 'amount' => 1000, 'refNumber' => 818182, 'paidAt' => '2026-07-22T16:30:00' ) );
$GLOBALS['audit_blocked_meta_writes']['81|' . LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE . '|paid'] = true;
try {
	$gateway->confirm_pending_order( $paid_state_failure_order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'failed final paid-state persistence stops automatic completion' );
}
unset( $GLOBALS['audit_blocked_meta_writes']['81|' . LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE . '|paid'] );
audit_assert( 1 === count( $paid_state_failure_order->transactions ), 'verified transaction is recorded only once before a paid-state persistence failure' );
audit_assert( 'manual-review' === get_post_meta( 81, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE ), 'paid-state persistence failure is moved to manual review' );
$before_paid_state_retry_remote = $GLOBALS['audit_remote_hit'];
try {
	$gateway->confirm_pending_order( $paid_state_failure_order );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'paid-state failure cannot be automatically retried into a duplicate transaction' );
}
audit_assert( 1 === count( $paid_state_failure_order->transactions ), 'paid-state failure retry does not record a duplicate transaction' );
audit_assert( $before_paid_state_retry_remote === $GLOBALS['audit_remote_hit'], 'paid-state failure retry is stopped before another verify API call' );

$before_remote = $GLOBALS['audit_remote_hit'];
$_GET = array( 'order' => 'order-mismatch-key', 'trackId' => '111111', 'llms_zibal_cb' => 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
try {
	$gateway->confirm_pending_order( $mismatch );
} catch ( Audit_Stop $stop ) {
	audit_assert( 0 === strpos( $stop->getMessage(), 'cancel:' ), 'wrong callback track ID is rejected' );
}
audit_assert( $before_remote === $GLOBALS['audit_remote_hit'], 'wrong track ID is rejected before any verify API call' );

$recording_checkout = new Audit_Order( 13, 'order-recording-checkout', 1000 );
update_post_meta( 13, LLMS_Payment_Gateway_zibal::META_PAYMENT_STATE, 'recording' );
$before_recording_checkout_remote = $GLOBALS['audit_remote_hit'];
$gateway->handle_pending_order( $recording_checkout, null, null );
audit_assert( $before_recording_checkout_remote === $GLOBALS['audit_remote_hit'], 'recording payment state blocks creation of another provider attempt' );

$stale_lock_order = new Audit_Order( 14, 'order-stale-request-lock', 1000 );
$GLOBALS['audit_options']['llms_zibal_request_lock_14'] = 'expired-owner|' . ( time() - 1 );
$before_stale_lock_remote = $GLOBALS['audit_remote_hit'];
audit_response( array( 'result' => 100, 'trackId' => 141414 ) );
try {
	$gateway->handle_pending_order( $stale_lock_order, null, null );
} catch ( Audit_Stop $stop ) {
	audit_assert( 'redirect:https://gateway.zibal.ir/start/141414' === $stop->getMessage(), 'an expired request lock is atomically taken over' );
}
audit_assert( $before_stale_lock_remote + 1 === $GLOBALS['audit_remote_hit'], 'stale-lock takeover still creates only one provider request' );
audit_assert( ! isset( $GLOBALS['audit_options']['llms_zibal_request_lock_14'] ), 'new stale-lock owner releases its own lock after persistence' );

$acquire_method = ( new ReflectionClass( $gateway ) )->getMethod( 'acquire_request_lock' );
$acquire_method->setAccessible( true );
$first_owner_lock = $acquire_method->invoke( $gateway, 16 );
$first_owner_value = $GLOBALS['audit_options']['llms_zibal_request_lock_16'];
$second_owner_lock = $acquire_method->invoke( $gateway, 16 );
audit_assert( is_array( $first_owner_lock ) && false === $second_owner_lock, 'insert-only request lock grants initial ownership to exactly one process' );
audit_assert( $first_owner_value === $GLOBALS['audit_options']['llms_zibal_request_lock_16'], 'losing request-lock contender cannot overwrite the current owner token' );

$release_method = ( new ReflectionClass( $gateway ) )->getMethod( 'release_request_lock' );
$release_method->setAccessible( true );
$release_method->invoke( $gateway, $first_owner_lock );
audit_assert( ! isset( $GLOBALS['audit_options']['llms_zibal_request_lock_16'] ), 'winning request-lock owner can release its own lock' );
$GLOBALS['audit_options']['llms_zibal_request_lock_15'] = 'real-owner|' . ( time() + 120 );
$release_method->invoke( $gateway, array( 'name' => 'llms_zibal_request_lock_15', 'value' => 'wrong-owner|' . ( time() + 120 ) ) );
audit_assert( isset( $GLOBALS['audit_options']['llms_zibal_request_lock_15'] ), 'a non-owner cannot release another request process lock' );

require dirname( __DIR__ ) . '/lifterlms-gateway-zibal.php';
audit_assert( '3.30.0' === LifterLMS_zibal::MIN_LIFTERLMS_VERSION, 'bootstrap declares LifterLMS 3.30.0 as the minimum supported version' );
$GLOBALS['audit_llms_version'] = '3.29.9';
$GLOBALS['audit_actions'] = array();
$GLOBALS['audit_filters'] = array();
LLMS_Gateway_zibal()->init();
audit_assert( in_array( 'admin_notices', $GLOBALS['audit_actions'], true ), 'LifterLMS 3.29 is rejected with an administrative dependency notice' );
audit_assert( ! in_array( 'lifterlms_payment_gateways', $GLOBALS['audit_filters'], true ), 'gateway is not registered below LifterLMS 3.30' );
$GLOBALS['audit_llms_version'] = '3.30.0';
$GLOBALS['audit_actions'] = array();
$GLOBALS['audit_filters'] = array();
LLMS_Gateway_zibal()->init();
audit_assert( in_array( 'lifterlms_payment_gateways', $GLOBALS['audit_filters'], true ), 'gateway registers when LifterLMS 3.30 is available' );

echo 'All security smoke tests passed.' . PHP_EOL;
