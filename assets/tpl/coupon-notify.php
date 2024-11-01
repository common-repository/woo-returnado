<?php
/**
 * Returnado coupon notification email template
 *
 * @see 	    https://wetail.se
 * @author 		Wetail
 * @package 	returnado-extension
 * @version     0.0.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

	<p><?php printf(__('Your new/updated coupon at %s received!',RTND),get_bloginfo( 'name' )); ?></p>
	<p></p>
	<p><?php printf(__('Coupon information',RTND)) ?>:</p>
		<ul>
			<li> <?php printf(__('Amount',RTND).': %s',wc_price($amount)); ?> </li>
			<li> <?php printf(__('Code',RTND).': <strong>%s</strong>',$code); ?> </li>
		</ul>
	<p> </p>
	<p> <?php printf(__('Get back to us anytime',RTND).': 
	                    <a href="%s" target="_blank" 
	                       title="'.__('If you have any questions for us - you are welcome!',RTND).'">
	                       %s
	                    </a>',$support_url,$support_url); ?></p>
	<hr/>
	<p></p>
	<p> <?php _e('This message was generated automatically, please, do not reply.',RTND); ?> </p>

<?php do_action( 'woocommerce_email_footer', $email );
