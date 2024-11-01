<?php

//checking if WP is running
defined('RTNDPATH') or die('Who are you, dude?');

//change default WP prefix - disabled
/*
add_filter( 'rest_url_prefix', function() { return RTND_EP; } );
add_action( 'shutdown', function() { flush_rewrite_rules(); } );
*/

class RTND_API_EXT extends WP_REST_Controller {

	const VERSION = 2;
	const PREFIX = '/returnado/v';

	//post data
	private $data = array();


	/**
	 * RTND_API_EXT constructor.
	 *
	 * adds endpoints
	 *
	 */
	public function __construct(){

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		add_action( 'rest_api_init', [ $this, 'register_routes_no_auth' ] );

	}

	/**
	 * Gets authentication headers even if FastCGI mod_rewrite is used
	 * without adding extra rule to .htaccess file
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool | string
	 */
	protected static function get_auth_header( $request ) {
		$authorization = false;
		if ( isset( $_POST["access_token"] ) ) {
			$authorization = $_POST["access_token"];
		}
		elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( isset( $headers['Authorization'] ) ) {
				$authorization = $headers['Authorization'];
			} elseif ( isset( $headers['authorization'] ) ) {
				$authorization = $headers['authorization'];
			}
		}
		elseif ( isset( $_SERVER["Authorization"] ) ) {
			$authorization = $_SERVER["Authorization"];
		}
		elseif ( isset( $_SERVER["HTTP_AUTHORIZATION"] ) ) {
			$authorization = $_SERVER["HTTP_AUTHORIZATION"];
		}
		if(!$authorization){ //check WP headers
			$headers = $request->get_headers();
			if(isset($headers['authorization']))
				$authorization = $headers['authorization'][0];
		}
		//last chance - check alternative header
        if(!$authorization){
            $headers = $request->get_headers();
            if(isset($headers['returnado']))
                $authorization = $headers['returnado'][0];
        }
		return $authorization;
	}

	/**
	 * Basic authentication by username and password. Username and password are the same as in the
	 * plugin settings page
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool|int|object
	 */
	public function auth( $request ){

		global $RTNDo;
		$options = $RTNDo->get_options();
		$unm = $options['rtnd_remote_user'];
		$psw = $options['rtnd_remote_password'];

		$auth_header = self::get_auth_header( $request );

		if($auth_header) {
			$auth = explode( ':', base64_decode( substr( $auth_header, 6 ) ) );
			if( $auth[0] === $unm && $auth[1] === $psw ) return true;
		}

		return new WP_Error( 'AUTH', 'Not authenticated',
            array(  'status' => 401,
                    'info' => 'Credentials provided are wrong or empty' ) );
	}

	/**
	 * End point processing and sending response
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return mixed
	 */
	public function process_end_points( $request ){

		global    $RTND_Collector,
		          $RTNDo,
		          $RTND_Processor,
		          $RTND_Sender;

		//fetching end_point
		$end_point = explode('/',$request->get_route())[3];
		if($pos = strpos($end_point,'?'))
			$end_point = substr($end_point,0,$pos);


		$params = $request->get_url_params();

		if(empty($params)) {
		    if(!empty($_REQUEST)) {
		        $params[0] = 'dummy';
                foreach ($_REQUEST as $key => $value) {
                    $params[] = $key;
                    $params[] = $value;
                }
            }
        }


		//item id
		$id  = isset($params['id'])?(int)$params['id']:0;
		$ids = $id;
		//for credit - many ids
		if(isset($params['id'])) {
			$ids_url = urldecode( $params['id'] );
			$ids     = explode( ',', $ids_url );
		}
		//currency id
		$cid = isset($params['currency_code'])?wc_clean($params['currency_code']):'';
		if($cid)
			if(!in_array( $cid, $RTND_Collector->fetch_currencies() ) )
				return new WP_Error('ITEM_NOT_FOUND',
									'Not found',
									array( 'status' => 404,
									       'info' => 'No such currency found'
									) );
		//block-unblock store credit
		$block = isset($params['block'])?$params['block']:$ids;

		//pagination
		$i = $sz = $pg = 0;
		$upd = '';
		$order = 'desc';
		//search products
		$search = '';

		if(is_array($params))
			while( isset($params[++$i]) ){
				if( 'size'   === $params[$i] ) $sz      = (isset($params[$i+1])?(int)$params[$i+1]:0);
				if( 'page'   === $params[$i] ) $pg      = (isset($params[$i+1])?(int)$params[$i+1]:0);
				if( 'updatedAfter'
                             === $params[$i] ) $upd     = (isset($params[$i+1])?$params[$i+1]:'');
				if( 'order'  === $params[$i] ) $order   = (isset($params[$i+1])?wc_clean($params[$i+1]):'desc');
				if( 'search' === $params[$i] ) $search  = (isset($params[$i+1])?wc_clean( urldecode( $params[$i+1] ) ):'');
			}

		$resp = '';

		$ep_type = '';

		$this->data = $request->get_json_params();

		switch($end_point){

			case 'products':
				$id ? $resp = $RTND_Collector->get_product( $id, false, $cid )
					: $resp = $RTND_Collector->get_all_products( $sz, $pg, $order, $search, false, $upd );
				$ep_type='GET';
				break;

			case 'products_pg':
				$size = 20; $page = 1; $resp = array();
				while ($r = $RTND_Collector->get_all_products( $size, $page++ )) array_push( $resp, $r );
				$ep_type='GET';
				break;

			case 'categories':
				$id ? $resp = $RTND_Collector->get_category( $id )
					: $resp = $RTND_Collector->get_all_categories( $sz, $pg, $order );
				$ep_type='GET';
				break;

			case 'orders':
				$id ? $resp = $RTND_Collector->get_order( $id )
					: $resp = $RTND_Collector->get_all_orders( $sz, $pg, $order, false, $upd );
				$ep_type='GET';
				break;

			case 'preorders':
				$id ? $resp = $RTND_Collector->get_order( $id )
					: $resp = $RTND_Collector->get_all_booking_orders( $sz, $pg, $order, false, $upd );
				$ep_type='GET';
				break;

			case 'customers':
				$id ? $resp = $RTND_Collector->get_customer($id)
					: $resp = $RTND_Collector->get_all_customers( $sz, $pg, $order, $search, false, $upd );
				$ep_type='GET';
				break;

			case 'credit':
				$id ? $resp = $RTND_Collector->get_or_block_credit( $block, $id )
					: $resp = $RTND_Collector->get_all_credits( $sz, $pg, $order );
				$ep_type='GET';
				break;

			case 'sync'			:$resp = $RTNDo->sync_all(); $ep_type='GET'; break;

			case 'details'      :$resp = $RTND_Collector->get_env_details(); $ep_type='GET'; break;

			case 'rates'        :$resp = $RTND_Processor->refresh_currency_rates(); $ep_type='GET'; break;

			case 'check'		:$resp = 'Success!'; $ep_type='GET'; break;

			case 'returnorder'	:$resp = $RTND_Processor->process_order($this->data); $ep_type='POST'; break;

			case 'order'		:$resp = $RTND_Processor->process_booking($this->data); $ep_type='POST'; break;

			case 'version'		:$resp = $RTNDo->get_version(); $ep_type='GET'; break;


			//additional end points for sequential synchronization
			case 'go_sync'          : $resp = $RTNDo->start_sync_all(); $ep_type='GET'; break;
			case 'go_sync_products' :
				if( $rz = $RTND_Collector->get_all_products( $sz, $pg, 'asc', '', 1 ) )
					$resp = $RTND_Processor->mark_synced( $rz, 'products',
                        ( false === strpos( $RTND_Sender->returnado_send( 'products', $rz ), 'ERROR' ) ? 1 : 0 ) );
				break;
			case 'go_sync_customers' :
				if( $rz = $RTND_Collector->get_all_customers( $sz, $pg, 'asc', '', 1 ) )
					$resp = $RTND_Processor->mark_synced( $rz, 'customers',
                        ( false === strpos( $RTND_Sender->returnado_send( 'customers', $rz ), 'ERROR' ) ? 1 : 0 ) );
				break;
			case 'go_sync_categories' :
				if( $rz = $RTND_Collector->get_all_categories( $sz, $pg ) )
					$resp = ( false === strpos( $RTND_Sender->returnado_send( 'categories', $rz ), 'ERROR' ) ? 1 : 0 );
				break;
			case 'go_sync_orders' :
				if( $rz = $RTND_Collector->get_all_orders( $sz, $pg, 'asc', 1 ) )
					$resp = $RTND_Processor->mark_synced( $rz, 'orders',
                        ( false === strpos( $RTND_Sender->returnado_send( 'orders', $rz ), 'ERROR' ) ? 1 : 0 ) );
				break;

			//counters
			case 'count_products'   : $resp = $RTND_Collector->count_all_products();    $ep_type='GET'; break;
			case 'count_customers'  : $resp = $RTND_Collector->count_all_customers();   $ep_type='GET'; break;
			case 'count_orders'     : $resp = $RTND_Collector->count_all_orders();      $ep_type='GET'; break;
			case 'count_categories' : $resp = $RTND_Collector->count_all_categories();  $ep_type='GET'; break;

			//patch EP
            case 'patch' :
                    require_once "patcher.php";
                    $RTND_Patcher = new RTND_Patcher();
                    switch ($params['patch']){
                        case 'guestorders'      : $resp = $RTND_Patcher::guest_orders();        break;
                        case 'nopersoncoupons'  : $resp = $RTND_Patcher::no_person_coupons();   break;
                        case 'checksettings'    : $resp = $RTND_Patcher::check_settings();      break;
                        default : $resp = "Unknown patch!";
                    }
                break;

		}

		//logging requests
		if( 'yes'===$RTNDo->get_options()['rtnd_log_incoming'] && $ep_type ){
			$log_filename = RTNDPATH.'/logs/in_log-'.strftime("%d_%m_%Y").'.txt';
			$time = strftime("%d_%m_%Y %H:%M:%S");
			file_put_contents($log_filename, '['.$time.'] ['.$this->get_client_ip().'] EP: /'.$end_point.' | TYPE: '.$ep_type.' | DATA: '
			                                 .($ep_type=='GET'?json_encode($resp):json_encode($this->data))."\r\n", FILE_APPEND);
		}

		return new WP_REST_Response( $resp , 200);
	}


    /**
     * Process end point request without basic authentication
     *
     * @param string $request
     * @return WP_REST_Response
     */

	public function process_end_points_no_auth( $request ){

		//fetching end_point
		$end_point = explode('/',$request->get_route())[3];
		if($pos = strpos($end_point,'?'))
			$end_point = substr($end_point,0,$pos);


		global 	$RTND_Collector,
		        $RTNDo;


		switch($end_point){

			case 'details'      :$resp = $RTND_Collector->get_env_details(); $ep_type='GET'; break;

			case 'version'		:$resp = $RTNDo->get_version(); $ep_type='GET'; break;

		}

		return new WP_REST_Response( $resp , 200);
	}

	/**
	 * Function get_client_ip was used for IP filtering
	 * now it's not used anymore, but kept for a case and loggin
	 * @return string
	 */
	private function get_client_ip() {
		$ipaddress = '';
		if (isset($_SERVER['HTTP_CLIENT_IP']))
			$ipaddress = $_SERVER['HTTP_CLIENT_IP'];
		else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
			$ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
		else if(isset($_SERVER['HTTP_X_FORWARDED']))
			$ipaddress = $_SERVER['HTTP_X_FORWARDED'];
		else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
			$ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
		else if(isset($_SERVER['HTTP_FORWARDED']))
			$ipaddress = $_SERVER['HTTP_FORWARDED'];
		else if(isset($_SERVER['REMOTE_ADDR']))
			$ipaddress = $_SERVER['REMOTE_ADDR'];
		else
			$ipaddress = 'UNKNOWN';
		return $ipaddress;
	}


	/**
	 * register_routes - Self-descriptive
	 *
	 */
	public function register_routes() {

		$namespace = self::PREFIX . self::VERSION;

		$end_points = array(
			'products'                                                                =>'GET',
			'products(\?)([^=]+)\=([^&]+)'                                            =>'GET',
			'products(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'    =>'GET',
			'products(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                        =>'GET',
			'products/(?P<id>[\d]+)'                                                  =>'GET',
            'products/(?P<id>[\d]+)/(?P<currency_code>\S{3})'                         =>'GET',
			'products_pg'                                                             =>'GET',
			'orders'                                                                  =>'GET',
			'orders(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'      =>'GET',
			'orders(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                          =>'GET',
            'orders/(?P<id>[\d]+)'                                                    =>'GET',
			'preorders'                                                               =>'GET',
			'preorders(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'   =>'GET',
			'preorders(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                       =>'GET',
            'preorders/(?P<id>[\d]+)'                                                 =>'GET',
			'categories'                                                              =>'GET',
			'categories(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'  =>'GET',
			'categories(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                      =>'GET',
			'categories/(?P<id>[\d]+)'                                                =>'GET',
            'customers'                                                               =>'GET',
			'customers(\?)([^=]+)\=([^&]+)'                                           =>'GET',
			'customers(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                       =>'GET',
			'customers/(?P<id>[\d]+)'                                                 =>'GET',
			'credit'                                                                  =>'GET',
			'credit(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'      =>'GET',
			'credit(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                          =>'GET',
			'credit/(?P<block>[\w\s]+)/(?P<id>[\d]+)'                                 =>'GET',
			'credit/(?P<id>[,\d+]*)'                                                  =>'GET',
			'credit/(?P<id>[,\d\%\C+]*)'                                              =>'GET',
            'patch/(?P<patch>[\w\s]+)'                                                =>'GET',
			'sync'                                                                    =>'GET',
			'go_sync'                                                                 =>'GET',
			'go_sync_products(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                                    =>'GET',
			'go_sync_orders(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                                      =>'GET',
			'go_sync_customers(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                                   =>'GET',
			'go_sync_categories(\?)([^=]+)\=([^&]+)(\&)([^=]+)\=([^&]+)'                                  =>'GET',
			'count_categories'                                    =>'GET',
			'count_products'                                      =>'GET',
			'count_orders'                                        =>'GET',
			'count_customers'                                     =>'GET',
			'rates'                                               =>'GET',
			'check'                                               =>'GET',
			'order'                                               =>'POST',
			'returnorder'                                         =>'POST'
		);

		foreach( $end_points as $end_point => $server_state )
			register_rest_route( $namespace, '/' . $end_point, array(
					array(
						'methods'             => $server_state,
						'callback'            => array( $this, 'process_end_points' ),
						'permission_callback' => array( $this, 'auth' )
					)
				)
			);

	}

	 /**
	 * register_routes_no_auth
	 *
	 * register routes without authentication
	 */
	public function register_routes_no_auth() {

		$namespace = self::PREFIX . self::VERSION;

		$end_points = array(
			'details'                                             =>'GET',
			'version'                                             =>'GET'
		);

		foreach( $end_points as $end_point => $server_state )
			register_rest_route( $namespace, '/' . $end_point, array(
					array(
						'methods'             => $server_state,
						'callback'            => array( $this, 'process_end_points_no_auth' )
					)
				)
			);

	}


}


