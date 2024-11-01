<?php
/**
 *  Returnado coupon plain text notification email template
 *
 * @see 	    https://wetail.se
 * @author 		Wetail
 * @package 	returnado-extension
 * @version     0.0.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;
echo "\n";
echo "== ".$email_heading . " ==\n\n";
echo sprintf(__('Your new/updated coupon at %s received',RTND),get_bloginfo( 'name' )). "\n\n";
echo sprintf(__('Coupon information',RTND)). ":\n\n";
echo sprintf("\t".__('Amount (%s)',CMAP).': %s',get_woocommerce_currency_symbol(),$amount). "\n";
echo sprintf("\t".__('Code',CMAP).': %s',$code). "\n\n";
echo sprintf(__('Get back to us anytime',CMAP).': %s',$support_url). "\n\n";
echo sprintf(__('This message was generated automatically, please, do not reply.',CMAP)). "\n";
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo "\t\t".apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
