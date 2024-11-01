<?php
 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class RTND_Coupon_Notification extends WC_Email {
	
	public $supportlink = "";
	
	public function __construct() {
		$this->id 				= 'rtnd_coupon_notification';
		$this->title 			= __('Returnado Coupon Notification',RTND);
		$this->description 		= __('This is the notification with the coupon amount and coupon 
                                      code sent to the customer after the Returnado coupon was created or updated',RTND);
		$this->heading 			= __('Returnado Coupon',RTND);
		$this->subject 			= __('Your store credit new details',RTND);
		$this->customer_email	= true;
		$this->supportlink		= site_url();
		$this->template_base	= RTNDPATH.'/assets/tpl/';
		$this->template_html  	= 'coupon-notify.php';
		$this->template_plain 	= 'coupon-notify-plain.php';
	
		// Trigger on new cloned installations
		add_filter( 'rtnd_email_coupon_notify', array( $this, 'trigger' ) );
		add_filter( 'rtnd_email_coupon_notify_view', array( $this, 'trigger_view' ) );
		
		// Call parent constructor to load any other defaults not explicity defined here
		parent::__construct();
	}
	
	public function trigger( $data ) {
		if (!$data) return;
		if (!$this->is_enabled()) return;
		$this->email_data = $data;
		return $this->send( $data['recipient'], $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}
	
	public function trigger_view( $data ) {
		if (!$data) return;
		if (!$this->is_enabled()) return;
		$this->email_data = $data;
		return $this->style_inline($this->get_content());
	}
	
	public function get_content_html() {
		ob_start();
		wc_get_template( $this->template_html, array(
			'email'         	=> $this->email_data['recipient'],
			'email_heading'    	=> $this->heading,
			'amount'			=> $this->email_data['amount'],
			'code'          	=> $this->email_data['code'],
			'support_url'		=> $this->get_option( 'supportlink' )
		), '', $this->template_base );
		return ob_get_clean();
	}
	
	public function get_content_plain() {
		ob_start();
		wc_get_template( $this->template_plain, array(
            'email_heading'    	=> $this->heading,
            'amount'			=> $this->email_data['amount'],
            'code'          	=> $this->email_data['code'],
            'support_url'		=> $this->get_option( 'supportlink' )
		), '', $this->template_base );
		return ob_get_clean();
	}
	
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'    => array(
				'title'   => __('Enable/Disable',RTND),
				'type'    => 'checkbox',
				'label'   => __('Enable this email notification',RTND),
				'default' => 'yes'
			),
			'subject'    => array(
				'title'       => __('Subject',RTND),
				'type'        => 'text',
				'description' => sprintf( __('This controls the email subject line. 
				                              Leave blank to use the default subject',RTND).': <code>%s</code>.', $this->subject ),
				'placeholder' => '',
				'default'     => ''
			),
			'heading'    => array(
				'title'       => __('Email Heading',RTND),
				'type'        => 'text',
				'description' => sprintf( __('This controls the main heading contained within the email notification. 
				                              Leave blank to use the default heading',RTND).': <code>%s</code>.', $this->heading ),
				'placeholder' => '',
				'default'     => ''
			),
			'supportlink'    => array(
				'title'       => __('Support Link',RTND),
				'type'        => 'text',
				'description' => sprintf( __('This is the support link which is placed in the bottom of the notification. 
                                              Leave blank for default value',RTND).': <code>%s</code>.', $this->supportlink ),
				'placeholder' => '',
				'default'     => $this->supportlink
			),
			'email_type' => array(
				'title'       => __('Email type',RTND),
				'type'        => 'select',
				'description' => __('Choose which format of email to send.',RTND),
				'default'     => 'html',
				'class'       => 'email_type',
				'options'     => array(
					'plain'     => 'Plain',
					'html'      => 'HTML',
					'multipart' => 'Multipart'
				)
			),
			'view_result'	=> array(
				'title'			=> __('View an example',RTND),
				'type'			=> 'button',
				'default'		=> __('Open',RTND),
				'class'			=> 'button button-secondary',
				'description'	=> __('Open a pop-up with template example, which is currently set and saved.',RTND),
				'css'			=> 'width:100px'
			),
            'send_check'	=> array(
                'title'			=> __('Send an example',RTND),
                'type'			=> 'button',
                'default'		=> __('Send',RTND),
                'class'			=> 'button button-secondary',
                'description'	=> __('Send an example of notification to defined email address',RTND),
                'css'			=> 'width:100px'
            )
		);
		?>	
			<div id="rtnd_view_template_result" style="display:none;position:fixed;z-index:100000;">
						<div id="wc-backbone-modal-dialog" tabindex="0" >
						<div class="wc-backbone-modal">
							<div class="wc-backbone-modal-content" style="width:auto;min-width:360px;">
								<section class="wc-backbone-modal-main" role="main">
									<header class="wc-backbone-modal-header">
										<h1><?php _e('View email template result',RTND) ?></h1>
										<a id="rtnd_close_btn" class="modal-close modal-close-link dashicons dashicons-no-alt">
											<span class="screen-reader-text"><?php _e('Close',RTND)?></span>
										</a>
									</header>
									<article style="max-height:790px;">
										<div id="rtnd_email_info">
											<p align="center">
												<img src="<?php echo site_url()?>/wp-admin/images/spinner.gif"/>
											</p>
										</div>
									</article>
								</section>
							</div>
						</div>
						<div class="wc-backbone-modal-backdrop modal-close"></div>
					</div>
				</div>
				<?php
					$etype = "";
					if(isset($_POST['woocommerce_rtnd_coupon_notification_email_type']))
						$etype = $_POST['woocommerce_rtnd_coupon_notification_email_type'];
					else $etype = $this->get_option('email_type');
				  ?>
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$('#rtnd_close_btn').on('click', function(e){ 
							$('#rtnd_view_template_result').fadeOut('',function(){
									$('#rtnd_email_info').html('<p align="center"><img src="<?php echo site_url()?>/wp-admin/images/spinner.gif"/></p>');
							}); 
					});
					$('#woocommerce_rtnd_coupon_notification_view_result').prop('value','<?php _e('Open',RTND) ?>');
					$('#woocommerce_rtnd_coupon_notification_send_check').prop('value','<?php _e('Send',RTND) ?>');
					$('#woocommerce_rtnd_coupon_notification_view_result').on('click',function(e){
						$('#rtnd_view_template_result').fadeIn();
						$.ajax({
							url:ajaxurl,
							data:{'action':'rtnd_ajax','do':'checksmtpview','nonce':'<?php echo wp_create_nonce(); ?>'},
							type:'post',
							success: function(data){
								$('#rtnd_email_info').html('');	
								$('#rtnd_email_info').html(<?php echo $etype == 'plain'? "'<pre>'+data+'</pre>'" : "data"; ?>);
							},
							error: function(a,b,error){
								$('#rtnd_email_info').html(error);
							}
						});
					});
                    $('#woocommerce_rtnd_coupon_notification_send_check').on('click',function(e){
                        var recipient = '';
                        if(!( recipient = prompt('<?php _e('Enter recepient address',RTND) ?>:',''))) return;
                        $('#rtnd_view_template_result').fadeIn();
                        $.ajax({
                            url:ajaxurl,
                            data:{'action':'rtnd_ajax','do':'checksmtp','recipient':recipient, 'nonce':'<?php echo wp_create_nonce(); ?>'},
                            type:'post',
                            success: function(data){
                                $('#rtnd_email_info').html(data);
                            },
                            error: function(a,b,error){
                                $('#rtnd_email_info').html(error);
                            }
                        });
                    });
				});
			</script>
		<?php
	}
	
}