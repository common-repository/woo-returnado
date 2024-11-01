<?php
/*
Plugin Name: WooCommerce Returnado
Plugin URI: http://wetail.se
Description: Extension for Returnado and widget interface providing order returning functionality.
Author: Wetail
Version: 0.4.7.34
Author URI: http://wetail.se
*/

if ( !defined('ABSPATH') ) exit;

define ( 'RTNDVERSION','0.4.7.34' );

define ( 'RTND','Returnado-Extension' );

define( 'RTNDPATH', dirname(__FILE__) );

define( 'RTNDINDEX', __FILE__ );

define( 'RTNDNAME', basename( __DIR__ ) );

define( 'RTNDURL', plugins_url() . '/' . RTNDNAME );

define( 'RTNDPRECISION', 5 ); //returnado default calculations precision

define( 'WOOMINPRECISION', 2); //minimum price precision in Woo

load_plugin_textdomain( RTND, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

require "inc/rtnd.php";
