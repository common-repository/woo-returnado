<?php
/**
 * Created by PhpStorm.
 * User: stim
 * Date: 20.02.18
 * Time: 14:01
 */

/**
 * Currently this is a custom class for handling Fortnox error codes
 */

if(!class_exists('RTND_API_Error_Handler')) :

class RTND_API_Error_Handler{

    //Recoverable codes
    const fnx_codes = [ '2000439', '2000776' ];

    /**
     * Get recoverable error code, if possible
     *
     * @param string $err
     * @return string
     */
    private static function get_recoverable_error_code( $err ){
        foreach( self::fnx_codes as $fe )
            if( false !== strpos( $err, $fe ) ) return $fe;
        return '';
    }

    /**
     * Handle exceptions and create refund object
     *
     * @param $refund
     * @param array $args
     * @param $log
     * @throws Exception
     */
    public static function create_refund_no_exceptions( &$refund, $args = [], $log ){

        list( $order_id, $total_refund, $line_items, $refund_reason ) = $args;

        //attempts - default = 1
        $r = 1;

        do {
            $r--;
            try {
                $refund = wc_create_refund(array(
                        'amount' => wc_format_decimal($total_refund, ""),
                        'reason' => __('Returnado reason', RTND) . ': ' . $refund_reason,
                        'order_id' => $order_id,
                        'line_items' => $line_items
                    )
                );
            } catch (Exception $e) {
                //check if error is produced by Fortnox => ignore it
                if( false !== strpos( strtolower( $e->getMessage() ), 'fortnox' ) ){

                    $log('TRYING TO RECOVER FROM FORTNOX EXCEPTION ON REFUND ' . $e->getCode()
                            . ' FOR ORDER ' . $order_id . '. DISABLING ALL HOOKS...','');

                    remove_all_actions( 'woocommerce_refund_created' );
                    remove_all_actions( 'woocommerce_create_refund' );
                    remove_all_actions( 'woocommerce_order_refunded' );

                    $r = 1;
                }else{
                    //check if refund created -> remove it
                    if(method_exists($refund, 'delete'))
                        $refund->delete( true );
                    $refund = null;
                    $log('REFUND FAILED FOR ORDER ' . $order_id, false );
                    throw new Exception( 'ERROR: Refund object could not be created (returned exception) in attempt to override Fortnox error ' . $e->getMessage() );
                }
            }
        }while( $r );

    }

    /**
     * Handle WP_Error
     *
     * @param $refund
     * @param array $args
     * @param $log
     * @throws Exception
     */
    public static function handle_wp_error( &$refund, $args = [], $log ){

        list( $order_id, $total_refund, $line_items, $refund_reason ) = $args;

        //deep loggin
        $log('REFUND FAILED FOR ORDER ' . $order_id . ' WITH WP_ERROR',
            '    CODE: ' . $refund->get_error_code() .
            ' MESSAGE: ' . $refund->get_error_message() .
            '    BODY: ' . str_replace( '  ', '', str_replace("\n",' ',var_export( $refund, 1 ) ) ) );
        /**
         * Overriding Fortnox Errors
         */
        $error_code = self::get_recoverable_error_code( $refund->get_error_message() );

        if ( ! $error_code )
            throw new Exception( 'ERROR: ' . $refund->get_error_message() );

        //trying to recover from the error by disabling the "refund" hook
        $log('TRYING TO RECOVER FROM FORTNOX WP_ERROR ' . $error_code . ' FOR ORDER ' . $order_id . '. DISABLING ALL HOOKS...','');

        remove_all_actions( 'woocommerce_refund_created' );
        remove_all_actions( 'woocommerce_create_refund' );
        remove_all_actions( 'woocommerce_order_refunded' );

        $refund = wc_create_refund( array(
                'amount'        => wc_format_decimal( $total_refund, "" ),
                'reason'        => __('Returnado reason',RTND) . ': ' . $refund_reason,
                'order_id'      => $order_id,
                'line_items'    => $line_items
            )
        );

        $log('RESULT REFUND OBJECT FOR ORDER '.$order_id, str_replace( '  ', '', str_replace("\n",' ',var_export( $refund, 1 ) ) ) );

        if ( ! $refund ) {
            //deep loggin
            $log('REFUND FAILED FOR ORDER ' . $order_id, false );
            throw new Exception( 'ERROR: Refund object could not be created (returned false) in attempt to override Fortnox error ' . $error_code );
        }

        if ( is_wp_error( $refund ) ) {
            $log('REFUND FAILED TWICE FOR ORDER ' . $order_id . ' WITH WP_ERROR',
                '    CODE: ' . $refund->get_error_code() .
                ' MESSAGE: ' . $refund->get_error_message() .
                '    BODY: ' . str_replace( '  ', '', str_replace("\n",' ',var_export( $refund, 1 ) ) ) );
            throw new Exception( 'ERROR: Refund failed in attempt to override Fortnox error ' . $error_code . ' with message: ' . $refund->get_error_message() );
        }
    }

}

endif;