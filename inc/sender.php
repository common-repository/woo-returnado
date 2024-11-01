<?php
 //RTND_Sender - class
 //DEFINE SECTION
 if(!defined('ABSPATH')) exit;

 /*
  * Returnado API requests sender
  */
 class RTND_Sender{

 	//options
	 private $options = array();

	 public function __construct($options){
	 	//init options
		 if(empty($options)){
			 global $RTNDo;
			 if(!$RTNDo) die('Base Returnado object class was not initialized (RTND_Ext)');
			 $options = $RTNDo->get_options();
		 }
		 $this->options = $options;
	 }

	 /**
	  * Send post data
	  *
	  * @param $type
	  * @param $data
	  *
	  * @return string
	  */
	 public function returnado_send( $type, $data ) {
		// data is the actual POST request body
		// type is the entity type, like 'orders'
        $username = $this->options['rtnd_remote_user'];
        $password = $this->options['rtnd_remote_password'];
        $path = $this->options['rtnd_remote_host'];
        
        if (!empty($username) && !empty($password) && !empty($path)) {
            $options = array(
                'http' => array(
                    'method'  => 'POST',
                    'content' => json_encode($data),	
                    'header'=> "Authorization: Basic " . base64_encode("$username:$password") .
                        "\r\nContent-Type: application/json"
                )
            );

            $url = $path . ($username!=='phpunittest' ? "/public-api/v1/" . $type : '');

            $context  = stream_context_create($options);
			
            @file_get_contents($url, false, $context);

	        $response_header = '';
            if(isset($http_response_header[0]))
            	$response_header = $http_response_header[0];


	        if('yes' === $this->options['rtnd_log_outgoing']){

		        //write LOG
		        $log_filename = RTNDPATH.'/logs/log-'.strftime("%d_%m_%Y").'.txt';
		        $time = strftime("%d_%m_%Y %H:%M:%S");
		        file_put_contents($log_filename, '[MEM: '.number_format(memory_get_usage(true),0,'.',' ').' bytes]['.$time.'] '
                                                  .$url.' POST ['.$response_header.'] DATA: '.$options['http']['content']
                                                  ."\r\n\r\n\r\n", FILE_APPEND);

	        }

			if (strpos($response_header,'200'))
				return '[SUCCESS]['.$type.']-['.$response_header.']';
			else
				return '[ERROR_SEND]['.$type.']-['.$response_header.']';
        }
		else return '[ERROR_AUTH]['.$type.']';
	}

	 /**
	  * Get reply from Returnado
	  *
	  * @param $ep
	  * @param $request
	  * @param array $data
	  *
	  * @return bool|string
	  */
	 public function returnado_get( $ep, $request, $data = [] ) {

		 $username = $this->options['rtnd_remote_user'];
		 $password = $this->options['rtnd_remote_password'];
		 $path = $this->options['rtnd_remote_host'];

		 if (!empty($username) && !empty($password) && !empty($path)) {
			 $options = array(
				 'http' => array(
					 'method'  => 'GET',
					 'content' => '',
					 'header'=> "Authorization: Basic " . base64_encode("$username:$password") .
					            "\r\nContent-Type: application/json" .
					            "\r\nAccept: application/json"
				 )
			 );

			 $url = $path . "/public-api/v1/" . $ep . ($request?'?'.$request:'');

			 $context  = stream_context_create($options);
			 $ans = @file_get_contents($url, false, $context);

			 $response_header = '';
			 if(isset($http_response_header[0]))
				 $response_header = $http_response_header[0];

			 if('yes' === $this->options['rtnd_log_outgoing']){

				 //write LOG
				 $log_filename = RTNDPATH.'/logs/log-'.strftime("%d_%m_%Y").'.txt';
				 $time = strftime("%d_%m_%Y %H:%M:%S");
				 file_put_contents($log_filename, '['.$time.'] '.$url.' GET ['.$response_header.'] DATA: '.$ans."\r\n", FILE_APPEND);

			 }
			 if(isset($http_response_header))
				 if (strpos($response_header,'200')) return $ans;
				 else return '';
			 else return '';
		 }
		 else return '[ERROR_AUTH]['.$ep.']';
	 }
 }