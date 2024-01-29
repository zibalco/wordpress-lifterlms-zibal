<?php
/**
 * zibal Payment Gateway for LifterLMS
 * @since    1.0.0
 * @version  1.0.0
 * @author   zibal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LLMS_Payment_Gateway_zibal extends LLMS_Payment_Gateway {




    const REDIRECT_URL = 'https://gateway.zibal.ir/start/';

    const MIN_AMOUNT = 100;

    public $MerchantID = '';



    /**
     * Constructor
     * @since    1.0.0
     * @version  1.0.0
     */
    public function __construct() {

        $this->id = 'zibal';
        $this->icon = '<img src="'.plugins_url('/images/zibal.png', __FILE__).'" style="width: auto; max-height: 40px;">';
        $this->admin_description = __( 'Allow customers to purchase courses and memberships using zibal.', 'lifterlms-zibal' );
        $this->admin_title = 'درگاه پرداخت زیبال';
        $this->title = 'zibal';
        $this->description = __( 'Pay via zibal', 'lifterlms-zibal' );

        $this->supports = array(
            'single_payments' => true,
        );


        // add zibal specific fields
        add_filter( 'llms_get_gateway_settings_fields', array( $this, 'settings_fields' ), 10, 2 );

        // output zibal account details on confirm screen
        add_action( 'lifterlms_checkout_confirm_after_payment_method', array( $this, 'after_payment_method_details' ) );
    }


    public function after_payment_method_details() {

        $key = isset( $_GET['order'] ) ? $_GET['order'] : '';

        $order = llms_get_order_by_key( $key );
        if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) ) {
            return;
        }

        echo '<input name="llms_zibal_token" type="hidden" value="' . $_GET['trackId'] . '">';

    }

    /**
     * Output some information we need on the confirmation screen
     * @return   void
     * @since    1.0.0
     * @version  1.0.0
     */
    public function confirm_pending_order( $order ) {



        if ( ! $order || 'zibal' !== $order->get( 'payment_gateway' ) ) {
            return;
        }

        $this->log( 'zibal `after_payment_method_callback()` started', $order, $_POST );

        $data = array("merchant" => self::get_MerchantID(), "trackId" => $_GET['trackId']);
        $jsonData = json_encode($data);
        $ch = curl_init('https://gateway.zibal.ir/v1/verify');
        curl_setopt($ch, CURLOPT_USERAGENT, 'zibal Rest Api v1');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $result = json_decode($result, true);

        if ($result['result'] == 100) {
            $txn_data = array();
            $txn_data['amount'] = $order->get_price( 'total', array(), 'float' );
            $txn_data['transaction_id'] = $result ['trackId'];
            $txn_data['orderId'] = $result ['orderId'];
            $txn_data['status'] = 'llms-txn-succeeded';
            $txn_data['payment_type'] = 'single';
            $txn_data['source_description'] = $result['cardNumber'];//isset( $_POST['card_holder'] ) ? $_POST['card_holder'] : '';

            $order->record_transaction( $txn_data );

            $this->log( $order, 'zibal `confirm_pending_order()` finished' );


            $order->add_note('شماره تراکنش : ' . $result ['trackId'] );

            $this->complete_transaction( $order );

        }else{
            $this->log( $order, 'zibal `confirm_pending_order()` finished with error : ' . $result ['data']['code'] );
            $order->add_note('Faild Transaction : ' . $result['trackId'] );

            wp_safe_redirect( llms_cancel_payment_url() );
            exit();

        }

    }

    /**
     * Get $MerchantID option
     * @return   string
     * @since    1.0.0
     * @version  1.0.0
     */
    public function get_MerchantID() {
        return $this->get_option( 'MerchantID' );
    }


    /**
     * Handle a Pending Order
     * Called by LLMS_Controller_Orders->create_pending_order() on checkout form submission
     * All data will be validated before it's passed to this function
     *
     * @param   obj       $order   Instance LLMS_Order for the order being processed
     * @param   obj       $plan    Instance LLMS_Access_Plan for the order being processed
     * @param   obj       $person  Instance of LLMS_Student for the purchasing customer
     * @param   obj|false $coupon  Instance of LLMS_Coupon applied to the order being processed, or false when none is being used
     * @return  void
     * @since   1.0.0
     * @version 1.0.0
     */
    public function handle_pending_order( $order, $plan, $person, $coupon = false ) {

        $this->log( 'zibal `handle_pending_order()` started', $order, $plan, $person, $coupon );

        // do some gateway specific validation before proceeding
        $total = $order->get_price( 'total', array(), 'float' );
        if ( $total < self::MIN_AMOUNT ) {
            return llms_add_notice( sprintf( __( 'با توجه به محدوديت هاي شاپرك امكان پرداخت با رقم درخواست شده ميسر نمي باشد حداقل مبلغ پرداختی  %s تومان است', 'lifterlms-zibal' ), self::MIN_AMOUNT ), 'error' );
        }

        $data = array("merchant" => self::get_MerchantID(),
            'amount' => $order->get_price( 'total', array(), 'float' ),
            'callbackUrl' => llms_confirm_payment_url( $order->get( 'order_key' )),
            'orderId' => $order->get( 'order_key' ),
            'description' => $order->get( 'order_key' ));
        $jsonData = json_encode($data);
        $ch = curl_init('https://gateway.zibal.ir/v1/request');
        curl_setopt($ch, CURLOPT_USERAGENT, 'zibal Rest Api v1');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);
        curl_close($ch);
      

        if ($result && $result['result'] == 100)
        {
            $this->log( $result, 'zibal `handle_pending_order()` finished' );
            do_action( 'lifterlms_handle_pending_order_complete', $order );
            $order->add_note('transaction ID : ' . $result['trackId'] );
            wp_redirect( self::REDIRECT_URL . $result["trackId"] );
            exit();
        }
        else
        {
            $this->log( $result, 'zibal `handle_pending_order()` finished with error code : ' );
            return llms_add_notice( 'خطا در اتصال به درگاه : ' . @$result['message'], 'error' );
        }

    }




    /**
     * Output custom settings fields on the LifterLMS Gateways Screen
     * @param    array     $fields      array of existing fields
     * @param    string    $gateway_id  id of the gateway
     * @return   array
     * @since    1.0.0
     * @version  1.0.0
     */
    public function settings_fields( $fields, $gateway_id ) {

        // don't add fields to other gateways!
        if ( $this->id !== $gateway_id ) {
            return $fields;
        }

        $fields[] = array(
            'type'  => 'custom-html',
            'value' => '
				<h4>' . __( 'zibal Settings', 'lifterlms-zibal' ) . '</h4>
				<p>' . __( 'مرچنت کد زیبال خود را برای اتصال به زیبال وارد کنید ', 'lifterlms-zibal' ) . '</p>
			',
        );

        $settings = array(
            "MerchantID" => __( 'مرچنت کد', 'lifterlms-zibal' ),
        );
        foreach( $settings as $k => $v ) {
            $fields[] = array(
                'id'            => $this->get_option_name( $k ),
                'default'       => $this->{'get_' . $k}(),
                'title'         => $v,
                'type'          => 'text',
            );
        }


        return $fields;

    }

}
