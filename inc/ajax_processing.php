<?php
 defined ('ABSPATH') or die(__('Ajax requires Wordpress to be set up and running.',RTND));
  
 global $RTNDo;
 if (!wp_verify_nonce($_POST['nonce'])) { _e("ERROR: authorizatrion is incorrect.",RTND); die(); }
 if (isset($_POST['do']))
	switch($_POST['do']){
		case 'ReturnadoSyncz':
			$size = (int)$_POST['size'];
			$page = (int)$_POST['page'];
			$response = array();
			if($page<10) $response['rez'] =  $size;
			else $response['rez'] =  0;
			$response['mem'] = __('Memory in use',RTND).': '.number_format(memory_get_usage(true)/1024/1024,0,'.',' ').' '.__('Mb', RTND);
			echo json_encode($response);
			break;
		case 'ReturnadoSync':
				$r = '';
				global $RTND_Collector, $RTND_Sender, $RTND_Processor;
				$size = (int)$_POST['size'];
				$page = (int)$_POST['page'];
				$response = array();
				$part_sync = ((int)$_POST['full_sync'] === 0);
				$response['mem'] = __('Memory in use',RTND).': '.number_format(memory_get_usage(true)/1024/1024,0,'.',' ').' '.__('Mb', RTND);
				switch($_POST['sync_object']){
					case 'categories':
						$r = $RTND_Collector->get_all_categories( $size, $page );
						if(!empty($r)) {
							$res = $RTND_Sender->returnado_send( 'categories', $r );
							if ( false === strpos( $res, 'ERROR' ) ) {
								$response['rez'] = count( $r );
							} else {
								$response['rez'] = $res;
							}
						}else{
							$response['rez'] = 0;
						}
						break;
					case 'customers':
						$r = $RTND_Collector->get_all_customers( $size, $page, 'asc', '', $part_sync );
						if(!empty($r)) {
							$res = $RTND_Sender->returnado_send( 'customers', $r );
							if ( false === strpos( $res, 'ERROR' ) ) {
								$response['rez'] = count( $r );
                                $RTND_Processor->mark_synced( $r, 'customers', 1 );
							} else {
								$response['rez'] = $res;
							}
						}else{
							$response['rez'] = 0;
						}
						break;
					case 'products':
						$r = $RTND_Collector->get_all_products( $size, $page, 'asc', '', $part_sync );
						if(!empty($r)) {
							$res = $RTND_Sender->returnado_send( 'products', $r );
							if ( false === strpos( $res, 'ERROR' ) ) {
								$response['rez'] = count( $r );
                                $RTND_Processor->mark_synced( $r, 'products', 1 );
							} else {
								$response['rez'] = $res;
							}
						}else{
							$response['rez'] = 0;
						}
						break;
					case 'orders':
						$r = $RTND_Collector->get_all_orders( $size, $page, 'asc', $part_sync );
						if(!empty($r)) {
							$res = $RTND_Sender->returnado_send( 'orders', $r );
							if ( false === strpos( $res, 'ERROR' ) ) {
								$response['rez'] = count( $r );
                                $RTND_Processor->mark_synced( $r, 'orders', 1 );
							} else {
								$response['rez'] = $res;
							}
						}else{
							$response['rez'] = 0;
						}
						break;
				}
				echo json_encode($response);
			break;
		case 'ReturnadoSyncTest':
				print_r('[SUCCESSFULLY TESTED]');
			break;
        case 'checksmtp':$chk = true;
        case 'checksmtpview':
            WC()->mailer();
            $data = [
                'email' 	=> get_user_by('id', 1)->user_email,
                'recipient' => (isset($_POST['recipient'])?wc_clean($_POST['recipient']):''),
                'amount' 	=> '123456',
                'code'	 	=> '12ab34cd'
            ];
            if ($chk)
                echo apply_filters('rtnd_email_coupon_notify', $data)?
	                    __('Email successfully sent to ',RTND).esc_html($_POST['recipient']):
	                    __('Email was NOT sent to ',RTND).esc_html($_POST['recipient']);
            else
                echo apply_filters('rtnd_email_coupon_notify_view', $data);
            break;
		case 'GetMem':
				echo __('Memory in use',RTND).': '.number_format(memory_get_usage(true)/1024/1024,0,'.',' ').' '.__('Mb', RTND);
			break;
		case 'GetSyncTotal':
                $full_sync = (bool)$_POST['full_sync'];
				global $RTND_Collector;
				echo ( $RTND_Collector->count_sync_data($full_sync) + 1 );
			break;
	default: _e("ERROR: AJAX action is not recognized",RTND);
 }
 else _e("ERROR: no action is defined.",RTND);
 
?>