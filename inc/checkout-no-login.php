<?php

defined('ABSPATH') or exit;

add_action('woocommerce_before_checkout_process', 'wcnl_set_customer', 0);

add_filter('woocommerce_thankyou', 'wcnl_unset_customer', 999);

function wcnl_set_customer(){
	if ( is_user_logged_in() ) return;
	$fname = wc_clean($_POST['billing_first_name']);
	$lname = wc_clean($_POST['billing_last_name']);
	$email = wc_clean($_POST['billing_email']);
	if(!$email) return;
	$UID = email_exists($email);
	if( !$UID && 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ) {
		//registering new user
		$nu_login = explode('@',$email)[0];
		$nu_pass = wp_generate_password();
		while (username_exists($nu_login)) $nu_login.='1';
		$userdata = array(
			'user_login'  	=>  $nu_login,
			'user_pass'    	=>  $nu_pass,
			'user_nicename' =>  ucfirst($nu_login),
			'user_email'	=>	$email,
			'user_url'		=>  '',
			'display_name'	=>	ucfirst($nu_login),
			'nickname'		=>	$nu_login,
			'first_name'	=>	$fname,
			'last_name'		=>	$lname,
			'description'	=>  '',
			'role'			=>	'customer'
		);
		$UID = wp_insert_user( $userdata ) ;
		if(!$UID) { //exception
				wc_add_notice('Användaren kunde inte registreras med denna information','error');
				return;
			}
        //sync user to Returnado
        global $RTNDo;
		$RTNDo->trigger_user($UID);
		//inform the customer about his credentials
		WC()->mailer->emails['WC_Email_Customer_New_Account']->trigger( $UID, $nu_pass , true );
	}
	wp_set_current_user( $UID );
	wc_set_customer_auth_cookie( $UID );
	if('yes' !== get_option( 'woocommerce_enable_guest_checkout' )) WC()->session->set( 'wcsi_session_user', $UID );
}

function wcnl_unset_customer(){
	if (isset(WC()->session->wcsi_session_user)) wp_logout();
}