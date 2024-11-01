 <?php

	//Returnado settings page

	defined('ABSPATH') or die('Who are you, dude?');

	global $RTNDo;

	if(isset($_POST['rtnd_save_options'])) $RTNDo->set_options($_POST);

	$o = $RTNDo->get_options();

//	print_r( RTND_Klarna_Extend::get_shipping_method_id_by_label('Free Shipping') );

	//test currency converter
//    global $RTND_Processor;
//    echo $RTND_Processor->convert_currency( 100, 'RUB', 'USD' );

// global $RTND_Collector;
// print_r( $RTND_Collector->count_all_customers(1) );

  ?>

  <script type="text/javascript" src="<?php echo RTNDURL.'/inc/js/default.js?v=1.0'; ?>"></script>
  <link rel='stylesheet' href='<?php echo RTNDURL?>/inc/css/styles_be.css?v=1.0.1' type='text/css' media='all' />
  
  <div id="processing">
	  <div id="wc-backbone-modal-dialog" tabindex="0" >
			<div class="wc-backbone-modal">
				<div class="wc-backbone-modal-content">
					<section class="wc-backbone-modal-main" role="main">
						<header class="wc-backbone-modal-header">
							<h1><?php _e('Synchronization',RTND) ?></h1>
							<button onClick="shadeOut();return false;" id="close-btn" class="modal-close modal-close-link dashicons dashicons-no-alt">
								<span class="screen-reader-text"><?php _e('Close',RTND)?></span>
							</button>
						</header>
						<article style="max-height: 738px;">
							<div id="rtnd_message">
								<img src="<?php echo site_url()?>/wp-admin/images/spinner.gif"/>
								<div class="caption"><?php _e('Please, wait until the synchronization process is over...',RTND)?></div>
                                <div class="clear"></div>
                                <div class="status-holder">
                                    <div class="status-bar"></div>
                                </div>
                                <div class="status"></div>
                                <div class="mem-status"></div>
							</div>
							<div id="success_message" class="hid">
								<img src="<?php echo RTNDURL?>/assets/img/done.png"/>
								<div class="caption">
									<p><?php _e('The synchronization is completed successfully!',RTND)?></p>
								</div>
							</div>
							<div id="error_message" class="hid">
								<img src="<?php echo RTNDURL?>/assets/img/error.png"/>
								<div class="caption">
									<p>
										<?php _e('There were errors in synchronization. Please, check Returnado server settings and try again...',RTND)?>
									</p>
									<p align="right"><a href="javascript:ShowInfo()"><?php _e('Information',RTND)?></a></p></div>
							</div>
                            <div class="info hid"></div>
						</article>
						<footer>
							<div class="inner">
								<button id="ok-btn" class="button button-primary button-large" style = "visibility:hidden" onClick="shadeOut(200);return false;">
									<?php _e('Ok',RTND) ?>
								</button>
							</div>
						</footer>
					</section>
				</div>
			</div>
			<div class="wc-backbone-modal-backdrop modal-close"></div>
		</div>
	</div>

 <a class="log-link"><?php echo 'ver. '.RTNDVERSION; ?></a>

 <div class="change-log"><?php include RTNDPATH.'/logs/versionlog.html'; ?></div>

  <h3><?php _e('Returnado API extension plugin',RTND); ?></h3>

  
  <p>
        <a class="button button-primary" onclick="ReturnadoSync('<?php echo wp_create_nonce()?>', 0);"><?php _e('Partial sync',RTND) ?></a>
        <a class="button button-secondary" onclick="ReturnadoSync('<?php echo wp_create_nonce()?>', 1);"><?php _e('Full sync',RTND) ?></a>
  </p>

  <p><?php _e('The synchronization with Returnado server is automatic by default.',RTND) ?></p>
  
  <hr/>
  <h3><?php _e('API settings',RTND)?></h3>
  <table class="form-table" border="0" style="width:80%;">
	<tr class="form-field form-required">
			<th scope="row">
				<label for="enable_option_id"><?php _e('Returnado API:',RTND);?> </label>
				<p style="font-size:80%;font-style:italic;"><?php _e('(Basic authentication method)',RTND) ?></p>
			</th>
			<td>
				<p><input type="checkbox" name="rtnd_api_enabled" id="enable_option_id" value="yes" <?php echo($o['rtnd_api_enabled']=='yes'?'checked':'')?> /></p>
			</td>
			<td align=right>
				<label for="enable_test_option_id"><?php _e('Enable Returnado API Test Platform:',RTND);?> </label>
			</td>
			<td>
					<input type="checkbox"  style="margin-top:7px"
                           name="rtnd_api_test_enabled"
                           id="enable_test_option_id" value="yes" <?php echo($o['rtnd_api_test_enabled']=='yes'?'checked':'')?> />
					<a class="button-secondary" href="<?php echo site_url().'/'.$RTNDo->back_end_slug[1]?>/" target="_blank">
						<?php _e('Open',RTND)?>
					</a>
			</td>
	 </tr>
      <tr>
          <td style="padding:0"></td><td style="padding:0"></td>
          <td align=right style="padding:0 10px 0 0">
              <label for="enable_test_mode_id"
                     title="<?php _e('Basic Auth in front end using current user and password', RTND); ?>"><i><?php _e('Test mode:',RTND);?></i></label>
          </td>
          <td style="padding:0 0 0 10px">
              <input type="checkbox" name="rtnd_test_mode" title="<?php _e('Basic Auth on front end using current user and password', RTND); ?>"
                     id="enable_test_mode_id" value="yes" <?php echo($o['rtnd_test_mode']=='yes'?'checked':'')?> />
          </td>
      </tr>

	 </table>

	 <h3><?php _e('General settings',RTND)?></h3>

	 <table class="form-table" border="0" style="">
	 <tr>
		<th>
			<label for="enable_sync_option_id"><?php _e('Automatic sync:',RTND);?> </label>
		</th>
		<td colspan="3">
			<p>
				<input type="checkbox" name="rtnd_sync_enabled" id="enable_sync_option_id" value="yes" <?php echo($o['rtnd_sync_enabled']=='yes'?'checked':'')?> />
                <span class="hint">
                    <?php _e('This option allows automatic synchronization to Returnado on actions: new user created, user updated, new product published, published product updated, order completed, stock changed.',RTND) ?>
                </span>
			</p>

		</td>
	</tr>
	<tr>
		<th>
			<label for="widget_shortcode_id"><?php _e('Returnado Widget shortcode:',RTND);?> </label>
		</th>
		<td colspan="3">
			<p><input type="text" name="rtnd_widget_shortcode" id="widget_shortcode_id" value="<?php echo $o['rtnd_widget_shortcode']?>"/></p>
		</td>
	</tr>
	<tr>
		<th>
			<label for="auto_refund_id"><?php _e('Automatically refund via API:',RTND);?> </label>
			<p style="font-size:80%;font-style:italic;"><?php _e('(Using order payment gateway)',RTND) ?></p>			
		</th>
		<td colspan="3">
			<p>
                <input type="checkbox" name="rtnd_api_refund" id="auto_refund_id" value="yes" <?php echo ('yes' === $o['rtnd_api_refund']?'checked':''); ?>/>
                <span class="hint">
                    <?php _e('New return orders from Returnado will be refunded automatically using the payment method and gateway stored in the original order.',RTND) ?>
                </span>
            </p>
		</td>
	</tr>
	<tr>
		<th>
			<label for="rtnd_pmgw_def_"><?php _e('Preferable payment method',RTND);?>:</label>
		</th>
		<td colspan = "2">
			<p><select name="rtnd_pmgw_def" id="rtnd_pmgw_def_">
				<option value='0' selected><?php _e('WooCommerce default',RTND);?></option>
				<?php
					foreach ( WC()->payment_gateways()->payment_gateways as $gateway )
						if ( $gateway->enabled=='yes' )
							echo "<option value='$gateway->id'".($o['rtnd_pmgw_def']==$gateway->id?' selected ':'').">$gateway->method_title</option>";
				?>
			</select></p>
		</td>
		<td>
			<p class="hint">
				<?php _e('New booking orders from Returnado will have this payment method as default one',RTND) ?>
			</p>
		</td>
	</tr>
	
	<tr>
		<th>
			<label for="rtnd_include_shipping_"><?php _e('Include shipping',RTND);?>:</label>
		</th>
		<td colspan = "2">
			<p><select name="rtnd_include_shipping" id="rtnd_include_shipping_">
				<option value='no' <?php if($o['rtnd_include_shipping'] == 'no') echo 'selected';?> ><?php _e('Exclude',RTND);?></option>
				<option value='yes' <?php if($o['rtnd_include_shipping'] == 'yes') echo 'selected';?> ><?php _e('Include',RTND);?></option>
			</select></p>
		</td>
		<td>
			<p class="hint">
				<?php _e('Shipping will be included/excluded into/from every new Returnado reconversion order',RTND) ?>.
			</p>
		</td>
	</tr>
	
	<tr>
		<th>
			<label for="rtnd_update_stock_"><?php _e('Update stock',RTND);?>:</label>
		</th>
		<td colspan = "3">
			<p>
                <input type="checkbox" <?php echo($o['rtnd_update_stock'] === 'yes' ?' checked ':'');?>
                       value="yes" name="rtnd_update_stock" id="rtnd_update_stock_" />
                <span class="hint">
                    <?php _e('If enabled reducing stock will be performed on approving preliminary order and items will be back in stock if returned successfully',RTND) ?>.
                </span>
            </p>
		</td>
	</tr>

     <tr>
         <th>
             <label><?php _e('Return value as',RTND);?>:</label>
         </th>
         <td colspan = "3">
             <p><label><input type="radio"
                              value="1" <?php echo($o['rtnd_virt_item']==1?'checked':'') ?>
                              name="rtnd_virt_item" />
                     <?php _e('Original products with negative diminished prices',RTND) ?>
                 </label></p>
             <p><label><input type="radio"
                              value="2" <?php echo($o['rtnd_virt_item']==2?'checked':'') ?>
                              name="rtnd_virt_item" />
                     <?php _e('Virtual product with total negative price',RTND) ?>
                 </label></p>
         </td>
     </tr>

	<tr>
		<th>
			<label for="rtnd_use_coupons_"><?php _e('Use store credit',RTND);?>:</label>
		</th>
		<td colspan = "3">
			<p>
                <input type="checkbox"
                       value="yes"
                       name="rtnd_use_coupons" <?php echo($o['rtnd_use_coupons']=='yes'?'checked':'') ?>
                       id="rtnd_use_coupons_"/>
                <span class="hint">
                    <?php _e('Coupon will be used as a store credit for refunding or exchanging for a less amount',RTND) ?>.
                </span>
            </p>
		</td>
	</tr>

     <tr>
         <th>
             <label for="rtnd_origin_prices_"><?php _e('Use original prices',RTND);?>:</label>
         </th>
         <td colspan = "3">
             <p>
                <input type="checkbox"
                       value="yes"
                       name="rtnd_original_prices" <?php echo($o['rtnd_original_prices']=='yes'?'checked':'') ?>
                       id="rtnd_origin_prices_"/>
                 <span class="hint">
                     <?php _e('Original product prices received from Returnado will be set for products instead of current ones on an exchange',RTND) ?>.
                 </span>
             </p>
         </td>

     </tr>

     </table>

    <a name="precision_settings"></a>

    <h3><?php _e('Price and tax rounding options', RTND) ?></h3>

    <table class="form-table" border="0" style="">

     <tr>
         <th>
             <label for="rtnd_min_precision_"><?php _e( 'Minimum number of decimals', RTND );?>:</label>
         </th>
         <td>
             <p>
                 <input type="number"
                        style="width:50px;"
                        name="rtnd_min_precision"
                        value="<?php echo($o['rtnd_min_precision']?$o['rtnd_min_precision']:WOOMINPRECISION) ?>"
                        pattern="[0-9]"
                        min="<?php echo WOOMINPRECISION ?>"
                        max="10"
                        id="rtnd_min_precision_"/>
             </p>
         </td>
         <td colspan = "2">
             <p class="hint">
                 <?php _e('Allowed WooCommerce number of decimals for price and tax rounding',RTND) ?>.
             </p>
         </td>

     </tr>

         <tr>
             <th>
                 <label for="rtnd_cut_trails_"><?php _e( 'Remove trailing zeroes', RTND );?>:</label>
             </th>
             <td>
                 <p>
                     <label>
                         <input type="checkbox"
                                value="yes"
                                name="rtnd_cut_trails" <?php echo(isset($o['rtnd_cut_trails'])
                                                             && $o['rtnd_cut_trails']==='yes'?'checked':'') ?>
                                id="rtnd_cut_trails_"/>
                         <?php _e('Enabled', 'woocommerce') ?>
                     </label>
                 </p>
             </td>
             <td colspan = "2">
                 <p class="hint">
                     <?php _e('All trailing zeroes in prices will be removed. E.g. 12,00 will be just 12',RTND) ?>.
                 </p>
             </td>
         </tr>

    </table>

     <h3><?php _e('Returnado Authentication',RTND);?></h3>

     <table class="form-table" border="0" style="">

     <tr>
         <td width="200px"></td>
		<td>
            <table border="0" style="width:100%;margin:0;padding:0">
                <tr>
                    <td><label for="rtnd_shop_id_"><?php _e('Shop ID',RTND); ?>:</label></td>
                    <td><input type="text" name="rtnd_shop_id" id="rtnd_shop_id_"
                               value="<?php echo $o['rtnd_shop_id']?>"/></td>

                </tr>
                <tr>
                    <td><label for="rtnd_remote_user_"><?php _e('User',RTND); ?>:</label></td>
                    <td><input type="text" name="rtnd_remote_user" id="rtnd_remote_user_"
                               value="<?php echo $o['rtnd_remote_user']?>"/></td>

                </tr>
                <tr>
                    <td><label for="rtnd_remote_password_"><?php _e('Password',RTND); ?>:</label></td>
                    <td><input type="password" id="rtnd_remote_password_" autocomplete="new-password"
                               name="rtnd_remote_password" value="<?php echo $o['rtnd_remote_password']?>" /></td>
                    <script type="text/javascript">
                        $j('#rtnd_remote_password_').hover(function () {
                            $j('#rtnd_remote_password_').attr('type', 'text');
                        }, function () {
                            $j('#rtnd_remote_password_').attr('type', 'password');
                        });
                    </script>
                </tr>
                <tr>
                    <td><label><?php _e('Logs',RTND); ?>:</label></td>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="rtnd_log_incoming"
                                   value="yes" <?php if('yes' === $o['rtnd_log_incoming']) echo'checked'; ?> />
                            <?php _e('Incoming (from)',RTND) ?>
                        </label>
                        <label style="margin-left:15px;">
                            <input type="checkbox"
                                   name="rtnd_log_outgoing"
                                   value="yes" <?php if('yes' === $o['rtnd_log_outgoing']) echo'checked'; ?> />
                            <?php _e('Outgoing (to Returnado server)',RTND) ?>
                        </label>

                        <!-- DEEP LOGGING OPTION -->
                        <label style="margin-left:15px;" title="<?php _e('Log every tiny step on return/refund',RTND) ?>">
                            <input type="checkbox"
                                   name="rtnd_log_deep"
                                   value="yes" <?php if('yes' === $o['rtnd_log_deep']) echo'checked'; ?> />
                            <?php _e('Deep logging',RTND) ?>
                        </label>

                    </td>
                </tr>
                <tr>
                    <td><a href="javascript:void()" title="<?php _e('Extra plugin options', RTND) ?>" onclick="jQuery('.rtnd_configure_hosts').slideToggle()"><?php _e('Configure hosts', RTND); ?></a></td>
                    <td>
                        <div class="rtnd_configure_hosts"
                             style="display:none;
                                    border: 1px solid #ccc;
                                    border-radius: 3px; padding: 5px 15px 15px 10px;">
                            <p>
                                <label><?php _e('Synchronization host', RTND);?>:<br/>
                                    <input type="text" name="rtnd_remote_host" value="<?php echo $o['rtnd_remote_host'] ?>"/>
                                </label>
                            </p>
                            <br/>
                            <p>
                                <label><?php _e('Widget host', RTND);?>:<br/>
                                    <input type="text" name="rtnd_remote_widget_host" value="<?php echo $o['rtnd_remote_widget_host'] ?>"/>
                                </label>
                            </p>
                            <br/>
                            <p>
                                <label><?php _e('Admin host', RTND);?>:<br/>
                                    <input type="text" name="rtnd_remote_admin_host" value="<?php echo $o['rtnd_remote_admin_host'] ?>"/>
                                </label>
                            </p>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
 </table>

 <input type="hidden" name="rtnd_save_options" value="1" />

<hr/>