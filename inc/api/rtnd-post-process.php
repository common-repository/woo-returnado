<?php

//checking if accessed directly
defined('ABSPATH') or die('Who are you, dude?');

/*
 * 
 * Class for processing requests from Returnado side
 * 
 */

class RTND_Post_Process{

	//set RTND options for the class

	private $options = array();

	//currency rates

	private $rates = array();

	/**
	 * RTND_Post_Process constructor.
	 * sets the options and refreshes currency rates (once per day)
	 * @param array $options
	 */
	public function __construct($options = []) {
		if(empty($options)){
			global $RTNDo;
			if(!$RTNDo) die('Base Returnado object class was not initialized (RTND_Ext)');
			$options = $RTNDo->get_options();
		}
		$this->options = $options;

		//currency rates refresh
		$rtnd_rates = get_option('rtnd_currency_rates');
		if($rtnd_rates)
			$this->rates = json_decode($rtnd_rates,true);
		$refresh = empty($this->rates);
		if(!$refresh) $refresh = $this->rates['last_updated'] < (time() - 43200); //refresh rates in 12 hours
		if($refresh) add_action('plugins_loaded',[$this,'refresh_currency_rates']);

	}


    /**
     * Deep logging
     *
     * @param $msg
     * @param $data
     */
	public function log( $msg, $data ){
	    if(!isset($this->options['rtnd_log_deep'])) return;
        if('yes' === $this->options['rtnd_log_deep']){
            //write LOG
            $log_filename = RTNDPATH.'/logs/log_deep-'.strftime("%d_%m_%Y").'.txt';
            $time = strftime("%d_%m_%Y %H:%M:%S");
            file_put_contents($log_filename, '[MEM: '.number_format(memory_get_usage(true)/1024/1024,0,'.',' ').' Mb]['.$time.'] '
                .$msg.' '
                .json_encode( $data )
                ."\r\n\r\n\r\n", FILE_APPEND);

        }
    }

	/**
	 * Restocking order item
	 *
	 * @param $order
	 * @param $product_id
	 * @param $quantity
	 * @param $item
	 *
	 * compatible to wc 3.0
	 */
	private function restock_item($order, $product_id, $quantity, $item ) {
		//checking option
		if ( 'yes' !== $this->options['rtnd_update_stock'] ) return;

		//deep loggin
        $this->log('START RESTOCK ITEM', $product_id);

		$_product = wc_get_product( $product_id );

		if(!$_product) return;

		global $RTNDo; $wc30 = $RTNDo->wc_version();

		if (!$wc30 && $_product->has_child()){
			$v = $_product->get_child($product_id);
			if ($v) $_product = $v;
		}

		$old_stock = ($wc30?$_product->get_stock_quantity():$_product->stock);

		//updating product stock
		$new_quantity = ($wc30?wc_update_product_stock( $_product, $quantity, 'increase' ):$_product->increase_stock( $quantity  ));

		if ($new_quantity){
            //deep loggin
            $this->log('>>> RESTOCK ITEM - NEW QUANTITY: ', $new_quantity);

            do_action( 'woocommerce_product_set_stock', $_product );

            do_action( 'woocommerce_auto_stock_restored', $_product, $item );
			if (!$wc30) $order->send_stock_notifications( $_product, $new_quantity, $quantity);
			$order->add_order_note( sprintf( __( 'Item #%s stock increased from %s to %s.', 'woocommerce' ), $product_id, $old_stock, $new_quantity ) );

			do_action( 'woocommerce_restock_refunded_item', $product_id, $old_stock, $new_quantity, $order, $_product );
		}

        //deep loggin
        $this->log('RESTOCK ITEM FINISHED ', $product_id);
	}

	/**
	 * Retrieve order item by product_id and store it's meta data
	 *
	 * @param $order
	 * @param $product_id
	 * @param string $item_meta
	 *
	 * @return null
	 */
	private function get_item_from_order($order, $product_id, $item_meta = ""){
		foreach ( $order->get_items() as $item_id=>$item )
			if ( $item['product_id'] == $product_id || $item['variation_id'] == $product_id ) {
				if ($item_meta) {
					$item_meta_new = json_decode(wc_get_order_item_meta($item_id,'returnado_next_action',true),true);
					if (empty($item_meta_new)) $item_meta_new = array();
					if (!in_array($item_meta,$item_meta_new))
						array_push($item_meta_new,$item_meta);
					wc_update_order_item_meta( $item_id, 'returnado_next_action', json_encode($item_meta_new));
				}
				$item['id'] = $item_id;
				return $item;
			}
		return null;
	}


	/**
	 * Update stock and cancel preliminary order with special order note
	 *
	 * @param $order_id
	 *
	 * @return bool
	 *
	 * compatible to wc 3.0
	 */
	protected function restock_and_cancel_order( $order_id ) {
		$order = new WC_Order( $order_id );
		global $RTNDo; $wc30 = $RTNDo->wc_version();
		if (!$order) return false;
        //deep loggin
        $this->log('RESTOCK AND CANCEL ORDER STARTED', $order_id);
		if ( 'yes' === $this->options['rtnd_update_stock'] ) {
			if ( get_option('woocommerce_manage_stock') == 'yes' && sizeof( $order->get_items() ) > 0 ) {
				foreach ( $order->get_items() as $item ) {
					if ( $item['product_id'] > 0 ) {
						$_product = $order->get_product_from_item( $item );
						if ( $_product && $_product->exists() && $_product->managing_stock() ) {
							$old_stock = $_product->stock;
							$qty = apply_filters( 'woocommerce_order_item_quantity', $item['qty'], $this, $item );
							$new_quantity = ($wc30?wc_update_product_stock( $_product, $qty, 'increase' ):$_product->increase_stock( $qty ));
                            //deep loggin
                            $this->log('>>> NEW ITEM QUANTITY', [ 'product_id' => $_product->get_id(), 'qty' => $new_quantity ]);
							do_action('woocommerce_product_set_stock',$_product);
							do_action( 'woocommerce_auto_stock_restored', $_product, $item );
							$order->add_order_note( sprintf( __( 'Item #%s stock incremented from %s to %s.', 'woocommerce' ),
															$item['product_id'], $old_stock, $new_quantity)
							);
							$order->send_stock_notifications( $_product, $new_quantity, $item['qty'] );
						}
					}
				}
			}else $order->add_order_note(__('Items were not restocked (stock management not enabled)',RTND));
		}
		$order->update_status( 'cancelled' );
		$order->add_order_note(__('RETURNADO_CANCEL',RTND));
        //deep loggin
        $this->log('RESTOCK AND CANCEL ORDER FINISHED', $order_id);
		return true;
	}

	/**
	 * Cancel preliminary order with special note
	 *
	 * @param $order_id
	 *
	 * @return bool
	 */
	protected function cancel_order( $order_id ) {
		$order = new WC_Order( $order_id );
		if (!$order) return false;
        $order->update_status( 'cancelled' );
        //deep loggin
        $this->log('CANCELLED ORDER', $order_id);
        $order->add_order_note(__('RETURNADO_CANCEL',RTND));
		return true;
	}

	/**
	 * Approving preliminary order and updating it's status
	 *
	 * @param $order_id
	 * @param $returnTotal
	 * @param $original_order
	 *
	 * @return bool
	 *
	 * compatible to wc 3.0
	 *
	 */
	protected function approve_booked_order($order_id, $returnTotal, $original_order){
		$order = wc_get_order( $order_id );

		if (!$order) return false;

		global $RTNDo; $wc30 = $RTNDo->wc_version();

		$oid = ($wc30?$order->get_id():$order->id);

		update_post_meta( $oid, '_originalOrder', $original_order );

		if($order->get_status() == 'returnawait'){
			if ( 'yes' === $this->options['rtnd_update_stock'] ) $wc30?wc_reduce_stock_levels($oid):$order->reduce_order_stock();
			$order->update_status( 'exchanged' );
		}else{
			$order->update_status( 'completed' );
		}
		$order->add_order_note(__('RETURNADO_APPROVE [ORDER',RTND).': '.$original_order.']');

        //deep loggin
        $this->log(sprintf('ORDER %s APPROVED FOR %s', $order_id, $original_order), true);

		global $RTND_Collector, $RTND_Sender;

		//sync to Returnado
		if ($this->options['rtnd_sync_enabled'] === 'yes') $RTND_Sender->returnado_send('orders',$RTND_Collector->get_order($oid));
		return true;
	}


	/**
	 * Preliminary approval of booking order
	 *
	 * @param $order_id
	 * @param $return_total
	 * @param $original_order
	 * @param|@return $couponInfo
	 * @param|@return $useCredit
	 *
	 * @return bool
	 *
	 * compatible to WC 3.0
	 */
	protected function preapprove_booked_order( $order_id, $return_total, $original_order, &$coupon_info, &$use_credit ){

		$order = wc_get_order( $order_id );

		if (!$order) return false;
		if (!$return_total || $return_total<0) return true;

		if($use_credit)
			if(!$this->add_order_discount( $order, $return_total, '_rtnd', $coupon_info, $use_credit )) return false;

		update_post_meta( (method_exists($order,'get_id')?$order->get_id():$order->id), '_originalOrder', $original_order );

		if($order->get_total()>0.4)
			$order->update_status( 'pending' );
		else
			$order->update_status( 'exchanged' );

		$order->add_order_note(__('RETURNADO_PRE_APPROVE',RTND));

        //deep loggin
        $this->log(sprintf('ORDER %s PRE-APPROVED FOR %s', $order_id, $original_order), true);

		return true;
	}


	/**
	 * Check if item is in item list
	 *
	 * @param $haystack
	 * @param $needle
	 *
	 * @return int|string
	 */
	protected function check_item($haystack,$needle){
		foreach($haystack as $item_id=>$item)
			if ($item['productVariantId'] == $needle['productVariantId']) return $item_id;
		return -1;
	}

	/**
	 * Retrieve tax rate for product by product id
	 *
	 * @param $product_id
	 * @param $result
	 *
	 * @return bool
	 */
	private function get_product_vat_rate($product_id, &$result){
		$product = wc_get_product($product_id);
		if(!$product) return false;
		if(!$product->is_taxable()) return false;
		$rates = WC_Tax::get_rates($product->get_tax_class());
		if (empty($rates)) return false;
		$result = 0;
			foreach ( $rates as $key => $rate ) $result+=$rate['rate'];
		return true;
	}

	/**
	 * Get tax for order item
     *
     * !!!WARNING!!! Rounding is set as in woocommerce!
	 *
	 * @param float $current_tax
	 * @param float $new_price
	 * @param int $order_item
	 * @param int $order_item_id
	 * @param float $vat_rate
     * @param object $order
	 *
	 */
	private function calculate_tax_for_order_item( &$current_tax,
                                                   $new_price,
                                                   $order_item,
                                                   $order_item_id,
                                                   $vat_rate = 0.0,
                                                   $order ){
		if(empty($current_tax))
			$current_tax = array();
		//get tax rates from order item
		//WC < 3.0 compatibility
		if(empty($order_item['taxes']))
			$order_item['taxes'] = wc_get_order_item_meta( $order_item_id, '_line_tax_data', true );
		if(!empty($order_item['taxes'])){
			foreach($order_item['taxes']['total'] as $tax_row_id=>$tax_row_value){
				if($tax_row_value){
					if(empty($current_tax[$tax_row_id])) $current_tax[$tax_row_id] = 0;
					if($vat_rate)
						$current_tax[$tax_row_id] += wc_format_decimal( $vat_rate*$new_price, "" );
					else
						$current_tax[$tax_row_id] += wc_format_decimal(
						    round( $tax_row_value / ( method_exists($order_item, 'get_total')
                                                        ? $order_item->get_total()
                                                        : $order_item['line_total']
                                                    ), WOOMINPRECISION ) * $new_price, "" );
					//Since 0.4.7.231
					//Check if line tax total is less than calculated tax total - equalize it
                    $tax_remaining = $order_item->get_total_tax() - $order->get_tax_refunded_for_item( $order_item_id, $tax_row_id );
					if( $current_tax[$tax_row_id] >= wc_format_decimal( $tax_remaining, "" ) )
					    $current_tax[$tax_row_id] = wc_format_decimal( $tax_remaining, "" );
				}
				if( !isset($current_tax[$tax_row_id]) || !($current_tax[$tax_row_id]>0) )
					$current_tax[$tax_row_id] = '0.000000';
			}
		}
	}

	/**
	 *
	 * Restock items from order and save item meta
	 *
	 * @param $items
	 * @param $order
	 * @param $refund_id
	 *
	 * @return bool
	 */
	protected function restock_items( $items, $order, $refund_id ){
		if(!$order) return false;

		$order_items = $order->get_items();

		$is_restock = (get_option('woocommerce_manage_stock') == 'yes' && sizeof( $order_items ) > 0);

		//RESTOCK ITEMS & SAVE META
		foreach($items as $item){

			$id = (int)$item['orderId'];
			if(!$id)
				$id = $this->get_order_item_by_product_id( $order_items, (int)$item['productVariantId'] );

			if(!$id) continue; //impossible to identify the item for restock

			if (            $is_restock
                        &&  isset($item['nextAction']) && strtolower($item['nextAction'])==='restock'
			            &&  (strtolower($item['status'])=='arrived'
		                &&  $item['deleted'] !== 'true'
		                &&  $item['deleted'] !== true)
                )
				$this->restock_item($order, (int)$item['productVariantId'], 1, $order_items[ $id ] );

			$item_meta = wc_get_order_item_meta( $id, 'Returnado [#'.$refund_id.']', true);
			if (empty($item_meta)) $item_meta = "[1] : ";
			else $item_meta .= "\r\n".'['.(substr_count($item_meta,';')+1).'] : ';
			if(isset($item['status'])) 		$item_meta .= 'Status: '.strtolower($item['status']);
			if(isset($item['condition'])) 	$item_meta .= ' | Condition: '.strtolower($item['condition']);
			if(isset($item['nextAction'])) 	$item_meta .= ' | Next action: '.strtolower($item['nextAction']);
			$item_meta .= ";";
			wc_update_order_item_meta( $id, 'Returnado [#'.$refund_id.']', $item_meta );
		}
		return true;
	}

	/**
	 * Calls payment gateway's automatic refund
	 *
	 * @param $order
	 * @param $refund
	 * @param $total_refund
	 * @param $refund_reason
	 *
	 * @return bool
	 *
	 * compatible to wc 3.0
	 */
	protected function do_API_refund( $order, $refund, $total_refund, $refund_reason ){

        //deep loggin
        $this->log(sprintf('API REFUND STARTED FOR ORDER %s', $order->get_id()), true);

        $this->log('>>>> API REFUND AMOUNT', $total_refund);
        $this->log('>>>> API REFUND REASON', $refund_reason);

		global $RTNDo; $wc30 = $RTNDo->wc_version();
		$oid = ($wc30?$order->get_id():$order->id);

		if ( 'yes' === $this->options['rtnd_api_refund'] ) {

            $this->log('>>>> API REFUND IS ENABLED', true);

			//setting order status to completed for Klarna
			$pst = get_post_status( $oid );
			$update_post_data  = array(
				'ID'          => $oid,
				'post_status' => 'wc-completed'
			);
			wp_update_post( $update_post_data );

			$result = true;

			///-------------------------------------------

			$order_payment_method = ($wc30?$order->get_payment_method():$order->payment_method);

            $this->log('>>>> API REFUND ORDER PAYMENT METHOD ID', $order_payment_method);

			if ( WC()->payment_gateways() ) {
				$payment_gateways = WC()->payment_gateways->payment_gateways();
			}
			if ( isset( $payment_gateways[ $order_payment_method ] ) && $payment_gateways[ $order_payment_method ]->supports( 'refunds' ) ) {

                $this->log('>>>> LAUNCHING API GATEWAY REFUND FOR ORDER', $oid);

				$result = $payment_gateways[ $order_payment_method ]->process_refund( $oid, $total_refund, $refund_reason );

                $this->log('>>>> GATEWAY API REFUND RETURNED', $result);

				do_action( 'woocommerce_refund_processed', $refund, $result );

				if ( is_wp_error( $result ) ) {
					$order->add_order_note(__('RETURNADO: Refund API failure',RTND). ': ['.$result->get_error_message().']');
                    $this->log('>>>> GATEWAY API REFUND FAILURED', $result->get_error_message());
                    update_post_meta( $oid, '_rtnd_refund_failure', '1');
				} elseif ( ! $result ) {
					$order->add_order_note(__('RETURNADO: Refund API general failure',RTND));
                    $this->log('>>>> GATEWAY API REFUND FAILURED TOTALLY', false);
                    update_post_meta( $oid, '_rtnd_refund_failure', '2');
				}
			} else  {
                $order->add_order_note(__('RETURNADO: Refund API is not supported by order gateway',RTND));
			    $this->log('>>>> API REFUND IS NOT SUPPORTED', $refund_reason);
                update_post_meta( $oid, '_rtnd_refund_failure', '3');
            }
			//restoring order status to completed for Klarna
			$update_post_data  = array(
				'ID'          => $oid,
				'post_status' => $pst
			);
			wp_update_post( $update_post_data );
			///-------------------------------------------
            ///
            $this->log('API REFUND IS COMPLETED', true);
			return $result;

		} else {
		    $order->add_order_note(__('RETURNADO: Automatic refund is disabled',RTND));
            update_post_meta( $oid, '_rtnd_refund_failure', '4');
            $this->log('>>>> API REFUND IS DISABLED', true);
        }
        $this->log('API REFUND IS COMPLETED', true);
		return false;
	}

	/*
	 * Reduce refund line items for order virtual negative line items
	 *
	 * @param | @return $line_items array()
	 * @param $order_items
	 *
	 */

	protected static function reduce_refund( &$line_items, $order_items ){
		if( empty( $order_items ) || empty( $line_items ) ) return;
		$reduce_amount = 0;
		foreach( $order_items as $order_item_id=>$order_item ){
			$order_item_total = $order_item->get_total();
			$reduce_amount+=($order_item_total<0?$order_item_total:0);
		}
		$count = count($line_items);
		$current_reduce = abs( $reduce_amount / $count );
		if($reduce_amount<0){
			foreach($line_items as $li_id=>$li){
				$li_taxes = 0;
				if(isset($li['refund_tax']))
					$li_taxes = array_sum($li['refund_tax']);
				$line_item_total = $li['refund_total'] + $li_taxes;
				$vat_rate = 0;
				if($line_item_total)
					$vat_rate = round( $li_taxes / $line_item_total, RTNDPRECISION );
				if( $current_reduce > $line_item_total ){
					$current_reduce += $current_reduce - $line_item_total;
					$line_items[$li_id]['refund_total'] = 0;
					if(isset($li['refund_tax']))
						foreach($li['refund_tax'] as $i=>$value) $line_items[$li_id]['refund_tax'][$i] = 0;
				}else{
					$reduce_refund_tax = round( $current_reduce * $vat_rate, RTNDPRECISION );
					$reduce_refund_total = $current_reduce - $reduce_refund_tax;
					$line_items[$li_id]['refund_total'] = $line_items[$li_id]['refund_total'] - $reduce_refund_total;
					if(isset($li['refund_tax'])) {
						$count_taxes = 0;
						foreach ( $line_items[ $li_id ]['refund_tax'] as $tax_id => $tax_value ) {
							if ( $tax_value ) {
								$count_taxes ++;
							}
						}
						$current_reduce_tax = $reduce_refund_tax / $count_taxes;
						foreach ( $line_items[ $li_id ]['refund_tax'] as $tax_id => $tax_value ) {
							if ( $tax_value ) {
								if ( $current_reduce_tax > $tax_value ) {
									$current_reduce_tax                            += $current_reduce_tax - $tax_value;
									$line_items[ $li_id ]['refund_tax'][ $tax_id ] = 0;
								} else {
									$line_items[ $li_id ]['refund_tax'][ $tax_id ] = $tax_value - $current_reduce_tax;
								}
							}
						}
					}
				}
			}
		}
	}

	/*
	 * Get order item id by product id (the first one)
	 *
	 * @param $order_items
	 * @param $product_id
	 */

	private function get_order_item_by_product_id( $order_items, $product_id ){
		if( empty($order_items) ) return;
		foreach ( $order_items as $item_id=>$item )
			if ( $product_id === $item->get_product_id() || $product_id === $item->get_variation_id() ) return $item_id;
		return 0;
	}

	/*
	 * Check if order items were totally refunded (for setting refunded status to the order)
	 *
	 * @param $order WC_Order
	 *
	 * @return bool|void
	 */

	private function check_order_for_refunded( &$order ){
		if( empty( $order ) ) return 0;
		foreach( $order->get_items() as $item_id=>$item ){
			if( $item->get_total() > 0 && abs( $order->get_qty_refunded_for_item( $item_id ) ) < $item->get_quantity() ) return false;
		}
        wp_update_post( array(
            'ID'          => $order->get_id(),
            'post_status' => 'wc-refunded'
        ) );
	}

	/**
	 * Refund received items in the order
	 *
	 * @param array $data
	 * @param WC_Order $order
	 * @param float $total_refund
	 * @param bool $use_credit
	 * @param array $coupon_info
	 * @param int $mark_items
	 * @param float $reconversion_order_total
	 *
	 * @return bool
	 *
	 * compatible to wc 3.0
	 */
	protected function refund_items( $data, $order,
                                     $total_refund,
                                     $use_credit,
                                     &$coupon_info,
                                     $mark_items = 0,
                                     $reconversion_order_total = 0.0 ){

		global $RTNDo; $wc30 = $RTNDo->wc_version();
		$oid = ($wc30?$order->get_id():$order->id);

		//deep loggin
        $this->log('REFUND ITEMS FOR ORDER', $oid);

		$tmp_order_total = 0;
		$order_status = '';
		$coupon_info = null;

		$items = (isset($data['items'])?$data['items']:null);

		if(!$items) return 'ERROR: Items data is empty';

		if( $order->get_total() <= 0 || $use_credit ) {
			//fake order total to be 0 or total refunded for creating WC refund object
			//because WC checks total refund amount >= order remain for refund
			$tmp_order_total = $order->get_total();
			$order_status = get_post_status( $oid );
			update_post_meta( $oid, '_order_total', $order->get_total_refunded() );
			$order->set_total( $order->get_total_refunded() );
			$total_refund = 0;
		}

		$order_items = $order->get_items();

		//PREPARE LINE ITEMS
		$line_items = array();
		$reasons = array();

		if( $items )
			foreach( $items as $item ){

				if(    isset($item['status']) && !in_array(strtolower($item['status']), ['arrived', 'rejected'])
                    || isset($item['deleted']) && ( $item['deleted'] === true || $item['deleted'] === 'true' ) ) {

                    continue; //nothing to refund

                }

                $i = (int)$item['orderId'];
                if(!$i)
                    $i = $this->get_order_item_by_product_id( $order_items, (int)$item['productVariantId'] );

                if(!$i) continue; //impossible to identify the item for refund

                if(!isset($line_items[ $i ]['refund_tax']))
                    $line_items[ $i ]['refund_tax'] = [];

                $this->calculate_tax_for_order_item( $line_items[ $i ]['refund_tax'], (float) $item['diminishedPrice'],
                    $order_items[ $i ],
                    $i,
                    isset( $item['vatRate'] ) ? $item['vatRate'] : 0,
                    $order
                );

                $c_refund = wc_format_decimal( (float)$item['diminishedPrice'], "" );
                $line_items[$i]['qty'] 			= (isset($line_items[$i]['qty'])?$line_items[$i]['qty']+1:1);
                $line_items[$i]['refund_total'] = (isset($line_items[$i]['refund_total'])?$line_items[$i]['refund_total']+$c_refund:$c_refund);
                $img = "";
                if(isset($item['reclamationImagePath'])){
                    $img = ' | '.__('reclamation image',RTND).': '.sanitize_text_field($item['reclamationImagePath']);
                }
                $reasons[]='ID['.sanitize_text_field( $item['productVariantId'] ).'] '
                                .sanitize_text_field( isset($item['reclamationReason'])?$item['reclamationReason']:'' ).': '
                                .sanitize_text_field( isset($item['reclamationReasonDetail'])?$item['reclamationReasonDetail']:'' )
                                .' (comment: '.sanitize_text_field( isset($item['comment'])?$item['comment']:'' )
                                .' | manager_comment: '.sanitize_text_field(isset($item['managerComment'])?$item['managerComment']:'').$img.')';

            }


		$refund_reason = implode(', ',$reasons);

		//REFUND
		// Create the refund object

		//disabling hooks (for security purposes)
		if($tmp_order_total != 0 || $use_credit || $mark_items) {
			remove_all_actions( 'woocommerce_order_refunded' );
		}

		add_filter( 'woocommerce_order_fully_refunded_status', function(){ return false; } );

		$refund = null;

        try{
            RTND_API_Error_Handler::create_refund_no_exceptions(
                $refund,
                [ $oid, $total_refund, $line_items, $refund_reason ],
                [$this, 'log']
            );
        }
        catch ( Exception $e ){
            return $e->getMessage();
        }

        //deep loggin
        $this->log('REFUNDING DATA',
            'REFUND AMOUNT: ' . $total_refund . ' | '.
            'ORDER ID:' . $oid . ' | '.
            'LINE ITEMS: ' . str_replace( '  ', '', str_replace("\n",' ',var_export( $line_items, 1 ) ) ) );

        $this->log('RESULT REFUND OBJECT FOR ORDER '.$oid, str_replace( '  ', '', str_replace("\n",' ',var_export( $refund, 1 ) ) ) );

		if(!$refund) {
            //deep loggin
            $this->log('REFUND FAILED FOR ORDER' . $oid, 'REFUND OBJECT RETURNED AS FALSE');
		    return 'ERROR: Refund object could not be created (returned false)';
        }

        $err_logs = '';

        if ( is_wp_error( $refund ) ) {
            try{
                RTND_API_Error_Handler::handle_wp_error( $refund,
                    [$oid, $total_refund, $line_items, $refund_reason],
                    [$this, 'log'] );
            }
            catch ( Exception $e ){
                return $e->getMessage();
            }
        }

		$refundID = ( $wc30 ? $refund->get_id() : $refund->id );

		if  ( !$use_credit && ($total_refund > 0.0) ) {
		    //Get actual total refund from items
            //Change since 0.4.7.231
            $new_total_refund = $this->get_return_total( $items );
            if($new_total_refund>$total_refund) // --- here we do it in case 2,98*2 = 7,45 :)
                $new_total_refund = $total_refund;
			$API_refund_result = $this->do_API_refund( $order, $refund, $new_total_refund, $refund_reason );
			if($API_refund_result && $reconversion_order_total){
				$reconversionOrder = wc_get_order( $data['reconversionOrderId'] );
				if($reconversionOrder)
					$this->set_virtual_product_price( $reconversionOrder, -1*$reconversion_order_total, $data['currency'], $data['items'] );
			}
			if(!$API_refund_result) $refundID = 'WARNING: Refund is created, but not processed with API (Gateway API failure)';
		}

		$current_currency = (isset($data['currency']) ? $data['currency'] : get_option( 'woocommerce_currency' ));

		if($use_credit){
			$coupon_info = $this->create_coupon( (float)$data['newGift'], $current_currency, ($wc30?$order->get_customer_id():$order->customer_user) );
			update_post_meta( $refundID, '_assigned_coupon', $coupon_info['coupon']['id'] );
			update_post_meta( $refundID, '_assigned_coupon_update_amount', (float)$data['newGift'] );
			$order->add_order_note( __('RETURNADO_STORE_CREDIT',RTND) . ' ' . $data['newGift'] );
            $this->log('NEW COUPON CREATED/UPDATED', $coupon_info);
		}

        //if the order totals were faked - we do not make the order refunded - we restore it's status back
		if( $tmp_order_total != 0 ){
            update_post_meta( $oid, '_order_total', $tmp_order_total );
			$order->set_total( $tmp_order_total );
			wp_update_post( array(
				'ID'          => $oid,
				'post_status' => $order_status
			) );
			update_post_meta( $oid, '_order_total', $tmp_order_total );
		}

		if($mark_items)
			$order->add_order_note( __('RETURNADO_EXCHANGE_#',RTND) . $refundID );

		//check if order was totally refunded
		$this->check_order_for_refunded( $order );

		if($wc30)
			@$order->save(); //safe call

		$order->add_order_note(__('RETURNADO_REFUND_COMPLETED',RTND));

        $this->log('RETURNADO_REFUND_COMPLETED', ['coupon_info' => $coupon_info]);

		return $refundID;
	}


	/**
	 *
	 * Complete order refund
	 *
	 * @param $order_id
	 *
	 * @return bool
	 *
	 * compatible to wc 3.0
	 */
	protected function total_order_refund($order_id){
		$order = wc_get_order($order_id);
		if(!$order) return;
		$order_post = get_post($order_id);
		if(!$order_post) return;
		if($order_post->post_status!='wc-completed') return;

        //deep loggin
        $this->log('TOTAL ORDER REFUND STARTED', $order_id);

		global $RTNDo; $wc30 = $RTNDo->wc_version();
		//RESTOCK
		if ( 'yes' === $this->options['rtnd_update_stock'] ){
			if ( get_option('woocommerce_manage_stock') == 'yes' && sizeof( $order->get_items() ) > 0 ) {
				foreach ( $order->get_items() as $item ) {
					if ( $item['product_id'] > 0 ) {
						$_product = ($wc30?$item->get_product():$order->get_product_from_item( $item ));
						if ( $_product && $_product->exists() && $_product->managing_stock() ) {
							$old_stock = ($wc30?$_product->get_stock_quantity():$_product->stock);
							$qty = apply_filters( 'woocommerce_order_item_quantity', $item['qty'], $this, $item );
							$new_quantity = ($wc30?wc_update_product_stock( $_product, $qty, 'increase' ):$_product->increase_stock( $qty ));
                            $this->log('ITEM STOCK UPDATED', ['product_id'=>$_product->get_id(), 'qty' => $new_quantity]);
							do_action('woocommerce_product_set_stock',$_product);
							do_action( 'woocommerce_auto_stock_restored', $_product, $item );
							$order->add_order_note( sprintf( __( 'Item #%s stock incremented from %s to %s.', 'woocommerce' ),
															$item['product_id'],
															$old_stock,
															$new_quantity) );
							if (!$wc30) $order->send_stock_notifications( $_product, $new_quantity, $item['qty'] );
						}
					}
				}
			}else $order->add_order_note(__('Items were not restocked (stock management not enabled)',RTND));
		}

		//CREATE_TOTAL_REFUND (no partial refund is required)
		$total_refund  = $order->get_total();
		$refund_reason = __('Returnado cancellation',RTND);
		$refund = wc_create_refund(array(
										'amount' => wc_format_decimal( $total_refund, "" ),
										'reason' => $refund_reason,
										'order_id' => $order_id,
										'line_items' => []
										)
								);

        $this->log('REFUND OBJECT', str_replace( '  ', '', str_replace("\n",' ',var_export( $refund, 1 ) ) ) );

		if (!$refund || is_wp_error($refund)) {
            $this->log('REFUND FAILED FOR ORDER', $order_id);
		    return false;
        }

		//REFUND VIA PAYMENT GATEWAY
		$this->do_API_refund($order, $refund, $total_refund, $refund_reason);

		//CHANGE STATUS		
		$order->update_status('refunded');

		//ADD NOTE
		$order->add_order_note(__('RETURNADO_REFUND_COMPLETED',RTND));

        $this->log('REFUND COMPLETED FOR ORDER', $order_id);

		return true;
	}

	/**
	 * Helper function. Generates code for Coupon
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	public function generate_random_string($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}


	/**
	 *
	 * Create coupon if it is not exists yet for a defined user or updates existing one
	 *
	 * @param int $amount
	 * @param int $user_id
	 * @param string $postfix_code
	 * @param string $free_shipping
	 * @param string $coupon_info
	 *
	 * @return array
	 */
	public function create_coupon( $amount = 0, $currency = '', $user_id = 0, $postfix_code = '_rtnd', $free_shipping = 'yes', $coupon_info = '' ) {

		if ($amount < 0 || !$user_id) return;

		//check currency and convert amount
		$def_currency = get_option('woocommerce_currency');
		if($currency!==$def_currency){ //convert
			$amount = $amount * $this->rates['rates'][$currency];
		}

        //check if user has coupon already
		global $RTND_Collector;
        $user_coupon_data = $RTND_Collector->get_coupon_details( $user_id );

        $user = get_user_by('id', $user_id);
        if (!$user) return;
        $user_email = $user->user_email;

        if ($user_coupon_data) {
        	$user_coupon = $user_coupon_data['coupon']['id'];
        	//user coupon exists - updating
            $old_amount = get_post_meta($user_coupon, 'coupon_amount', true);
            $new_amount = $old_amount + $amount;
            update_post_meta($user_coupon, 'coupon_amount', $new_amount);
            $coupon_code = get_post($user_coupon)->post_name;
            $new_coupon_id = $user_coupon;

        }else{

            //no user coupon - creating new
            $new_amount = $amount;
            $coupon_code = $this->generate_random_string() . $postfix_code; // Code
            $userdata = get_userdata( $user_id );
            $user_link = '<a href="'. get_edit_user_link( $user_id ) .'">'. esc_attr( $userdata->user_nicename ) .'</a>';
            if(empty($coupon_info)) $coupon_info = sprintf(__("Returnado coupon for user [%s]", RTND), $user_link);
            $discount_type = 'fixed_cart'; // Type: fixed_cart, percent, fixed_product, percent_product
            $coupon = array(
                'post_title' => $coupon_code,
                'post_name' => $coupon_code,
                'post_content' => '',
                'post_excerpt' => $coupon_info,
                'post_status' => 'publish',
                'post_author' => $user_id,
                'post_type' => 'shop_coupon'
            );

            $new_coupon_id = wp_insert_post($coupon);

            if(!$new_coupon_id) return false;

            //assign coupon to the user
            update_user_meta($user_id,'_returnado_coupon',$new_coupon_id);

            // Add meta
            update_post_meta($new_coupon_id, 'discount_type', $discount_type);
            update_post_meta($new_coupon_id, 'coupon_amount', $amount);
            update_post_meta($new_coupon_id, 'individual_use', 'yes');
            //update_post_meta($new_coupon_id, 'customer_email', $user_email); removed restrictions since 0.4.5
            update_post_meta($new_coupon_id, 'rtnd_customer', $user_id);
            update_post_meta($new_coupon_id, 'product_ids', '');
            update_post_meta($new_coupon_id, 'exclude_product_ids', '');
            update_post_meta($new_coupon_id, 'usage_limit', '');
            update_post_meta($new_coupon_id, 'expiry_date', '');
            update_post_meta($new_coupon_id, 'apply_before_tax', 'yes');
            update_post_meta($new_coupon_id, 'free_shipping', $free_shipping);
        }
		//Send Email - disabled
       /*ob_start(); //block email body output
            $ml = WC()->mailer();
            $data = [
                'email'     => $user_email,
                'recipient' => $user_email,
                'amount'    => $new_amount,
                'code'      => $coupon_code
            ];

           apply_filters('rtnd_email_coupon_notify', $data);
        ob_clean();
        */
		return [    'coupon'    =>  [
		                                'id'            =>  $new_coupon_id,
										'customerId'	=>	$user_id,
                                        'code'          =>  $coupon_code,
                                        'value'         =>  $new_amount
                                    ]
                ];
	}

	/*
	 * Reduce coupon info on fail
	 *
	 * @param $reduce_value
	 * @param $coupon_info
	 *
	 */

	protected static function reduce_coupon( $reduce_value, &$coupon_info ){

		if(!$coupon_info || !$reduce_value || !isset($coupon_info['coupon']['id'])) return;

		$coupon_id = $coupon_info['coupon']['id'];
		$current_coupon_amount = get_post_meta( $coupon_id, 'coupon_amount', true );
		$new_coupon_amount = $current_coupon_amount - $reduce_value;
		if($new_coupon_amount >= 0)
			update_post_meta( $coupon_id, 'coupon_amount', $new_coupon_amount );

		$coupon_info = null;

	}

    /**
     * Check and save extra difference on refund value
     *
     * Since 0.4.7.231
     *
     * @param $order_id
     * @param $value
     * @param string $type
     * @return float
     */
	protected function check_refund_extra( $order_id, $value, $type = 'price' ){
	    $precision = ( isset($this->options['rtnd_min_precision'])?$this->options['rtnd_min_precision']:WOOMINPRECISION );
	    $precision_index = 5*pow(10,$precision+1);
	    $rem = get_post_meta( $order_id, '_refund_'.$type.'_rem', true );
	    if(!$rem) $rem = 0;
        $value_down = round( $value, $precision, PHP_ROUND_HALF_DOWN );
        $new_rem = 0;
        if( $value > ($value_down + $precision_index) )
            $new_rem = $value - ($value_down + $precision_index);
        elseif( 'tax' === $type )
            $new_rem = $value - $value_down;
        update_post_meta( $order_id, '_round_' . $type . '_rem', $new_rem );
        return round( $value+$rem, $precision );
    }

	/**
	 *
	 * Retrieves ReturnTotal amount from received items
	 *
     * Changed since 0.4.7.231 (check_refund_extra)
     *
	 * @param $items
     * @param int $order_id
	 *
	 * @return float|string
	 */
	protected function get_return_total( $items, $order_id = 0 ){
		$ttl_p = 0; $ttl_t = 0;
		foreach($items as $item)
			if (strtolower($item['status'])==='arrived' && $item['deleted'] !== 'true' && $item['deleted'] !== true) {
		        $return_price = (float)$item['diminishedPrice'];
                $ttl_p += $this->check_refund_extra( $order_id, $return_price );
                $ttl_t += $return_price * (float)$item['vatRate'];
            }
        return $ttl_p + $this->check_refund_extra( $order_id, $ttl_t, 'tax' );
	}


	/**
	 *
	 * Retrieves order total excluding refunded items and shipping
	 * Retrieves order paid amount
	 * Checks if order was paid
	 *
	 * @param $order_id
	 * @param int $result
	 * @param int $paid_amount
	 * @param int $exclude_shipping
	 *
	 * @return $result
	 * @return $paidAmount
	 * @return bool
	 */
	private function get_order_total__($order_id, &$result = 0, &$paid_amount = 0, $exclude_shipping = 0){
		if(!$paid_amount) $paid_amount = 0;
		$order = wc_get_order($order_id);
		if(!$order) return false;
		$result = wc_format_decimal($order->get_total() - $order->get_total_refunded() - $exclude_shipping*$order->get_total_shipping(), RTNDPRECISION);
		if ($order->has_status(['completed','processing'])) $paid_amount = $result;
		return $result>=0;
	}

	/**
	 *
	 * Retrieves pure order total without returnado items excluding refunded item amount and shipping
	 * Retrieves order paid amount
	 *
	 * @param int $order_id
	 * @param float $prepaid
	 *
	 * @return float $res
	 */
	private function get_reconversion_total( $order_id, &$prepaid = 0.0 ){
		if(!isset($prepaid) || !$prepaid) $prepaid = 0;
		$order = wc_get_order( $order_id );
		if(!$order) return 0;
		$res = 0;
		foreach( $order->get_items() as $item_id=>$item ){
			if( $item->get_total() > 0
			    && !wc_get_order_item_meta( $item_id, 'ReturnTax', true )
			    && ( $item->get_quantity() - $order->get_qty_refunded_for_item( $item_id ) ) > 0 ) {
				$res += $item->get_total() + $item->get_total_tax() - $order->get_total_refunded_for_item( $item_id );
			}
		}
		if ($order->has_status(['completed','processing']))
			$prepaid = wc_format_decimal($order->get_total() - $order->get_total_refunded() - $order->get_total_shipping(), RTNDPRECISION);
		return $res;
	}


	/**
	 *
	 * Spread prices over Returnado precision and diminish rate
	 *
	 * @param array $items
	 * @param float $_refund  // $_refund = $returnTotal - $reconversionTotal
	 * @param float $_return_total
	 *
	 * @return float|string
	 */
	private function diminish_prices( &$items = [], $_refund = 0.0, $_return_total = 0.0, $order_id = 0 ){
		if(empty($items)) return $_return_total;
		$dRate = $_refund / $_return_total;
		$m = $items[0]['diminishedPrice'];
		$m_id = 0;
        $this->log('DIMINISH PRICES STARTED', true);
        $this->log('>>> DIMINISH PRICES ITEMS BEFORE', $items);
		foreach($items as $item_id=>$item){
		    //store original
            $or_price = $items[$item_id]['diminishedPrice'];
			//spreading
			$items[$item_id]['diminishedPrice'] = wc_format_decimal($item['diminishedPrice'] * $dRate, RTNDPRECISION);
			//storing if initial was more than new
			if ($m>$item['diminishedPrice']) { $m_id = $item_id; $m = $item['diminishedPrice']; }
			//storing for setting as negative item
			$items[$item_id]['loweredPrice'] = wc_format_decimal( $or_price - $items[$item_id]['diminishedPrice'], RTNDPRECISION );
		}
		$_return_total = $this->get_return_total( $items, $order_id );
		if (wc_format_decimal($_refund - $_return_total,RTNDPRECISION) >= 0.01){
			//adding to stored one
			$items[$m_id]['diminishedPrice'] = wc_format_decimal( $items[$m_id]['diminishedPrice'] + 0.01, RTNDPRECISION);
            $items[$m_id]['loweredPrice'] =  wc_format_decimal( $items[$m_id]['loweredPrice'] - 0.01, RTNDPRECISION);
			return $this->get_return_total( $items, $order_id );
		}
        $this->log('>>> DIMINISH PRICES ITEMS AFTER', $items);
        $this->log('DIMINISH PRICES COMPLETED', true);
		return $_return_total;
	}


	/**
	 * If negatively priced item is kept as virtual product, we update it's price and re-calculate order totals
	 *
	 * @param $order
	 * @param $price
	 * @param string $currency
	 * @param array $items
	 *
	 * @return bool
	 *
	 * compatible to wc 3.0
	 */
	private function set_virtual_product_price( $order, $price, $currency = '', $items = [] ){

		global $RTNDo; $wc30 = $RTNDo->wc_version();

		$order_currency = ($wc30?$order->get_currency():$order->order_currency);
		$virt_item = $this->options['rtnd_virt_item'];
		if(!$virt_item) $virt_item = 2; //default - virtual product
		if(!$currency) $currency = $order_currency;

		if($currency !== $order_currency){ //converting into order currency
			$price = $this->convert_currency( $price, $currency, $order_currency );
			//converting items prices to order currency
			if(!empty($items) && $virt_item == 1){
				foreach($items as $ind=>$d_item){
					$item_price = $d_item['diminishedPrice'];
					$items[$ind]['diminishedPrice']	= $this->convert_currency( $items[$ind]['diminishedPrice'], $currency, $order_currency );
				}
			}
		}


		if($virt_item == 1){ //original items negatively priced

			if (empty($items)) return false;
			foreach($items as $d_item)
				foreach($order->get_items() as $item_id=>$item) {
					if (
						($d_item['productVariantId'] == $item['variation_id'] ||
						 $d_item['productVariantId'] == $item['product_id']) &&
						 isset($item['ReturnOrder'])
						){
							$item_price = isset($d_item['loweredPrice'])?$d_item['loweredPrice']:$d_item['diminishedPrice'];
							$item_tax = abs( wc_format_decimal( $item_price * $d_item['vatRate'], "" ) );
							wc_update_order_item_meta( $item_id, '_line_subtotal', -1*($item_price+$item_tax) );
							wc_update_order_item_meta( $item_id, '_line_total', -1*($item_price+$item_tax) );
							wc_update_order_item_meta( $item_id, 'ReturnTax', $item_tax );
							$item['line_subtotal'] = $item['line_total'] = -1*($item_price+$item_tax);
							wc_update_order_item_meta( $item_id, '_line_subtotal_tax', 0 );
							wc_update_order_item_meta( $item_id, '_line_tax', 0 );
							wc_update_order_item_meta( $item_id, '_line_tax_data', '' );
					}
				}

			$order->calculate_totals(1);
			if(method_exists($order,'save'))
				$order->save(); //WC 3.0

		}else{ //one virtual product

			foreach($order->get_items() as $item_id=>$item)
				if(isset($item['ReturnOrder'])){
					if(isset($item['ReturnTax']) && isset($item['ReturnVatRate'])){
						$vatRate = (float)$item['ReturnVatRate'];
						$item['ReturnTax'] = $item_tax = abs( wc_format_decimal( ( $price / ( 1 + $vatRate ) ) * $vatRate, "" ) );
						wc_update_order_item_meta( $item_id, 'ReturnTax', $item_tax );
					}
					update_post_meta( $item['product_id'], '_regular_price', $price );
					update_post_meta( $item['product_id'], '_price', $price );
					wc_update_order_item_meta( $item_id, '_line_subtotal', $price);
					wc_update_order_item_meta( $item_id, '_line_total', $price );
					wc_update_order_item_meta( $item_id, '_tax_class', 0 );
					wc_update_order_item_meta( $item_id, '_line_subtotal_tax', 0 );
					wc_update_order_item_meta( $item_id, '_line_tax', 0 );
					wc_update_order_item_meta( $item_id, '_line_tax_data', '' );
					$item['line_subtotal'] = $item['line_total'] = $price;
					$order->calculate_totals(0);
					if(method_exists($order,'save'))
						@$order->save(); //WC 3.0
					return true;
				}

		}
		return false;
	}


	/**
	 *
	 * Base for /returnorder EP
	 * Processing all received data from Returnado
	 *
	 *
	 * @param $data
	 *
	 * compatible to WC 3.0
	 */
	public function process_order( $data ){

        $this->log('PROCESSING INCOMING /RETURNORDER DATA STARTED', $data);

		if ( empty($data['orderId']) )
			return new WP_Error('INPUT_DATA',
								'Unprocessable Entity',
								array( 'status' => 422,
								       'info' => 'The "orderId" parameter is empty or not set'
								) );

		if (empty($data['items'])||!count($data['items'])>0)
			return new WP_Error('INPUT_DATA',
								'Unprocessable Entity',
								array( 'status' => 422,
								       'info' => 'The "items" parameter is empty or not set'
								) );

		$this->wpml(0);

		$order = wc_get_order( (int)$data['orderId'] );

		$order_post = get_post( (int)$data['orderId'] );

		if (!$order || !$order_post) {
            $this->log('PROCESSING FAILURE: ORDER DOES NOT EXIST', $data['orderId']);
			$this->wpml(1);
			return new WP_Error('ORDER_NOT_EXISTS',
								'Original data lost',
								array( 'status' => 422,
								       'info' => 'Original order #'.$data['orderId'].' could not be found'
								) );
		}

		//check WC version
		global $RTNDo; $wc30 = $RTNDo->wc_version();
		$order_id = ( $wc30 ? $order->get_id() : $order->id );

		//check order status (in case)
		if ( $order_post->post_status!='wc-exchanged' && $order_post->post_status!='wc-completed' ) {
			$this->wpml(1);
            $this->log('PROCESSING FAILURE: UNPROCESSABLE STATUS', $order_post->post_status);
			return new WP_Error('UNPROCESSABLE_STATUS',
								'Order status changed',
								array( 'status' => 422,
								       'info' => 'Original order #'.$order_post->ID.' status is not "completed" or "exchanged"'
								) );
		}

		//check items for refund quantity (since 0.4.4)
		if( !$this->validate_refund_items( $data, $error ) ) {
            $this->wpml(1);
            $this->log('PROCESSING FAILURE: ITEMS VALIDATION FAILED', $error);
            return new WP_Error('INPUT_DATA',
                'Unprocessable Entity',
                array('status' => 422,
                    'info' => 'Returning items were not validated with message: ' . $error
                ));
        }
		
		$new_gift = (isset($data['newGift'])?(float)$data['newGift']:0);

		$use_credit = ($new_gift>0 && 'yes' === $this->options['rtnd_use_coupons']);

		//check for guest order, patch if necessary
        if( $use_credit && !$order->get_customer_id() ){
            $dummy = 0;
            if(!class_exists('RTND_Patcher'))
                require_once "patcher.php";
            $order->set_customer_id( RTND_Patcher::patch_guest_order( $order->get_id(), $dummy ) );
            $this->log('PATCHING ORIGINAL GUEST ORDER', $order_id);
            if( !$order->get_customer_id() ){
                $this->wpml(1);
                $this->log('PROCESSING FAILURE: GUEST ORDER COULD NOT BE PATCHED', $error);
                return new WP_Error('PATCHING',
                    'Refund failure',
                    array('status' => 422,
                        'info' => 'Order information is probably insufficient to create a new user for store credit'
                    ));
            }
        }

		$current_currency = $data['currency'];

		$coupon_info = null;
		$refund_id = 0;

		$return_total = $this->get_return_total( $data['items'], $order_id );

		$refund_amount = $use_credit? $new_gift : $return_total;

		//check if refund amount is greater than available
        $remain_refund_amount = $order->get_remaining_refund_amount();
        if($refund_amount>$remain_refund_amount)
            $refund_amount = $remain_refund_amount;

		//if there is a reconversion order Id in the request body
		if (isset($data['reconversionOrderId'])){

			$reconversion_order = wc_get_order((int)$data['reconversionOrderId']);
			$reconversion_order_post = get_post( (int)$data['reconversionOrderId'] );
			if(!$reconversion_order) {
                $this->log('PROCESSING FAILURE: PRELIMINARY ORDER DOES NOT EXIST', $data['reconversionOrderId']);
				$this->wpml(1);
				return new WP_Error('ORDER_NOT_EXISTS',
									'Reconversion order lost',
									 array( 'status' => 422,
									        'info' => 'Reconversion order #'.$data['reconversionOrderId'].' was not found'
									 ) );
			}

			if ($data['reconversionOrderDeleted']=='true') {

				//CANCELLING RECONVERSION ORDER

				if($reconversion_order_post->post_status=='wc-returnawait'){ // < -- simple cancel for preliminary order

					if( !$this->cancel_order( (int)$data['reconversionOrderId']) ) {
                        $this->log('PROCESSING FAILURE: PRELIMINARY ORDER COULD NOT BE CANCELLED', $data['reconversionOrderId']);
						$this->wpml(1);
						return new WP_Error('ORDER_CANCELLATION',
											'Reconverion order fail',
											array( 'status' => 422,
													'info' => 'Reconversion order #'.$data['reconversionOrderId'].' cancellation failed'
												) );
					}

				}else if ($reconversion_order_post->post_status=='wc-completed'){ //< -- preliminary was paid so we make total refund

					if(!$this->total_order_refund( (int)$data['reconversionOrderId']) ) {
                        $this->log('PROCESSING FAILURE: PRELIMINARY ORDER COULD NOT BE REFUNDED', $data['reconversionOrderId']);
						$this->wpml(1);
						return new WP_Error('TOTAL_REFUND',
											'Fail on total refund',
											array( 'status' => 422,
													'info'  => 'Total reconversion order #'.$data['reconversionOrderId'].' refund failed'
												) );
					}

				}else $reconversion_order->add_order_note(__('RETURNADO_REQUIRED_CANCELLATION',RTND)); // < -- note for shop administrator

				//REFUND
                $refund_id = $this->refund_items( $data, $order, $refund_amount, $use_credit, $coupon_info );
				if( !(is_numeric($refund_id) ) ) {
                    $this->log('PROCESSING FAILURE: REFUND ITEMS FAILURE FOR ORDER', [ 'order_id' => $data['orderId'], 'data' => $data ]);
					$this->wpml(1);
					return new WP_Error(   'REFUND_FAIL',
						'Return items refund failed',
						array( 'status' => 422,
						       'info' => 'Refunding items for order #'.$data['orderId'].' failed on '
                                            .'preliminary order cancellation with message: ' . $refund_id
						) );
				};
					
			}else{ 
			
				//EXCHANGE ITEMS

				//updating the virtual items in the reconversion order according to the diminished prices received
				if( 'wc-returnawait' === $reconversion_order_post->post_status )
					$this->set_virtual_product_price( $reconversion_order, -1*$return_total, $current_currency, $data['items'] );

				//check if we already refunded to store credit
				$reconversion_total = $this->get_reconversion_total( (int) $data['reconversionOrderId'], $prepaid_amount );

				//APPROVING
				if ( $return_total + $prepaid_amount >= $reconversion_total ) {

                    //APPROVE
                    if ( ! $this->approve_booked_order( (int) $data['reconversionOrderId'], $return_total, $order_id ) ) {
                        $this->log('PROCESSING FAILURE: APPROVING FAILED', $data['reconversionOrderId']);
                        $this->wpml( 1 );
                        return new WP_Error( 'APPROVE',
                            'Approving failed',
                            array(
                                'status' => 422,
                                'info'   => 'Reconversion order #' . $data['reconversionOrderId'] . ' approving failed'
                            ) );
                    }

					//REFUND HANDLING

                    if ($use_credit) {
                        $refund_id = $this->refund_items($data, $order, $new_gift, $use_credit, $coupon_info, 1);

                    } else {
                        $difference = $reconversion_total - $return_total;
                        if ($difference < 0) {
                            $return_total = $this->diminish_prices( $data['items'], -1 * $difference, $return_total, $order_id );
                            $refund_id = $this->refund_items($data, $order, $return_total, $use_credit, $coupon_info, 0, $reconversion_total);
                        } else {
                            $refund_id = $this->refund_items($data, $order, 0, $use_credit, $coupon_info, 1);
                        }
                    }


					if( !is_numeric( $refund_id ) ) {
                        $this->log('PROCESSING FAILURE: REFUND FAILURE FOR ORDER ID', $data['orderId']);
                        $this->wpml(1);
                        return new WP_Error(   'REFUND_FAIL',
                            'Return items refund failed',
                            array( 'status' => 422,
                                'info' => 'Refunding items for order #'.$data['orderId'].' '
                                         .'failed with message: ' . $refund_id
                            ) );
                    };


				} else { // need more money - this situation should never happen

					//PARTIAL REFUND - NEW DIMINISHED PRICE AFTER PRELIMINARY WAS PAID - LEAVE A MESSAGE
					if( 'wc-completed' === $reconversion_order_post->post_status ){
						$difference = $reconversion_total - $return_total - $prepaid_amount;
						$reconversion_order->add_order_note('RETURNADO_RETURN: need more money '.wc_price( $difference ));
					}else

						//PREAPPROVE FOR NON_PAID PRELIMINARY USING RETURN TOTAL
						if ( ! $this->preapprove_booked_order( (int) $data['reconversionOrderId'], $return_total, $order_id, $coupon_info, $use_credit ) ) {
                            $this->log('PROCESSING FAILURE: PRE-APPROVE FAILURE FOR PRELIMINARY ORDER', $data['reconversionOrderId']);
							$this->wpml( 1 );
							return new WP_Error( 'PREAPPROVE',
								'Pre-approving failed',
								array(
									'status' => 422,
									'info'   => 'Preliminary approval failed for order #' . $data['reconversionOrderId']
								) );
						}

				}

			} //endif ($data['reconversionOrderDeleted']=='true')

		}else // no reconversion - just refund
			//REFUND
            $refund_id = $this->refund_items( $data, $order, $refund_amount, $use_credit, $coupon_info );
			if( !is_numeric($refund_id) ) {
                $this->log('PROCESSING FAILURE: REFUND FAILURE FOR ORDER ID', $data['orderId']);
				$this->wpml(1);
				return new WP_Error(   'REFUND_FAIL',
										'Return items refund failed',
										array( 'status' => 422,
										       'info' => 'Refunding items for order #'.$data['orderId'].' failed '
                                                        .'on basic return with message: ' . $refund_id
										) );
			}

		//RESTOCK
		if($refund_id)
			if( !$this->restock_items( $data['items'], $order, $refund_id )) {
                $this->log('PROCESSING FAILURE: RESTOCK FAILURE FOR ORDER ID', $data['orderId']);
				$this->wpml(1);
				return new WP_Error('RESTOCK',
									'Restock failed',
									array( 'status' => 422,
											'info' => 'Restocking items for order #'.$data['orderId'].' failed'
										) );
			}

		$order->add_order_note(__('RETURNADO_RETURN_COMPLETED',RTND));

        $this->log('PROCESSING COMPLETED FOR ORDER ID', $data['orderId']);

		$this->wpml(1);

		return $coupon_info;
	}

	/**
	 * Cancelling order creation in WC on errors
	 * 
	 * @param $id
	 * @param $header
     * @return object
	 */
	protected function fail_order( $id, $info = '' ){
        WC()->session->__unset('rtnd_on_preliminary');
		$this->wpml(1);//back to wpml
		wc_delete_shop_order_transients( $id );
		wp_delete_post( $id, true );
		return new WP_Error('RECONVERSION_FAIL',
							'Failed to create reconversion order',
							array( 'status' => 422,
									'info'  => $info
								) );
	}


	/**
	 * Adding order discount basing on the coupon
	 * This function is not used now, but has things to use in the future
	 * 
	 * @param $order
	 * @param int $discount
	 * @param string $postfix
	 * @param|@return $coupon_info
	 * @param|@return $useCredit
	 * 
	 * @return &$order
	 *
	 * compatible to WC 3.0
	 */
	protected function add_order_discount( &$order, $discount = 0, $postfix = '_rtnd', &$coupon_info, &$useCredit ){

		global $RTNDo; $wc30 = $RTNDo->wc_version();
		$order_id = ($wc30?$order->get_id():$order->id);

		$coupon_info = $this->create_coupon(
			$discount,
			($wc30?$order->get_currency():$order->order_currency),
			($wc30?$order->get_customer_id():$order->customer_user),
			$postfix,
			($this->options['rtnd_include_shipping']=='yes'?'no':'yes'),
			'COUPON_CREATED_FOR_PREAPPROVED_ORDER: '.$order_id
		);

		if(empty($coupon_info)) return false;

		$useCredit = 1;

		$coupon = $coupon_info['coupon']['code'];
		
		wp_cache_flush();

		//add items to cart for spreading the discount
		WC()->cart->empty_cart(true);
		WC()->cart->remove_coupons();
		foreach( $order->get_items() as $item_id => $item ) 
			if ( ! empty( $item['variation_id'] ) && 'product_variation' === get_post_type( $item['variation_id'] ) ) 
					WC()->cart->add_to_cart( $item['variation_id'] , (int)$item['qty'] );
			else
					WC()->cart->add_to_cart( $item['product_id'] , (int)$item['qty'] );

		//add discount
		WC()->cart->add_discount( $coupon );

		//clear order
		$order->remove_order_items('line_item');

		//add discounted items back to the order
		foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
				$item_id = $order->add_product(
						$values['data'], $values['quantity'], array(
							'variation' => $values['variation'],
							'totals' => array(
									'subtotal' => $values['line_subtotal'],
									'subtotal_tax' => $values['line_subtotal_tax'],
									'total' => $values['line_total'],
									'tax' => $values['line_tax'],
									'tax_data' => $values['line_tax_data']
							)
						)
				);
		}

		WC()->cart->empty_cart(true);
		WC()->cart->remove_coupons();

		$order->add_coupon( $coupon, $discount );

		$this->reduce_coupon( $discount, $coupon_info );

		$order->calculate_totals(true);

		$rest_amount = $discount - $order->get_total_discount();

		if( $rest_amount > 0 )
			return ( $coupon_info = $this->create_coupon( $rest_amount, ($wc30?$order->get_currency():$order->order_currency), ($wc30?$order->get_customer_id():$order->customer_user) ) );

		return true;
	}


	/**
	 * Validate returning data
	 *
	 * Check:   return items quantity <= order items quantity
	 *          return data currency === order currency
	 * 
	 * @param array $data
	 * @param string $error
	 *
	 * @return bool
	 *
	 */
	private function validate_refund_items( $data, &$error ){
		if(empty($data['items'])) {
			$error = 'Return items are empty';
			return false;
		}
		$order_currency = get_post_meta($data['orderId'],'_order_currency',true);
		if( $order_currency !== $data['currency']){
			$error = 'Return currency does not match order currency ('.$data['currency'].' != '.$order_currency.')';
			return false;
		}
		$items = $data['items'];
		$item_quantity = array();
		foreach($items as $item){
			if($item['deleted']) continue;
			if(!in_array(strtolower($item['status']),['arrived', 'rejected'])) continue;
			$item_quantity[$item['orderId']] = (isset($item_quantity[$item['orderId']])?$item_quantity[$item['orderId']]+1:1);
		}
		$order = wc_get_order($data['orderId']);
		if(!$order){
			$error = 'Order '.$data['orderId'].' was not found';
			return false;
		}
		$order_items = $order->get_items();
		foreach($item_quantity as $item_id=>$qty)
			if( $order->get_qty_refunded_for_item( $item_id ) + $order_items[$item_id]->get_quantity() < $qty ){
				$error = 'Refund item quantity is greater than possible for refund.';
				return false;
			}
		$error = '';
		return true;
	}


	/**
	 * Calculate return items total and tax
	 * 
	 * @param array $items
	 * @param float $taxes
     * @param string $new_currency
	 *
	 * @return float
	 */
	private function get_items_total_and_tax( $items = [], &$taxes = 0.0, $new_currency ){
		if(empty($items)) return 0.0;
		$ttl = 0.0; $taxes = 0;
		foreach($items as $item){
		    $price = (float)$item['realPrice'];
		    $vat = (float)$item['vatRate'];
		    if($new_currency !== $item['currency'])
		        $price = $this->convert_currency( $price, $item['currency'], $new_currency);
			$i_tax = wc_format_decimal( $price * $vat, RTNDPRECISION );
			$ttl += $price + $i_tax;
			$taxes += $i_tax;
		}
		return $ttl;
	}

	/**
	 * WPML plugin filtering
	 *
	 * @param int $on
	 */
	public function wpml($on = 1){ //WPML on-off
		if ( $on ){
			if ( !has_filter( 'translate_object_id', 'icl_object_id')  && function_exists('icl_object_id') )
				add_filter( 'translate_object_id', 'icl_object_id', 10, 4 );
				global $woocommerce_wpml;
				if(isset($woocommerce_wpml))
					add_filter( 'woocommerce_order_get_items', array( $woocommerce_wpml->orders, 'woocommerce_order_get_items' ), 10, 2 );
		}else{
			remove_filter( 'translate_object_id', 'icl_object_id' );
			global $woocommerce_wpml;
			if(isset($woocommerce_wpml))
				remove_filter( 'woocommerce_order_get_items', array( $woocommerce_wpml->orders, 'woocommerce_order_get_items' ) );
		}
	}

	/**
	 * Retrieve product attributes by ID
	 *
	 * @return array
	 */
	private function get_product_attributes( $product_variant_id ){
		//get product variation attributes
		global $wpdb;
		$attribute_request = $wpdb->get_results("SELECT  `meta_key` as `attr_name`,
														     `meta_value` as `attr_value` 
														FROM `{$wpdb->prefix}postmeta`
													   WHERE `meta_key` LIKE 'attribute_%' AND `post_id` = $product_variant_id"
		);
		$attributes = array();
		if(!empty($attribute_request))
			foreach($attribute_request as $attr)
				$attributes[$attr->attr_name] = $attr->attr_value;
		return $attributes;
	}

    /**
     * Currency convertor
     *
     * @param $price
     * @param $currency_from
     * @param $currency_to
     *
     * @return float
     */
	public function convert_currency( $price, $currency_from, $currency_to ){
	    $def_currency = get_option( 'woocommerce_currency' );
	    if($def_currency!==$currency_from)
	        $rate = $this->get_fixer_rate( $currency_from, $currency_to );
	    else
            $rate = $this->rates['rates'][ $currency_to ];
        return wc_format_decimal( $price * $rate, RTNDPRECISION );
    }

	/**
	 * Base for /order EP
	 *
	 * Preliminary order processing
	 *
	 * @param $data
	 * @return bool | array
	 *
	 * compatible to wc 3.0
	 */
	public function process_booking( $data ) {

        $this->log('PROCESSING /ORDER DATA - NEW PRELIMINARY ORDER - STARTED', $data);

		if(!isset($data['returnItems']) || !isset($data['items'])) {
            $this->log('PROCESSING PRELIMINARY FAILURE - NO DATA SET', $data);
            return new WP_Error('NO_DATA_SET',
                'Received empty body',
                array('status' => 422,
                    'info' => 'No data to process defined'
                ));
        }

		//CALCULATING RETURN_TOTAL & RETURN_TOTAL_TAX
        $new_currency = get_option( 'woocommerce_currency' );
		if(isset($data['items'][0]['currency']))
		    $new_currency = $data['items'][0]['currency'];

		$return_total = $this->get_items_total_and_tax( $data['returnItems'], $return_tax, $new_currency );

        //check the original order
		$original_order_id = (int)$data['orderId'];
        if($original_order_id){
            $original_order = wc_get_order($original_order_id);
            if(!$original_order) {
                $this->log('PROCESSING PRELIMINARY FAILURE - ORIGINAL ORDER NOT EXISTS', $data);
                return new WP_Error('ORDER_NOT_EXISTS',
                    'Original data lost',
                    array('status' => 422,
                        'info' => 'Original order #' . $original_order_id . ' could not be found.'
                    ));
            }
        }

		//PROCEED ON CREATING RECONVERSION ORDER
		if ( empty( $data['customerId'] ) || !$original_order->get_customer_id() ) {
		    //check if original order is guest order - patch it and sync to returnado
            if(!class_exists('RTND_Patcher'))
                require_once "patcher.php";
            if(!($data['customerId'] = RTND_Patcher::patch_guest_order( $original_order_id, $dummy, true ))){
                $this->log('PROCESSING PRELIMINARY FAILURE - NO CUSTOMER SET', $data);
                return new WP_Error( 'NO_CUSTOMER',
                    'Customer Id is undefined',
                    array( 'status' => 422,
                        'info' => 'customerId parameter is empty or not set and original order could not be patched'
                    ) );
            }
		}

		$cuid = (int) $data['customerId'];
		$cl   = get_user_by( 'id', $cuid );

		if ( ! $cl ) {
            $this->log('PROCESSING PRELIMINARY FAILURE - CUSTOMER NOT FOUND', $data);
			return new WP_Error('USER_NOT_FOUND',
								'User is permanently deleted',
								array( 'status' => 422,
										'info' => 'User #'.$cuid.' not found'
									) );
		}

		if ( empty( $data['items'] ) || ! count( $data['items'] ) > 0 ) {
            $this->log('PROCESSING PRELIMINARY FAILURE - ITEMS NOT SET', $data);
			return new WP_Error('INPUT_DATA',
								'Unprocessable Entity',
								array( 'status' => 422,
										'info' => 'The "items" parameter is empty or not set'
									) );
		}

		$items = $data['items'];

		//linked items - since 0.4.0
		$linked_items = array();

		//creating a booking order from Returnado data
		//get usermeta
		$clm = array_map( function ( $a ) {
			return $a[0];
		}, get_user_meta( $cl->ID ) );

		//fill new order info
		$address_billing  = array(
			'first_name' => isset($clm['first_name'])?$clm['first_name']:'',
			'last_name'  => isset($clm['last_name'])?$clm['last_name']:'',
			'company'    => isset($clm['billing_company'])?$clm['billing_company']:'',
			'email'      => $cl->user_email,
			'phone'      => isset($clm['billing_phone'])?$clm['billing_phone']:'',
			'address_1'  => isset($clm['billing_address_1'])?$clm['billing_address_1']:'',
			'address_2'  => isset($clm['billing_address_2'])?$clm['billing_address_2']:'',
			'city'       => isset($clm['billing_city'])?$clm['billing_city']:'',
			'state'      => isset($clm['billing_state'])?$clm['billing_state']:'',
			'postcode'   => isset($clm['billing_postcode'])?$clm['billing_postcode']:'',
			'country'    => isset($clm['billing_country'])?$clm['billing_country']:''
		);
		$address_shipping = array(
			'first_name' => isset($clm['first_name'])?$clm['first_name']:'',
			'last_name'  => isset($clm['last_name'])?$clm['last_name']:'',
			'company'    => isset($clm['shipping_company'])?$clm['shipping_company']:'',
			'email'      => $cl->user_email,
			'phone'      => isset($clm['shipping_phone'])?$clm['shipping_phone']:'',
			'address_1'  => isset($clm['shipping_address_1'])?$clm['shipping_address_1']:'',
			'address_2'  => isset($clm['shipping_address_2'])?$clm['shipping_address_2']:'',
			'city'       => isset($clm['shipping_city'])?$clm['shipping_city']:'',
			'state'      => isset($clm['shipping_state'])?$clm['shipping_state']:'',
			'postcode'   => isset($clm['shipping_postcode'])?$clm['shipping_postcode']:'',
			'country'    => isset($clm['shipping_country'])?$clm['shipping_country']:''
		);

		$new_order = wc_create_order();

        //enable preliminary order processing session-wise
        WC()->session->set('rtnd_on_preliminary', true);

		$new_order->set_address( $address_billing, 'billing' );
		$new_order->set_address( $address_shipping, 'shipping' );

		global $RTNDo; $wc30 = $RTNDo->wc_version();
		$new_order_id = ($wc30?$new_order->get_id():$new_order->id);

        $this->log('>>>> NEW PRELIMINARY ORDER CREATED', $new_order_id);

		//overriding currency exchanger - setting the same currency as it was synced to Returnado
		$currency = get_option( 'woocommerce_currency' );

		$this->wpml( 0 );//wpml off for objects

		//Add all items from accepted data
		$discount = ( isset($data['returnTotal']) ? (float) $data['returnTotal'] : 0 );
		if ( $discount > 0 ) {
			$new_order->add_order_note( __( 'RETURNADO_RETURN_TOTAL', RTND ), ': ' . $discount );
		}

        $this->log('>>>> STARTING TO ADD ITEMS INTO NEW ORDER', [
            'customer_data' => str_replace( '  ', '', str_replace("\n",' ',var_export( WC()->customer, 1) ) )
        ]);

		foreach ( $items as $item ) {

            $this->log('>>>> ADDING ITEM TO ORDER', ['order_id' => $new_order_id, 'item_data' => $item]);

			if(isset($item['currency'])) $currency = $item['currency'];

			//get the product and set it's price according to Returnado value
			$q = (int) $item['quantity'] ? (int) $item['quantity'] : 1;

            $pvd       = (int) $item['productVariantId'];

            $parent_id = get_post( $pvd )->post_parent;
            if ( ! $parent_id ) {
                $parent_id = $pvd;
            }

            //get the product
            $pr = wc_get_product( $pvd );

			//check STOCK
            $instock = $pr->is_in_stock();

            $max_q = $pr->get_max_purchase_quantity();

            $stock_qty = ( $max_q < 0 || $q <= $max_q );

            if ( !$instock || !$stock_qty ) {
                return $this->fail_order( $new_order_id,
                    'Stock unavailable for item #'.$item['productVariantId']);
            }

			//add products to the order optionally with original (old) or new price

			//recalculate taxes and prices from returnado side
			$price = $item['realPrice'];
			$tax = 0;

			if ( 'yes' !== $this->options['rtnd_original_prices'] ) {
				global $RTND_Collector;
				$price = $RTND_Collector->get_product_price( $parent_id, $pvd, $tax, $currency );
			} else {
				$tax = $item['realPrice'] * $item['vatRate'];
			}

			$item_id = $new_order->add_product( $pr, $q, [
					'totals' => [
						'total'        => wc_format_decimal($q * $price, RTNDPRECISION),
						'total_tax'    => wc_format_decimal($q * $tax, RTNDPRECISION),
						'subtotal'     => wc_format_decimal($price, RTNDPRECISION),
						'subtotal_tax' => wc_format_decimal($tax, RTNDPRECISION)
					]
				]
			);
			if ( $item_id && 'yes' === $this->options['rtnd_original_prices'] ) {
				wc_update_order_item_meta( $item_id, '_rtnd_original_net_price', $price );
				if ( 'yes' === get_option( 'woocommerce_prices_include_tax' ) && $pr->is_taxable() ) $price += $tax;
				wc_update_order_item_meta( $item_id, '_rtnd_original_price', $price );
				wc_update_order_item_meta( $item_id, '_rtnd_original_tax', $tax );
			}

			if ( ! $item_id ) {
                $this->log('>>>> ADDING ITEM TO ORDER FAILURE!', ['order_id' => $new_order_id, 'item_data' => $item]);
				return $this->fail_order( $new_order_id, 'Item #'.$item['productVariantId'].'could not be added to preliminary order');
			}

            $this->log('>>>> ITEM ADDED. ORDER ITEM DATA', [
                'order_id' => $new_order_id,
                'new_item_id' => $item_id,
                'order_item_tax_data' => wc_get_order_item_meta( $item_id, '' ),
                'product_data' => $pr
            ]);

			$linked_items[] = ['fromOrderItemId' => $item['orderItemId'], 'toOrderItemId' => $item_id];

			//attributes part - ready, but disabled for now
//            if(!empty($item['attributes'])){
//                foreach($item['attributes'] as $attr_name=>$attribute)
//                    wc_update_order_item_meta( $item_id, 'pa_' . $attr_name, $attribute['value'] );
//            }
		}

		//Add shipping

		if($this->options['rtnd_include_shipping']=='yes'){
			$shipping_zone = WC_Shipping_Zones::get_zone(0);
			$shipping_methods = $shipping_zone->get_shipping_methods( true );
			$shipping_method = reset($shipping_methods);
			global $wpdb;
			$rates = $wpdb->get_results( "SELECT * 
                                            FROM `{$wpdb->prefix}woocommerce_tax_rates` 
                                           WHERE `tax_rate_id` = 1" );
			$shipping_def = new WC_Shipping_Rate(
												$shipping_method->id,
												$shipping_method->method_title,
												(float)$shipping_method->instance_settings['cost'],
												["1"=> (float)$shipping_method->instance_settings['cost']/100*$rates[0]->tax_rate],
												$shipping_method->id
												);
			$ad = 0;
			if($original_order_id){
				if ($original_order){
					foreach($original_order->get_shipping_methods() as $shipping_method_id=>$shipping_method){
						$shipping = new WC_Shipping_Rate(
														 $shipping_method['method_id'],
														 $shipping_method['name'],
														 (float)$shipping_method['cost'],
														 ["1"=> (float)$shipping_method['line_tax']],
														 $shipping_method['method_id']
														);
						$shipping->add_meta_data('Original order for shipping',$original_order_id);
						if($wc30){
							$shp = new WC_Order_Item_Shipping();
							$shp->set_shipping_rate( $shipping );
							$new_order->add_item( $shp );
						}else
							$new_order->add_shipping($shipping);
						$ad = 1;
					}
					$new_order->set_address( $original_order->get_address( 'shipping' ), 'shipping' );
				}
			}
			
			if ($ad==0)
				if($wc30){
					$shp = new WC_Order_Item_Shipping();
					$shp->set_shipping_rate( $shipping );
					$new_order->add_item( $shp );
				}else
					$new_order->add_shipping($shipping);
		}else{	//add free shipping
			$free_shipping = RTND_Klarna_Extend::get_shipping_method_id_by_label('free_shipping');
			$shipping = new WC_Shipping_Rate($free_shipping, 'Free Shipping', 0, ["1"=>0], $free_shipping);
			if($wc30){
				$shp = new WC_Order_Item_Shipping();
				$shp->set_shipping_rate( $shipping );
				$new_order->add_item( $shp );
			}else
				$new_order->add_shipping($shipping);
			$free_s = 1;
		}
        $this->log('>>>> NEW PRELIMINARY ORDER SHIPPING PROCESSED', true);
		//end add shipping

		//---------------------------------------------------------------------------------save returnTotal
			$sRTl = 0;
			$coupon_info = [];
				if($return_total>0){
					
					//coupon for rest amount - TBD
                    /*
					if('yes' === $this->options['rtnd_use_coupons']){
						$new_order->calculate_totals(true);
						$orderTotal = $new_order->get_total();
						$rest_amount = $returnTotal - $orderTotal;
						if($rest_amount>0) {
							$coupon_info = $this->create_coupon($rest_amount, $currency, $cl->ID);
							$returnTotal = $orderTotal;
						}
					}
					*/

					//SAVING RETURN TOTAL AS NEGATIVE LINE ITEM
					//option rtnd_virt_item
                    // - save it as original item with negative price (1) or virtual product item (2)

                    $virt_item = $this->options['rtnd_virt_item'];
                    if(!$virt_item) $virt_item = 2; //default - virtual product

                    if($virt_item == 1){ //original products with negative prices
                        $totals = [];
                        foreach($data['returnItems'] as $item){

                        	    if( $item['currency'] !== $currency ){

		                            $item['realPrice'] = $this->convert_currency( $item['realPrice'], $item['currency'], $currency );

	                            }

                                $i_tax = -1*wc_format_decimal($item['realPrice']*$item['vatRate'],RTNDPRECISION);
                                $i_price = -1 * $item['realPrice'] + $i_tax;
                                $pvd = (int)$item['productVariantId'];

                                $totals[$pvd] = [
                                    'subtotal'      => wc_format_decimal( $i_price, "" ),
                                    'subtotal_tax'  => 0,
                                    'total_tax'     => 0,
                                    'return_tax'    => $i_tax,
                                    'total'         => wc_format_decimal( $i_price, "" ),
                                    'tax_class'     => '0',
                                    'tax_status'    => 'none'
                                ];

                                $pr = wc_get_product($pvd);

                                $iid = $new_order->add_product( $pr, 1, [ 'totals' => $totals[$pvd] ] );

                                $this->log('>>>> NEGATIVE ITEM ADDED. ORDER ITEM DATA', [
                                    'order_id' => $new_order_id,
                                    'new_item_id' => $iid,
                                    'order_item_data' => wc_get_order_item_meta( $iid, '' )
                                ]);

	                            wc_update_order_item_meta( $iid,'ReturnOrder', $data['orderId'] );
                                wc_update_order_item_meta( $iid,'ReturnTax', abs($totals[$pvd]['return_tax']) );
                                wc_update_order_item_meta( $iid,'ReturnTotal', abs($i_price) );

                            }

                        $new_order->calculate_totals(1);

                        $totals = $new_order->get_total();

                        $sRTl = 1;

                    }else{               //virtual product with negative price

                        $post = array(
                            'post_author' => 1,
                            'post_content' => '',
                            'post_status' => "rtnd",
                            'post_title' => "Returnado Return Total",
                            'post_parent' => '',
                            'post_type' => "product",
                        );

                        //Create post
	                    $wp_error = '';
                        $post_id = wp_insert_post( $post, $wp_error );
						if(!$post_id) return $this->fail_order($new_order_id, 'WC functionality failure (unknown)' );
						
                        wp_set_object_terms($post_id, 'simple', 'product_type');
                        update_post_meta( $post_id, '_visibility', 'hidden' );
                        update_post_meta( $post_id, '_stock_status', 'instock');
                        update_post_meta( $post_id, 'total_sales', '0');
                        update_post_meta( $post_id, '_downloadable', 'no');
                        update_post_meta( $post_id, '_virtual', 'yes');
                        update_post_meta( $post_id, '_sku', $post_id );
                        update_post_meta( $post_id, '_returnado_product', 'yes');
                        update_post_meta( $post_id, '_regular_price', $return_total );
                        update_post_meta( $post_id, '_product_attributes', array());
                        update_post_meta( $post_id, '_price', $return_total );
                        update_post_meta( $post_id, '_manage_stock', "no" );
                        update_post_meta( $post_id, '_backorders', "no" );
                        update_post_meta( $post_id, '_stock', "" );
                        update_post_meta( $post_id, '_tax_status', "none" );
                        update_post_meta( $post_id, '_tax_class', "none" );

                        //making our product negative
                        $our_pr = wc_get_product($post_id);
						if(!$our_pr) return $this->fail_order($new_order_id, 'WC functionality failure (virtual product)');
						
                        $price = -1*$return_total;
                        $our_pr->price = $price;
                        if(method_exists($our_pr, 'set_price'))
                            $our_pr->set_price($price); //WC 3.0 compatibility*/

                        $iid = $new_order->add_product($our_pr, 1, [ 'totals' => [ 'subtotal' => wc_format_decimal(-1*$return_total, ""),
                                                                                   'subtotal_tax' => 0,
                                                                                   'total' => wc_format_decimal( -1*$return_total, "" ),
                                                                                   'total_tax' => 0 ] ]);

						if(!$iid) return $this->fail_order($new_order_id, 'Adding virtual product to the new preliminary order failed');

                        $this->log('>>>> NEGATIVE ITEM ADDED. ORDER ITEM DATA', [
                            'order_id' => $new_order_id,
                            'new_item_id' => $iid,
                            'order_item_data' => wc_get_order_item_meta( $iid, '' )
                        ]);

                        wc_update_order_item_meta( $iid, 'ReturnOrder', $data['orderId']);

                        update_post_meta( $post_id, 'ReturnOrder', $data['orderId'] );
                        $rc = array_count_values( array_map( function($a){ return $a['productVariantId']; },$data['returnItems'] ) );
                        array_walk( $rc, function(&$a, $b) { $a = $b.'('.$a.')'; } );
                        $ri = implode(', ', $rc );
                        wc_update_order_item_meta( $iid, 'ReturnItems', $ri);
                        wc_update_order_item_meta( $iid, '_tax_class', 0);
                        update_post_meta( $post_id, 'ReturnItems', $ri );
                        if($return_tax) {
                            update_post_meta( $post_id, 'ReturnTax', $return_tax );
                            wc_update_order_item_meta( $iid, 'ReturnTax', $return_tax);
                        }
                        wc_update_order_item_meta( $iid, 'ReturnTotal', $return_total);

                        $new_order->calculate_totals(1);

                        $totals = $new_order->get_total();

                        $sRTl = 1;
                    }



			}

		//---------------------------------------------------------------------------------end save by returnTotal
		//updating meta
		update_post_meta( $new_order_id, '_customer_user', $cl->ID );
		update_post_meta( $new_order_id, '_order_currency', $currency );

		//set payment method - the same as in the original order
        update_post_meta( $new_order_id, '_payment_method', get_post_meta( $original_order_id, '_payment_method', true ) );
		
		if (!$sRTl) {
			$new_order->calculate_totals(true);
			$totals = $new_order->get_total();
		}
		$new_order->update_status( 'returnawait' );
		$new_order->add_order_note(__('RETURNADO_PRELIMINARY_ORDER',RTND));
		$pmgw_i = $this->options['rtnd_pmgw_def'];
		$p_index = $pmgw_i?$pmgw_i:0;
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$pmgw = isset($gateways[$p_index])?$gateways[$p_index]:null;
		if($pmgw)
			$new_order->set_payment_method($pmgw); //set_default_payment_gateway
		
		$this->wpml(1);//back to wpml

        $this->log('PRELIMINARY ORDER CREATED AND PROCESSED', $new_order_id);

		$r = [
				'orderId' 	 => ( string ) $new_order_id,
                'sequentialOrderId' => apply_filters( 'woocommerce_order_number', $new_order_id, $new_order ),
				'paymentUrl' => ( $totals > 0 ? str_replace( site_url(), '', $new_order->get_checkout_payment_url( false ) ) : '' )
				];

		if(!empty($coupon_info)){
		    $r['coupon'] = $coupon_info['coupon'];
        }

        if(!empty($linked_items))
        	$r['items'] = $linked_items;

        WC()->session->__unset('rtnd_on_preliminary');

        return $r;

	}

	// Helper function

    /**
     * Fetch rates from fixer using current base
     *
     * @param $base
     * @return array|bool
     */
    protected function fetch_rates( $base ){
        $url = "http://data.fixer.io/api/latest?access_key=370cf8e7dd5d98cad825d85486d274a5&format=1";
        try{
            $r = @file_get_contents( $url );
            if(!$r) return false;
            $res = json_decode( $r, true );
            if( empty( $res['rates'] ) || !isset( $res['rates']['SEK'] ) ) return false;
            if( $res['base'] === $base ) return $res['rates']; //we are lucky
            $r = [];
            foreach($res['rates'] as $CID=>$rate)
                $r[$CID] = round( $res['rates'][$CID] / $res['rates'][$base], 7 );
            return $r;
        }catch(\Exception $e){
            $this->log( 'ERROR FETCHING CURRENCY RATES', $e->getMessage() );
            return false;
        }
    }

	/**
	 * Get Fixer Rate
     *
     * retrieve all currency rates
	 *
	 * @param string $base_currency
	 * @param string $to_currency
	 *
	 * @return float rate_value
	 */
    public function get_fixer_rate( $base_currency = 'USD', $to_currency = "SEK" ){

        if($base_currency === $to_currency) return 1;
        $our_rates = json_decode( get_option('rtnd_currency_rates'), true);

        //1. We have our rates fresh and active
        if( $our_rates && $to_currency !== 'UPDATE_CURRENCY' ){
            if($our_rates['last_updated'] >= (time() - 3600*24) //refresh rates once per 24 hours
                && isset($our_rates['rates'][$to_currency])
                && $our_rates['rates'][$to_currency]>0 )
                return $our_rates['rates'][$to_currency];
        }

        //2. Something wrong about our rates or new refresh was requested - let's try to fetch it from fixer api
        $rates = $this->fetch_rates( $base_currency );
        if( !$rates ){
            if( $to_currency === 'UPDATE_CURRENCY' ) return 1;
            //May be we exceeded month limit on hits? Trying to get the old rate value
            if(!$our_rates) return 1;
            if( isset( $our_rates['rates'][$to_currency] ) && $our_rates['rates'][$to_currency]>0 )
                return $our_rates['rates'][$to_currency];
        }else{
            //save fresh rates
            $rz = array();
            $rz['last_updated'] = time();
            $this->rates = $rz['rates'] = $rates;
            update_option('rtnd_currency_rates', json_encode( $rz ) );
            return ( isset( $rz['rates'][$to_currency] ) ? $rz['rates'][$to_currency] : 1 );
        }
        return 1;
    }


	/**
	 * Helper - refresh currency rates
	 *
	 * @return array currency_rates
	 */
	public function refresh_currency_rates(){
        $this->get_fixer_rate( get_option('woocommerce_currency'), 'UPDATE_CURRENCY' );
		return $this->rates;
	}

    /**
     * Mark sent objects as synced with current timestamp
     *
     * @param array $objects
     * @param string $type
     * @param int $send_result
     *
     * @return int
     */
    public static function mark_synced( $objects, $type, $send_result ){
        if(!$send_result) return $send_result;
        foreach($objects as $obj){
            switch ($type){
                case 'customers'    :
                    update_user_meta( $obj['customerId'], '_rtnd_synced', time() );
                    break;
                case 'products'     :
                    update_post_meta( $obj['productId'], '_rtnd_synced', time() );
                    break;
                case 'orders'       :
                    update_post_meta( $obj['orderId'], '_rtnd_synced', time() );
            }
        }
        return $send_result;
    }
}