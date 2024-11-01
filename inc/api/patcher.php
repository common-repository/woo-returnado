<?php
/**
 * Created by PhpStorm.
 * User: stim
 * Date: 30.10.17
 * Time: 10:24
 *
 * Class-patcher for definite customizations
 */
defined('RTNDPATH') or die();

class RTND_Patcher{

    /*
     * Patch removing coupon personalization information
     */
    public static function no_person_coupons(){
        //patch for non-personalization
        $rtnd_coupons = get_posts(
            [
                'post_type' => 'shop_coupon',
                'meta_query' => [[
                    'key'  => 'rtnd_customer',
                    'value'=> 0,
                    'compare'   => '>'
                ]]
            ]
        );
        $patched_coupons = 0;
        if(!empty($rtnd_coupons))
            foreach( $rtnd_coupons as $coupon )
                $patched_coupons += delete_post_meta( $coupon->ID, 'customer_email' )*1;
        return 'Personalization removed from ' . $patched_coupons . ' coupon(s)';
    }

    /*
     * Patch registering guest users and set them as customers for their orders
     * (to make possible synchronization to Returnado for guest orders)
     */
    public static function guest_orders(){
        $rtnd_guest_orders = get_posts([
            'post_type'     => 'shop_order',
            'post_status'	   => ['wc-completed','wc-exchanged'],
            'nopaging'	 => true,
            'suppress_filters'  => 1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'meta_query'	=> [[
                'key'     => '_customer_user',
                'compare' => '==',
                'value'   => '0'
            ]]
        ]);
        $processed_users = 0;
        $orders_assigned = 0;
        foreach($rtnd_guest_orders as $order_post)
            $orders_assigned += (self::patch_guest_order( $order_post->ID, $processed_users ) > 0);
        return 'Registered ' . $processed_users . ' user(s). Assigned ' . $orders_assigned . ' order(s).';
    }

    /**
     * Patching guest order
     *
     * @param int $order_id
     * @param int $new_users_count
     * @param bool $sync
     *
     * @return int
     */

    public static function patch_guest_order( $order_id, &$new_users_count, $sync = false ){
        //check if it is possible to register user (email is set)
        $user_email = get_post_meta( $order_id, '_billing_email', true );
        if(!$user_email) return 0;
        //create user if not already exists
        $user = get_user_by('email', $user_email);
        $UID = 0;
        if(!empty($user)) $UID = $user->ID;
        if(!$UID){
            $pass = wp_generate_password( 12, false );
            $login = explode( '@', $user_email )[0];
            while(get_user_by('login', $login)) $login.='1';
            $UID = wp_create_user( $login, $pass, $user_email );
            if(!$UID) return 0;
            //set user meta info from the order
            $order_meta = get_post_meta( $order_id );
            foreach( $order_meta as $meta_key=>$values ){
                if( false !== strpos( $meta_key, '_billing' ) ||
                    false !== strpos( $meta_key, '_shipping' ) ){
                    update_user_meta( $UID, substr( $meta_key, 1 ), $values[0] );
                }
            }
            $new_users_count++;
        }
        //assign customer id to the order
        update_post_meta( $order_id, '_customer_user', $UID );
        if($sync){
            global $RTND_Sender, $RTND_Collector;
            if( $rz = $RTND_Collector->get_order( $order_id ) )
                $RTND_Sender->returnado_send( 'orders', $rz );
        }
        return $UID;
    }

    /*
     * Patch checking and setting appropriate settings for the plugin
     * - if checkout guest mode is enabled:
     *      - it checks is automatic username and password are enabled, if not - enables it
     */

    public static function check_settings(){
        $changes = '';
        if( 'yes' === get_option( 'woocommerce_enable_guest_checkout')){
            if( 'yes' !== get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) ){
                update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
                $changes .= 'Enable customer registration on the "Checkout" page - enabled. ';
            }
            if( 'yes' !== get_option( 'woocommerce_registration_generate_username' ) ){
                update_option( 'woocommerce_registration_generate_username', 'yes' );
                $changes .= 'Automatically generate username from customer email - enabled. ';
            }
            if( 'yes' !== get_option( 'woocommerce_registration_generate_password' ) ){
                update_option( 'woocommerce_registration_generate_password', 'yes' );
                $changes .= 'Automatically generate customer password - enabled. ';
            }
        }
        if(empty($changes)) $changes .= 'No changes needed.';
        return $changes;
    }
}