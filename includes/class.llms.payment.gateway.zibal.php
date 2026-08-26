<?php
/**
 * Zibal Payment Gateway for LifterLMS.
 *
 * @package LifterLMS_Zibal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LLMS_Payment_Gateway_zibal extends LLMS_Payment_Gateway {

	const REDIRECT_URL = 'https://gateway.zibal.ir/start/';
	const REQUEST_URL  = 'https://gateway.zibal.ir/v1/request';
	const VERIFY_URL   = 'https://gateway.zibal.ir/v1/verify';
	const INQUIRY_URL  = 'https://gateway.zibal.ir/v1/inquiry';
	const VERSION      = '2.4.0';
	const HTTP_TIMEOUT       = 20;
	const LOCK_TTL           = 60;
	const MIN_GATEWAY_AMOUNT = 1000;

	const META_TRACK_ID             = '_llms_zibal_track_id';
	const META_REQUESTED_AMOUNT     = '_llms_zibal_requested_amount';
	const META_PAYMENT_STATE        = '_llms_zibal_payment_state';
	const META_ORDER_BINDING        = '_llms_zibal_order_binding';
	const META_CALLBACK_TOKEN_HASH  = '_llms_zibal_callback_token_hash';
	const META_MERCHANT_FINGERPRINT = '_llms_zibal_merchant_fingerprint';
	const META_TRANSACTION_ID       = '_llms_zibal_transaction_id';
	const META_RESULT_STATUS        = '_llms_zibal_result_status';
	const META_RESULT_CODE          = '_llms_zibal_result_code';
	const META_RESULT_MESSAGE       = '_llms_zibal_result_message';
	const META_PAID_AT              = '_llms_zibal_paid_at';
	const META_CARD_NUMBER          = '_llms_zibal_card_number';
	const META_CURRENT_ATTEMPT      = '_llms_zibal_current_attempt';
	const META_ATTEMPT_PREFIX       = '_llms_zibal_attempt_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                = 'zibal';
		$this->icon              = sprintf(
			'<img src="%s" alt="%s" style="width:auto;max-height:40px;">',
			esc_url( plugins_url( '/Images/zibal.png', __FILE__ ) ),
			esc_attr__( 'Zibal', 'lifterlms-zibal' )
		);
		$this->admin_description = __( 'Allow customers to purchase courses and memberships using Zibal.', 'lifterlms-zibal' );
		$this->admin_title       = __( 'درگاه پرداخت زیبال', 'lifterlms-zibal' );
		$this->title             = __( 'زیبال', 'lifterlms-zibal' );
		$this->description       = __( 'پرداخت امن از طریق زیبال', 'lifterlms-zibal' );
		$this->test_mode_title       = __( 'حالت تست زیبال', 'lifterlms-zibal' );
		$this->test_mode_description = __( 'در حالت تست، مرچنت رسمی zibal استفاده می‌شود و تراکنش واقعی برای مرچنت شما ثبت نمی‌شود.', 'lifterlms-zibal' );
		$this->supports          = array(
			'checkout_fields'           => false,
			'cc_save'                   => false,
			'refunds'                   => false,
			'single_payments'           => true,
			'recurring_payments'        => true,
			'recurring_retry'           => false,
			'test_mode'                 => true,
			'modify_recurring_payments' => false,
		);

		add_filter( 'llms_get_gateway_settings_fields', array( $this, 'settings_fields' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_start_due_payment' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_process_zibal_return' ), 1 );
		add_action( 'lifterlms_checkout_confirm_after_payment_method', array( $this, 'after_payment_method_details' ) );
		add_action( 'add_meta_boxes_llms_order', array( $this, 'add_order_payment_meta_box' ) );
		add_shortcode( 'llms_zibal_payment_result', array( $this, 'render_payment_result_shortcode' ) );
		add_filter( 'the_content', array( $this, 'append_payment_result' ), 9 );
		add_action( 'llms_view_order_after_secondary', array( $this, 'render_due_payment_button' ) );
	}

	/**
	 * Add a dedicated Zibal details box to Zibal orders in wp-admin.
	 *
	 * @param WP_Post $post Order post.
	 * @return void
	 */
	public function add_order_payment_meta_box( $post ) {
		if ( ! $post || ! isset( $post->ID ) ) {
			return;
		}

		$order = new LLMS_Order( $post );
		if ( 'zibal' !== $order->get( 'payment_gateway' ) ) {
			return;
		}

		add_meta_box(
			'llms-zibal-payment-details',
			__( 'جزئیات پرداخت زیبال', 'lifterlms-zibal' ),
			array( $this, 'render_order_payment_meta_box' ),
			'llms_order',
			'side',
			'high'
		);
	}

	/**
	 * Render customer-safe Zibal data in the administrative order screen.
	 *
	 * @param WP_Post $post Order post.
	 * @return void
	 */
	public function render_order_payment_meta_box( $post ) {
		$order_id       = $post && isset( $post->ID ) ? absint( $post->ID ) : 0;
		$result_status  = (string) get_post_meta( $order_id, self::META_RESULT_STATUS, true );
		$transaction_id = (string) get_post_meta( $order_id, self::META_TRANSACTION_ID, true );
		$paid_at        = (string) get_post_meta( $order_id, self::META_PAID_AT, true );
		$card_number    = $this->sanitize_card_number( get_post_meta( $order_id, self::META_CARD_NUMBER, true ) );
		$result_code    = (string) get_post_meta( $order_id, self::META_RESULT_CODE, true );
		$result_message = (string) get_post_meta( $order_id, self::META_RESULT_MESSAGE, true );
		$attempt_id     = $this->validate_attempt_id( get_post_meta( $order_id, self::META_CURRENT_ATTEMPT, true ) );
		$attempt        = $attempt_id ? $this->get_attempt( $order_id, $attempt_id ) : array();
		$status_labels = array(
			'pending'       => __( 'در انتظار پرداخت', 'lifterlms-zibal' ),
			'success'       => __( 'موفق', 'lifterlms-zibal' ),
			'failed'        => __( 'ناموفق', 'lifterlms-zibal' ),
			'manual-review' => __( 'نیازمند بررسی', 'lifterlms-zibal' ),
		);
		$status_label = isset( $status_labels[ $result_status ] ) ? $status_labels[ $result_status ] : '-';

		echo '<table class="widefat striped" dir="rtl"><tbody>';
		$this->render_admin_detail_row( __( 'وضعیت پرداخت', 'lifterlms-zibal' ), $status_label );
		$this->render_admin_detail_row( __( 'شماره تراکنش', 'lifterlms-zibal' ), $transaction_id ?: '-' );
		$this->render_admin_detail_row( __( 'زمان پرداخت', 'lifterlms-zibal' ), $paid_at ?: '-' );
		$this->render_admin_detail_row( __( 'شماره کارت', 'lifterlms-zibal' ), $card_number );
		if ( $attempt ) {
			$this->render_admin_detail_row( __( 'شناسه تلاش پرداخت', 'lifterlms-zibal' ), $attempt_id );
			$this->render_admin_detail_row( __( 'نوع پرداخت', 'lifterlms-zibal' ), isset( $attempt['payment_type'] ) ? $attempt['payment_type'] : '-' );
			$this->render_admin_detail_row( __( 'محیط', 'lifterlms-zibal' ), isset( $attempt['api_mode'] ) ? $attempt['api_mode'] : '-' );
		}
		if ( in_array( $result_status, array( 'failed', 'manual-review' ), true ) ) {
			$this->render_admin_detail_row( __( 'کد پاسخ زیبال', 'lifterlms-zibal' ), $result_code ?: '-' );
			$this->render_admin_detail_row( __( 'پاسخ زیبال', 'lifterlms-zibal' ), $result_message ?: '-' );
		}
		echo '</tbody></table>';
	}

	/**
	 * Process the browser return from Zibal without a second confirmation click.
	 *
	 * Zibal callbacks cannot carry a WordPress nonce. The callback is authenticated
	 * inside confirm_pending_order() using the random binding token, stored track ID,
	 * merchant fingerprint, expected amount, and a server-side verify request.
	 *
	 * @return void
	 */
	public function maybe_process_zibal_return() {
		$success        = $this->request_value( 'success' );
		$order_key      = $this->request_value( 'order' );
		$track_id       = $this->request_value( 'trackId' );
		$callback_token = $this->request_value( 'llms_zibal_cb' );
		if ( ! in_array( $success, array( '0', '1' ), true ) || ! $order_key || ! $track_id || ! $callback_token ) {
			return;
		}

		$order = llms_get_order_by_key( $order_key );
		if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) ) {
			return;
		}

		$this->confirm_pending_order( $order );
	}

	/**
	 * Start a due recurring payment from the authenticated order screen.
	 *
	 * @return void
	 */
	public function maybe_start_due_payment() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}

		if ( 'llms_zibal_pay_due' !== $this->request_value( 'llms_zibal_action' ) ) {
			return;
		}

		$order_id = absint( $this->request_value( 'llms_zibal_order_id' ) );
		$nonce    = $this->request_value( 'llms_zibal_nonce' );
		if ( ! $order_id || ! $nonce || ! wp_verify_nonce( $nonce, 'llms_zibal_pay_due_' . $order_id ) ) {
			llms_add_notice( esc_html__( 'درخواست پرداخت معتبر نیست. لطفاً صفحه را تازه کنید.', 'lifterlms-zibal' ), 'error' );
			return;
		}

		$order = llms_get_post( $order_id );
		if (
			! $order ||
			! is_a( $order, 'LLMS_Order' ) ||
			'zibal' !== $order->get( 'payment_gateway' ) ||
			! $order->is_recurring() ||
			absint( $order->get( 'user_id' ) ) !== get_current_user_id() ||
			! in_array( (string) $order->get( 'status' ), array( 'llms-on-hold', 'llms-pending' ), true )
		) {
			llms_add_notice( esc_html__( 'این سفارش در حال حاضر قابل پرداخت نیست.', 'lifterlms-zibal' ), 'error' );
			return;
		}

		$result = $this->start_payment_attempt( $order, 'recurring', false );
		if ( is_wp_error( $result ) ) {
			llms_add_notice( esc_html( $result->get_error_message() ), 'error' );
			$this->redirect_to_order_view( $order );
		}
	}

	/**
	 * Display the pay button for a manual recurring installment.
	 *
	 * Rendered through llms_view_order_after_secondary, which LifterLMS
	 * introduced in 6.0.0. The plugin bootstrap enforces that floor so a due
	 * installment always has a way to be paid.
	 *
	 * @param LLMS_Order $order Order shown in the student dashboard.
	 * @return void
	 */
	public function render_due_payment_button( $order ) {
		if (
			! $order ||
			! is_a( $order, 'LLMS_Order' ) ||
			'zibal' !== $order->get( 'payment_gateway' ) ||
			! $order->is_recurring() ||
			'llms-on-hold' !== (string) $order->get( 'status' ) ||
			absint( $order->get( 'user_id' ) ) !== get_current_user_id()
		) {
			return;
		}

		$order_id = $this->get_order_id( $order );
		?>
		<section class="llms-zibal-due-payment" dir="rtl">
			<p><?php echo esc_html__( 'موعد پرداخت این قسط رسیده است. پرداخت با انتقال به درگاه زیبال انجام می‌شود.', 'lifterlms-zibal' ); ?></p>
			<form method="post">
				<input type="hidden" name="llms_zibal_action" value="llms_zibal_pay_due">
				<input type="hidden" name="llms_zibal_order_id" value="<?php echo esc_attr( $order_id ); ?>">
				<?php wp_nonce_field( 'llms_zibal_pay_due_' . $order_id, 'llms_zibal_nonce' ); ?>
				<button class="llms-button-primary" type="submit"><?php echo esc_html__( 'پرداخت قسط با زیبال', 'lifterlms-zibal' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * External payment details are collected by Zibal.
	 *
	 * @return bool
	 */
	public function is_external_payment_entry() {
		return true;
	}

	/**
	 * Determine whether Zibal can process the selected access plan.
	 *
	 * Payments are performed on Zibal's hosted page. Recurring orders are
	 * collected manually at each due date and never debit a saved card.
	 *
	 * @param LLMS_Access_Plan|null $plan  Access plan being purchased.
	 * @param LLMS_Order|null       $order Existing order when switching source.
	 * @return bool
	 */
	public function can_process_access_plan( $plan, $order = null ) {
		$payment = $order ? $order : $plan;
		if ( ! is_object( $payment ) || ! method_exists( $payment, 'is_recurring' ) ) {
			return false;
		}

		if ( ! $this->get_merchant_id() ) {
			return false;
		}

		$currency = $order && method_exists( $order, 'get' ) ? (string) $order->get( 'currency' ) : '';
		if ( ! $currency && function_exists( 'get_lifterlms_currency' ) ) {
			$currency = (string) get_lifterlms_currency();
		}
		if ( $currency && ! $this->is_supported_currency( $currency, $order ) ) {
			return false;
		}

		// LLMS_Payment_Gateway::can_process_access_plan() only exists as of LifterLMS 7.5.0.
		if ( method_exists( get_parent_class( $this ), 'can_process_access_plan' ) ) {
			return parent::can_process_access_plan( $plan, $order );
		}

		return true;
	}

	/**
	 * Append the payment result to the LifterLMS checkout redirect page.
	 *
	 * @param string $content Page content.
	 * @return string
	 */
	public function append_payment_result( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( ! $this->request_value( 'order-complete' ) || has_shortcode( $content, 'llms_zibal_payment_result' ) ) {
			return $content;
		}

		return $content . '[llms_zibal_payment_result]';
	}

	/**
	 * Render a customer-safe payment result for the current Zibal order.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_payment_result_shortcode( $attributes = array() ) {
		$attributes = shortcode_atts( array( 'order' => '' ), $attributes, 'llms_zibal_payment_result' );
		$order_key  = $attributes['order'] ? sanitize_text_field( (string) $attributes['order'] ) : $this->request_value( 'order-complete' );
		if ( ! $order_key ) {
			return '';
		}

		$order = llms_get_order_by_key( $order_key );
		if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) || ! hash_equals( (string) $order->get( 'order_key' ), $order_key ) ) {
			return '';
		}

		$order_user_id = absint( $order->get( 'user_id' ) );
		if ( ! $order_user_id || ( get_current_user_id() !== $order_user_id && ! current_user_can( 'manage_options' ) ) ) {
			return '';
		}

		$order_id = $this->get_order_id( $order );
		$status   = (string) get_post_meta( $order_id, self::META_RESULT_STATUS, true );
		if ( 'success' === $status ) {
			return $this->render_success_result( $order_id );
		}

		if ( in_array( $status, array( 'failed', 'manual-review' ), true ) ) {
			return $this->render_failure_result( $order_id, $status );
		}

		return '';
	}

	/**
	 * Preserve the provider track ID when LifterLMS renders its confirmation form.
	 *
	 * The value is output only after it has been validated and matched with the
	 * track ID stored server-side for the current order.
	 */
	public function after_payment_method_details() {
		$order_key      = $this->request_value( 'order' );
		$track_id       = $this->get_callback_track_id();
		$callback_token = $this->get_callback_token();

		if ( ! $order_key || ! $track_id || ! $callback_token ) {
			return;
		}

		$order = llms_get_order_by_key( $order_key );
		if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) ) {
			return;
		}

		$stored_track_id = (string) get_post_meta( $this->get_order_id( $order ), self::META_TRACK_ID, true );
		$stored_token_hash = (string) get_post_meta( $this->get_order_id( $order ), self::META_CALLBACK_TOKEN_HASH, true );
		if (
			! $stored_track_id ||
			! hash_equals( $stored_track_id, $track_id ) ||
			! $stored_token_hash ||
			! hash_equals( $stored_token_hash, $this->binding_hash( $callback_token ) )
		) {
			return;
		}

		printf(
			'<input name="llms_zibal_token" type="hidden" value="%1$s"><input name="llms_zibal_cb" type="hidden" value="%2$s">',
			esc_attr( $track_id ),
			esc_attr( $callback_token )
		);
	}

	/**
	 * Verify and complete a pending Zibal order.
	 *
	 * @param LLMS_Order $order LifterLMS order.
	 */
	public function confirm_pending_order( $order ) {
		if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) ) {
			return;
		}

		$order_id  = $this->get_order_id( $order );
		$track_id  = $this->get_callback_track_id();
		$attempt_id = $this->get_callback_attempt_id();
		if ( ! $order_id || ! $track_id ) {
			$this->fail_payment( $order, __( 'Missing or invalid Zibal callback data.', 'lifterlms-zibal' ) );
		}

		$attempt = $attempt_id ? $this->get_attempt( $order_id, $attempt_id ) : $this->get_legacy_attempt( $order );
		if ( ! $attempt ) {
			$this->fail_payment( $order, __( 'The Zibal payment attempt could not be located.', 'lifterlms-zibal' ) );
		}

		$attempt_id      = isset( $attempt['id'] ) ? $this->validate_attempt_id( $attempt['id'] ) : '';
		$stored_track_id = isset( $attempt['track_id'] ) ? $this->validate_track_id( $attempt['track_id'] ) : '';
		$expected_amount = isset( $attempt['requested_amount'] ) ? (string) $attempt['requested_amount'] : '';
		$payment_state   = isset( $attempt['state'] ) ? sanitize_key( $attempt['state'] ) : '';
		$stored_txn_id   = isset( $attempt['transaction_id'] ) ? $this->validate_track_id( $attempt['transaction_id'] ) : '';

		if ( ! $stored_track_id || ! hash_equals( $stored_track_id, $track_id ) ) {
			$this->fail_payment( $order, __( 'Zibal track ID does not match the pending order.', 'lifterlms-zibal' ) );
		}

		$current_attempt = $this->validate_attempt_id( get_post_meta( $order_id, self::META_CURRENT_ATTEMPT, true ) );
		if ( $attempt_id && $current_attempt && ! hash_equals( $current_attempt, $attempt_id ) && 'paid' !== $payment_state ) {
			$this->move_to_manual_review( $order, __( 'A callback was received for an older Zibal attempt while another attempt is current.', 'lifterlms-zibal' ), $attempt );
		}

		$order_key        = (string) $order->get( 'order_key' );
		$callback_order   = $this->request_value( 'order' );
		$stored_binding   = isset( $attempt['order_binding'] ) ? (string) $attempt['order_binding'] : '';
		$current_binding  = $this->binding_hash( $order_key );
		$callback_token   = $this->get_callback_token();
		$stored_token_hash = isset( $attempt['callback_token_hash'] ) ? (string) $attempt['callback_token_hash'] : '';
		if (
			! $callback_order ||
			! hash_equals( $order_key, $callback_order ) ||
			! $stored_binding ||
			! hash_equals( $stored_binding, $current_binding ) ||
			! $callback_token ||
			! $stored_token_hash ||
			! hash_equals( $stored_token_hash, $this->binding_hash( $callback_token ) )
		) {
			$this->fail_payment( $order, __( 'Zibal callback is not bound to this order.', 'lifterlms-zibal' ) );
		}

		if ( 'paid' === $payment_state && $stored_txn_id ) {
			if ( ! $this->ensure_order_paid_status( $order ) ) {
				$this->move_to_manual_review( $order, __( 'The verified Zibal order could not be restored to its paid status.', 'lifterlms-zibal' ), $attempt );
			}
			$this->complete_transaction( $order );
		}
		if ( ! in_array( $payment_state, array( 'requested', 'verification-pending' ), true ) ) {
			$this->move_to_manual_review( $order, __( 'Zibal callback received for an attempt that is not awaiting verification.', 'lifterlms-zibal' ), $attempt );
		}

		$merchant         = $this->get_merchant_id();
		$stored_merchant  = isset( $attempt['merchant_fingerprint'] ) ? (string) $attempt['merchant_fingerprint'] : '';
		$stored_api_mode  = isset( $attempt['api_mode'] ) ? sanitize_key( $attempt['api_mode'] ) : '';
		if ( ! $stored_api_mode || ! hash_equals( $stored_api_mode, $this->get_api_mode() ) ) {
			$this->move_to_manual_review( $order, __( 'Zibal test/live mode changed after payment was requested.', 'lifterlms-zibal' ), $attempt );
		}
		if ( ! $merchant || ! $stored_merchant || ! hash_equals( $stored_merchant, $this->merchant_fingerprint( $merchant ) ) ) {
			$this->move_to_manual_review( $order, __( 'Zibal merchant configuration changed after payment was requested.', 'lifterlms-zibal' ), $attempt );
		}

		if ( ! preg_match( '/^[0-9]+$/', $expected_amount ) || self::MIN_GATEWAY_AMOUNT >= (int) $expected_amount ) {
			$this->move_to_manual_review( $order, __( 'Stored Zibal payment amount is missing or invalid.', 'lifterlms-zibal' ), $attempt );
		}

		$payment_type  = isset( $attempt['payment_type'] ) ? sanitize_key( $attempt['payment_type'] ) : 'single';
		$current_amount = $this->get_gateway_amount( $order, $payment_type );
		if ( is_wp_error( $current_amount ) || ! hash_equals( $expected_amount, (string) $current_amount ) ) {
			$this->move_to_manual_review( $order, __( 'The current order amount or currency no longer matches the original Zibal payment request.', 'lifterlms-zibal' ), $attempt );
		}

		$lock_name = $this->acquire_verification_lock( $order_id, $attempt_id ?: 'legacy' );
		if ( ! $lock_name ) {
			$this->fail_payment( $order, __( 'Another Zibal verification is already in progress.', 'lifterlms-zibal' ) );
		}

		$response = $this->verify_with_inquiry( $merchant, $track_id, $this->get_callback_success() );
		if ( is_wp_error( $response ) ) {
			$error_data   = $response->get_error_data();
			$error_data   = is_array( $error_data ) ? $error_data : array();
			$provider_res = isset( $error_data['response'] ) && is_array( $error_data['response'] ) ? $error_data['response'] : array();
			$result_code  = isset( $error_data['result_code'] ) ? (int) $error_data['result_code'] : 0;
			$status_code  = isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
			$message      = $this->get_provider_message( $provider_res );
			$detail       = $this->build_provider_failure_note( __( 'تأیید و استعلام پرداخت', 'lifterlms-zibal' ), $result_code, $provider_res, $status_code );

			$attempt['result_code']    = $result_code;
			$attempt['provider_status'] = $status_code;
			$attempt['result_message'] = $message;
			$attempt['state']          = 'zibal_payment_terminal' === $response->get_error_code() ? 'failed' : 'manual-review';
			$this->save_attempt( $order_id, $attempt );
			$this->store_result_meta( $order_id, 'failed' === $attempt['state'] ? 'failed' : 'manual-review', $result_code, $message );
			$this->release_verification_lock( $lock_name );

			if ( 'failed' === $attempt['state'] ) {
				$this->fail_payment( $order, $detail );
			}

			$this->move_to_manual_review( $order, $detail, $attempt );
		}

		$response_has_track_id = isset( $response['trackId'] );
		$verified_track_id     = $response_has_track_id && is_scalar( $response['trackId'] ) ? $this->validate_track_id( $response['trackId'] ) : '';
		$verified_order_id     = isset( $response['orderId'] ) && is_scalar( $response['orderId'] ) ? sanitize_text_field( (string) $response['orderId'] ) : '';
		$verified_amount       = isset( $response['amount'] ) && is_numeric( $response['amount'] ) ? (string) (int) round( (float) $response['amount'] ) : '';
		$verified_status       = isset( $response['status'] ) && is_numeric( $response['status'] ) ? (int) $response['status'] : 0;
		$provider_order_id     = isset( $attempt['provider_order_id'] ) ? (string) $attempt['provider_order_id'] : $order_key;

		if (
			( $response_has_track_id && ( ! $verified_track_id || ! hash_equals( $stored_track_id, $verified_track_id ) ) ) ||
			! $verified_order_id ||
			! hash_equals( $provider_order_id, $verified_order_id ) ||
			! $verified_amount ||
			! hash_equals( $expected_amount, $verified_amount ) ||
			1 !== $verified_status
		) {
			$this->release_verification_lock( $lock_name );
			$attempt['state'] = 'manual-review';
			$this->save_attempt( $order_id, $attempt );
			$this->move_to_manual_review( $order, __( 'Zibal verification data did not match the local payment attempt.', 'lifterlms-zibal' ), $attempt );
		}

		$reference_id  = isset( $response['refNumber'] ) && is_scalar( $response['refNumber'] ) ? $this->validate_track_id( $response['refNumber'] ) : '';
		$transaction_id = $reference_id ?: $stored_track_id;
		$paid_at        = $this->sanitize_paid_at( isset( $response['paidAt'] ) ? $response['paidAt'] : '' );
		$card_number    = $this->sanitize_card_number( isset( $response['cardNumber'] ) ? $response['cardNumber'] : '' );

		$transaction_data = array(
			'amount'             => isset( $attempt['local_amount'] ) ? (float) $attempt['local_amount'] : $this->get_local_amount( $order, $payment_type ),
			'transaction_id'     => $transaction_id,
			'status'             => 'llms-txn-succeeded',
			'payment_gateway'    => 'zibal',
			'payment_type'       => $payment_type,
			'source_description' => $card_number,
		);
		if ( '-' !== $paid_at ) {
			$transaction_data['completed_date'] = $paid_at;
		}
		$attempt['transaction_id'] = $transaction_id;
		$attempt['state']          = 'recording';
		if ( ! $this->save_attempt( $order_id, $attempt ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'Zibal was verified, but the transaction recording state could not be persisted.', 'lifterlms-zibal' ), $attempt );
		}
		update_post_meta( $order_id, self::META_TRANSACTION_ID, $transaction_id );
		update_post_meta( $order_id, self::META_PAYMENT_STATE, 'recording' );

		$transaction = $order->record_transaction( $transaction_data );
		if ( ! $transaction || is_wp_error( $transaction ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'Zibal verified the payment, but LifterLMS could not record the transaction.', 'lifterlms-zibal' ), $attempt );
		}
		if ( ! $this->ensure_order_paid_status( $order ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'Zibal verified the payment, but the LifterLMS order could not be changed to its paid status.', 'lifterlms-zibal' ), $attempt );
		}

		$attempt['state'] = 'paid';
		if ( ! $this->save_attempt( $order_id, $attempt ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'The Zibal transaction was recorded, but the final attempt state could not be persisted.', 'lifterlms-zibal' ), $attempt );
		}
		update_post_meta( $order_id, self::META_PAYMENT_STATE, 'paid' );
		if ( ! hash_equals( 'paid', (string) get_post_meta( $order_id, self::META_PAYMENT_STATE, true ) ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'The Zibal transaction was recorded, but the final paid state could not be persisted.', 'lifterlms-zibal' ), $attempt );
		}
		$this->store_result_meta( $order_id, 'success', 100, '', $paid_at, $card_number );
		$order->add_note( $this->build_success_note( $paid_at, $transaction_id, $card_number ) );
		$this->release_verification_lock( $lock_name );
		$this->log( 'Zibal payment verified and recorded.', $order_id, $transaction_id );
		$this->complete_transaction( $order );
	}

	/**
	 * Get the merchant setting.
	 *
	 * @return string
	 */
	public function get_MerchantID() {
		return $this->get_merchant_id();
	}

	/**
	 * Handle a pending order and redirect the customer to Zibal.
	 *
	 * @param LLMS_Order       $order  Order.
	 * @param LLMS_Access_Plan $plan   Access plan.
	 * @param LLMS_Student     $person Student.
	 * @param LLMS_Coupon|bool $coupon Coupon.
	 */
	public function handle_pending_order( $order, $plan, $person, $coupon = false ) {
		$initial_amount = $this->get_local_amount( $order, 'initial' );
		if ( 0.0 === (float) $initial_amount ) {
			return $this->complete_free_initial_payment( $order, $plan );
		}

		$payment_type = method_exists( $order, 'has_trial' ) && $order->has_trial() ? 'trial' : 'single';
		$result       = $this->start_payment_attempt( $order, $payment_type, true );
		if ( is_wp_error( $result ) ) {
			llms_add_notice( esc_html( $result->get_error_message() ), 'error' );
		}

		return $result;
	}

	/**
	 * Mark a recurring installment as due without charging a card automatically.
	 *
	 * Setting the order on hold routes it through LLMS_Controller_Orders::error_order(),
	 * which unschedules the recurring payment and unenrolls the student until the
	 * installment is paid. LifterLMS has no "payment due" notification of its own, so
	 * llms_manual_payment_due is fired for sites that want to notify the student.
	 *
	 * @param LLMS_Order $order Order being processed by the scheduler.
	 * @return void
	 */
	public function handle_recurring_transaction( $order ) {
		if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) || ! $order->is_recurring() ) {
			return;
		}

		$local_amount = $this->get_local_amount( $order, 'recurring' );
		if ( 0.0 === (float) $local_amount ) {
			$order->record_transaction(
				array(
					'amount'             => 0.0,
					'source_description' => __( 'رایگان', 'lifterlms-zibal' ),
					'transaction_id'     => 'zibal-free-' . wp_generate_password( 20, false, false ),
					'status'             => 'llms-txn-succeeded',
					'payment_gateway'    => 'zibal',
					'payment_type'       => 'recurring',
				)
			);
			return;
		}

		$order->set_status( 'on-hold' );
		update_post_meta( $this->get_order_id( $order ), self::META_PAYMENT_STATE, 'due' );
		update_post_meta( $this->get_order_id( $order ), self::META_RESULT_STATUS, 'pending' );
		$order->add_note( __( 'موعد قسط جدید رسید. سفارش تا پرداخت دستی کاربر از طریق زیبال در حالت انتظار است.', 'lifterlms-zibal' ) );
		do_action( 'llms_manual_payment_due', $order, $this );
	}

	/**
	 * Switch a recurring order to Zibal or collect a due installment.
	 *
	 * @param LLMS_Order $order     Order.
	 * @param array      $form_data Submitted LifterLMS form data.
	 * @return void|WP_Error
	 */
	public function handle_payment_source_switch( $order, $form_data = array() ) {
		if ( ! $order || ! $order->is_recurring() ) {
			// LLMS_Controller_Checkout::switch_payment_source() ignores the return value and
			// only inspects the error notices, so every failure has to raise one.
			llms_add_notice( esc_html__( 'سفارش دوره‌ای معتبر نیست.', 'lifterlms-zibal' ), 'error' );
			return new WP_Error( 'zibal_invalid_recurring_order', __( 'سفارش دوره‌ای معتبر نیست.', 'lifterlms-zibal' ) );
		}

		$action           = isset( $form_data['llms_switch_action'] ) && is_scalar( $form_data['llms_switch_action'] ) ? sanitize_key( wp_unslash( $form_data['llms_switch_action'] ) ) : '';
		$previous_gateway = (string) $order->get( 'payment_gateway' );
		$order->set( 'payment_gateway', 'zibal' );
		if ( 'zibal' !== $previous_gateway || ! $order->get( 'gateway_api_mode' ) ) {
			$order->set( 'gateway_api_mode', $this->get_api_mode() );
		}
		$order->set( 'gateway_customer_id', '' );
		$order->set( 'gateway_source_id', '' );
		$order->set( 'gateway_subscription_id', '' );

		if ( 'pay' === $action ) {
			// A successful attempt redirects to Zibal and exits, so LifterLMS never reaches
			// switch_payment_source_success(). Finish its bookkeeping before leaving.
			$order_id = $this->get_order_id( $order );
			do_action( 'llms_order_payment_source_switched', $order, 'zibal', $previous_gateway );
			delete_post_meta( $order_id, '_llms_temp_gateway_ids' );

			$result = $this->start_payment_attempt( $order, 'recurring', false );
			if ( is_wp_error( $result ) ) {
				llms_add_notice( esc_html( $result->get_error_message() ), 'error' );
			}

			return $result;
		}

		$order->add_note( __( 'روش پرداخت دوره‌ای به زیبال تغییر کرد. پرداخت هر سررسید با تأیید کاربر انجام می‌شود.', 'lifterlms-zibal' ) );
	}

	/**
	 * Complete a free access plan or free trial without contacting Zibal.
	 *
	 * @param LLMS_Order       $order Order.
	 * @param LLMS_Access_Plan $plan  Access plan.
	 * @return void
	 */
	private function complete_free_initial_payment( $order, $plan ) {
		$order_id = $this->get_order_id( $order );
		$is_free  = is_object( $plan ) && method_exists( $plan, 'is_free' ) && $plan->is_free();

		if ( $is_free && ! $order->is_recurring() ) {
			$order->set_status( 'completed' );
		} else {
			$payment_type = method_exists( $order, 'has_trial' ) && $order->has_trial() ? 'trial' : 'single';
			$order->record_transaction(
				array(
					'amount'             => 0.0,
					'source_description' => __( 'رایگان', 'lifterlms-zibal' ),
					'transaction_id'     => 'zibal-free-' . wp_generate_password( 20, false, false ),
					'status'             => 'llms-txn-succeeded',
					'payment_gateway'    => 'zibal',
					'payment_type'       => $payment_type,
				)
			);
		}

		update_post_meta( $order_id, self::META_PAYMENT_STATE, 'paid' );
		$this->store_result_meta( $order_id, 'success', 100, '', '', '-' );
		$order->add_note( __( 'مبلغ پرداخت اولیه صفر بود و بدون اتصال به زیبال ثبت شد.', 'lifterlms-zibal' ) );
		$this->complete_transaction( $order );
	}

	/**
	 * Create or resume an independently stored Zibal payment attempt.
	 *
	 * @param LLMS_Order $order        Order.
	 * @param string     $payment_type Transaction type: single, trial, or recurring.
	 * @param bool       $initial      Whether this is the checkout payment.
	 * @return void|WP_Error
	 */
	private function start_payment_attempt( $order, $payment_type, $initial ) {
		$payment_type = sanitize_key( $payment_type );
		if ( ! in_array( $payment_type, array( 'single', 'trial', 'recurring' ), true ) ) {
			return new WP_Error( 'zibal_invalid_payment_type', __( 'نوع پرداخت معتبر نیست.', 'lifterlms-zibal' ) );
		}

		$order_id  = $this->get_order_id( $order );
		$order_key = sanitize_text_field( (string) $order->get( 'order_key' ) );
		if ( ! $order_id || ! $order_key ) {
			return new WP_Error( 'zibal_invalid_order', __( 'اطلاعات سفارش معتبر نیست.', 'lifterlms-zibal' ) );
		}

		$api_mode       = $this->get_api_mode();
		$order_api_mode = sanitize_key( (string) $order->get( 'gateway_api_mode' ) );
		if ( ! $order_api_mode ) {
			$order->set( 'gateway_api_mode', $api_mode );
			$order_api_mode = $api_mode;
		}
		if ( ! hash_equals( $order_api_mode, $api_mode ) ) {
			$order->add_note( __( 'محیط تست/واقعی زیبال پس از ایجاد سفارش تغییر کرده است. پرداخت برای جلوگیری از ترکیب دو محیط متوقف شد.', 'lifterlms-zibal' ) );
			return new WP_Error( 'zibal_api_mode_mismatch', __( 'محیط تست یا واقعی این سفارش با تنظیمات فعلی یکسان نیست.', 'lifterlms-zibal' ) );
		}

		$merchant = $this->get_merchant_id();
		if ( ! $merchant ) {
			return new WP_Error( 'zibal_missing_merchant', __( 'درگاه زیبال پیکربندی نشده است.', 'lifterlms-zibal' ) );
		}

		$local_amount = $this->get_local_amount( $order, $payment_type );
		$amount       = $this->get_gateway_amount( $order, $payment_type );
		if ( is_wp_error( $amount ) ) {
			$this->log( 'Invalid Zibal amount: ' . $amount->get_error_code(), $order_id );
			$message = 'zibal_amount_too_small' === $amount->get_error_code()
				? __( 'مبلغ ارسالی به زیبال باید بیشتر از ۱۰۰۰ ریال باشد.', 'lifterlms-zibal' )
				: __( 'مبلغ سفارش برای پرداخت از طریق زیبال معتبر نیست.', 'lifterlms-zibal' );
			return new WP_Error( $amount->get_error_code(), $message );
		}

		$current_attempt_id = $this->validate_attempt_id( get_post_meta( $order_id, self::META_CURRENT_ATTEMPT, true ) );
		$current_attempt    = $current_attempt_id ? $this->get_attempt( $order_id, $current_attempt_id ) : array();
		if ( $current_attempt && in_array( isset( $current_attempt['state'] ) ? $current_attempt['state'] : '', array( 'recording', 'verification-pending', 'manual-review' ), true ) ) {
			return new WP_Error( 'zibal_attempt_requires_review', __( 'وضعیت پرداخت قبلی هنوز نیازمند بررسی است. لطفاً با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' ) );
		}

		if (
			$current_attempt &&
			'requested' === ( isset( $current_attempt['state'] ) ? $current_attempt['state'] : '' ) &&
			isset( $current_attempt['track_id'], $current_attempt['requested_amount'], $current_attempt['payment_type'], $current_attempt['merchant_fingerprint'], $current_attempt['api_mode'] ) &&
			$this->validate_track_id( $current_attempt['track_id'] ) &&
			hash_equals( (string) $current_attempt['requested_amount'], (string) $amount ) &&
			hash_equals( (string) $current_attempt['payment_type'], $payment_type ) &&
			hash_equals( (string) $current_attempt['merchant_fingerprint'], $this->merchant_fingerprint( $merchant ) ) &&
			hash_equals( (string) $current_attempt['api_mode'], $api_mode )
		) {
			$this->redirect_to_zibal( $current_attempt['track_id'] );
		}

		if ( $current_attempt && 'requested' === ( isset( $current_attempt['state'] ) ? $current_attempt['state'] : '' ) ) {
			return new WP_Error( 'zibal_open_attempt', __( 'یک درخواست پرداخت باز برای این سفارش وجود دارد. ابتدا وضعیت آن باید مشخص شود.', 'lifterlms-zibal' ) );
		}

		$request_lock = $this->acquire_request_lock( $order_id );
		if ( ! $request_lock ) {
			return new WP_Error( 'zibal_request_locked', __( 'درخواست دیگری در حال ثبت است. چند لحظه دیگر دوباره تلاش کنید.', 'lifterlms-zibal' ) );
		}

		$attempt_id        = strtolower( wp_generate_password( 24, false, false ) );
		$callback_token    = wp_generate_password( 32, false, false );
		$provider_order_id = 'llms-' . $order_id . '-' . $attempt_id;
		$callback_url      = add_query_arg(
			array(
				'llms_zibal_attempt' => $attempt_id,
				'llms_zibal_cb'      => $callback_token,
			),
			llms_confirm_payment_url( $order_key )
		);
		$attempt = array(
			'id'                   => $attempt_id,
			'track_id'             => '',
			'requested_amount'      => (string) $amount,
			'local_amount'          => $this->normalize_local_amount( $local_amount ),
			'payment_type'          => $payment_type,
			'provider_order_id'     => $provider_order_id,
			'order_binding'         => $this->binding_hash( $order_key ),
			'callback_token_hash'   => $this->binding_hash( $callback_token ),
			'merchant_fingerprint' => $this->merchant_fingerprint( $merchant ),
			'api_mode'              => $api_mode,
			'state'                 => 'creating',
			'transaction_id'        => '',
			'result_code'           => 0,
			'provider_status'       => 0,
			'result_message'        => '',
			'created_at'            => time(),
			'updated_at'            => time(),
		);

		if ( ! $this->save_attempt( $order_id, $attempt ) ) {
			$this->release_verification_lock( $request_lock );
			return new WP_Error( 'zibal_attempt_persistence', __( 'ثبت اطلاعات پرداخت ممکن نشد. لطفاً دوباره تلاش کنید.', 'lifterlms-zibal' ) );
		}
		update_post_meta( $order_id, self::META_CURRENT_ATTEMPT, $attempt_id );
		if ( ! hash_equals( $attempt_id, (string) get_post_meta( $order_id, self::META_CURRENT_ATTEMPT, true ) ) ) {
			$this->release_verification_lock( $request_lock );
			return new WP_Error( 'zibal_attempt_persistence', __( 'ثبت شناسه تلاش پرداخت ممکن نشد. لطفاً دوباره تلاش کنید.', 'lifterlms-zibal' ) );
		}

		$response = $this->api_post(
			self::REQUEST_URL,
			array(
				'merchant'    => $merchant,
				'amount'      => $amount,
				'callbackUrl' => $callback_url,
				'orderId'     => $provider_order_id,
				'description' => sprintf( 'LifterLMS order %1$d - %2$s', $order_id, $payment_type ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$attempt['state']          = 'request-error';
			$attempt['result_message'] = $response->get_error_code();
			$this->save_attempt( $order_id, $attempt );
			$this->release_verification_lock( $request_lock );
			$this->log( 'Zibal request transport error: ' . $response->get_error_code(), $order_id, $attempt_id );
			return new WP_Error( 'zibal_request_transport', __( 'در حال حاضر ارتباط با درگاه زیبال ممکن نیست. لطفاً دوباره تلاش کنید.', 'lifterlms-zibal' ) );
		}

		$result_code = isset( $response['result'] ) && is_numeric( $response['result'] ) ? (int) $response['result'] : 0;
		$track_id    = isset( $response['trackId'] ) && is_scalar( $response['trackId'] ) ? $this->validate_track_id( $response['trackId'] ) : '';
		if ( 100 !== $result_code || ! $track_id ) {
			$attempt['state']          = 'failed';
			$attempt['result_code']    = $result_code;
			$attempt['result_message'] = $this->get_provider_message( $response );
			$this->save_attempt( $order_id, $attempt );
			$this->store_result_meta( $order_id, 'failed', $result_code, $attempt['result_message'] );
			$order->add_note( $this->build_provider_failure_note( __( 'ایجاد درخواست پرداخت', 'lifterlms-zibal' ), $result_code, $response ) );
			$this->release_verification_lock( $request_lock );
			$this->log( 'Zibal request rejected.', $order_id, $result_code );
			return new WP_Error( 'zibal_request_rejected', __( 'درخواست پرداخت توسط زیبال پذیرفته نشد. جزئیات دقیق در یادداشت سفارش ثبت شد.', 'lifterlms-zibal' ) );
		}

		$attempt['track_id']       = $track_id;
		$attempt['transaction_id'] = $track_id;
		$attempt['state']          = 'requested';
		$attempt['result_code']    = 100;
		$attempt['result_message'] = '';
		if ( ! $this->save_attempt( $order_id, $attempt ) ) {
			$this->release_verification_lock( $request_lock );
			return new WP_Error( 'zibal_attempt_persistence', __( 'ثبت شناسه پرداخت ممکن نشد. از پرداخت خودداری کنید و با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' ) );
		}

		$payment_meta = array(
			self::META_REQUESTED_AMOUNT     => (string) $amount,
			self::META_ORDER_BINDING        => $attempt['order_binding'],
			self::META_CALLBACK_TOKEN_HASH  => $attempt['callback_token_hash'],
			self::META_MERCHANT_FINGERPRINT => $attempt['merchant_fingerprint'],
			self::META_TRACK_ID             => $track_id,
			self::META_PAYMENT_STATE        => 'requested',
			self::META_TRANSACTION_ID       => $track_id,
			self::META_RESULT_STATUS        => 'pending',
			self::META_RESULT_CODE          => '',
			self::META_RESULT_MESSAGE       => '',
			self::META_PAID_AT              => '',
			self::META_CARD_NUMBER          => '',
		);
		foreach ( $payment_meta as $meta_key => $meta_value ) {
			update_post_meta( $order_id, $meta_key, $meta_value );
		}

		$order->add_note( sprintf( __( 'درخواست پرداخت زیبال ثبت شد. شناسه تلاش: %1$s - شناسه پیگیری: %2$s', 'lifterlms-zibal' ), $attempt_id, $track_id ) );
		if ( $initial ) {
			do_action( 'lifterlms_handle_pending_order_complete', $order );
		}
		$this->release_verification_lock( $request_lock );
		$this->log( 'Zibal payment requested.', $order_id, $track_id );
		$this->redirect_to_zibal( $track_id );
	}

	/**
	 * Add Zibal settings.
	 *
	 * @param array  $fields     Existing fields.
	 * @param string $gateway_id Gateway ID.
	 * @return array
	 */
	public function settings_fields( $fields, $gateway_id ) {
		if ( $this->id !== $gateway_id ) {
			return $fields;
		}

		$fields[] = array(
			'type'  => 'custom-html',
			'value' => sprintf(
				'<h4>%s</h4><p>%s</p>',
				esc_html__( 'تنظیمات زیبال', 'lifterlms-zibal' ),
				esc_html__( 'مرچنت‌کد زیبال و واحد مبلغ فروشگاه را وارد کنید.', 'lifterlms-zibal' )
			),
		);

		$fields[] = array(
			'id'                => $this->get_option_name( 'MerchantID' ),
			'default'           => $this->get_configured_merchant_id(),
			'title'             => esc_html__( 'مرچنت‌کد', 'lifterlms-zibal' ),
			'type'              => 'text',
			'custom_attributes' => array( 'maxlength' => 128 ),
			'autoload'          => false,
		);

		$fields[] = array(
			'id'      => $this->get_option_name( 'currency_unit' ),
			'default' => 'auto',
			'title'   => esc_html__( 'واحد مبلغ فروشگاه', 'lifterlms-zibal' ),
			'type'    => 'select',
			'options' => array(
				'auto'  => esc_html__( 'تشخیص خودکار', 'lifterlms-zibal' ),
				'rial'  => esc_html__( 'ریال', 'lifterlms-zibal' ),
				'toman' => esc_html__( 'تومان', 'lifterlms-zibal' ),
			),
		);

		return $fields;
	}

	/**
	 * Post JSON to a fixed Zibal endpoint.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $payload Request data.
	 * @return array|WP_Error
	 */
	private function api_post( $url, $payload ) {
		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error( 'zibal_json_encode', __( 'Could not encode the Zibal request.', 'lifterlms-zibal' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'             => self::HTTP_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => 1048576,
				'headers'             => array(
					'Accept'         => 'application/json',
					'Content-Type'   => 'application/json',
					'X-Zibal-Plugin' => 'wordpress-lifterlms/' . self::VERSION,
				),
				'user-agent'          => $this->get_user_agent(),
				'body'                => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $response_body, true );
		if ( 200 > $status || 300 <= $status ) {
			if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ) {
				$result_code = isset( $decoded['result'] ) && is_numeric( $decoded['result'] ) ? (int) $decoded['result'] : 0;
				return array(
					'result'  => 100 === $result_code ? 0 : $result_code,
					'message' => (string) $decoded['message'],
				);
			}

			return new WP_Error( 'zibal_http_status', sprintf( 'Unexpected Zibal HTTP status: %d', $status ) );
		}

		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'zibal_invalid_json', __( 'Zibal returned an invalid JSON response.', 'lifterlms-zibal' ) );
		}

		return $decoded;
	}

	/**
	 * Verify a payment and reconcile ambiguous responses using inquiry.
	 *
	 * @param string $merchant         Merchant identifier.
	 * @param string $track_id         Zibal track ID.
	 * @param bool   $callback_success Whether the browser callback reported a successful payment.
	 * @return array|WP_Error
	 */
	private function verify_with_inquiry( $merchant, $track_id, $callback_success = true ) {
		$verify = $this->api_post(
			self::VERIFY_URL,
			array(
				'merchant' => $merchant,
				'trackId'  => $track_id,
			)
		);
		$verify_result = is_array( $verify ) && isset( $verify['result'] ) && is_numeric( $verify['result'] ) ? (int) $verify['result'] : 0;
		if ( is_array( $verify ) && 100 === $verify_result ) {
			return $verify;
		}

		if ( is_array( $verify ) && ! in_array( $verify_result, array( 201, 202 ), true ) ) {
			return new WP_Error(
				'zibal_payment_terminal',
				$this->get_provider_message( $verify ),
				array(
					'response'    => $verify,
					'result_code' => $verify_result,
					'status'      => isset( $verify['status'] ) && is_numeric( $verify['status'] ) ? (int) $verify['status'] : 0,
				)
			);
		}

		$inquiry = $this->api_post(
			self::INQUIRY_URL,
			array(
				'merchant' => $merchant,
				'trackId'  => $track_id,
			)
		);
		if ( is_wp_error( $inquiry ) ) {
			return new WP_Error(
				'zibal_payment_ambiguous',
				$inquiry->get_error_message(),
				array(
					'response'    => is_array( $verify ) ? $verify : array(),
					'result_code' => $verify_result,
					'status'      => 0,
				)
			);
		}

		$inquiry_result = isset( $inquiry['result'] ) && is_numeric( $inquiry['result'] ) ? (int) $inquiry['result'] : 0;
		$status         = isset( $inquiry['status'] ) && is_numeric( $inquiry['status'] ) ? (int) $inquiry['status'] : 0;
		if ( 100 !== $inquiry_result ) {
			return new WP_Error(
				'zibal_payment_ambiguous',
				$this->get_provider_message( $inquiry ),
				array(
					'response'    => $inquiry,
					'result_code' => $inquiry_result,
					'status'      => $status,
				)
			);
		}

		if ( 1 === $status ) {
			return $inquiry;
		}

		if ( 2 === $status ) {
			$retry = $this->api_post(
				self::VERIFY_URL,
				array(
					'merchant' => $merchant,
					'trackId'  => $track_id,
				)
			);
			$retry_result = is_array( $retry ) && isset( $retry['result'] ) && is_numeric( $retry['result'] ) ? (int) $retry['result'] : 0;
			if ( is_array( $retry ) && 100 === $retry_result ) {
				return $retry;
			}

			if ( is_array( $retry ) && 201 === $retry_result ) {
				$second_inquiry = $this->api_post(
					self::INQUIRY_URL,
					array(
						'merchant' => $merchant,
						'trackId'  => $track_id,
					)
				);
				if (
					is_array( $second_inquiry ) &&
					isset( $second_inquiry['result'], $second_inquiry['status'] ) &&
					100 === (int) $second_inquiry['result'] &&
					1 === (int) $second_inquiry['status']
				) {
					return $second_inquiry;
				}
			}

			return new WP_Error(
				'zibal_payment_ambiguous',
				is_array( $retry ) ? $this->get_provider_message( $retry ) : $retry->get_error_message(),
				array(
					'response'    => is_array( $retry ) ? $retry : $inquiry,
					'result_code' => $retry_result,
					'status'      => $status,
				)
			);
		}

		$terminal_statuses = array( 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 15, 18, 21 );

		/*
		 * Status -1 means Zibal never received a payment for this session. When the browser
		 * callback also reported failure, the customer simply abandoned or cancelled the
		 * gateway page. That is an ordinary failed checkout, so it must not be escalated to
		 * manual review: doing so puts the order on hold, which unenrolls the student and
		 * unschedules the recurring payment for a payment that was never attempted.
		 */
		if ( -1 === $status && ! $callback_success ) {
			$terminal_statuses[] = -1;
		}

		$error_code     = in_array( $status, $terminal_statuses, true ) ? 'zibal_payment_terminal' : 'zibal_payment_ambiguous';
		$error_response = $inquiry;

		return new WP_Error(
			$error_code,
			$this->get_provider_message( $error_response ),
			array(
				'response'    => $error_response,
				'result_code' => $verify_result ?: $inquiry_result,
				'status'      => $status,
			)
		);
	}

	/**
	 * Validate and sanitize an attempt identifier.
	 *
	 * @param mixed $attempt_id Attempt identifier.
	 * @return string
	 */
	private function validate_attempt_id( $attempt_id ) {
		$attempt_id = strtolower( sanitize_text_field( (string) $attempt_id ) );
		if ( 'legacy' === $attempt_id ) {
			return $attempt_id;
		}

		return preg_match( '/^[a-z0-9]{16,32}$/', $attempt_id ) ? $attempt_id : '';
	}

	/**
	 * Get an attempt identifier from the callback.
	 *
	 * @return string
	 */
	private function get_callback_attempt_id() {
		return $this->validate_attempt_id( $this->request_value( 'llms_zibal_attempt' ) );
	}

	/**
	 * Get the post meta key for an attempt.
	 *
	 * @param string $attempt_id Attempt identifier.
	 * @return string
	 */
	private function get_attempt_meta_key( $attempt_id ) {
		return self::META_ATTEMPT_PREFIX . $attempt_id;
	}

	/**
	 * Sanitize an attempt record before storage or use.
	 *
	 * @param array $attempt Attempt data.
	 * @return array
	 */
	private function sanitize_attempt( $attempt ) {
		if ( ! is_array( $attempt ) ) {
			return array();
		}

		$attempt_id = isset( $attempt['id'] ) ? $this->validate_attempt_id( $attempt['id'] ) : '';
		if ( ! $attempt_id ) {
			return array();
		}

		$states = array( 'creating', 'requested', 'request-error', 'verification-pending', 'recording', 'paid', 'failed', 'manual-review' );
		$types  = array( 'single', 'trial', 'recurring' );
		$state  = isset( $attempt['state'] ) ? sanitize_key( $attempt['state'] ) : '';
		$type   = isset( $attempt['payment_type'] ) ? sanitize_key( $attempt['payment_type'] ) : '';

		return array(
			'id'                   => $attempt_id,
			'track_id'             => isset( $attempt['track_id'] ) ? $this->validate_track_id( $attempt['track_id'] ) : '',
			'requested_amount'      => isset( $attempt['requested_amount'] ) && preg_match( '/^[0-9]+$/', (string) $attempt['requested_amount'] ) ? (string) $attempt['requested_amount'] : '',
			'local_amount'          => isset( $attempt['local_amount'] ) && is_numeric( $attempt['local_amount'] ) ? $this->normalize_local_amount( $attempt['local_amount'] ) : '',
			'payment_type'          => in_array( $type, $types, true ) ? $type : 'single',
			'provider_order_id'     => isset( $attempt['provider_order_id'] ) && is_scalar( $attempt['provider_order_id'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $attempt['provider_order_id'] ), 0, 100 ) : '',
			'order_binding'         => isset( $attempt['order_binding'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $attempt['order_binding'] ) ? (string) $attempt['order_binding'] : '',
			'callback_token_hash'   => isset( $attempt['callback_token_hash'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $attempt['callback_token_hash'] ) ? (string) $attempt['callback_token_hash'] : '',
			'merchant_fingerprint' => isset( $attempt['merchant_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $attempt['merchant_fingerprint'] ) ? (string) $attempt['merchant_fingerprint'] : '',
			'api_mode'              => isset( $attempt['api_mode'] ) && 'test' === sanitize_key( $attempt['api_mode'] ) ? 'test' : 'live',
			'state'                 => in_array( $state, $states, true ) ? $state : 'manual-review',
			'transaction_id'        => isset( $attempt['transaction_id'] ) ? $this->validate_track_id( $attempt['transaction_id'] ) : '',
			'result_code'           => isset( $attempt['result_code'] ) && is_numeric( $attempt['result_code'] ) ? (int) $attempt['result_code'] : 0,
			'provider_status'       => isset( $attempt['provider_status'] ) && is_numeric( $attempt['provider_status'] ) ? (int) $attempt['provider_status'] : 0,
			'result_message'        => isset( $attempt['result_message'] ) && is_scalar( $attempt['result_message'] ) ? substr( sanitize_textarea_field( (string) $attempt['result_message'] ), 0, 1000 ) : '',
			'created_at'            => isset( $attempt['created_at'] ) ? absint( $attempt['created_at'] ) : time(),
			'updated_at'            => time(),
		);
	}

	/**
	 * Persist a payment attempt and verify the stored value.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $attempt  Attempt data.
	 * @return bool
	 */
	private function save_attempt( $order_id, $attempt ) {
		$attempt = $this->sanitize_attempt( $attempt );
		if ( ! $order_id || ! $attempt ) {
			return false;
		}

		update_post_meta( $order_id, $this->get_attempt_meta_key( $attempt['id'] ), $attempt );
		$stored = get_post_meta( $order_id, $this->get_attempt_meta_key( $attempt['id'] ), true );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		$stored = $this->sanitize_attempt( $stored );
		foreach ( array( 'id', 'track_id', 'requested_amount', 'payment_type', 'provider_order_id', 'callback_token_hash', 'merchant_fingerprint', 'api_mode', 'state', 'transaction_id' ) as $key ) {
			if ( ! isset( $stored[ $key ], $attempt[ $key ] ) || ! hash_equals( (string) $attempt[ $key ], (string) $stored[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Load a stored attempt.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $attempt_id Attempt identifier.
	 * @return array
	 */
	private function get_attempt( $order_id, $attempt_id ) {
		$attempt_id = $this->validate_attempt_id( $attempt_id );
		if ( ! $order_id || ! $attempt_id ) {
			return array();
		}

		return $this->sanitize_attempt( get_post_meta( $order_id, $this->get_attempt_meta_key( $attempt_id ), true ) );
	}

	/**
	 * Adapt an in-flight pre-2.4.0 request to the attempt model.
	 *
	 * @param LLMS_Order $order Order.
	 * @return array
	 */
	private function get_legacy_attempt( $order ) {
		$order_id = $this->get_order_id( $order );
		$track_id = $this->validate_track_id( get_post_meta( $order_id, self::META_TRACK_ID, true ) );
		if ( ! $track_id ) {
			return array();
		}

		$payment_type = method_exists( $order, 'has_trial' ) && $order->has_trial() ? 'trial' : 'single';
		return $this->sanitize_attempt(
			array(
				'id'                   => 'legacy',
				'track_id'             => $track_id,
				'requested_amount'      => (string) get_post_meta( $order_id, self::META_REQUESTED_AMOUNT, true ),
				'local_amount'          => $this->get_local_amount( $order, $payment_type ),
				'payment_type'          => $payment_type,
				'provider_order_id'     => (string) $order->get( 'order_key' ),
				'order_binding'         => (string) get_post_meta( $order_id, self::META_ORDER_BINDING, true ),
				'callback_token_hash'   => (string) get_post_meta( $order_id, self::META_CALLBACK_TOKEN_HASH, true ),
				'merchant_fingerprint' => (string) get_post_meta( $order_id, self::META_MERCHANT_FINGERPRINT, true ),
				'api_mode'              => (string) $order->get( 'gateway_api_mode' ),
				'state'                 => (string) get_post_meta( $order_id, self::META_PAYMENT_STATE, true ),
				'transaction_id'        => (string) get_post_meta( $order_id, self::META_TRANSACTION_ID, true ),
				'created_at'            => time(),
			)
		);
	}

	/**
	 * Calculate the integer amount sent to Zibal.
	 *
	 * @param LLMS_Order $order        Order.
	 * @param string     $payment_type Payment type or "initial".
	 * @return int|WP_Error
	 */
	private function get_gateway_amount( $order, $payment_type = 'single' ) {
		$local_amount = $this->get_local_amount( $order, $payment_type );
		$local_amount_float = is_numeric( $local_amount ) ? (float) $local_amount : 0.0;
		if ( ! is_finite( $local_amount_float ) || 0 >= $local_amount_float ) {
			return new WP_Error( 'zibal_invalid_amount' );
		}

		$currency = strtoupper( sanitize_text_field( (string) $order->get( 'currency' ) ) );
		if ( ! $this->is_supported_currency( $currency, $order ) ) {
			return new WP_Error( 'zibal_unsupported_currency' );
		}

		$unit = sanitize_key( (string) $this->get_option( 'currency_unit' ) );
		if ( ! in_array( $unit, array( 'auto', 'rial', 'toman' ), true ) ) {
			$unit = 'auto';
		}

		if ( 'auto' === $unit ) {
			$unit = in_array( $currency, array( 'IRT', 'TOMAN' ), true ) ? 'toman' : 'rial';
		}

		$calculated_amount = $local_amount_float * ( 'toman' === $unit ? 10 : 1 );
		if ( ! is_finite( $calculated_amount ) || PHP_INT_MAX < $calculated_amount ) {
			return new WP_Error( 'zibal_invalid_gateway_amount' );
		}

		$amount          = (int) round( $calculated_amount );
		$filtered_amount = apply_filters( 'llms_zibal_gateway_amount', $amount, $local_amount, $currency, $order );
		if ( ! is_numeric( $filtered_amount ) ) {
			return new WP_Error( 'zibal_invalid_gateway_amount' );
		}

		$filtered_amount = (float) $filtered_amount;
		if ( ! is_finite( $filtered_amount ) || 0 >= $filtered_amount || PHP_INT_MAX < $filtered_amount ) {
			return new WP_Error( 'zibal_invalid_gateway_amount' );
		}

		$filtered_amount = (int) round( $filtered_amount );
		if ( self::MIN_GATEWAY_AMOUNT >= $filtered_amount ) {
			return new WP_Error( 'zibal_amount_too_small' );
		}

		return $filtered_amount;
	}

	/**
	 * Get the local amount for the exact payment occurrence.
	 *
	 * @param LLMS_Order $order        Order.
	 * @param string     $payment_type Payment type or "initial".
	 * @return float
	 */
	private function get_local_amount( $order, $payment_type ) {
		if ( 'recurring' === $payment_type ) {
			return (float) $order->get_price( 'total', array(), 'float' );
		}

		if ( method_exists( $order, 'get_initial_price' ) ) {
			return (float) $order->get_initial_price( array(), 'float' );
		}

		return (float) $order->get_price( 'total', array(), 'float' );
	}

	/**
	 * Get the store currencies Zibal can be charged in.
	 *
	 * @param LLMS_Order|null $order Order, when one is available.
	 * @return string[]
	 */
	private function get_supported_currencies( $order = null ) {
		$supported_currencies = (array) apply_filters(
			'llms_zibal_supported_currencies',
			array( 'IRR', 'IRT', 'TOMAN' ),
			$order
		);
		$supported_currencies = array_map(
			static function ( $currency_code ) {
				if ( ! is_scalar( $currency_code ) ) {
					return '';
				}

				return strtoupper( sanitize_text_field( (string) $currency_code ) );
			},
			$supported_currencies
		);

		return array_values( array_filter( $supported_currencies ) );
	}

	/**
	 * Determine whether a currency code can be sent to Zibal.
	 *
	 * @param mixed           $currency Currency code.
	 * @param LLMS_Order|null $order    Order, when one is available.
	 * @return bool
	 */
	private function is_supported_currency( $currency, $order = null ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		if ( ! $currency ) {
			return false;
		}

		return in_array( $currency, $this->get_supported_currencies( $order ), true );
	}

	/**
	 * Normalize a local decimal amount for durable attempt storage.
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	private function normalize_local_amount( $amount ) {
		$normalized = number_format( (float) $amount, 8, '.', '' );
		return rtrim( rtrim( $normalized, '0' ), '.' );
	}

	/**
	 * Build a stable, analytics-friendly HTTP user agent for Zibal requests.
	 *
	 * No customer or site-identifying data is included.
	 *
	 * @return string
	 */
	private function get_user_agent() {
		$wp_version       = preg_replace( '/[^0-9A-Za-z._-]/', '', (string) get_bloginfo( 'version' ) );
		$php_version      = preg_replace( '/[^0-9A-Za-z._-]/', '', PHP_VERSION );
		$lifterlms_version = '';
		if ( function_exists( 'llms' ) ) {
			$lifterlms = llms();
			if ( is_object( $lifterlms ) && isset( $lifterlms->version ) ) {
				$lifterlms_version = preg_replace( '/[^0-9A-Za-z._-]/', '', (string) $lifterlms->version );
			}
		}

		$user_agent = sprintf(
			'Zibal-WordPress-LifterLMS/%1$s (WordPress/%2$s; LifterLMS/%3$s; PHP/%4$s)',
			self::VERSION,
			$wp_version ?: 'unknown',
			$lifterlms_version ?: 'unknown',
			$php_version ?: 'unknown'
		);

		$filtered_user_agent = apply_filters( 'llms_zibal_user_agent', $user_agent, self::VERSION );
		if ( is_scalar( $filtered_user_agent ) ) {
			$user_agent = (string) $filtered_user_agent;
		}
		$user_agent = str_replace( array( "\r", "\n" ), '', sanitize_text_field( $user_agent ) );

		return substr( $user_agent, 0, 255 );
	}

	/**
	 * Get and validate the configured merchant identifier.
	 *
	 * @return string
	 */
	private function get_merchant_id() {
		if ( $this->is_test_mode_enabled() ) {
			return 'zibal';
		}

		return $this->get_configured_merchant_id();
	}

	/**
	 * Get the saved live merchant without applying test mode.
	 *
	 * @return string
	 */
	private function get_configured_merchant_id() {
		$merchant = sanitize_text_field( (string) $this->get_option( 'MerchantID' ) );
		if ( ! $merchant || 128 < strlen( $merchant ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $merchant ) ) {
			return '';
		}

		return $merchant;
	}

	/**
	 * Read a scalar request value safely.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_value( $key ) {
		$value = '';
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
			$value = wp_unslash( $_GET[ $key ] );
		} elseif ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
			$value = wp_unslash( $_POST[ $key ] );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Retrieve and validate the callback track ID.
	 *
	 * @return string
	 */
	private function get_callback_track_id() {
		$track_id = $this->request_value( 'trackId' );
		if ( ! $track_id ) {
			$track_id = $this->request_value( 'llms_zibal_token' );
		}

		return $this->validate_track_id( $track_id );
	}

	/**
	 * Determine whether the Zibal browser callback reported a successful payment.
	 *
	 * Advisory only: the payment is still settled by a server-side verify. It is used
	 * to tell an abandoned session apart from a genuinely contradictory response.
	 *
	 * @return bool
	 */
	private function get_callback_success() {
		return '1' === $this->request_value( 'success' );
	}

	/**
	 * Retrieve the cryptographically random callback binding token.
	 *
	 * @return string
	 */
	private function get_callback_token() {
		$token = $this->request_value( 'llms_zibal_cb' );
		return preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * Validate a Zibal track ID.
	 *
	 * @param mixed $track_id Track ID.
	 * @return string
	 */
	private function validate_track_id( $track_id ) {
		$track_id = sanitize_text_field( (string) $track_id );
		return preg_match( '/^[0-9]{1,64}$/', $track_id ) ? $track_id : '';
	}

	/**
	 * Sanitize and safely mask a card number returned by Zibal.
	 *
	 * @param mixed $card_number Card number returned by Zibal.
	 * @return string
	 */
	private function sanitize_card_number( $card_number ) {
		if ( ! is_scalar( $card_number ) ) {
			return '-';
		}

		$card_number = preg_replace( '/[^0-9*\-]/', '', (string) $card_number );
		if ( ! $card_number ) {
			return '-';
		}

		$digits = preg_replace( '/[^0-9]/', '', $card_number );
		if ( false === strpos( $card_number, '*' ) && 10 < strlen( $digits ) ) {
			return substr( $digits, 0, 6 ) . '******' . substr( $digits, -4 );
		}

		return substr( $card_number, 0, 32 );
	}

	/**
	 * Read the exact plain-text message returned by Zibal.
	 *
	 * @param array $response Decoded Zibal response.
	 * @return string
	 */
	private function get_provider_message( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['message'] ) || ! is_scalar( $response['message'] ) ) {
			return '-';
		}

		$message = sanitize_textarea_field( (string) $response['message'] );
		return $message ? $message : '-';
	}

	/**
	 * Build an admin order note for a failed Zibal response.
	 *
	 * @param string $stage       Payment stage.
	 * @param int    $result_code Zibal result code.
	 * @param array  $response    Decoded Zibal response.
	 * @param int    $status_code Zibal payment status.
	 * @return string
	 */
	private function build_provider_failure_note( $stage, $result_code, $response, $status_code = 0 ) {
		$note = sprintf(
			__( "پرداخت زیبال ناموفق بود.\nمرحله: %1\$s\nکد نتیجه زیبال: %2\$d\nپیام دقیق زیبال: %3\$s", 'lifterlms-zibal' ),
			sanitize_text_field( $stage ),
			(int) $result_code,
			$this->get_provider_message( $response )
		);

		if ( $status_code ) {
			$note .= sprintf(
				__( "\nوضعیت زیبال: %1\$d - %2\$s", 'lifterlms-zibal' ),
				(int) $status_code,
				$this->get_provider_status_message( $status_code )
			);
		}

		return $note;
	}

	/**
	 * Translate a documented Zibal payment status for order notes.
	 *
	 * @param int $status_code Provider status.
	 * @return string
	 */
	private function get_provider_status_message( $status_code ) {
		$messages = array(
			-2 => __( 'خطای داخلی زیبال', 'lifterlms-zibal' ),
			-1 => __( 'در انتظار پرداخت', 'lifterlms-zibal' ),
			1  => __( 'پرداخت‌شده و تأییدشده', 'lifterlms-zibal' ),
			2  => __( 'پرداخت‌شده و تأییدنشده', 'lifterlms-zibal' ),
			3  => __( 'لغوشده توسط کاربر', 'lifterlms-zibal' ),
			4  => __( 'شماره کارت نامعتبر است', 'lifterlms-zibal' ),
			5  => __( 'موجودی حساب کافی نیست', 'lifterlms-zibal' ),
			6  => __( 'رمز واردشده اشتباه است', 'lifterlms-zibal' ),
			7  => __( 'تعداد درخواست‌ها بیش از حد مجاز است', 'lifterlms-zibal' ),
			8  => __( 'تعداد پرداخت اینترنتی روزانه بیش از حد مجاز است', 'lifterlms-zibal' ),
			9  => __( 'مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز است', 'lifterlms-zibal' ),
			10 => __( 'صادرکننده کارت نامعتبر است', 'lifterlms-zibal' ),
			11 => __( 'خطای سوییچ بانکی', 'lifterlms-zibal' ),
			12 => __( 'کارت قابل دسترسی نیست', 'lifterlms-zibal' ),
			15 => __( 'تراکنش استرداد شده است', 'lifterlms-zibal' ),
			16 => __( 'تراکنش در حال استرداد است', 'lifterlms-zibal' ),
			18 => __( 'تراکنش ریورس شده است', 'lifterlms-zibal' ),
			21 => __( 'پذیرنده نامعتبر است', 'lifterlms-zibal' ),
		);

		return isset( $messages[ (int) $status_code ] ) ? $messages[ (int) $status_code ] : __( 'وضعیت ناشناخته', 'lifterlms-zibal' );
	}

	/**
	 * Build the structured admin note for a successful payment.
	 *
	 * The provider success message is deliberately excluded.
	 *
	 * @param string $paid_at        Provider payment date and time.
	 * @param string $transaction_id Zibal reference or tracking number.
	 * @param string $card_number    Safely masked card number.
	 * @return string
	 */
	private function build_success_note( $paid_at, $transaction_id, $card_number ) {
		return sprintf(
			__( "پرداخت زیبال با موفقیت انجام شد.\nوضعیت: موفق\nتاریخ و ساعت تراکنش: %1\$s\nشماره تراکنش: %2\$s\nشماره کارت: %3\$s", 'lifterlms-zibal' ),
			$paid_at ?: '-',
			$transaction_id ?: '-',
			$card_number ?: '-'
		);
	}

	/**
	 * Make the native LifterLMS order status deterministic after verification.
	 *
	 * A successful transaction normally triggers LifterLMS' transaction status
	 * listener. Explicitly setting the status also covers installations where that
	 * listener is unavailable during the gateway callback.
	 *
	 * @param LLMS_Order $order Order object.
	 * @return bool
	 */
	private function ensure_order_paid_status( $order ) {
		$target_status = $order->is_recurring() ? 'active' : 'completed';
		$valid_statuses = array( $target_status, 'llms-' . $target_status );
		$status         = (string) $order->get( 'status' );
		if ( in_array( $status, $valid_statuses, true ) ) {
			return true;
		}

		if ( ! method_exists( $order, 'set_status' ) ) {
			return false;
		}

		$order->set_status( $target_status );
		return in_array( (string) $order->get( 'status' ), $valid_statuses, true );
	}

	/**
	 * Output one escaped row in the administrative payment details box.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private function render_admin_detail_row( $label, $value ) {
		printf(
			'<tr><th scope="row">%1$s</th><td style="direction:ltr;text-align:left;word-break:break-word">%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Sanitize the ISO payment time returned by Zibal.
	 *
	 * @param mixed $paid_at Provider payment date and time.
	 * @return string
	 */
	private function sanitize_paid_at( $paid_at ) {
		if ( ! is_scalar( $paid_at ) ) {
			return '-';
		}

		$paid_at = sanitize_text_field( (string) $paid_at );
		if ( ! $paid_at ) {
			return '-';
		}

		return substr( str_replace( 'T', ' ', $paid_at ), 0, 64 );
	}

	/**
	 * Persist customer-safe fields used on the payment result page.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $status     Result status.
	 * @param int    $result_code Zibal result code.
	 * @param string $message    Exact plain-text provider message on failure.
	 * @param string $paid_at    Provider payment time on success.
	 * @param string $card_number Masked card number on success.
	 */
	private function store_result_meta( $order_id, $status, $result_code = 0, $message = '', $paid_at = '', $card_number = '' ) {
		update_post_meta( $order_id, self::META_RESULT_STATUS, sanitize_key( $status ) );
		update_post_meta( $order_id, self::META_RESULT_CODE, $result_code ? (string) (int) $result_code : '' );
		update_post_meta( $order_id, self::META_RESULT_MESSAGE, sanitize_textarea_field( $message ) );
		update_post_meta( $order_id, self::META_PAID_AT, $this->sanitize_paid_at( $paid_at ) );
		update_post_meta( $order_id, self::META_CARD_NUMBER, $this->sanitize_card_number( $card_number ) );
	}

	/**
	 * Render a successful payment result.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function render_success_result( $order_id ) {
		$transaction_id = (string) get_post_meta( $order_id, self::META_TRANSACTION_ID, true );
		$paid_at        = (string) get_post_meta( $order_id, self::META_PAID_AT, true );
		$card_number    = (string) get_post_meta( $order_id, self::META_CARD_NUMBER, true );

		return sprintf(
			'<section class="llms-zibal-payment-result llms-zibal-payment-result--success" dir="rtl" role="status"><h2>%1$s</h2><dl><div><dt>%2$s</dt><dd>%3$s</dd></div><div><dt>%4$s</dt><dd>%5$s</dd></div><div><dt>%6$s</dt><dd>%7$s</dd></div><div><dt>%8$s</dt><dd>%9$s</dd></div></dl></section>',
			esc_html__( 'پرداخت با موفقیت انجام شد', 'lifterlms-zibal' ),
			esc_html__( 'وضعیت سفارش', 'lifterlms-zibal' ),
			esc_html__( 'موفق', 'lifterlms-zibal' ),
			esc_html__( 'شماره تراکنش', 'lifterlms-zibal' ),
			esc_html( $transaction_id ?: '-' ),
			esc_html__( 'زمان پرداخت', 'lifterlms-zibal' ),
			esc_html( $paid_at ?: '-' ),
			esc_html__( 'شماره کارت', 'lifterlms-zibal' ),
			esc_html( $card_number ?: '-' )
		);
	}

	/**
	 * Render a failed or manual-review payment result.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Result status.
	 * @return string
	 */
	private function render_failure_result( $order_id, $status ) {
		$is_manual   = 'manual-review' === $status;
		$title       = $is_manual ? __( 'پرداخت نیازمند بررسی است', 'lifterlms-zibal' ) : __( 'پرداخت ناموفق بود', 'lifterlms-zibal' );
		$label       = $is_manual ? __( 'نیازمند بررسی', 'lifterlms-zibal' ) : __( 'ناموفق', 'lifterlms-zibal' );
		$message     = $is_manual
			? __( 'وضعیت پرداخت نیازمند بررسی است. لطفاً با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' )
			: __( 'پرداخت تأیید نشد. برای بررسی جزئیات با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' );

		return sprintf(
			'<section class="llms-zibal-payment-result llms-zibal-payment-result--failed" dir="rtl" role="alert"><h2>%1$s</h2><dl><div><dt>%2$s</dt><dd>%3$s</dd></div><div><dt>%4$s</dt><dd>%5$s</dd></div></dl></section>',
			esc_html( $title ),
			esc_html__( 'وضعیت سفارش', 'lifterlms-zibal' ),
			esc_html( $label ),
			esc_html__( 'راهنما', 'lifterlms-zibal' ),
			esc_html( $message )
		);
	}

	/**
	 * Build the configured checkout result URL for an order.
	 *
	 * @param LLMS_Order $order  Order.
	 * @param string     $status Result status.
	 * @return string
	 */
	private function get_result_redirect_url( $order, $status ) {
		return add_query_arg(
			'llms_zibal_status',
			sanitize_key( $status ),
			$this->get_complete_transaction_redirect_url( $order )
		);
	}

	/**
	 * Redirect to a validated Zibal payment session.
	 *
	 * @param mixed $track_id Track ID.
	 * @return void
	 */
	private function redirect_to_zibal( $track_id ) {
		$track_id = $this->validate_track_id( $track_id );
		if ( ! $track_id ) {
			return;
		}

		$redirect_url = self::REDIRECT_URL . rawurlencode( $track_id );
		if ( function_exists( 'llms_redirect_and_exit' ) ) {
			llms_redirect_and_exit( $redirect_url, array( 'safe' => false ) );
		}

		wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed Zibal host and validated numeric track ID.
		exit;
	}

	/**
	 * Redirect a student back to the order screen.
	 *
	 * @param LLMS_Order $order Order.
	 * @return void
	 */
	private function redirect_to_order_view( $order ) {
		$url = $order->get_view_link();
		if ( function_exists( 'llms_redirect_and_exit' ) ) {
			llms_redirect_and_exit( $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Get the order post ID.
	 *
	 * @param LLMS_Order $order Order.
	 * @return int
	 */
	private function get_order_id( $order ) {
		return absint( $order->get( 'id' ) );
	}

	/**
	 * Hash a callback binding without storing another copy of the order key.
	 *
	 * @param string $order_key Order key.
	 * @return string
	 */
	private function binding_hash( $order_key ) {
		return hash_hmac( 'sha256', $order_key, wp_salt( 'auth' ) );
	}

	/**
	 * Fingerprint the merchant configuration used to create the payment.
	 *
	 * @param string $merchant Merchant ID.
	 * @return string
	 */
	private function merchant_fingerprint( $merchant ) {
		return hash_hmac( 'sha256', $merchant, wp_salt( 'secure_auth' ) );
	}

	/**
	 * Acquire an atomic, short-lived verification lock.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $attempt_id Attempt identifier.
	 * @return string|false
	 */
	private function acquire_verification_lock( $order_id, $attempt_id = 'legacy' ) {
		$attempt_id = sanitize_key( (string) $attempt_id );
		$lock_name  = 'llms_zibal_verify_lock_' . absint( $order_id ) . '_' . ( $attempt_id ?: 'legacy' );
		$now       = time();
		if ( add_option( $lock_name, $now, '', false ) ) {
			return $lock_name;
		}

		$existing = (int) get_option( $lock_name, 0 );
		if ( $existing && ( $existing + self::LOCK_TTL ) < $now ) {
			delete_option( $lock_name );
			if ( add_option( $lock_name, $now, '', false ) ) {
				return $lock_name;
			}
		}

		return false;
	}

	/**
	 * Acquire the per-order request creation lock.
	 *
	 * @param int $order_id Order ID.
	 * @return string|false
	 */
	private function acquire_request_lock( $order_id ) {
		return $this->acquire_verification_lock( $order_id, 'request' );
	}

	/**
	 * Release a verification lock.
	 *
	 * @param string $lock_name Lock option name.
	 */
	private function release_verification_lock( $lock_name ) {
		if ( $lock_name ) {
			delete_option( $lock_name );
		}
	}

	/**
	 * Send an order to manual review and stop the checkout flow.
	 *
	 * @param LLMS_Order $order  Order.
	 * @param string     $detail  Admin-only detail.
	 * @param array      $attempt Payment attempt.
	 */
	private function move_to_manual_review( $order, $detail, $attempt = array() ) {
		$order_id = $this->get_order_id( $order );
		if ( $attempt ) {
			$attempt['state'] = 'manual-review';
			$this->save_attempt( $order_id, $attempt );
		}
		update_post_meta( $order_id, self::META_PAYMENT_STATE, 'manual-review' );
		if ( get_post_meta( $order_id, self::META_RESULT_MESSAGE, true ) ) {
			update_post_meta( $order_id, self::META_RESULT_STATUS, 'manual-review' );
		} else {
			$this->store_result_meta(
				$order_id,
				'manual-review',
				0,
				__( 'وضعیت پرداخت نیازمند بررسی است. لطفاً با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' )
			);
		}
		$order->add_note( sanitize_textarea_field( $detail ) );
		if ( method_exists( $order, 'set_status' ) ) {
			$order->set_status( 'on-hold' );
		}
		$this->log( 'Zibal order moved to manual review.', $order_id );
		llms_add_notice( esc_html__( 'وضعیت پرداخت نیازمند بررسی است. لطفاً با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' ), 'error' );
		wp_safe_redirect( $this->get_result_redirect_url( $order, 'manual-review' ) );
		exit;
	}

	/**
	 * Fail safely with a generic customer message and an admin-only detail.
	 *
	 * @param LLMS_Order $order  Order.
	 * @param string     $detail Admin-only detail.
	 */
	private function fail_payment( $order, $detail ) {
		$order_id = $this->get_order_id( $order );
		if ( ! get_post_meta( $order_id, self::META_RESULT_MESSAGE, true ) ) {
			$this->store_result_meta(
				$order_id,
				'failed',
				0,
				__( 'تأیید پرداخت زیبال ناموفق بود. در صورت کسر وجه با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' )
			);
		}
		$order->add_note( sanitize_textarea_field( $detail ) );
		$this->log( 'Zibal payment failed.', $order_id, sanitize_text_field( $detail ) );
		llms_add_notice( esc_html__( 'تأیید پرداخت زیبال ناموفق بود. در صورت کسر وجه با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' ), 'error' );
		wp_safe_redirect( $this->get_result_redirect_url( $order, 'failed' ) );
		exit;
	}
}
