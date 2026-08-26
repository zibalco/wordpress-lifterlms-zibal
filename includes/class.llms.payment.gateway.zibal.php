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
	const VERSION      = '2.3.2';
	const HTTP_TIMEOUT = 20;
	const LOCK_TTL     = 60;

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
		$this->supports          = array(
			'checkout_fields'           => false,
			'cc_save'                   => false,
			'refunds'                   => false,
			'single_payments'           => true,
			'recurring_payments'        => false,
			'recurring_retry'           => false,
			'test_mode'                 => false,
			'modify_recurring_payments' => false,
		);

		add_filter( 'llms_get_gateway_settings_fields', array( $this, 'settings_fields' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_process_zibal_return' ), 1 );
		add_action( 'lifterlms_checkout_confirm_after_payment_method', array( $this, 'after_payment_method_details' ) );
		add_action( 'add_meta_boxes_llms_order', array( $this, 'add_order_payment_meta_box' ) );
		add_shortcode( 'llms_zibal_payment_result', array( $this, 'render_payment_result_shortcode' ) );
		add_filter( 'the_content', array( $this, 'append_payment_result' ), 9 );
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
	 * Zibal checkout in this integration supports paid one-time plans only.
	 * Declaring this explicitly keeps the gateway visible across LifterLMS
	 * versions while preventing unsupported recurring orders.
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

		return ! $payment->is_recurring();
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

		$order_id = $this->get_order_id( $order );
		$track_id = $this->get_callback_track_id();
		if ( ! $order_id || ! $track_id ) {
			$this->fail_payment( $order, __( 'Missing or invalid Zibal callback data.', 'lifterlms-zibal' ) );
		}

		$stored_track_id = (string) get_post_meta( $order_id, self::META_TRACK_ID, true );
		$expected_amount = (string) get_post_meta( $order_id, self::META_REQUESTED_AMOUNT, true );
		$payment_state   = (string) get_post_meta( $order_id, self::META_PAYMENT_STATE, true );
		$stored_txn_id   = (string) get_post_meta( $order_id, self::META_TRANSACTION_ID, true );

		if ( ! $stored_track_id || ! hash_equals( $stored_track_id, $track_id ) ) {
			$this->fail_payment( $order, __( 'Zibal track ID does not match the pending order.', 'lifterlms-zibal' ) );
		}

		$order_key       = (string) $order->get( 'order_key' );
		$callback_order  = $this->request_value( 'order' );
		$stored_binding  = (string) get_post_meta( $order_id, self::META_ORDER_BINDING, true );
		$current_binding = $this->binding_hash( $order_key );
		$callback_token  = $this->get_callback_token();
		$stored_token_hash = (string) get_post_meta( $order_id, self::META_CALLBACK_TOKEN_HASH, true );
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
			if ( ! $this->ensure_order_completed( $order ) ) {
				$this->move_to_manual_review( $order, __( 'The verified Zibal order could not be changed to completed status.', 'lifterlms-zibal' ) );
			}
			$this->complete_transaction( $order );
		}
		if ( 'requested' !== $payment_state ) {
			$this->move_to_manual_review( $order, __( 'Zibal callback received for an order that is not in the requested payment state.', 'lifterlms-zibal' ) );
		}

		$merchant        = $this->get_merchant_id();
		$stored_merchant = (string) get_post_meta( $order_id, self::META_MERCHANT_FINGERPRINT, true );
		if ( ! $merchant || ! $stored_merchant || ! hash_equals( $stored_merchant, $this->merchant_fingerprint( $merchant ) ) ) {
			$this->move_to_manual_review( $order, __( 'Zibal merchant configuration changed after payment was requested.', 'lifterlms-zibal' ) );
		}

		if ( ! preg_match( '/^[0-9]+$/', $expected_amount ) || 0 >= (int) $expected_amount ) {
			$this->move_to_manual_review( $order, __( 'Stored Zibal payment amount is missing or invalid.', 'lifterlms-zibal' ) );
		}

		$current_amount = $this->get_gateway_amount( $order );
		if ( is_wp_error( $current_amount ) || ! hash_equals( $expected_amount, (string) $current_amount ) ) {
			$this->move_to_manual_review( $order, __( 'The current order amount or currency no longer matches the original Zibal payment request.', 'lifterlms-zibal' ) );
		}

		$lock_name = $this->acquire_verification_lock( $order_id );
		if ( ! $lock_name ) {
			$this->fail_payment( $order, __( 'Another Zibal verification is already in progress.', 'lifterlms-zibal' ) );
		}

		$response = $this->api_post(
			self::VERIFY_URL,
			array(
				'merchant' => $merchant,
				'trackId'  => $track_id,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->release_verification_lock( $lock_name );
			$this->log( 'Zibal verify transport error: ' . $response->get_error_code() );
			$this->fail_payment( $order, __( 'Zibal verification service was unavailable.', 'lifterlms-zibal' ) );
		}

		$result_code = isset( $response['result'] ) && is_numeric( $response['result'] ) ? (int) $response['result'] : 0;
		if ( 100 !== $result_code ) {
			$this->release_verification_lock( $lock_name );
			$failure_note = $this->build_provider_failure_note( __( 'تأیید پرداخت', 'lifterlms-zibal' ), $result_code, $response );
			$this->store_result_meta(
				$order_id,
				'failed',
				$result_code,
				$this->get_provider_message( $response )
			);
			if ( 201 === $result_code ) {
				$this->move_to_manual_review( $order, $failure_note );
			}

			$this->fail_payment( $order, $failure_note );
		}

		$response_has_track_id = isset( $response['trackId'] );
		$verified_track_id     = $response_has_track_id && is_scalar( $response['trackId'] ) ? $this->validate_track_id( $response['trackId'] ) : '';
		$verified_order_id     = isset( $response['orderId'] ) && is_scalar( $response['orderId'] ) ? sanitize_text_field( (string) $response['orderId'] ) : '';
		$verified_amount       = isset( $response['amount'] ) && is_numeric( $response['amount'] ) ? (string) (int) round( (float) $response['amount'] ) : '';

		if (
			( $response_has_track_id && ( ! $verified_track_id || ! hash_equals( $stored_track_id, $verified_track_id ) ) ) ||
			! $verified_order_id ||
			! hash_equals( $order_key, $verified_order_id ) ||
			! $verified_amount ||
			! hash_equals( $expected_amount, $verified_amount )
		) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'Zibal verification data did not match the local order.', 'lifterlms-zibal' ) );
		}

		$reference_id  = isset( $response['refNumber'] ) && is_scalar( $response['refNumber'] ) ? $this->validate_track_id( $response['refNumber'] ) : '';
		$transaction_id = $reference_id ?: $stored_track_id;
		$paid_at        = $this->sanitize_paid_at( isset( $response['paidAt'] ) ? $response['paidAt'] : '' );
		$card_number    = $this->sanitize_card_number( isset( $response['cardNumber'] ) ? $response['cardNumber'] : '' );

		$transaction_data = array(
			'amount'             => $order->get_price( 'total', array(), 'float' ),
			'transaction_id'     => $transaction_id,
			'status'             => 'llms-txn-succeeded',
			'payment_type'       => 'single',
			'source_description' => $card_number,
		);
		if ( '-' !== $paid_at ) {
			$transaction_data['completed_date'] = $paid_at;
		}
		$recording_meta = array(
			self::META_TRANSACTION_ID => $transaction_id,
			self::META_PAYMENT_STATE  => 'recording',
		);
		foreach ( $recording_meta as $meta_key => $meta_value ) {
			update_post_meta( $order_id, $meta_key, $meta_value );
			if ( ! hash_equals( $meta_value, (string) get_post_meta( $order_id, $meta_key, true ) ) ) {
				$this->release_verification_lock( $lock_name );
				$this->move_to_manual_review( $order, __( 'Zibal was verified, but the transaction recording state could not be persisted.', 'lifterlms-zibal' ) );
			}
		}

		$transaction = $order->record_transaction( $transaction_data );
		if ( ! $transaction || is_wp_error( $transaction ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'Zibal verified the payment, but LifterLMS could not record the transaction.', 'lifterlms-zibal' ) );
		}
		if ( ! $this->ensure_order_completed( $order ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'Zibal verified the payment, but the LifterLMS order could not be changed to completed status.', 'lifterlms-zibal' ) );
		}

		update_post_meta( $order_id, self::META_PAYMENT_STATE, 'paid' );
		if ( ! hash_equals( 'paid', (string) get_post_meta( $order_id, self::META_PAYMENT_STATE, true ) ) ) {
			$this->release_verification_lock( $lock_name );
			$this->move_to_manual_review( $order, __( 'The Zibal transaction was recorded, but the final paid state could not be persisted.', 'lifterlms-zibal' ) );
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
		$merchant = $this->get_merchant_id();
		if ( ! $merchant ) {
			return llms_add_notice( esc_html__( 'درگاه زیبال پیکربندی نشده است.', 'lifterlms-zibal' ), 'error' );
		}

		$amount = $this->get_gateway_amount( $order );
		if ( is_wp_error( $amount ) ) {
			$this->log( 'Invalid Zibal amount: ' . $amount->get_error_code() );
			return llms_add_notice( esc_html__( 'مبلغ سفارش برای پرداخت از طریق زیبال معتبر نیست.', 'lifterlms-zibal' ), 'error' );
		}

		$order_id  = $this->get_order_id( $order );
		$order_key = sanitize_text_field( (string) $order->get( 'order_key' ) );
		if ( ! $order_id || ! $order_key ) {
			return llms_add_notice( esc_html__( 'اطلاعات سفارش معتبر نیست.', 'lifterlms-zibal' ), 'error' );
		}

		$callback_token = wp_generate_password( 32, false, false );
		$callback_url   = add_query_arg(
			'llms_zibal_cb',
			$callback_token,
			llms_confirm_payment_url( $order_key )
		);

		$response = $this->api_post(
			self::REQUEST_URL,
			array(
				'merchant'    => $merchant,
				'amount'      => $amount,
				'callbackUrl' => $callback_url,
				'orderId'     => $order_key,
				'description' => sprintf( 'LifterLMS order %d', $order_id ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Zibal request transport error: ' . $response->get_error_code() );
			return llms_add_notice( esc_html__( 'در حال حاضر ارتباط با درگاه زیبال ممکن نیست. لطفاً دوباره تلاش کنید.', 'lifterlms-zibal' ), 'error' );
		}

		$result_code = isset( $response['result'] ) && is_numeric( $response['result'] ) ? (int) $response['result'] : 0;
		$track_id    = isset( $response['trackId'] ) && is_scalar( $response['trackId'] ) ? $this->validate_track_id( $response['trackId'] ) : '';
		if ( 100 !== $result_code || ! $track_id ) {
			if ( 100 !== $result_code ) {
				$order->add_note( $this->build_provider_failure_note( __( 'ایجاد درخواست پرداخت', 'lifterlms-zibal' ), $result_code, $response ) );
			} else {
				$order->add_note( __( 'پاسخ موفق زیبال فاقد شناسه پیگیری معتبر بود.', 'lifterlms-zibal' ) );
			}
			$this->log( 'Zibal request rejected.', $result_code );
			return llms_add_notice( esc_html__( 'درخواست پرداخت توسط زیبال پذیرفته نشد. لطفاً دوباره تلاش کنید.', 'lifterlms-zibal' ), 'error' );
		}

		$payment_meta = array(
			self::META_REQUESTED_AMOUNT     => (string) $amount,
			self::META_ORDER_BINDING        => $this->binding_hash( $order_key ),
			self::META_CALLBACK_TOKEN_HASH  => $this->binding_hash( $callback_token ),
			self::META_MERCHANT_FINGERPRINT => $this->merchant_fingerprint( $merchant ),
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
			if ( ! hash_equals( $meta_value, (string) get_post_meta( $order_id, $meta_key, true ) ) ) {
				$this->log( 'Could not persist Zibal payment metadata.', $order_id, $meta_key );
				return llms_add_notice( esc_html__( 'ثبت اطلاعات پرداخت ممکن نشد. لطفاً دوباره تلاش کنید.', 'lifterlms-zibal' ), 'error' );
			}
		}

		$order->add_note( sprintf( 'Zibal transaction requested. Track ID: %s', $track_id ) );
		do_action( 'lifterlms_handle_pending_order_complete', $order );
		$this->log( 'Zibal payment requested.', $order_id, $track_id );

		$redirect_url = self::REDIRECT_URL . rawurlencode( $track_id );
		if ( function_exists( 'llms_redirect_and_exit' ) ) {
			llms_redirect_and_exit( $redirect_url, array( 'safe' => false ) );
		}

		wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Trusted Zibal URL and validated numeric track ID.
		exit;
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
			'default'           => $this->get_merchant_id(),
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
	 * Calculate the integer amount sent to Zibal.
	 *
	 * @param LLMS_Order $order Order.
	 * @return int|WP_Error
	 */
	private function get_gateway_amount( $order ) {
		$local_amount = $order->get_price( 'total', array(), 'float' );
		$local_amount_float = is_numeric( $local_amount ) ? (float) $local_amount : 0.0;
		if ( ! is_finite( $local_amount_float ) || 0 >= $local_amount_float ) {
			return new WP_Error( 'zibal_invalid_amount' );
		}

		$currency = strtoupper( sanitize_text_field( (string) $order->get( 'currency' ) ) );
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
		$supported_currencies = array_filter( $supported_currencies );
		if ( ! $currency || ! in_array( $currency, $supported_currencies, true ) ) {
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

		return (int) round( $filtered_amount );
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
	 * @return string
	 */
	private function build_provider_failure_note( $stage, $result_code, $response ) {
		return sprintf(
			__( "پرداخت زیبال ناموفق بود.\nمرحله: %1\$s\nکد نتیجه زیبال: %2\$d\nپیام زیبال: %3\$s", 'lifterlms-zibal' ),
			sanitize_text_field( $stage ),
			(int) $result_code,
			$this->get_provider_message( $response )
		);
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
	private function ensure_order_completed( $order ) {
		$status = (string) $order->get( 'status' );
		if ( in_array( $status, array( 'completed', 'llms-completed' ), true ) ) {
			return true;
		}

		if ( ! method_exists( $order, 'set_status' ) ) {
			return false;
		}

		$order->set_status( 'completed' );
		return in_array( (string) $order->get( 'status' ), array( 'completed', 'llms-completed' ), true );
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
		$result_code = (string) get_post_meta( $order_id, self::META_RESULT_CODE, true );
		$message     = (string) get_post_meta( $order_id, self::META_RESULT_MESSAGE, true );
		$is_manual   = 'manual-review' === $status;
		$title       = $is_manual ? __( 'پرداخت نیازمند بررسی است', 'lifterlms-zibal' ) : __( 'پرداخت ناموفق بود', 'lifterlms-zibal' );
		$label       = $is_manual ? __( 'نیازمند بررسی', 'lifterlms-zibal' ) : __( 'ناموفق', 'lifterlms-zibal' );
		if ( ! $message ) {
			$message = $is_manual
				? __( 'وضعیت پرداخت نیازمند بررسی است. لطفاً با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' )
				: __( 'تأیید پرداخت زیبال ناموفق بود. در صورت کسر وجه با پشتیبانی تماس بگیرید.', 'lifterlms-zibal' );
		}

		return sprintf(
			'<section class="llms-zibal-payment-result llms-zibal-payment-result--failed" dir="rtl" role="alert"><h2>%1$s</h2><dl><div><dt>%2$s</dt><dd>%3$s</dd></div>%4$s<div><dt>%5$s</dt><dd>%6$s</dd></div></dl></section>',
			esc_html( $title ),
			esc_html__( 'وضعیت سفارش', 'lifterlms-zibal' ),
			esc_html( $label ),
			$result_code ? '<div><dt>' . esc_html__( 'کد پاسخ زیبال', 'lifterlms-zibal' ) . '</dt><dd>' . esc_html( $result_code ) . '</dd></div>' : '',
			esc_html__( 'پاسخ زیبال', 'lifterlms-zibal' ),
			nl2br( esc_html( $message ) )
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
	 * @param int $order_id Order ID.
	 * @return string|false
	 */
	private function acquire_verification_lock( $order_id ) {
		$lock_name = 'llms_zibal_verify_lock_' . absint( $order_id );
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
	 * @param string     $detail Admin-only detail.
	 */
	private function move_to_manual_review( $order, $detail ) {
		$order_id = $this->get_order_id( $order );
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
