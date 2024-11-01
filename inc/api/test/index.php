<?php

  defined('ABSPATH') or die('Allowed only in site!');
  
  if (!current_user_can('administrator')) die('Accessible for administrators only!');

  global $RTNDo;

  if(!isset($RTNDo)) die('Not allowed directly!');

  $options = $RTNDo->get_options();

  if ('yes' !== $options['rtnd_api_test_enabled']) die('The Returnado API Test platform is disabled.');

//**********************************************DEFAULT_PARAMETERS********************************************

//define('DEFAULT_REMOTE_URL','http://woocommerce-20261-54837-203731.cloudwaysapps.com/');
//define('DEFAULT_REMOTE_URL','http://wpdev0.lo/');
define('DEFAULT_REMOTE_URL',site_url());
//define('DEFAULT_user','global');
define('DEFAULT_user',$options['rtnd_remote_user']);
define('DEFAULT_password',$options['rtnd_remote_password']);
//define('DEFAULT_password','secret');


//************************************************************************************************************



error_reporting( E_ALL );
ini_set( 'display_errors', 'On' );
require_once "class-rtnd-api-client.php";

$store_url 			= (isset($_SESSION['remote_url'])?$_SESSION['remote_url']:DEFAULT_REMOTE_URL);
$user 		= (isset($_SESSION['user'])?$_SESSION['user']:DEFAULT_user);
$password 	= (isset($_SESSION['password'])?$_SESSION['password']:DEFAULT_password);


// Initialize the class
$rtnd_api = new RTND_Client( $user, $password, $store_url );

//my products*******************************************************************************************************************************
?>

<!DOCTYPE html>
<html lang="ru-RU">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Returnado API</title>
	<link rel='stylesheet' href='<?=RTNDURL?>/inc/api/test/default.css' type='text/css' media='all' />
	<script type="text/javascript">
		function CalcTotal(){
			var i = 0;
			var z = 0;
			while(document.getElementById('pr_c_'+i)){
				z += document.getElementById('pr_'+i).checked?document.getElementById('pr_q_'+i).value*document.getElementById('pr_c_'+i).value:0;
				i++;
			}
			document.getElementById('totals').innerHTML = z;	
		}
		
		function CreateBookingRequest(){
				var booking_request = 	'{\r\n'+
										'	"customerId":"'+document.getElementsByName('fetch_customer')[0].value+'",\r\n'+
										'	"orderId":'+document.getElementsByName('fetch_originalOrder')[0].value+',\r\n'+
										'	"items":[';
				var i = 0; var c = 0;
				while(document.getElementById('pr_c_'+i)){
					if(document.getElementById('pr_'+i).checked){
						booking_request+=(c>0?',':'')+'{\r\n'+
														'			"orderItemId":"'+("00000" + i+1).slice(-5)+'",\r\n'+
														'			"productVariantId":"'+document.getElementById('pr_'+i).value+'",\r\n'+
														'			"quantity":'+document.getElementById('pr_q_'+i).value+',\r\n'+
														'			"realPrice":'+document.getElementById('pr_c_'+i).value+',\r\n'+
														'			"vatRate":'+document.getElementById('vat_r_'+i).value+',\r\n'+
														'			"currency":"'+document.getElementById('curr_'+i).value+'"\r\n'+
														'		}';
						c++;
					}
					i++;
				}
			booking_request+=	'	],\r\n'+
								'	"returnItems":['+returnItems[document.getElementsByName('fetch_originalOrder')[0].value]+'\r\n'+
								'	]\r\n'+
								'}';
			document.getElementById('booking_order_textarea').innerHTML = booking_request;				
		}
		
		
		function CreateReturnRequest(){	
				var return_request = 	'{\r\n'+
										'	"newGift":"'+document.getElementsByName('fetch_newGift')[0].value+'",\r\n'+
										'	"currency":"'+document.getElementById('curr_0').value+'",\r\n'+
										'	"status":"WHATEVER",\r\n'+
										'	"reconversionOrderId":"'+document.getElementsByName('fetch_bookingOrder')[0].value+'",\r\n'+
										'	"reconversionOrderDeleted":'+document.getElementsByName('fetch_reconDeleted')[0].value+',\r\n'+
										'	"orderId":"'+document.getElementsByName('fetch_originalOrder')[1].value+'",\r\n'+
										'	"items":['+orders[document.getElementsByName('fetch_originalOrder')[1].value];
			return_request+=']\r\n}';
			document.getElementById('return_order_textarea').innerHTML = return_request;				
		}
		
	</script>
</head>
<body>
<h3>Returnado Extension API Test</h3>
<p style="text-align:center;font-size:11px;padding-left:30px;">Note: This is the platform for testing Returnado API commands directed to WC.</p>
<hr/>
REMOTE HOST: <a href="<?=$store_url?>" target="_blank"><?=$store_url?></a>
<hr/>
<pre>
<?php

function clean($string) {
   $string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.

   return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
}

$id = (isset($_REQUEST['id'])?$_REQUEST['id']:'');
$cid = (isset($_REQUEST['cid'])?$_REQUEST['cid']:'');

if (!isset($_REQUEST['do'])){
//FETCHING INFO FOR CREATING REQUESTS
global $RTND_Collector;
$pr_q = $RTND_Collector->get_test_products();
$cst_q = $RTND_Collector->get_all_customers(20,1,'desc');
$ord_q = $RTND_Collector->get_all_orders(20,1,'desc');
$ord_b_q = $RTND_Collector->get_all_booking_orders(20,1,'desc');
$currency = get_option('woocommerce_currency');
$customer_selector = '<select name="fetch_customer">';
foreach($cst_q as $c)
	$customer_selector.='<option value="'.$c['customerId'].'">'.$c['email'].'</option>';
$customer_selector.='</select>';
$order_selector = '<select name="fetch_originalOrder">';
echo '<script type="text/javascript">
		var orders = [];
		var returnItems = [];
	';
foreach($ord_q as $o){
	$order_selector.='<option value="'.$o['orderId'].'">'.$o['orderId'].'</option>';
	$ois = ''; //order_items
	$rtis = ''; //return_items (items to be included into reconversion request from original order)
	$oisi = 0;
	foreach($o['items'] as $oi){
        for($i=0;$i<$oi['quantity'];$i++){
            $ois.=($oisi>0?',':'').'{\r\n		"orderId": "'.$oi['orderItemId'].'",\r\n		"productVariantId": "'.$oi['productVariantId'].'",\r\n		"status": "ARRIVED",\r\n		"condition": "NORMAL",\r\n		"nextAction": "RESTOCK",\r\n		"reclamationReason": "MANUFACTURING_ERROR",\r\n		"arrivalDate": "2017-03-06T14:45:39.406Z",\r\n		"comment": "Zipper is broken",\r\n		"managerComment": "Only 1 of 5 buttons is in place",\r\n		"diminishedPrice": "'. $oi['realPrice'] .'",\r\n		"vatRate": "'.$oi['vatRate'].'",\r\n		"reclamationImagePath": "'.RTNDURL.'/assets/img/no-image.png",\r\n		"deleted": false\r\n	}';
            $rtis.=($oisi>0?',':'').'{\r\n		"productVariantId": "'.$oi['productVariantId'].'",\r\n		"realPrice": "'.$oi['realPrice'].'",\r\n		"vatRate": "'.$oi['vatRate'].'",\r\n		"currency":"'.$oi['currency'].'"\r\n	}';
            $oisi++;
         }
	}
	echo "
	orders['".$o['orderId']."'] = '".$ois."';
	returnItems['".$o['orderId']."'] = '".$rtis."';
	";
}
echo '</script>';
$order_selector.='</select>';
$booking_order_selector = '<select name="fetch_bookingOrder">';
foreach($ord_b_q as $o)
	$booking_order_selector .='<option value="'.$o['orderId'].'">'.$o['orderId'].'</option>';
$booking_order_selector .='<option value="0">None</option></select>';
$products = "";
$i=0;
foreach($pr_q as $pr)
	foreach($pr['variants'] as $prV)
	    if($prV['quantity'] && $prV['stockStatus'])
            $products .= '<span style="margin:10px auto;width:240px;height:40px;display:inline-block;padding:5px;border:1px solid #fff;">
                            <input type="checkbox" name="pr_ids" id="pr_'.$i.'" value="'.$prV['productVariantId'].'" onClick="CalcTotal()"/>
                            <label for="pr_'.$i.'" style="width:160px;display:inline-block;"> ID: '.$prV['productVariantId'].', Q: [ '.$prV['quantity'].' ]<br/>P: '.$prV['cost'].'</label>
                            <input type="hidden" value="'.$prV['cost'].'" id="pr_c_'.$i.'" />
                            <input type="hidden" value="'.$prV['vatRate'].'" id="vat_r_'.$i.'" />
                            <input type="hidden" value="'.$currency.'" id="curr_'.$i.'" />
                            <input type="text" value="1" name="pr_q" id="pr_q_'.$i++.'" style="width:16px" onChange="CalcTotal();"/>
                          </span>';

//END FETCHING
}

if (isset($_REQUEST['do']))
switch($_REQUEST['do']){
	
	case 'get_request':echo'END POINT: '. $_POST[ 'get_request' ]. '<hr/>'; print_r( $rtnd_api->get_request( ltrim( $_POST['get_request'], '/')  ) ); break;

	case 'aro':echo 'COMMAND: POST REQUEST TO <b>/RETURNORDER</b> ENDPOINT<hr/>';
			$d=json_decode(sanitize_text_field(stripslashes($_POST['jsondata'])));
			if ($d)
				print_r( $rtnd_api->make_return_order($d) );
			else
				echo 'JSON data invalid!';
		break;
	
	case 'bk':echo 'COMMAND: POST REQUEST TO <b>/ORDER</b> ENDPOINT<hr/>';
			$d = json_decode(sanitize_text_field(stripslashes($_POST['jsondata'])));
			if ($d)
				print_r( $rtnd_api->book_order($d) );
			else
				echo 'JSON data invalid!';
		break;

	case 'prms':
		echo'COMMAND: CHANGE PARAMETERS<hr/>';
		if (isset($_REQUEST['remote_url'])) 
		if (!empty($_REQUEST['remote_url']))
		{
				$_SESSION['remote_url'] = $_REQUEST['remote_url'];
				echo 'REMOTE URL CHANGED TO [ '.$_SESSION['remote_url'].' ] <br/>';
		}
		if (isset($_REQUEST['user']))
		if (!empty($_REQUEST['user']))
		{
				$_SESSION['user'] = $_REQUEST['user'];
				echo 'USER CHANGED TO [ '.$_SESSION['user'].' ] <br/>';
		}
		if (isset($_REQUEST['password']))
		if (!empty($_REQUEST['password']))
		{
				$_SESSION['password'] = $_REQUEST['password'];
				echo 'PASSWORD CHANGED TO [ '.$_SESSION['password'].' ] <br/>';
		}
		break;
	case 'prms-def':
		echo'COMMAND: RESTORE DEFAULT PARAMETERS - DONE!';
		$_SESSION['remote_url'] = DEFAULT_REMOTE_URL;
		$_SESSION['user'] = DEFAULT_user;
		$_SESSION['password'] = DEFAULT_password;
		break;
	default:echo'COMMAND: UNKNOWN';
}
else include "command_list.html"; ?>
</pre>
<hr/>
REMOTE HOST: <a href="<?=$store_url?>" target="_blank"><?=$store_url?></a>
<hr/>
<p style="font-size:11px;text-align:right;padding-right:25px;">Comment: If there is any new command you may need, please, contact <a href="http://wetail.se">Wetail Ltd.</a></p>
</body>
</html>


