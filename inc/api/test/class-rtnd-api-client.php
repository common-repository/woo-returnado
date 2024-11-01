<?php
/*
 * Class RTND_Client is used for sending and getting data using local API interface
 * private user - auth username
 * private pass - auth password
 * private url - local host url
 */
class RTND_Client {
	const API_ENDPOINT = '/returnado/v2/';
	private $user = '';
	private $pass = '';
	private $url = '';


	/**
	 * RTND_Client constructor.
	 *
	 * @param $user
	 * @param $pass
	 * @param $store_url
	 *
	 * @throws Exception
	 */
	public function __construct( $user, $pass, $store_url) {
		if ( ! empty( $user ) && ! empty( $pass ) && ! empty( $store_url ) ) {
			$this->url = (  rtrim($store_url,'/' ) . '/wp-json/' ) . ltrim(self::API_ENDPOINT, '/');
			$this->user = $user;
			$this->pass = $pass;
		}else
			throw new Exception( 'Error: __construct() - Using test environment without username and password set in plugin options is impossible' );
	}
	
	/*RTND PLUGIN EXTENSIONS TEST*******************************************************************************************************/


	/**
	 * get_request
	 *
	 * @param $q
	 *
	 * @return array|mixed|object|string
	 */

	public function get_request($q){
		return $this->SendGet($q);
	}


	/**
	 * make_return_order
	 *
	 * @param array $datax
	 *
	 * @return string
	 */

	public function make_return_order($datax = array()) {
		return $this->SendPost( 'returnorder', $datax);
	}


	/**
	 * Sends request for creating a preliminary Returnado order
	 *
	 * @param array $datax
	 *
	 * @return string
	 */
	public function book_order($datax = array()) {
		return $this->SendPost( 'order', $datax);
	}

	/*RTND PLUGIN EXTENSIONS TEST END*******************************************************************************************************/


	/**
	 * Sender of post data
	 *
	 * @param $type
	 * @param $data
	 *
	 * @return string
	 */
	public function SendPost($type, $data) {

		$username = $this->user;
		$password = $this->pass;

		$url = rtrim( self::API_ENDPOINT.$type, '/' );

		$request  = new WP_REST_Request( 'POST', $url );
		$request->set_headers( array(
			'Authorization' => 'Basic '. base64_encode( "$username:$password" ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json'
		) );
		$request->set_body(json_encode($data));
		$r = rest_get_server()->dispatch($request);
		return ['headers' => $r->get_headers(), 'body' => $r->get_data()];
	}


	/**
	 * Helper function for retrieving data using curl method
	 *
	 * @param $url
	 * @param $header
	 *
	 * @return mixed
	 */
	function get_url($url,$header) {
		$process = curl_init($url);
		curl_setopt($process, CURLOPT_HTTPHEADER, $header);
		curl_setopt($process, CURLOPT_FRESH_CONNECT, TRUE);
		$return = curl_exec($process);
		curl_close($process);
		return $return;
	}


	/**
	 * Sender for GET request
	 *
	 * @param $type
	 *
	 * @return array|mixed|object|string
	 */
	public function SendGet( $type ) {

		$username = $this->user;
        $password = $this->pass;

		$url = rtrim( self::API_ENDPOINT.$type, '/' );


		$request  = new WP_REST_Request( 'GET', $url );
		$request->set_headers( array(
			'Authorization' => 'Basic '. base64_encode( "$username:$password" ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json'
			) );
		$r = rest_get_server()->dispatch($request);
		return [ 'headers' => $r->get_headers(), 'body' => $r->get_data() ];

	}

	/**
	 * Quick - no response - Sender for GET request
	 *
	 * @param $type
	 *
	 * @return array|mixed|object|string
	 */
	public function touch( $type ) {

		$username = $this->user;
		$password = $this->pass;
		$path = $this->url;

		$options = array(
			'http' => array(
				'method'  => 'GET',
				'content' => '',
				'timeout' => 1,
				'header'=> "Authorization: Basic " . base64_encode("$username:$password") .
				           "\r\nContent-Type: application/json" .
				           "\r\nAccept: application/json"
			)
		);

		$url = $path . $type;

		$context  = stream_context_create($options);

		@file_get_contents($url, false, $context);

		return 'OK';
	}

	/**
	 * Pure response sender
	 *
	 * @param $type
	 *
	 * @return array|mixed|object|string
	 */
	public function SendGetPure( $type ) {

		$username = $this->user;
		$password = $this->pass;

		$url = rtrim( self::API_ENDPOINT.$type, '/' );


		$request  = new WP_REST_Request( 'GET', $url );
		$request->set_headers( array(
			'Authorization' => 'Basic '. base64_encode( "$username:$password" ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json'
		) );

		$r = rest_get_server()->dispatch($request);

		return $r->get_data();

	}

}
