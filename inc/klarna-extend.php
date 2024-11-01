<?php

RTND or die();

/**
 * New and old Klarna gateways compatibility fix class
 */

class RTND_Klarna_Extend{

    public static function init(){

        //enable Klarna as payment method
        add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'enable_klarna' ], 999, 1 );

        //remove ongoing_klarna_order
        add_action( 'woocommerce_cart_emptied', [ __CLASS__, 'clear_klarna' ] );

        //enable Klarna gateway in pay for reminder action
        add_filter( 'template_include', [ __CLASS__, 'pay_for_reminder_with_klarna' ], 10, 1 );

        //building-in Klarna checkout on pay-for-order page
        add_action( 'woocommerce_pay_order_before_submit', [ __CLASS__, 'built_in_klarna'], 15, 1 );

        //update meta from cart to order
        add_action( 'woocommerce_after_calculate_totals', [ __CLASS__, 'update_carttoorder_meta' ], 10, 1 );

        //for proper discounted order synchronization this option must be enabled
        // "Send discounts as separate items" in Klarna Checkout options
        add_filter( 'klarna_checkout_form_fields',  [ __CLASS__, 'klarna_form_fields' ], 999 );
        add_filter( 'option_woocommerce_klarna_checkout_settings', [ __CLASS__, 'klarna_custom_coupons' ], 1, 999 );

        //Set free shipping
        add_action( 'woocommerce_init', [ __CLASS__, 'fake_wc_on_reminder_pay' ], 999 );

        //Process new order for Miss Klarna when paying for remainder
        add_filter( 'woocommerce_create_order', [ __CLASS__, 'create_kco_order' ] );
    }

    /**
     * Process order for little miss Klarna
     *
     * @return int
     */
    public static function create_kco_order(){
        if(
                !isset(WC()->session->ongoing_klarna_order)
            ||  'kco' !== self::get_kco_id()

        ) return 0;

        $order_id = WC()->session->ongoing_klarna_order;

        $order = wc_get_order( $order_id );
        if(!$order) return 0;

        //get Klarna order from Server
        $klarna_order_id = WC()->session->get( 'kco_wc_order_id' );
        if(!$klarna_order_id) return 0;

        $response     = KCO_WC()->api->request_post_get_order( $klarna_order_id );
        $klarna_order = json_decode( $response['body'] );
        if(!$klarna_order) return 0;

        $order->set_created_via( 'Klarna Checkout' );
        $order->set_payment_method( 'kco' );

        $order->update_meta_data( '_wc_klarna_order_id', sanitize_key( $klarna_order->order_id ) );
        $order->update_meta_data( '_transaction_id', sanitize_key( $klarna_order->order_id ) );

        if ( 'ACCEPTED' === $klarna_order->fraud_status ) {
            $order->payment_complete( $klarna_order->order_id );
            $order->update_status( 'processing' );
            $order->add_order_note( 'Payment via Klarna Checkout, order ID: ' . sanitize_key( $klarna_order->order_id ) );
        } elseif ( 'REJECTED' === $klarna_order->fraud_status ) {
            $order->update_status( 'on-hold', 'Klarna Checkout order was rejected.' );
        }

        $order->set_payment_method_title( 'Klarna' );

        $order->save();

        KCO_WC()->api->request_post_acknowledge_order( $klarna_order->order_id );
        KCO_WC()->api->request_post_set_merchant_reference(
            $klarna_order->order_id,
            array(
                'merchant_reference1' => $order->get_order_number(),
                'merchant_reference2' => $order->get_id(),
            )
        );

        WC()->session->set( 'kco_wc_order_id', $klarna_order->order_id );

        return $order->get_id();
    }

    /**
     * Set Free Shipping available for Woocommerce
     */
    public static function fake_wc_on_reminder_pay(){
        if(!isset(WC()->session->rtnd_build_in_Klarna)) return;
        global $wpdb;
        //get all available free_shipping instances (this method is the fastest)
        $all_instances = $wpdb->get_results("SELECT `instance_id` 
                                               FROM `{$wpdb->prefix}woocommerce_shipping_zone_methods` 
                                              WHERE `method_id` = 'free_shipping'");
        //add filter for every free_shipping option
        if(!empty($all_instances))
            foreach($all_instances as $instance)
                add_filter(
                    'option_woocommerce_free_shipping_' . $instance->instance_id . '_settings',
                    [ __CLASS__, 'override_free_shipping_options' ]
                );
    }


    /**
     * Enable klarna as payment method
     *
     * @param $available_gateways
     *
     * @return mixed
     */
    public static function enable_klarna($available_gateways){
        if(!isset(WC()->session->ongoing_klarna_order)) return $available_gateways;
        $gateways = WC()->payment_gateways()->payment_gateways;
        if(empty($gateways)) return $available_gateways;
        $kco_id = self::get_kco_id();
        if(!$kco_id) return $available_gateways;
        foreach ( $gateways as $gateway )
            if( $kco_id === $gateway->id && 'yes' === $gateway->enabled ) {
                $gateway->icon = "https://cdn.klarna.com/1.0/shared/image/generic/logo/sv_se/basic/blue-black.png?width=100";
                $gateway->description = __( 'Pay easily using Klarna as a payment method by Wetail', RTND );
                $available_gateways[ $gateway->id ] = $gateway;
            }
        return $available_gateways;
    }

    /**
     * Clear ongoing Klarna order session information
     *
     */
    public static function clear_klarna(){
        WC()->session->set( 'ongoing_klarna_order', null );
        WC()->session->set( 'rtnd_original_prices', null );
        WC()->session->set( 'rtnd_chosen_shipping_methods', null );
        WC()->session->set( 'rtnd_build_in_Klarna', null );
    }



    /**
     *  Show proper Klarna checkout options
     *
     * @param $fields
     * @return mixed
     */
    public static function klarna_form_fields( $fields ){
        $fields['send_discounts_separately']['default'] = 'yes';
        $fields['send_discounts_separately']['value'] = 'yes';
        $fields['send_discounts_separately']['disabled'] = true;
        $fields['send_discounts_separately']['description'] .= '<br/>' .
            __('This option is forced to be enabled when Returnado service is active', RTND);
        return $fields;
    }

    /**
     * Force coupons to be sent as WC cart items (to have equal order totals)
     *
     * @param $options
     * @return mixed
     */
    public static function klarna_custom_coupons($options){
        $options['send_discounts_separately'] = 'yes';
        return $options;
    }

    /**
     * Retrieve Klarna gateway ID
     *
     * @return string
     */
    public static function get_kco_id(){
        if(class_exists('Klarna_Checkout_For_WooCommerce')) return 'kco';
        if(class_exists('WC_Gateway_Klarna')) return 'klarna_checkout';
        return '';
    }


    /**
     * Enable Klarna as payment method built in the checkout
     *
     */
    public static function built_in_klarna(){

        if(isset(WC()->session->rtnd_build_in_Klarna)){
            $kco_id = self::get_kco_id();
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($){
                    setTimeout(function(){
                        $('input[name=payment_method]').off().on('change',function(e){
                            var on = $('#payment_method_<?php echo $kco_id ?>').prop('checked');
                            if(on)
                                $('#rtnd_klarna_checkout').slideDown();
                            else
                                $('#rtnd_klarna_checkout').slideUp();
                            $('#terms').attr('checked',true).parents('p').first().hide();
                            $('#place_order').css('display',on?'none':'block');
                            return false;
                        });
                        $('#payment_method_<?php echo $kco_id ?>').attr('checked',true).trigger('change');
                    },100);
                });
            </script>
            <div class="clear"></div>
            <div id="rtnd_klarna_checkout" style="display:none">
                <style>
                    .klarna_checkout  .woocommerce, .klarna_checkout  .woocommerce-error{
                        display:none !important;
                    }
                </style>
                <?php
                    if('kco' === $kco_id){
                        kco_wc_show_snippet();
                    }else
                        echo do_shortcode('[woocommerce_klarna_checkout]');
                ?>
            </div>
            <?php
        }
    }

    /**
     * Restore cart content from order
     *
     * @param $order_id
     *
     * @return bool
     */
    public static function restore_cart_from_order( $order_id ){
        global $RTND_Processor;
        $RTND_Processor->wpml(0);
        $order = wc_get_order((int)$order_id);
        if (!$order) {
            wc_add_notice(__('Order not found',RTND),'error');
            $RTND_Processor->wpml(1);
            return false;
        }

        WC()->cart->empty_cart(true);
        WC()->cart->remove_coupons();

        global $sitepress;
        if (isset($sitepress)) $sitepress->switch_lang('all',true);

        $original_prices = [];

        foreach( $order->get_items() as $item_id => $item ){
            $pid = $item['variation_id']?$item['variation_id']:$item['product_id'];
            $i = WC()->cart->add_to_cart( $pid , (int)$item['qty'] );
            if(isset($item['ReturnOrder'])){
                $original_prices[$pid]['return'] = $item['line_total'];
                WC()->cart->cart_contents[$i]['ReturnOrder']        = $item['ReturnOrder'];
            }
            if(isset($item['ReturnItems']))             WC()->cart->cart_contents[$i]['ReturnItems']        = $item['ReturnItems'];
            if(isset($item['ReturnTax']))               WC()->cart->cart_contents[$i]['ReturnTax']          = $item['ReturnTax'];
            if(isset($item['_rtnd_original_tax']))      WC()->cart->cart_contents[$i]['_original_tax']      = $item['_rtnd_original_tax'];
            if(isset($item['_rtnd_original_price']))    {
                WC()->cart->cart_contents[$i]['_original_price']    = $item['_rtnd_original_price'];
                $original_prices[$pid]['price'] = $item['_rtnd_original_price'];
                if( 'yes' === get_option('woocommerce_prices_include_tax') )
                    $original_prices[$pid]['price'] += (isset($item['_rtnd_original_tax'])?$item['_rtnd_original_tax']:0);
            }
        }

        if(!empty($original_prices))
            WC()->session->set( 'rtnd_original_prices', $original_prices );

        foreach($order->get_used_coupons() as $coupon_code) WC()->cart->add_discount($coupon_code);

        WC()->cart->shipping_total = $order->get_shipping_total();
        WC()->cart->shipping_tax_total = $order ->get_shipping_tax();

        $oad = $order->get_address('shipping');

        WC()->customer->set_billing_country($oad['country']); //reset default country
        WC()->customer->set_shipping_country($oad['country']);

        WC()->customer->set_billing_postcode($oad['postcode']);
        WC()->customer->set_billing_city($oad['city']);
        WC()->customer->set_billing_address($oad['address_1']);
        WC()->customer->set_shipping_location($oad['country'],$oad['state'],$oad['postcode'],$oad['city']);
        WC()->customer->set_shipping_address($oad['address_1']?$oad['address_1']:$oad['address_2']);
        $shipping_methods = array();
        foreach($order->get_shipping_methods() as $shipping_method_id => $shipping_method)
            $shipping_methods[] = $shipping_method['method_id'];
        WC()->session->set('rtnd_chosen_shipping_methods', $shipping_methods);
        WC()->session->set('rtnd_chosen_shipping_total', [
            'total' => $order->get_shipping_total(),
            'tax'   => $order->get_shipping_tax()
            ]
        );
        WC()->cart->calculate_totals(true);
        WC()->cart->persistent_cart_update();
        $RTND_Processor->wpml(1);
        return true;
    }

    /**
     * Override free shipping options for all free shipping instances if we are on exchanging process
     */
    public static function override_free_shipping_options( $options ){
        $options['requires']    = '';
        $options['min_amount'] = 0;
        return $options;
    }

    /**
     * Check if we are paying for reminder with Klarna and restore cart content
     */
    public static function pay_for_reminder_with_klarna( $tpl ){
        if( !isset($_REQUEST['pay_for_order'])  && !defined('DOING_AJAX') ){
            if( isset( WC()->session->rtnd_build_in_Klarna ) && !isset( $_REQUEST['kco_wc_order_id'] ) )
               WC()->cart->empty_cart( true );
            return $tpl;
        }
        global $wp;
        $kco_id = self::get_kco_id();
        $order_id = $wp->query_vars['order-pay'];
        $order_payment_method_id = get_post_meta( $order_id, '_payment_method', true );
        if(!$order_payment_method_id)
            $order_payment_method_id = $kco_id;
        WC()->session->set( 'chosen_payment_method', $order_payment_method_id );
        self::restore_cart_from_order( $order_id );
        WC()->session->set( 'rtnd_build_in_Klarna', 'on_duty!');
        WC()->session->set( 'ongoing_klarna_order', $order_id );
        return $tpl;
    }

    public static function retrieve_shipping_method_id_from_db( $label ){
        global $wpdb;
        $method_id = strtolower(str_replace(' ','_',$label));
        $all_instances = $wpdb->get_results("SELECT `instance_id` 
                                               FROM `{$wpdb->prefix}woocommerce_shipping_zone_methods` 
                                              WHERE `method_id` = '{$method_id}'");
        if(!empty($all_instances))
            return $method_id . ':' . $all_instances[0]->instance_id;
        return $method_id . ":1";
    }

    public static function get_shipping_method_id_by_label( $label ){
        $all_packs = WC()->shipping->get_packages();
        if(empty($all_packs))//impossible to get current packages (improper hook)
            //get method ID directly from DB
            return self::retrieve_shipping_method_id_from_db( $label );
        foreach( $all_packs as $package )
            foreach($package['rates'] as $method_id=>$rate)
                if( false !== strpos( $method_id, $label )
                    ||  $rate->get_label() === $label )
                return $method_id;
        return self::retrieve_shipping_method_id_from_db( $label );
    }


    /**
     * Update cart meta for return order
     *
     * @param $cart
     *
     * @return mixed
     */
    public static function update_carttoorder_meta( $cart ){
        $order_id = (isset(WC()->session->ongoing_klarna_order)?WC()->session->ongoing_klarna_order:0);
        if(!$order_id) return $cart;
        global $RTND_Processor;
        $RTND_Processor->wpml(0);
        $order = wc_get_order( $order_id );
        if (!$order) {$RTND_Processor->wpml(1);return $cart;}
        foreach($order->get_items() as $item_id=>$item){
            if(get_post_status($item['product_id']) == 'rtnd') {
                wc_update_order_item_meta( $item_id, 'ReturnOrder', get_post_meta($item['product_id'],'ReturnOrder',true));
                wc_update_order_item_meta( $item_id, 'ReturnItems', get_post_meta($item['product_id'],'ReturnItems',true));
                if($rt = get_post_meta($item['product_id'],'ReturnTax',true))
                    wc_update_order_item_meta( $item_id, 'ReturnTax', $rt);
                $RTND_Processor->wpml(1);
                return $cart;
            }
        }
    }
}

RTND_Klarna_Extend::init();