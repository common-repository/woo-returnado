<?php

 //RTND_Ext-class

 //DEFINE SECTION
 if(!defined('ABSPATH')) exit;
 
 define( 'RTND_DEF_REMOTE_SYNC_HOST',   'https://woocommerce.returnado.com'     );
 define( 'RTND_DEF_REMOTE_WIDGET_HOST', 'https://woocommerce.returnado.com'     );
 define( 'RTND_DEF_REMOTE_ADMIN_HOST',  'https://get-woocommerce.returnado.com' );

 /*
  * Basic Returnado plugin class
  * 
  * Init all actions and filters
  * 
  */
 class RTND_Ext {

     //back end slugs for admin and testing
	public $back_end_slug	= array("rtnd-plugin","rtnd-api-test","rtnd-api-wc-test");

	//stop trigger
	private $trigger_stop = 0;

	//used coupon stop update
	private $coupon_stop_update = 0;

	//plugin options
	private $options = array();

    //plugin version
	private $version = RTNDVERSION;

	 /**
	  * RTND_Ext constructor.
	  */
	 public function __construct() {

		 //init options
		 $opts = json_decode( get_option( 'rtnd_options' ), true );
		 $this->options = (empty($opts)?RTND_Ext::defaults():$opts);

	    //adding actions and filters to wordpress

		//storing session variables
		add_action( 'init', function(){if(session_status() == PHP_SESSION_NONE && !headers_sent()) session_start();}, 0);
		
		//Returnado shortcode initialization
		$shortcode = $this->options['rtnd_widget_shortcode'];
		if (empty($shortcode)) $shortcode = 'returnado_returns';
		add_shortcode( $shortcode, array( $this, 'iframe_sc' ) );
		 
		//Returnado admin panel
		add_action('admin_menu',array($this,'add_admin_menus'),999);	
		add_filter( 'custom_menu_order', array($this, 'menus_order'));
		
		//Returnado Settings tab
		add_filter( 'woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 99, 1);
		add_action( 'woocommerce_settings_tabs_rtnd_settings', array($this, 'settings_tab') );
		
		//check if API requested
		add_filter( 'template_include', array($this, 'api_test'), 20 );
		
		//AJAX processing
		add_action( 'wp_ajax_rtnd_ajax', array('RTND_Ext','rtnd_ajax_submit' ));
		add_action( 'wp_ajax_nopriv_dnap_ajax', array('RTND_Ext','rtnd_ajax_submit'));
		
		//Installing
		register_activation_hook( RTNDINDEX, array('RTND_Ext','install'));
		register_uninstall_hook( RTNDINDEX,  array('RTND_Ext','uninstall'));
		add_filter( 'plugin_action_links_' . plugin_basename(RTNDINDEX), array($this, 'settings_link' ));
		
		//check virtual product on order status change
		add_action( 'woocommerce_order_status_changed', array($this, 'check_virtual_product'), 10, 3);
		
		if ($this->options['rtnd_sync_enabled']){
			
		    //triggers
			
            //USER
			add_action( 'user_register', array($this, 'trigger_user'), 10, 1 );
			add_action( 'edit_user_profile_update', array($this, 'trigger_user'),10, 1 );
			add_action( 'personal_options_update', array($this, 'trigger_user'),10, 1 );
			
			//CATEGORY, PRODUCT, ORDER
			add_action( 'save_post', array($this, 'trigger_post'));
			
			//stock syncing
			add_action( 'woocommerce_variation_set_stock', array($this, 'stock_change'),99,1);
			add_action( 'woocommerce_product_set_stock', array($this, 'stock_change'),99,1);
			add_action( 'woocommerce_save_product_variation', array($this, 'stock_change'),99,1);
			
			//BE notices
			add_action('admin_notices',array($this,'notice'));
		}
		
		//add ability to pay for reminder
		add_filter('user_has_cap',['RTND_Ext', 'anyone_can_pay'],10,3);
		add_filter('woocommerce_valid_order_statuses_for_payment', array($this, 'make_valid_status'), 10, 2);
		 
		//custom order status
		add_action( 'init', array($this, 'exchange_order_status') );
		add_filter( 'wc_order_statuses', array($this,'add_exchange_order_status' ));

		//STORE CREDIT*********************************************************************************************

		add_action( 'woocommerce_checkout_order_processed',array( 'RTND_Ext','update_store_credit'),99, 1);

		//store credit validation - disabled since 0.4.5
		//add_filter( 'woocommerce_coupon_is_valid',array( 'RTND_Ext','validate_store_credit'),99, 2);

         //reducing coupon on removing refund
		add_action( 'before_delete_post', array( 'RTND_Ext', 'before_refund_deleted' ), 99, 1 );

		//update prices in booking order on checkout
		add_action( 'woocommerce_before_calculate_totals', array($this, 'before_shipping'),10,1);
		
		/*****************************************************************************************END STORE CREDIT*/

        //make our virtual product purchasable
		add_filter( 'woocommerce_is_purchasable', array($this,'make_purchasable'), 99, 2 );

		//SET PROPER ON REMINDER
         // product prices when paying for remainder
         add_filter( 'woocommerce_product_get_price', [ $this, 'get_proper_price' ], 999, 2 );
         add_filter( 'woocommerce_product_variation_get_price', [ $this, 'get_proper_price' ], 999, 2);
         // product taxable status -> not taxable
         add_filter( 'woocommerce_product_is_taxable', [ __CLASS__, 'get_proper_taxable' ], 999, 2 );

		
		//store original item unrounded prices
		//add_action('woocommerce_new_order_item', [$this, 'store_unrounded_price'], 0, 3);
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'save_unrounded_prices' ], 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hide_order_itemmeta' ], 10, 1);

         //WOOCS compatibility for default woocommerce options
         add_filter( 'option_woocommerce_currency', ['RTND_Ext', 'woocs_options_compatibility'], 1, 999);

        //custom email template for notifications - disabled
       // add_filter( 'woocommerce_email_classes', ['RTND_Ext','add_wc_email_template'] );

         //Rounding options
         if(isset($this->options['rtnd_cut_trails']) && 'yes' === $this->options['rtnd_cut_trails'])
             add_filter( 'woocommerce_price_trim_zeros', '__return_true' );

         add_filter( 'wc_get_price_decimals', [ $this, 'set_rtnd_min_precision' ] );
         add_filter( 'option_woocommerce_price_num_decimals', [ $this, 'set_rtnd_min_precision' ], 999 );
         add_filter( 'option_woocs', [ $this, 'set_rtnd_min_precision' ], 999, 1 );
         add_filter( 'woocommerce_get_settings_general', [ $this, 'set_num_decimals' ] );

    }

     /**
      * Set general number of decimals setting
      */
     public function set_num_decimals( $settings ){
         foreach($settings as $id=>$setting)
             if('woocommerce_price_num_decimals' === $setting['id']){
                 $settings[$id]['custom_attributes'] = ['disabled'=>true];
                 $settings[$id]['default'] = $this->options['rtnd_min_precision'];
                 $settings[$id]['value'] = $this->options['rtnd_min_precision'];
                 $settings[$id]['desc_tip'] = false;
                 $settings[$id]['desc'] .= ' ' . sprintf( __('This option is now controlled by %s plugin', RTND),
                    '<a href="admin.php?page=wc-settings&tab=rtnd_settings#precision_settings">Returnado</a>');
                 return $settings;
         }
         return $settings;
     }

     /**
      * Set minimum precision on rounding
      *
      * @param int|array $options
      * @return int|array
      */
     public function set_rtnd_min_precision( $options ){
         $precision = (isset($this->options['rtnd_min_precision'])?$this->options['rtnd_min_precision']:WOOMINPRECISION);
         if(is_array($options)){
             foreach($options as $currency=>$option)
                 $options[$currency]['decimals'] = $precision;
             return $options;
         }
	     return $precision;
     }

     /**
      * WOOCS options compatibility
      *
      * @param string $option
      *
      * @return string
      */
     public static function woocs_options_compatibility( $option ){
         if(!class_exists('WOOCS')) return $option;
         global $WOOCS;
         if(isset($WOOCS)) return $WOOCS->default_currency;
         return $option;
     }

     /**
      * Set proper product price when we are performing payment for remainder
      *
      * @param float $price
      * @param object $product
      *
      * @return float
      */
     public function get_proper_price( $price = 0.0, $product ){

         if(!isset(WC()->session->rtnd_chosen_shipping_methods)) return $price;

         $pid = (int)$product->get_id();

         if( isset(WC()->session->rtnd_original_prices)
                 && isset(WC()->session->rtnd_original_prices[$pid])
                    && isset(WC()->session->rtnd_original_prices[$pid]['return']) )
                        return WC()->session->rtnd_original_prices[$pid]['return'];

         //restore original price (optionally)
         //check option and restore
         if ( 'yes' === $this->options['rtnd_original_prices'] ) {
             if(isset(WC()->session->rtnd_original_prices) && isset(WC()->session->rtnd_original_prices[$pid]))
                 return WC()->session->rtnd_original_prices[$pid]['price'];
         }

         return $price;
     }

     /**
      * Get proper taxable status for the product being paid as a remainder
      *
      * @param $status
      * @param $product
      * @return bool
      */
     public static function get_proper_taxable( $status, $product ){
         if(!isset(WC()->session->rtnd_chosen_shipping_methods)) return $status;
         $pid = (int)$product->get_id();
         if( isset(WC()->session->rtnd_original_prices)
             && isset(WC()->session->rtnd_original_prices[$pid]['return']) )return false;
         return $status;
     }

	 /**
      * Add email notification template to WC
      * 
	  * @param $email_classes
	  *
	  * @return mixed
	  */
	 public static function add_wc_email_template( $email_classes ) {
         require( 'coupon-notificator.php' );
         $email_classes['rtnd_coupon_notificator'] = new RTND_Coupon_Notification();
         return $email_classes;
     }

	 /**
      * Hide order item meta
      * 
	  * @param $arr
	  *
	  * @return array
	  */
	 public static function hide_order_itemmeta($arr) {
		$arr[] = '_original_price';
		$arr[] = '_rtnd_original_price';
		$arr[] = '_rtnd_original_net_price';
		$arr[] = '_original_tax';
		$arr[] = '_original_title';
		$arr[] = '_original_attributes';
		$arr[] = '_rtnd_original_tax';
		return $arr;
	}

     /**
      * Fake precision for the cart to get the desired price values
      *
      * @return int
      */
    public static function set_rtnd_precision( ){
        return RTNDPRECISION;
    }

     /**
      * Save unrounded prices
      *
      ** new since 0.4.7.2
      *
      * !!ADDED SINCE 0.4.7.21 - save the item original product title and selected attributes
      * Since 0.4.7.22 - compatible with WPML
      *
      * @param $order_id
      * @param $posted_data
      * @param $order
      */
    public static function save_unrounded_prices( $order_id, $posted_data, $order ){
        add_filter( 'wc_get_price_decimals', [ 'RTND_Ext', 'set_rtnd_precision' ] );
        $cart = (isset(WC()->cart)?WC()->cart:0);
        if(!$cart) return;
        $items = $order->get_items( 'line_item' );
        if(count($items)){
            $cart->calculate_totals();
            foreach($items as $item_id=>$item){
                if(isset($item['variation_id']) && $item['variation_id']){
                    $pid = (int)$item['variation_id'];
                    if(has_filter('wpml_object_id'))
                        $pid = apply_filters( 'wpml_object_id', $pid, 'product_variation', true );
                }else{
                    $pid = (int)$item['product_id'];
                    if(has_filter('wpml_object_id'))
                        $pid = apply_filters( 'wpml_object_id', $pid, 'product', true );
                }


                $key = self::find_product_in_cart( $cart->cart_contents, $pid );

                //self::trace_to_option([$pid,$cart,$key]);

                if(!$key) continue;

                $net_price =  wc_format_decimal( $cart->cart_contents[$key]['line_total'], RTNDPRECISION );
                $tax = wc_format_decimal( $cart->cart_contents[$key]['line_tax'], RTNDPRECISION );
                wc_update_order_item_meta( $item_id, '_original_price', $net_price );
                wc_update_order_item_meta( $item_id, '_original_tax', $tax );

                //original title
                $title = @$cart->cart_contents[$key]['data']->get_title();
                if($title)
                    wc_update_order_item_meta( $item_id, '_original_title', $title );

                //original selected attributes
                $a = [];
                $v = isset($cart->cart_contents[$key]['variation'])?$cart->cart_contents[$key]['variation']:'';
                if($v)
                    foreach($v as $an=>$av){
                        $a_name = str_replace( 'attribute_', '',str_replace('pa_', '', $an) );
                        $a[$a_name] = [
                            'label' => ucfirst($a_name),
                            'value' => $av
                        ];
                    }
                if(!empty($a))
                    wc_update_order_item_meta( $item_id, '_original_attributes', $a );
            }
        }
        remove_filter( 'wc_get_price_decimals', [ 'RTND_Ext', 'set_rtnd_precision' ] );
    }

	 /**
      * Check WC version
      * 
	  * @param string $version
	  *
	  * @return bool
	  */
	 public function wc_version( $version = '3.0' ) {
		if ( class_exists( 'WooCommerce' ) ) {
			global $woocommerce;
			if ( version_compare( $woocommerce->version, $version, ">=" ) ) {
				return true;
			}
		}
		return false;
	}

	 /**
      * Make virtual product purchasable
      * 
	  * @param $state
	  * @param $product
	  *
	  * @return bool
      *
      * compatible to WC 3.0
	  */
	 public function make_purchasable($state,$product){
		if(get_post(($this->wc_version()?$product->get_id():$product->id))->post_status == 'rtnd') return true;
		return $state;
	}

	 /**
      * Allow any user to pay for the order
      * 
	  * @param $all_caps
	  * @param $cap
	  * @param $args
	  *
	  * @return mixed
	  */
	 public static function anyone_can_pay($all_caps, $cap, $args){
		if(isset($args[2]))
			$all_caps['pay_for_order'] = $args[2];
		return $all_caps;
	}


	 /**
      * Check if order contains virtual product and remove product from WC
      * 
	  * @param $order_id
	  * @param $old_status
	  * @param $new_status
	  */
	 public function check_virtual_product( $order_id, $old_status, $new_status ){
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		if(in_array($new_status,['cancelled','refunded','completed','failed','exchanged'])){
			$order = wc_get_order($order_id);
			if(!$order) {$RTND_Processor->wpml(1);return;}
			foreach($order->get_items() as $item_id=>$item){
				if(get_post_status($item['product_id']) == 'rtnd') wp_delete_post($item['product_id'],true);
			}
		}
	}

	 /**
      * Enable settings link
      * 
	  * @param $l
	  *
	  * @return array
	  */
	 public function settings_link( $l ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=rtnd_settings' ) . '">' . __( 'Settings', RTND ) . '</a>'
		);
		return array_merge( $plugin_links, $l );
	}


	 /**
      * Set proper shipping in the cart
      * 
	  * @param $cart
	  *
	  * @return mixed
	  */
	 public function before_shipping( $cart ) {
		if(!isset(WC()->session->rtnd_chosen_shipping_methods)) return $cart;
        $chosen_methods = WC()->session->rtnd_chosen_shipping_methods;
        if(in_array('free_shipping', WC()->session->rtnd_chosen_shipping_methods))
            $chosen_methods = array( RTND_Klarna_Extend::get_shipping_method_id_by_label('free_shipping') );
        WC()->session->chosen_shipping_methods = $chosen_methods;
//			global $RTND_Collector;
//			foreach($cart->cart_contents as $item_key=>$item){
//			    $pid = ( isset($item['variation_id']) && $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
//			    $product = wc_get_product( $pid );
//				if(strpos($product->get_title(), 'Returnado')!==false){ //since 0.4.7.23 we add filter for the price
//					$price = $RTND_Collector->get_product_price(
//					        $item['product_id'],
//                            isset($item['variation_id'])?$item['variation_id']:0
//                    );
//					$price = -1*$price;
//					$item['data']->price = $price;
//					//WC 3.0
//                    if(method_exists($item['data'], 'set_price')) $item['data']->set_price( $price );
//				} else {
//					//restore original price (optionally)
//					//check option and restore
//					if ( 'yes' === $this->options['rtnd_original_prices'] )&& isset( $item['_original_price'] ) ) {
//						$item['data']->price = $item['_original_price'];
//						//WC 3.0
//                        if(method_exists($item['data'], 'set_price'))
//                            $item['data']->set_price( $item['_original_price'] );
//					}
//				}
//			}
        return $cart;
	}


	 /**
      * Retrieve product cart item ID by product_id
      *
      * @param array $cart_contents
	  * @param bool $product_id
	  *
	  * @return int
	  */
	 public static function find_product_in_cart( $cart_contents = [], $product_id = false ) {
	    if(!count($cart_contents))
            $cart_contents = (isset(WC()->cart->cart_contents)?WC()->cart->cart_contents:[]);
        if(!$product_id) return 0;
        foreach ( $cart_contents as $cart_item_key => $cart_item )
            if ( $cart_item['product_id'] === $product_id
                ||  $cart_item['variation_id'] === $product_id )
                    return $cart_item_key;
        return 0;
     }

	 /**
      * Make own statuses valid for order payment
	  * @param $array
	  * @param $instance
	  *
	  * @return array
	  */
	 public function make_valid_status( $array, $instance ) {		
        $my_order_status = array('exchanged', 'returnawait');
        return array_merge($array, $my_order_status);
    }


	 /**
      * Settings tab init
      * 
	  * @param $tabs
	  *
	  * @return mixed
	  */
	 public function add_settings_tab($tabs){
		$tabs['rtnd_settings'] = __( 'Returnado', RTND );
        return $tabs;
	}

	 /**
	  * Settings tab content
	  */
	 public function settings_tab(){
		include RTNDPATH.'/inc/customize.php';
	}


	 /**
	  * Validate coupon for store credit - not used since 0.4.5
      *
      * @param bool $valid
      * @param object $coupon
      *
      * @return bool
	  */
	 public static function validate_store_credit( $valid, $coupon ){

         if(!$valid) return $valid;

         // Limit to defined email addresses
         if ( is_array( $coupon->get_email_restrictions() ) && sizeof( $coupon->get_email_restrictions() ) > 0 ) {

             $check_emails = array();

             if ( is_user_logged_in() ) {
                 $current_user   = wp_get_current_user();
                 $check_emails[] = $current_user->user_email;
             }

             if( isset( $_POST['billing_email'] ) )
                    $check_emails[] = $_POST['billing_email'];

             //add klarna email
             if( isset( $_REQUEST['email'] ) )
	                $check_emails[] = $_REQUEST['email'];
	         if( isset( $_POST['billing_address']['email'] ) )
		         $check_emails[] = $_POST['billing_address']['email'];

             if(!$check_emails) return true; //nothing to compare with

             $check_emails   = array_map( 'sanitize_email', array_map( 'strtolower', $check_emails ) );

             if ( 0 == sizeof( array_intersect( $check_emails, $coupon->get_email_restrictions() ) ) ) return false;

         }
         return true;
     }

	 /**
	  * Update store credit after the purchase
      *
      * @param int $order_id
      *
	  */
	 public static function update_store_credit( $order_id ){
	     $order = wc_get_order( $order_id );
	     if(!$order) return;
	     $order_coupons = $order->get_items('coupon');
	     if(empty($order_coupons)) return;
	     foreach($order_coupons as $item_id=>$item ) {
             $coupon_id = wc_get_coupon_id_by_code($item->get_code());
             //check if the coupon is returnado coupon
             $rtnd_customer = get_post_meta( $coupon_id, 'rtnd_customer', true );
             if(!$rtnd_customer) continue;
             $current_coupon_amount = get_post_meta($coupon_id, 'coupon_amount', true);
             $new_amount = $current_coupon_amount - $item->get_discount();
             update_post_meta($coupon_id, 'coupon_amount', $new_amount);
         }
     }

     /**
	  * Update store credit before refund deleted
      *
      * Check and clear order item meta data
      *
      *
      * @param int $refund_id
	  */
	 public static function before_refund_deleted( $refund_id ){
	     $post = get_post($refund_id);
	     if( $post->post_type !== 'shop_order_refund' ) return;

		 //check and clear order item meta data
		 global $wpdb;
		 $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = 'Returnado [#$refund_id]'");

		 //reduce coupon amount
	     $assigned_coupon = get_post_meta( $refund_id, '_assigned_coupon', true);
	     if(!$assigned_coupon) return;
	     $refund_amount = get_post_meta( $refund_id, '_assigned_coupon_update_amount', true);
	     if(!$refund_amount)
	        $refund_amount = get_post_meta( $refund_id, '_refund_amount', true);
		 $current_coupon_amount = get_post_meta( $assigned_coupon, 'coupon_amount', true );
		 $new_amount = $current_coupon_amount - $refund_amount;
		 update_post_meta( $assigned_coupon, 'coupon_amount', $new_amount);
     }

	 /**
	  * Own order statuses: "exchanged" and "returnawait"
	  */
	 public static function exchange_order_status() {
		register_post_status( 'wc-exchanged', array(
			'label'                     => __('Returnado Exchanged',RTND),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Returnado Exchanged <span class="count">(%s)</span>','Returnado Exchanged <span class="count">(%s)</span>',RTND)
		) );
		
		register_post_status( 'wc-returnawait', array(
			'label'                     => __('Returnado Await',RTND),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Returnado Await <span class="count">(%s)</span>','Returnado Await <span class="count">(%s)</span>',RTND)
		) );
	}

	 /**
	  * Own order statuses: "exchanged" and "returnawait"
	  */
	public static function add_exchange_order_status( $order_statuses ) {
			$order_statuses['wc-exchanged'] 	= __( 'Returnado Exchanged',RTND);
			$order_statuses['wc-returnawait'] 	= __( 'Returnado Await',RTND);
		return $order_statuses;
	}

	 /**
      * Trigger user update / save
	  * @param $id
	  */
	 public function trigger_user($id){
        global $RTND_Sender, $RTND_Collector;
         $r = $RTND_Sender->returnado_send( 'customers',	$RTND_Collector->get_customer($id) );
        update_option( 'rtnd_mess', $r );
        if(strpos($r,'ERROR') === false) update_user_meta( $id, '_rtnd_synced', time() );
	}

	 /**
      * Trigger stock change
      *
	  * @param $item
      *
      * compatible to wc 3.0
	  */
	 public function stock_change( $item ){
		global $RTND_Sender, $RTND_Collector, $RTND_Processor;
		$RTND_Processor->wpml(0);
		$id = 0;
		if(!is_object($item))
		    $id = (int)$item;
		if( !$id ){
		    if($this->wc_version())
		        $id = $item->get_id();
		    else
		        $id = $item->id;
        }
		$pr = $RTND_Collector->get_product( $id, 'single' );
        if ($pr) {
            $r = $RTND_Sender->returnado_send('products', $pr);
            update_option('rtnd_mess', $r);
            if(strpos($r,'ERROR') === false) update_post_meta( $id, '_rtnd_synced', time() );
        }
		$RTND_Processor->wpml(1);
	}

	 /**
      * Trigger post (product or order)
      *
	  * @param $id
	  */
	 public function trigger_post($id){
		//if($this->trigger_stop) return 0;
		$this->trigger_stop=1;
		global $RTND_Sender,$RTND_Collector;
		$p = get_post($id);

		if ($p->post_type==='shop_order'){

			if (in_array($p->post_status, ['wc-completed','wc-exchanged'])){
			    //sync order customer - if such exists
                $customer_id = get_post_meta( $id, '_customer_user', true );
                if($customer_id) $this->trigger_user( $customer_id );
                //send data
			    $r = $RTND_Sender->returnado_send('orders',	$RTND_Collector->get_order($id));
                update_option('rtnd_mess', $r);
                if(strpos($r,'ERROR') === false) update_post_meta( $id, '_rtnd_synced', time() );
            }

		}
		
		if (in_array($p->post_type,['product','publish'])) {
			$pr = $RTND_Collector->get_product( $id, 'single' );
			if ($pr) {
			    $r = $RTND_Sender->returnado_send('categories',	$RTND_Collector->get_product_categories($id)).' - '.$RTND_Sender->returnado_send('products',	$pr);
                update_option('rtnd_mess', $r );
                if(strpos($r,'ERROR') === false) update_post_meta( $id, '_rtnd_synced', time() );
            }
		}

	}


	 /**
	  * BE Admin notice output
	  */
	 public function notice(){
		$m = get_option( 'rtnd_mess' );
		if ($m) {
			if (strpos($m,'ERROR'))
			  echo '<div class="notice notice-warning is-dismissible"><p>
						'.__('Returnado background synchronization failure.',RTND).' '.$m.'
					</p></div>';
			else
				echo '<div class="notice notice-success is-dismissible"><p>
						'.__('Returnado background synchronization successful.',RTND).'
					</p></div>';
				delete_option('rtnd_mess');
		}
	}

	 /**
      * iframe Returnado shortcode
      *
	  * @return string
	  */
	 public function iframe_sc(){
		$shop_id = $this->options['rtnd_shop_id'];
		$remote_host = $this->options['rtnd_remote_widget_host'];
		ob_start();
		    include RTNDPATH . '/assets/tpl/rtnd-shortcode.php';
		return ob_get_clean();
	}


	 /**
      * API request sniffer
      *
	  * @param $page_template
	  *
	  * @return string
	  */
	 public function api_test( $page_template )
	 {
	     //Load Returnado testing platform
		$pagename = strtolower($_SERVER['REQUEST_URI']);
		if ( strpos($pagename,$this->back_end_slug[1]) !== false ) {
			return RTNDPATH . '/inc/api/test/index.php';
		}

		//Front-End Test mode enabled
         if( 'yes' === $this->options['rtnd_test_mode'] && !is_admin() ){
	         //checking if auth data is there, if not - adding
	         if (empty($_SERVER['PHP_AUTH_USER']) && (isset($_SERVER['HTTP_AUTHORIZATION'])))
		         list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) =
			         explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
	         $unm = $this->options['rtnd_remote_user'];
	         $psw = $this->options['rtnd_remote_password'];
	         $authenticated = 0;
	         if(isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']))
	            $authenticated=($_SERVER['PHP_AUTH_USER']==$unm&&$_SERVER['PHP_AUTH_PW']==$psw);
	         if(!$authenticated)
	         {
		         header('WWW-Authenticate: Basic realm="Restricted Area"');
		         header('HTTP/1.1 401 Unauthorized');
		         _e('Access denied!',RTND);
		         return false;
	         }
         }

		return $page_template;
	}

	 /**
      * Launches complete synchronization to Returnado
      *
	  * @return string
	  */
	 public function sync_all(){
        require_once "api/test/class-rtnd-api-client.php";
        $client = new RTND_Client($this->options['rtnd_remote_user'],$this->options['rtnd_remote_password'],site_url());
        return $client->touch('go_sync');
	}

	 /**
	  * Process complete sequental synchronization to Returnado
	  *
	  * @return string
	  */
	 public function start_sync_all(){
		 require_once "api/test/class-rtnd-api-client.php";
		 $client = new RTND_Client($this->options['rtnd_remote_user'],$this->options['rtnd_remote_password'],site_url());
		 $sz = 50; $pg = 1;
		 while ( $client->SendGetPure( "go_sync_products?page=$pg&size=$sz" ) ) $pg++;
		 $sz = 50; $pg = 1;
		 while ( $client->SendGetPure( "go_sync_customers?page=$pg&size=$sz" ) ) $pg++;
		 $sz = 50; $pg = 1;
		 while ( $client->SendGetPure( "go_sync_categories?page=$pg&size=$sz" ) ) $pg++;
		 $sz = 50; $pg = 1;
		 while ( $client->SendGetPure( "go_sync_orders?page=$pg&size=$sz" ) ) $pg++;
	 }

	 /**
      * Default Returnado plugin options
      *
	  * @return array
	  */
	 protected static function defaults(){
	     return [
	             'rtnd_shop_id'         => '1',
	             'rtnd_api_enabled'     => 'yes',
	             'rtnd_api_test_enabled'=> '',
	             'rtnd_test_mode'       => '',
                 'rtnd_sync_enabled'    => '',
                 'rtnd_remote_host'          => RTND_DEF_REMOTE_SYNC_HOST,
                 'rtnd_remote_widget_host'   => RTND_DEF_REMOTE_WIDGET_HOST,
                 'rtnd_remote_admin_host'    => RTND_DEF_REMOTE_ADMIN_HOST,
                 'rtnd_remote_password' => 'secret',
                 'rtnd_remote_user'     => 'global',
                 'rtnd_update_stock'    => 'yes',
                 'rtnd_widget_shortcode'=> 'returnado',
                 'rtnd_api_refund'      => 'yes',
                 'rtnd_pmgw_def'        => '0',
                 'rtnd_include_shipping'=> '0',
                 'rtnd_virt_item'       => '2',
                 'rtnd_use_coupons'     => 'yes',
                 'rtnd_log_incoming'    => '',
		         'rtnd_log_outgoing'    => '',
		         'rtnd_log_deep'        => '',
		         'rtnd_anyone_can_pay'  => 'yes',
                 'rtnd_original_prices' => '',
                 'rtnd_min_precision'   => WOOMINPRECISION,
                 'rtnd_cut_trails'      => ''
         ];
    }

	 /**
	  * Retrieve all plugin's options
	  *
	  * @return array
	  */
	 public function get_options(){
		 return $this->options;
	 }

	 /**
	  * Set all plugin options
	  *
	  * @param $o
	  *
	  * @return bool
	  */
	 public function set_options($o){
	     //validate hosts
         if(isset($o['rtnd_remote_host']))
             $remote_host = filter_var( $o['rtnd_remote_host'], FILTER_VALIDATE_URL );
         if(!$remote_host) $remote_host = RTND_DEF_REMOTE_SYNC_HOST;

         if(isset($o['rtnd_remote_host']))
            $remote_widget_host = filter_var( $o['rtnd_remote_widget_host'], FILTER_VALIDATE_URL );
         if(!$remote_widget_host)
             $remote_widget_host = RTND_DEF_REMOTE_WIDGET_HOST;

         if(isset($o['rtnd_remote_admin_host']))
            $remote_admin_host = filter_var( $o['rtnd_remote_admin_host'], FILTER_VALIDATE_URL );
         if(!$remote_admin_host)
             $remote_admin_host = RTND_DEF_REMOTE_ADMIN_HOST;

		 $this->options = [
		     'rtnd_shop_id'         => (isset($o['rtnd_shop_id'])           ?$o['rtnd_shop_id']:''),
		     'rtnd_api_enabled'     => (isset($o['rtnd_api_enabled'])       ?$o['rtnd_api_enabled']:''),
		     'rtnd_api_test_enabled'=> (isset($o['rtnd_api_test_enabled'])  ?$o['rtnd_api_test_enabled']:''),
		     'rtnd_test_mode'       => (isset($o['rtnd_test_mode'])         ?$o['rtnd_test_mode']:''),
		     'rtnd_sync_enabled'    => (isset($o['rtnd_sync_enabled'])      ?$o['rtnd_sync_enabled']:''),
		     'rtnd_remote_host'            => $remote_host,
		     'rtnd_remote_widget_host'     => $remote_widget_host,
		     'rtnd_remote_admin_host'      => $remote_admin_host,
		     'rtnd_remote_password' => (isset($o['rtnd_remote_password'])   ?$o['rtnd_remote_password']:''),
		     'rtnd_remote_user'     => (isset($o['rtnd_remote_user'])       ?$o['rtnd_remote_user']:''),
		     'rtnd_update_stock'    => (isset($o['rtnd_update_stock'])      ?$o['rtnd_update_stock']:''),
		     'rtnd_widget_shortcode'=> (isset($o['rtnd_widget_shortcode'])  ?$o['rtnd_widget_shortcode']:''),
		     'rtnd_api_refund'      => (isset($o['rtnd_api_refund'])        ?$o['rtnd_api_refund']:''),
		     'rtnd_pmgw_def'        => (isset($o['rtnd_pmgw_def'])          ?$o['rtnd_pmgw_def']:''),
		     'rtnd_include_shipping'=> (isset($o['rtnd_include_shipping'])  ?$o['rtnd_include_shipping']:''),
		     'rtnd_virt_item'       => (isset($o['rtnd_virt_item'])         ?$o['rtnd_virt_item']:'2'),
		     'rtnd_use_coupons'     => (isset($o['rtnd_use_coupons'])       ?$o['rtnd_use_coupons']:''),
		     'rtnd_log_incoming'    => (isset($o['rtnd_log_incoming'])      ?$o['rtnd_log_incoming']:''),
		     'rtnd_log_outgoing'    => (isset($o['rtnd_log_outgoing'])      ?$o['rtnd_log_outgoing']:''),
		     'rtnd_log_deep'        => (isset($o['rtnd_log_deep'])          ?$o['rtnd_log_deep']:''),
		     'rtnd_anyone_can_pay'  => (isset($o['rtnd_anyone_can_pay'])    ?$o['rtnd_anyone_can_pay']:''),
		     'rtnd_original_prices' => (isset($o['rtnd_original_prices'])   ?$o['rtnd_original_prices']:''),
		     'rtnd_min_precision'   => (isset($o['rtnd_min_precision'])     ?$o['rtnd_min_precision']:''),
		     'rtnd_cut_trails'      => (isset($o['rtnd_cut_trails'])        ?$o['rtnd_cut_trails']:'')
	     ];

		 update_option( 'rtnd_options', json_encode($this->options) );

		 //reinititalize objects with new options
         global $RTND_Sender, $RTND_Collector, $RTND_Processor;

		 $RTND_Sender    = new RTND_Sender( $this->options );
		 $RTND_Collector = new RTND_Collector( $this->options );
		 $RTND_Processor = new RTND_POST_PROCESS( $this->options );

		 return true;
	 }

	 /**
	  * Install trigger
	  */
	 public static function install(){
		//check for WC to be installed
		if (!class_exists( 'WooCommerce' ) )
			die('<p><b>'.__('WooCommerce plugin is required.',RTND).'</b>
				'.__('Please, install and activate WooCommerce plugin first. You may use this link to download and',RTND).' <a href="plugin-install.php?tab=plugin-information&plugin=woocommerce&TB_iframe=true&width=772&height=644">'.__('Install Woocommerce',RTND).'.</a></p>');

		//adding and enabling options
        $opts = get_option( 'rtnd_options' );
        if(!$opts) update_option('rtnd_options',json_encode(RTND_Ext::defaults()));
		
		//copy no-image into uploads
		if (!file_exists(ABSPATH.'/wp-content/uploads/no-image.png')) copy(RTNDPATH.'/assets/img/no-image.png',ABSPATH.'/wp-content/uploads/no-image.png');
		
	}

	 /**
	  * Uninstall trigger
	  */
	 public static function uninstall(){
		//removing options
        delete_option('rtnd_options');
		//remove default image
		unlink(ABSPATH.'/wp-content/uploads/no-image.png');
	}

	 /**
	  * AJAX submitting
	  */
	 public static function rtnd_ajax_submit(){ //AJAX SUBMITTING
		include "ajax_processing.php";
		wp_die();
	}

	 /**
	  * Inserting Returnado Admin Panel
	  */
	 public function returnado_admin_panel(){
		?>
			<style>
				.notice, .updated{
					display:none !important;
					visibility:hidden !important;
				}
				#wpcontent, #wpbody, #wpbody-content{
					padding:0 !important;
					height:90vh !important;
					display:block;
				}
			</style>
			<iframe src="<?php echo $this->options['rtnd_remote_admin_host']; ?>"
                    frameborder="0"
                    scrolling="auto"
                    frameborder="0" scrolling="auto" style="height: 100%; margin:0; padding:0; width:100%; border: none;"></iframe>
		<?php
	}

	 /**
	  * Add admin menus
	  */
	 public function add_admin_menus(){
	   add_submenu_page(
            'woocommerce',
            __('Returns',RTND),
			__('Returns',RTND),
            'manage_options',
            $this->back_end_slug[0],
			array($this,'returnado_admin_panel')
        );
	}

	 /**
	  * Rearrange admin menus
	  */
	 public function menus_order(){
		if(!current_user_can('administrator')) return;
		global $submenu;
		$mi = count($submenu['woocommerce'])-1;
		$me = $submenu['woocommerce'][$mi];		
		unset($submenu['woocommerce'][$mi]);
		array_splice( $submenu['woocommerce'], 2, 0, [$me]);
	}


	 /**
      * Retrieving current plugin version
      *
	  * @return string
	  */
	 public function get_version(){
		return $this->version;	
	}

	/**
     * Helper - tracer
     *
     * @param $data
     */
	public static function trace_to_option( $data ){
	    update_option( '_rtnd_check', print_r($data, true) );
    }
}

//init base class object
global $RTNDo;

 $RTNDo = new RTND_Ext;

//init child objects
if(is_a($RTNDo,'RTND_Ext')) {

	include_once "collector.php";
	include_once "sender.php";
	require "klarna-extend.php";
	//require "checkout-no-login.php";
	require "api/rtnd-post-process.php";
	require "api/rtnd-api-error-handler.php";
	global $RTND_Sender, $RTND_Collector, $RTND_Processor;
	$RTND_Sender    = new RTND_Sender( $RTNDo->get_options() );
	$RTND_Collector = new RTND_Collector( $RTNDo->get_options() );
	$RTND_Processor = new RTND_POST_PROCESS( $RTNDo->get_options() );

}
//API_EXTENSION
if ($RTNDo->get_options()['rtnd_api_enabled'])		{
	//Rest API (basic authentication)
    include_once "api/rtnd-api-ext.php";
	$RTND_API = new RTND_API_EXT($RTNDo->get_options());
}

