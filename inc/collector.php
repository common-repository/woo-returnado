<?php

 //exit if accessed directly
 if(!defined('ABSPATH')) exit;

 /*
  * class Collector is used to gather all required info to send to Returnado
  *
  */

 class RTND_Collector {
	//set RTND options for the class

	private $options = array();

	public function __construct($options = []) {
		if(empty($options)){
			global $RTNDo;
			if(!isset($RTNDo)) $options = [];
			else $options = $RTNDo->get_options();
		}
		$this->options = $options;
	}
	 /**
	  * Get category by id
	  *
	  * @param int $id
	  *
	  * @return array|void
	  */
	 public function get_category( $id = 0 ){
		if (!$id) return array();
		$cat = get_term_by('id',$id,'product_cat');
		if(!$cat) return;
		$res []= array(
							'categoryId' => $cat->term_id,
							'internalCategoryId' => 0,
							'name'		 => $cat->name
						);
		return $res;
	}

	 /**
	  * Retrieving all categories
	  *
	  * @param int $size
	  * @param int $page
      * @param string $order
	  *
	  * @return array
	  */
	 public function get_all_categories($size = 100, $page = 0, $order = 'asc'){
        $order = ( 'asc' === $order ? 'ASC' : 'DESC' );
		if (!$size&&!$page)
			$args = array(
					'hide_empty' => 0,
					'orderby'          => 'term_id',
					'order'            => $order,
					'nopaging'	       => true,
					'suppress_filters' => 1
				);
		else{
			$offset = ( $page-1 ) * $size;
			$args = array(
					'hide_empty'       => 0,
					'number'           => $size,
					'offset'           => $offset,
					'orderby'          => 'term_id',
					'order'            => $order,
					'suppress_filters' => 1
				);
		}
		$i=0;
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$cats = get_terms('product_cat',$args);
		$res=array();
		foreach($cats as $cat)
			$res[$i++] = array(
							'categoryId' => $cat->term_id,
							'internalCategoryId' => 0,
							'name'		 => $cat->name);
		
		$RTND_Processor->wpml(1);
		
		return $res;
	}


	 /**
	  * Retrieve Image name prefixed with <server_name>/wp-content/uploads/
	  *
	  * @param $s
	  *
	  * @return bool|string
	  */
	 private function get_image_name($s){
		$p=strpos($s,'/uploads/');
		if ($p)
			return substr($s,$p+9);
		else
			return 'no-image.png';
	}

	 /**
	  * Retrieve product gallery
	  *
	  * @param $productObj
	  *
	  * @return array
	  *
	  * compatible to WC 3.0
	  */
	 public function get_product_gallery($productObj){

	 	global $RTNDo;
	 	$wc30 = $RTNDo->wc_version();

		$attachment_ids = ($wc30?$productObj->get_gallery_image_ids():$productObj->get_gallery_attachment_ids());
		$r=array();$i=0;
		foreach( $attachment_ids as $attachment_id ) 
			$r[$i++] = $this->get_image_name(wp_get_attachment_url( $attachment_id ));
		if (empty($r))
			{
				$image = wp_get_attachment_image_src( get_post_thumbnail_id( $wc30?$productObj->get_id():$productObj->id ), 'single-post-thumbnail' );
				$r[] = $this->get_image_name($image[0]);
			}
        return $r;
	}

	 /**
	  * Get variation attributes for Returnado
	  *
	  * @param $product_variation
	  *
	  * @return array
	  */
	 private function get_variation_attributes( $product_variation ){
	     $v_attributes = $product_variation->get_variation_attributes();
	     $attributes = [];
	     if(!empty($v_attributes))
            foreach ( $v_attributes as $attribute_name => $attribute ) {
                    // taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`
                        $a_name = ucwords( str_replace( 'attribute_', '', str_replace( 'pa_', '', $attribute_name ) ) );
                        $attributes[$a_name] = array (		'label'	=> $a_name,
                                                            'value' => $attribute );
                }
		return $attributes;
	}

	 /**
	  * Get price in a specified currency
	  *
	  * @param $pid
	  * @param $cid
	  *
	  * @return int|null|string
	  */
	 private function get_currency_price( $pid, $cid ){

	     global $wpdb;

	     //retrieve original price directly from DB
         $price = $wpdb->get_var( "SELECT `meta_value` FROM `{$wpdb->prefix}postmeta` WHERE `meta_key` = '_price' and `post_id` = $pid" );

         //default price
         if(!$cid) $cid = get_option('woocommerce_currency');

         else if(!in_array($cid,$this->fetch_currencies())) {
             return 0;
         }

         //WOCS suppport
         global $WOOCS;
         if(isset($WOOCS)) {
             $WOOCS->set_currency($cid);
             return $WOOCS->woocs_exchange_value($price);
         }

        //aelia
        if (class_exists('WC_Aelia_CurrencySwitcher')) {
            $prices = json_decode($wpdb->get_var("SELECT `meta_value` FROM `{$wpdb->prefix}postmeta` 
													WHERE `meta_key` = '_sale_currency_prices' AND `post_id` = $pid"),true);
            //check if sale prices outdated
            if(!empty($prices)){
                $outdated = $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}postmeta` WHERE `meta_key` = '_sale_price_dates_from' 
																							 AND `meta_value` <> '' 
																							 AND `meta_value` > UNIX_TIMESTAMP() 
																							 AND `post_id` = $pid");
                if(!$outdated)
                    $outdated = $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}postmeta` WHERE `meta_key` = '_sale_price_dates_to' 
																								 AND `meta_value` <> '' 
																								 AND `meta_value` < UNIX_TIMESTAMP() 
																								 AND `post_id` = $pid");
            }
            if (empty($prices) || $outdated) //no sale prices set
                $prices = json_decode($wpdb->get_var("SELECT `meta_value` FROM `{$wpdb->prefix}postmeta` WHERE `meta_key` = '_regular_currency_prices' 
																										   AND `post_id` = $pid"),true);
                //if required price is set
            if (!empty($prices)){
                if (isset($prices[$cid])) return $prices[$cid];
            }
            else {//no prices set - convert
                $cs = WC_Aelia_CurrencySwitcher::instance();
                $def_cid = get_option('woocommerce_currency');
                $price = $cs->convert($price,$def_cid,$cid,5,false);
				if($price) return $price;
				return 0;
            }
        }

        //wcpbc
        if(class_exists('WC_Product_Price_Based_Country')){
            $countries = get_option('wc_price_based_country_regions');
            if(is_array($countries))
                foreach($countries as $country_id=>$country)
                    if($country['currency']==$cid) break;
                    else $country_id = 0;
            if($country_id){
                $price = $wpdb->get_var( "SELECT `meta_value` 
											FROM `{$wpdb->prefix}postmeta` 
											WHERE `meta_key` = '_".$country_id."_price' 
											AND `post_id` = $pid" );
                if($price)
                    return $price;
            }
        }

        return $price;
    }

	 /**
	  * Get product net price independently from any other filtering
	  *
	  * @param $id
	  * @param int $vid
	  * @param int $tax_amount
	  * @param string $currency_id
	  *
	  * @return float
	  */
	 public function get_product_price( $id, $vid = 0, &$tax_amount = 0, $currency_id = '' ){
		//get price
		$price_precision = 5;$tax_amount = 0;
		global $wpdb;
		if(!$vid) $vid = $id;
        $price = $this->get_currency_price($vid, $currency_id);
		if(!$price) return 0;
		//get tax for price
		//get tax status
		if('taxable'!==get_post_meta( $id, '_tax_status', true )) return wc_format_decimal($price,$price_precision);
		//get tax rate
		$tax_rate = $this->get_product_tax_rate(wc_get_product($id));
		 if ('yes' === get_option('woocommerce_prices_include_tax')) $tax_rate = $tax_rate / ($tax_rate + 1);
		$tax = wc_format_decimal( $price * $tax_rate, $price_precision );
		$tax_amount = $tax;
		if ('yes' === get_option('woocommerce_prices_include_tax')) return wc_format_decimal( $price - $tax, $price_precision );
		return wc_format_decimal($price, $price_precision);
	}

	 /**
	  * Retrieve product tax rate
	  *
	  * @param $product
	  *
	  * @return float
	  */
	 private function get_product_tax_rate($product){
		if ( !$product->is_taxable() ) return 0.0;
		$rates = WC_Tax::get_rates($product->get_tax_class());
		if(empty($rates)) { //no default rates - getting rates for default country
			$def_country = get_option( 'woocommerce_default_country' );
			$rates       = WC_Tax::find_rates( [ 'country' => $def_country, 'tax_class' => $product->get_tax_class() ] );
		}
		if(empty($rates)) return 0.0;
		$r = 0.0;
		foreach ( $rates as $key => $rate ) $r+=$rate['rate'];
        return $r/100;
	}

	 /**
	  * Retrieve product tax value
	  *
	  * @param $product
	  *
	  * @return array|float|int
	  */
	 private function get_product_tax($product) {
		$price = $product->get_price();
		if ( !$product->is_taxable() ) return 0;
		$tax_amount = 0;
		if ( get_option( 'woocommerce_prices_include_tax' ) === 'no' ) {
			$tax_rates  = WC_Tax::get_rates( $product->get_tax_class() );
			$taxes      = WC_Tax::calc_tax( $price, $tax_rates, false );
			$tax_amount = WC_Tax::get_tax_total( $taxes );
		} else {
				$tax_rates      = WC_Tax::get_rates( $product->get_tax_class() );
				$base_tax_rates = WC_Tax::get_base_tax_rates( $product->tax_class );
				if ( ! empty( WC()->customer ) && WC()->customer->is_vat_exempt() ) {
					$base_taxes    = WC_Tax::calc_tax( $price, $base_tax_rates, true );
					$tax_amount    = array_sum( $base_taxes );
				} elseif ( $tax_rates !== $base_tax_rates && apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {
					$base_taxes         = WC_Tax::calc_tax( $price, $base_tax_rates, true );
					$tax_amount         = WC_Tax::calc_tax( $price - array_sum( $base_taxes ), $tax_rates, false );
				}
			}
		return $tax_amount;
    }


	 /**
	  * Retrieve product post
	  *
	  * @param $product_id
	  *
	  * @return mixed
	  */
	 public function get_product_post($product_id){
		$args = array(
			'post_type' => 'product',
			'supress_filters' => 1,
			'p'			=> $product_id
			);
		$p = new WP_Query($args);
		return $p->posts[0];
	}

	 /**
	  * Collect all product categories
	  *
	  * @param $id
	  *
	  * @return array
	  */
	 public function get_product_categories($id){
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'all' ) );
		$i=0;
		$res=array();
		foreach($cats as $cat)
			$res[$i++] = array(
							'categoryId' => $cat->term_id,
							'name'		 => $cat->name);
		$RTND_Processor->wpml(1);
		return $res;
	}

	 /**
	  * Get all cross-sells
	  *
	  * @param $product_id
	  * @param $currency_id
	  *
	  * @return array
	  */
	 protected function get_crossells_products($product_id, $currency_id){
		$r = array();
		if (!$product_id) return $r;
		$cross_sells		= get_post_meta($product_id, '_crosssell_ids', true);
		if(!empty($cross_sells))
			foreach($cross_sells as $crosssel)
				$r[] = $this->get_product($crosssel, 'crosssells', $currency_id)[0];
		return $r;
		
	}

	 /**
	  * Get all up-sells
	  *
	  * @param $product_id
	  * @param $currency_id
	  *
	  * @return array
	  */
	 protected function get_upsells_products($product_id, $currency_id){
		$r = array();
		if (!$product_id) return $r;
		$up_sells 		= get_post_meta($product_id, '_upsell_ids', true);
		if(!empty($up_sells))
			foreach($up_sells as $upsel)
				$r[] = $this->get_product($upsel, 'upsells', $currency_id)[0];
		return $r;
	}


	 /**
	  * Get all related products (including cross-sells and up-sells)
	  *
	  * @param $product_id
	  * @param $currency_id
	  *
	  * @return array
	  */
	 protected function get_related_products( $product_id = 0, $currency_id = ''){
		$r = array();
		if (!$product_id) return $r;
		$cross_sells	= get_post_meta($product_id, '_crosssell_ids', true);
		if(!$cross_sells) $cross_sells = [];
		$up_sells 		= get_post_meta($product_id, '_upsell_ids',true);
		if(!$up_sells) $up_sells = [];
		 //WC 3.0
		 global $RTNDo; $wc30 = $RTNDo->wc_version();
		 if($wc30)
			 $relateds = wc_get_related_products( $product_id, 10, array_merge( $cross_sells, $up_sells) );
		 else{
			 $pr = wc_get_product($product_id);
			 $relateds		= $pr->get_related( 10 );
		 }

		if(!empty($relateds))
			foreach($relateds as $rel)
				$r[] = $this->get_product( $rel, 'related', $currency_id )[0];

		//add upsells and crosssells
        $upsells = $this->get_upsells_products($product_id, $currency_id);
        $crosssells = $this->get_crossells_products($product_id, $currency_id);
        if(!empty($upsells)) $r = array_merge($r,$upsells);
        if(!empty($crosssells)) $r = array_merge($r,$crosssells);

		return $r;
	}

	 /**
	  * Get variations for a product object with prices in the specified currency
	  *
	  * @param $product
	  * @param string $currency_id
	  *
	  * @return array
	  *
	  * compatible with WC > 3.0
	  */
	 private function get_variations( $product, $currency_id = '', $stockEnabled = true ) {
		 $variations = array();

		 global $RTNDo; $wc30 = $RTNDo->wc_version();

		 foreach ( $product->get_children() as $child_id ) {

			 $variation = ($wc30?wc_get_product($child_id):$product->get_child( $child_id ));

			 if (!$variation) continue;

			 if ( !$variation->exists() ) continue;

			 $vid = ($wc30?$variation->get_id():$variation->get_variation_id());

			 $pid = ($wc30?$product->get_id():$product->id);

			 $cost = $this->get_product_price( $pid, $vid, $tax_amount, $currency_id );

			 if($cost)
				 $variations[] = array(
					 'productVariantId'  => $vid,
					 'name'		         => html_entity_decode($product->get_title()),
					 'cost'       	 	 => $cost,
					 'taxAmount'		 => $tax_amount,
					 'precision'		 => wc_get_price_decimals(),
					 'vatRate'			 => wc_format_decimal( $this->get_product_tax_rate($variation), 2 ),
					 'quantity'   		 => (int) $variation->get_stock_quantity(),
					 'sku'               => $variation->get_sku(),
					 'stockStatus'		 => $variation->is_in_stock() && $stockEnabled,
					 'currency'			 => ($currency_id?$currency_id:get_option( 'woocommerce_currency' )),
					 'images'			 => array($this->get_image_name(wp_get_attachment_image_src( get_post_thumbnail_id($vid))[0])),
					 'attributes'        => $this->get_variation_attributes( $variation ),
				 );
		 }
		 return $variations;
	 }

	 /**
	  * Make single product variable with one variant
	  *
	  * @param $product
	  * @param string $currency_id
	  *
	  * @return array
	  *
	  * compatible to WC > 3.0
	  */
	 private function transform_into_variation( $product, $currency_id = '', $stockEnabled = true ) {
		 $variation = array();
		 $pid = (method_exists($product,'get_id')?$product->get_id():$product->id);
		 $cost = $this->get_product_price( $pid, $pid, $tax_amount, $currency_id );
		 if($cost)
			 $variation[] = array(
				 'productVariantId'  => $pid,
				 'name'		        => html_entity_decode($product->get_title()),
				 'cost'       		=> $cost,
				 'taxAmount'			=> $tax_amount,
				 'precision'			=> wc_get_price_decimals(),
				 'vatRate'			=> wc_format_decimal( $this->get_product_tax_rate($product), 2 ),
				 'quantity'   		=> (int) $product->get_stock_quantity(),
                 'sku'              => $product->get_sku(),
				 'stockStatus'		=> $product->is_in_stock() && $stockEnabled,
				 'currency'			=> ($currency_id?$currency_id:get_option( 'woocommerce_currency' )),
				 'images'			=> $this->get_product_gallery( $product ),
				 'attributes'        => [],
			 );
		 return $variation;
	 }

     /**
      * Get list of product attributes
      *
      * @param WC_Product $product
      *
      * @return array
      *
      */

     public function get_product_attributes( $product ){
         $attrs = $product->get_attributes();
         $r = array();
         if(!empty($attrs))
            foreach($attrs as $attribute=>$value){
                $attr_name = str_replace( 'attribute_', '', str_replace( 'pa_', '', $attribute ) );
                $product_attributes = get_terms( $attribute );
                $values = array();
                if(is_wp_error($product_attributes) || !$product_attributes || $attr_name === $attribute){
                    //custom product attribute
                    $values = $value['options'];
                }else {
                    //taxonomy
                    $values = array_map(function ($a) {
                        return $a->name;
                    }, $product_attributes);
                }
                $r[$attr_name] = [
                    'values' => $values,
                    'useForVariants' => $value['variation']
                ];
            }
         return $r;
     }

	 /**
	  * Product information collector for Returnado
	  *
	  * @param $id
	  * @param bool $only_this
	  * @param string $currency_id
	  *
	  * @return array
	  */
	 public function get_product( $id, $only_this = false, $currency_id = '' ){

	    if(!(int)$id) return [];

		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$product = '';

		if((int)$id)
		    $product = wc_get_product($id);

		//if ($product->parent_id) { //requested variation ID - change to parent - temporary disabled
			//$id = $product->parent_id;
			//$product = wc_get_product($id);
		//}

		if(!$product || !($product->is_type('simple') || $product->is_type('variable'))) { $RTND_Processor->wpml(1); return []; }
		$r = null;
		$product_post = get_post($id);

		if (!$product_post) { $RTND_Processor->wpml(1); return []; }

		//status-dependency is currenctly disabled (since 0.3.1.4)
		//if ($product_post->post_status!='publish') {$RTND_Processor->wpml(1);return;}
		 //but setting up status field

		 $status = $product_post->post_status;

		$product_description = '';
		if(!empty($product_post->post_excerpt))
		    $product_description = html_entity_decode($product_post->post_excerpt);

		if(!$product_description && !empty($product_post->post_content))
            $product_description = html_entity_decode($product_post->post_content);

		 $r = array( [
             'productId'    => $id,
             'name'		    => html_entity_decode($product->get_title()),
             'description'  => $product_description,
             'images'	    => $this->get_product_gallery( $product ),
             'rating'	    => $product->get_average_rating(),
             'manufacturer' => '',
             'category'	    => $this->get_product_categories($id),
             'sku'          => $product->get_sku(),
             'status'       => $status,
             //'attributes'   => $this->get_product_attributes( $product ),
             'variants'	    => ($product->has_child()
                            ? $this->get_variations($product, $currency_id, ('publish' === $status))
                            : $this->transform_into_variation($product, $currency_id, ('publish' === $status))),
             'modified'     => strftime( "%Y-%m-%dT%TZ", strtotime( $product_post->post_modified_gmt ) )
         ] );
		if($only_this) {
            if ($only_this !== 'all')
                $r[0]['channel'] = $only_this;
        }
		else
            $r[0]['relatedProducts'] = $this->get_related_products($id, $currency_id);

		$RTND_Processor->wpml(1);

		return $r;
	}

	 /**
	  * Get all product information with pagination
      *
      * !!!Since 0.4.7 reworked using WPDB and SQL query in order to process synced/unsynced products
	  *
	  * @param int $size
	  * @param int $page
      * @param string $order
      * @param string $search
      * @param bool $sync
      * @param string $updatedAfter ISO 8601 datetime string
	  *
	  * @return array
	  */
	 public function get_all_products( $size = 20, $page = 1, $order = 'asc', $search = '', $sync = false, $updatedAfter = '' ){

	    $order = ( 'asc' === $order ? 'ASC' : 'DESC' );

	    global $wpdb; if(empty($wpdb)) return [];

	    $offset = ($page?( $page-1 ) * $size:0);
	    $limit = ($size?$size:20);
	    $search_title = ($search?esc_sql( $wpdb->esc_like( $search ) ):'');

	    $sql = "SELECT ID ".
               "FROM {$wpdb->prefix}posts t1"
                .($sync
                    ?" LEFT JOIN {$wpdb->prefix}postmeta t2
                        ON  t2.post_id = t1.ID 
                        AND UNIX_TIMESTAMP( STR_TO_DATE( t1.post_modified, '%Y-%m-%d %h:%i:%s' ) ) > t2.meta_value
                        AND t2.meta_key = '_rtnd_synced'
                       LEFT JOIN {$wpdb->prefix}postmeta t3
                        ON t3.post_id = t1.ID 
                        AND t3.meta_key = '_rtnd_synced' "
                    :'')
                .($search_title
                    ?" LEFT JOIN {$wpdb->prefix}postmeta t4
                        ON t4.post_id = t1.ID 
                        AND t4.meta_key = '_stock_status'"
                    :'')
                //since 0.4.7.34 we added checking for woocommerce product type: only simple or variable ones
                ." INNER JOIN {$wpdb->prefix}term_relationships AS term_relationships ON t1.ID = term_relationships.object_id
                   INNER JOIN {$wpdb->prefix}term_taxonomy AS term_taxonomy ON term_relationships.term_taxonomy_id = term_taxonomy.term_taxonomy_id
                   INNER JOIN {$wpdb->prefix}terms AS terms ON term_taxonomy.term_id = terms.term_id
                WHERE
                    term_taxonomy.taxonomy = 'product_type'
                    AND ( terms.slug = 'simple' OR terms.slug = 'variable' )
                    AND t1.post_type = 'product'"
                .($sync?   " AND ( t2.post_id IS NOT NULL OR t3.post_id IS NULL )":'')
                .($search_title? " AND t1.post_title LIKE '%{$search_title}%'
                                   AND t1.post_status = 'publish'
                                   AND t4.meta_value = 'instock'":'')
                .($updatedAfter? " AND UNIX_TIMESTAMP( t1.post_modified_gmt ) 
                                     >= UNIX_TIMESTAMP( STR_TO_DATE( '$updatedAfter', '%Y-%m-%dT%TZ' ) )" : '' )
                ." GROUP BY ID "
                ." ORDER BY "
                .($updatedAfter? "t1.post_modified_gmt DESC" :"ID {$order}")
                ." LIMIT {$limit}"
                ." OFFSET {$offset}";

		//WPML support
		global $sitepress;
		if (isset($sitepress)) $sitepress->switch_lang('all');

        $res = [];

        $products = $wpdb->get_results( $sql );

        if( empty($products) || count($products) === 0 ) return $res;

        foreach($products as $p) {
            $pr = $this->get_product( $p->ID, 'all' );
            if ( !empty($pr) )
                $res[] = $pr[0];
        }

        return $res;
	}

     /**
      * Get products for testing platform with positive price and stock
      *
      * @return array
      */
     public function get_test_products(){
         $order = 'DESC';
         $size = 20; $page = 1;
         $offset = $page;
         $args = array(
             'post_type'        => 'product',
             'post_status'      => 'publish',
             'posts_per_page' => $size,
             'paged' => $offset,
             'orderby'          => 'ID',
             'order'            => $order,
             'suppress_filters'  => 1,
             'meta_query'       => [
                 [
                     'key'     => '_stock',
                     'compare' => '>',
                     'value'   => '1'
                 ],
                 [
                     'key'     => '_price',
                     'compare' => '>',
                     'value'   => '1'
                 ],[
                     'key'     => '_stock_status',
                     'compare' => '==',
                     'value'   => 'instock'
                 ]
             ]
         );

         //WPML support
         global $sitepress;
         if (isset($sitepress)) $sitepress->switch_lang('all');

         $products = new WP_Query( $args );
         $res = array();

         foreach($products->posts as $p) {
             $pr = $this->get_product($p->ID, 'all');
             if (!empty($pr))
                 $res[] = $pr[0];
         }

         return $res;
     }

	 /**
	  * Get one customer's information
	  *
	  * @param $id
	  *
	  * @return array|void
	  */
	 public function get_customer($id){
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$customer = get_user_by('id',$id);
		if(!$customer) {$RTND_Processor->wpml(1);return;}
		$cid = $customer->ID;
		$c_email = $customer->user_email;
		$customer_meta = get_user_meta($cid);
		$res[] = [
			'customerId' => $cid,
			'firstname'  => (
                isset($customer_meta['first_name'])&&!empty($customer_meta['first_name'][0])
                    ? $customer_meta['first_name'][0]
                    : (
                        isset($customer_meta['billing_first_name']) && !empty($customer_meta['billing_first_name'][0])
                        ? $customer_meta['billing_first_name'][0]
                        : ''
                      )
            ),
			'lastname'   => (
			    isset($customer_meta['last_name']) && !empty($customer_meta['last_name'][0])
                    ? $customer_meta['last_name'][0]
                    : (
                        isset($customer_meta['billing_last_name']) && !empty($customer_meta['billing_last_name'][0])
                        ? $customer_meta['billing_last_name'][0]
                        : ''
                      )
            ),
			'email'		 => $c_email,
			'data'		 => (
			    isset($customer_meta['description']) && !empty($customer_meta['description'][0])
                    ? $customer_meta['description'][0]
                    : ''
            ),
            'modified'  => strftime( "%Y-%m-%dT%TZ", (
                !empty( $customer_meta['last_update'] )
                    ? $customer_meta['last_update'][0]
                    : strtotime( $customer->user_registered ) ) )
		];
		$RTND_Processor->wpml(1);
		return $res;
	}

	 /**
	  * Get all customer's information
	  *
	  * @param int $size
	  * @param int $page
      * @param string $order
      * @param string $search
      * @param bool $sync
      * @param string $updatedAfter ISO 8601 datetime format
	  *
	  * @return array
	  */
	 public function get_all_customers( $size = 50, $page = 0, $order = 'asc', $search = '', $sync = false, $updatedAfter = '' ){

	    $order = ( 'asc' === $order ? 'ASC' : 'DESC' );


         global $wpdb; if(empty($wpdb)) return [];

         $offset = ($page?( $page-1 ) * $size:0);
         $limit = ($size?$size:50);
         $search_title = ($search?esc_sql( $wpdb->esc_like( $search ) ):'');

         $sql = "SELECT ID ".
                "FROM {$wpdb->prefix}users t1
                ".($sync?"
                    LEFT JOIN {$wpdb->prefix}usermeta t2_1
                        ON  t2_1.user_id = t1.ID 
                        AND t2_1.meta_key = '_rtnd_synced'
                    LEFT JOIN {$wpdb->prefix}usermeta t2_2
                        ON  t2_2.user_id = t1.ID 
                        AND t2_2.meta_key = 'last_update'
                        AND t2_2.meta_value > t2_1.meta_value
                    LEFT JOIN {$wpdb->prefix}usermeta t3
                        ON t3.user_id = t1.ID 
                        AND t3.meta_key = '_rtnd_synced'
                ":'')
                .($updatedAfter?"
                    LEFT JOIN {$wpdb->prefix}usermeta t2_3
                        ON  t2_3.user_id = t1.ID 
                        AND t2_3.meta_key = 'last_update'
                        AND t2_3.meta_value >= UNIX_TIMESTAMP( STR_TO_DATE( '$updatedAfter', '%Y-%m-%dT%TZ' ) )
                ":'')
                ." WHERE 1=1 "
                .($sync?   " AND ( t2_2.user_id IS NOT NULL OR t3.user_id IS NULL )":'')
                .($search_title? " AND t1.user_email LIKE '%{$search_title}%'":'')
                .($updatedAfter? " AND ( t2_3.user_id IS NOT NULL  
                                          OR 
                                          UNIX_TIMESTAMP( t1.user_registered ) 
                                            >= UNIX_TIMESTAMP( STR_TO_DATE( '$updatedAfter', '%Y-%m-%dT%TZ' ) )
                                        )" : '' )
                 ." GROUP BY ID "
                 ." ORDER BY ID {$order} "
                 ." LIMIT {$limit} "
                 ." OFFSET {$offset}";

         //WPML support
         global $sitepress;
         if (isset($sitepress)) $sitepress->switch_lang('all');

         $res = [];

         $users = $wpdb->get_results( $sql );

         if( empty($users) || count($users) === 0 ) return $res;


		 foreach($users as $customer)
			$res[] = $this->get_customer( $customer->ID )[0];

		 return $res;
	}

	 /**
	  * Format datetime stamp to UTC or Y-m-d H:i:s
	  * @param $timestamp
	  * @param bool $convert_to_utc
	  *
	  * @return string
	  */
	 private function format_datetime( $timestamp, $convert_to_utc = false ) {
		if ( $convert_to_utc ) $timezone = new DateTimeZone( wc_timezone_string() );
		else $timezone = new DateTimeZone( 'UTC' );
		try {
			if ( is_numeric( $timestamp ) ) $date = new DateTime( "@{$timestamp}" );
			else $date = new DateTime( $timestamp, $timezone );
			if ( $convert_to_utc ) $date->modify( -1 * $date->getOffset() . ' seconds' );
		} catch ( Exception $e ) {$date = new DateTime( '@0' );}
		return $date->format( 'Y-m-d H:i:s' );
	}

    private function get_item_totals( $item_id ){
            if(!$item_id) return [ 'price' => 0, 'tax' => 0 ];
            global $wpdb;
            $price      = $wpdb->get_var( "SELECT `meta_value` FROM `{$wpdb->prefix}woocommerce_order_itemmeta` WHERE `meta_key` = '_line_total'        AND `order_item_id` = $item_id" );
            $tax        = $wpdb->get_var( "SELECT `meta_value` FROM `{$wpdb->prefix}woocommerce_order_itemmeta` WHERE `meta_key` = '_line_tax'          AND `order_item_id` = $item_id" );
            $sub_tax    = $wpdb->get_var( "SELECT `meta_value` FROM `{$wpdb->prefix}woocommerce_order_itemmeta` WHERE `meta_key` = '_line_subtotal_tax' AND `order_item_id` = $item_id" );
            $sub_price  = $wpdb->get_var( "SELECT `meta_value` FROM `{$wpdb->prefix}woocommerce_order_itemmeta` WHERE `meta_key` = '_line_subtotal'     AND `order_item_id` = $item_id" );
            return [
                'price'     => $price,
                'tax'       => $tax,
                'sub_price' => $sub_price,
                'sub_tax'   => $sub_tax
            ];
    }

	 /**
	  *
	  * Collect all information about one order
	  *
	  * @param $id
	  * @param bool $inclAwait
	  *
	  * @return array|string|void
	  *
	  * compatible to WC 3.0
	  */
	 public function get_order($id, $inclAwait = false){
	 	global $RTNDo; $wc30 = $RTNDo->wc_version();
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$order = wc_get_order( $id );
		$order_post = get_post( $id );
		if(!$order || !$order_post) {
			$RTND_Processor->wpml(1);
			return;
		}
		if ($order_post->post_status!='wc-completed'
		    && $order_post->post_status!='wc-exchanged'
		    && ($inclAwait && $order_post->post_status!='wc-returnawait')) {
				$RTND_Processor->wpml(1);
				return null;
		}
		$res = array();

		$shipAddress = ($wc30?$order->get_shipping_address_1():$order->shipping_address_1);
		if(empty($shipAddress)) $shipAddress = ($wc30?$order->get_billing_address_1():$order->billing_address_1);

		$res[0] = array(
			'orderId'                   => ($wc30?$order->get_id():$order->id),
            'sequentialOrderId'         => apply_filters( 'woocommerce_order_number', $id, $order ),
			'customerId'              	=> ($wc30?$order->get_customer_id():$order->customer_user),
			'createdTime'				=> $this->format_datetime( $order_post->post_date_gmt ),
			'modifiedTime'				=> strftime( "%Y-%m-%dT%TZ", strtotime( $order_post->post_modified_gmt ) ),
			'items'						=> [],
			'data'						=> ($wc30?$order->get_customer_note():$order->customer_note),
			'phone'						=> ($wc30?$order->get_billing_phone():$order->billing_phone),
			'shipAddress'				=> $shipAddress,
			'canReturn'					=> true
			);

		//extra customer data for the guest orders (WC 3)
         if(!$res[0]['customerId']){
             $res[0]['customer'] = [
                 'firstname'    => $order->get_billing_first_name(),
                 'lastname'     => $order->get_billing_last_name(),
                 'email'        => $order->get_billing_email(),
                 'data'         => [
                        'customer_ip'   => $order->get_customer_ip_address()
                     ]
             ];
         }

		 $currency = ($wc30?$order->get_currency():$order->order_currency);

         foreach( $order->get_items() as $item_id => $item ) {

             //check if the item has 'ReturnTax' meta - means the item was created as negative line item
             if(isset($item['ReturnTax'])) continue;

             $refunded_qty = -1*$order->get_qty_refunded_for_item($item_id);

             if ($refunded_qty===$item['qty']) {
                 continue;
             }

             $pid = wc_get_order_item_meta($item_id, '_product_id', true);
             $pvd = wc_get_order_item_meta($item_id, '_variation_id', true);

             if(empty($pvd)) $pvd = $pid;

             $in_stock = ('instock' === get_post_meta($pvd, '_stock_status', true));

             $prc = 0.0; $tax = 0.0; $vat_rate = 0.0;

             //get item original net price stored from Returnado
             if('yes' === $this->options['rtnd_original_prices']){

                 $prc = wc_get_order_item_meta($item_id,'_rtnd_original_net_price', true);
                 $tax = wc_get_order_item_meta($item_id,'_rtnd_original_tax', true);

                 //if no returnado prices - getting item's original prices stored on saving the items
                 if(!$prc) $prc = wc_get_order_item_meta($item_id,'_original_price', true);
                 if(!$tax) $tax = wc_get_order_item_meta($item_id,'_original_tax', true);

             }

             if(!$prc || !$tax) {

                 $item_totals = $this->get_item_totals($item_id);

                 if (!($prc > 0.0)) $prc = wc_format_decimal($item_totals['price'], RTNDPRECISION);

                 if (!($tax > 0.0)) $tax = wc_format_decimal($item_totals['tax'], RTNDPRECISION);

             }elseif( $item['line_total'] < floor($prc) ){

                 //check if we have a situation when original order prices were discounted after the purchase


                     $rate = ($item['line_total'] + $item['line_tax'])
                                 / ($item['line_subtotal'] + $item['line_subtotal_tax']);

                     $prc *= $rate;
                     $tax *= $rate;

             }

             $product = 0;

             if( 'publish' === get_post_status( $pvd ) )
                 $product = wc_get_product($pid);

             if(!($tax>0.0)){
                 if($product){
                     $vat_rate = wc_format_decimal( $this->get_product_tax_rate($product), 2 );
                     if($vat_rate>0.0){
                         $prc = wc_format_decimal( $prc / ( 1 + $vat_rate ), RTNDPRECISION );
                         $tax = wc_format_decimal( $prc * $vat_rate, RTNDPRECISION );
                     }
                 }
             }

             if(!($vat_rate>0.0)){
                 if($product){
                     $vat_rate = wc_format_decimal( $this->get_product_tax_rate($product), 2 );
                 }
                 elseif ($tax>0.0){
                     $vat_rate = wc_format_decimal( $tax / $prc, 2 );
                 }
             }

             $attributes = $item->get_meta( '_original_attributes', true );
             if(!$attributes)
                 $attributes = $this->get_order_item_attributes( $item_id );

             $product_name = $item->get_meta( '_original_title' );
             if(!$product_name && $product)
                 $product_name = $product->get_title();

             $sku = get_post_meta( $pvd, '_sku', true );
             if(!$sku)
                 $sku = get_post_meta( $pid, '_sku', true);

             $qty = ((int)$item['qty']?(int)$item['qty']:1);

             $current_qty = $qty - $refunded_qty;

             $current_prc = $prc / $qty;

             $current_tax = $tax / $qty;

             $res[0]['items'][] = array(
                 'orderItemId'          => $item_id,
                 'productVariantId' 	=> $pvd,
                 'productId' 	        => $pid,
                 'SKU'                  => $sku,
                 'quantity'   	        => $current_qty,
                 'stockStatus'      	=> $in_stock,
                 'realPrice'	        => $current_prc?$current_prc:'0.0',
                 'taxAmount'	        => $current_tax?$current_tax:'0.0',
                 'title'                => $item->get_name(),
                 'name'                 => $product_name,
                 'attributes'           => $attributes,
                 'precision'	        => wc_get_price_decimals(),
                 'vatRate'		        => $vat_rate?$vat_rate:'0.0',
                 'currency'		        => $currency
             );
             /*
              * Note:
              *
              * Currently we are fetching price and vatRate from the product if it is taxable, not from the order
              * because WCPBC plugin does not support taxes on foreign currencies for orders
              *
              */
         }

		 $RTND_Processor->wpml(1);
		 return $res;
	}

     /**
      * Get order item attributes
      *
      * @param int $item_id
      * @return array
      */
	private function get_order_item_attributes( $item_id ){
	     global $wpdb;
	     $resq = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_itemmeta
	                                WHERE order_item_id = $item_id
	                                AND ( meta_key LIKE 'pa_%' OR meta_key LIKE 'attribute_%' )");
	     $return = [];
	     if(!empty($resq))
	         foreach($resq as $result){
	            $attr_name = str_replace( 'attribute_', '', str_replace( 'pa_', '', $result->meta_key ) );
	            $return[$attr_name] = [
                    'label' => $attr_name,
                    'value' => $result->meta_value
                 ];
             }
        return $return;
    }


	 /**
	  * Collect information about all orders
	  *
	  * @param int $size
	  * @param int $page
      * @param string $order
      * @param bool $sync
      * @param string $updatedAfter ISO 8601 datetime format
	  *
	  * @return array
	  */
	 public function get_all_orders( $size = 20, $page = 0, $order = 'asc', $sync = false, $updatedAfter = '' ){

         $order = ( 'asc' === $order ? 'ASC' : 'DESC' );

         global $wpdb; if(empty($wpdb)) return [];

         $offset = ($page?( $page-1 ) * $size:0);
         $limit = ($size?$size:20);


         $sql = "SELECT ID ".
                "FROM {$wpdb->prefix}posts t1
                ".($sync?"
                    LEFT JOIN {$wpdb->prefix}postmeta t2
                        ON  t2.post_id = t1.ID 
                        AND UNIX_TIMESTAMP( STR_TO_DATE( t1.post_modified, '%Y-%m-%d %h:%i:%s' ) ) > t2.meta_value
                        AND t2.meta_key = '_rtnd_synced'
                    LEFT JOIN {$wpdb->prefix}postmeta t3
                        ON t3.post_id = t1.ID 
                        AND t3.meta_key = '_rtnd_synced'
                ":'').
               " WHERE 1=1".
               " AND t1.post_type = 'shop_order'".
               " AND t1.post_status IN ('wc-completed', 'wc-exchanged')"
               .($sync ? " AND ( t2.post_id IS NOT NULL OR t3.post_id IS NULL )":'')
               .($updatedAfter? " AND UNIX_TIMESTAMP( t1.post_modified_gmt ) 
                                         >= UNIX_TIMESTAMP( STR_TO_DATE( '$updatedAfter', '%Y-%m-%dT%TZ' ) )" : '' )
               ." GROUP BY ID "
               ." ORDER BY "
               .($updatedAfter? "t1.post_modified_gmt DESC" :"ID {$order}")
                 ." LIMIT {$limit}"
                 ." OFFSET {$offset}";


         //WPML support
         global $sitepress;
         if (isset($sitepress)) $sitepress->switch_lang('all');

         global $RTND_Processor;

         $RTND_Processor->wpml(0);

         $res = [];

         $orders = $wpdb->get_results( $sql );

         if( empty($orders) || count($orders) === 0 ) return $res;

		 foreach($orders as $o)
				$res[] = $this->get_order( $o->ID )[0];

         $RTND_Processor->wpml(1);

         return $res;
	}

	 /**
	  * Collect all preliminary orders
      *
      * @param int $size
      * @param int $page
      * @param string $order
	  * 
	  * @return array
	  */
	 public function get_all_booking_orders( $size = 100, $page = 0, $order = 'asc' ){
        $order = ( 'asc' === $order ? 'ASC' : 'DESC' );
		if($size && $page)
			$args = array(
						'post_type'         => 'shop_order',
						'post_status' 	    => 'wc-returnawait',
						'suppress_filters'  => 1,
						'orderby'           => 'ID',
						'order'             => $order,
						'posts_per_page'    => $size,
						'paged' => $page
					);
		else
	 	    $args = array(
						'post_type'         => 'shop_order',
						'post_status' 	    => 'wc-returnawait',
						'suppress_filters'  => 1,
						'orderby'           => 'ID',
						'order'             => $order,
						'nopaging'	        => true
						);
		//WPML support
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$posts_array = get_posts( $args );
		$res=array();
		foreach($posts_array as $o) {
			$rz = $this->get_order( $o->ID, true );
			if($rz)
				$res[] = $rz[0];
		}
		$RTND_Processor->wpml(1);
		return $res;
	}

	/*
	*Helper function
	*Set coupon status blocked or unblocked by post ID
	*@param $status 
	*@param $coupon id
	*@return num rows affected or false
	*/
	private function set_coupon_status($status, $coupon_id){
		global $wpdb;
		$stat = [
			'block'	=> 'pending',
			'unblock'	=> 'publish'
		];
		return wp_update_post( array( 'ID' => $coupon_id, 'post_status' => $stat[$status] ) );
	}

	/*
	*Helper function
	*Retrieve coupon ID by user ID
	*@param $uid - user ID
	*@return coupon post ID
	*/
	private function get_coupon_by_user_id($uid){
		global $wpdb;
		$coupon_id = $wpdb->get_var("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'rtnd_customer' AND meta_value = $uid limit 1");
		return ((int)$coupon_id>0?$coupon_id:null);
	}

	 /**
	  * Helper function
	  *
	  * Get coupon details
	  *
	  * @param $uid - customer ID
	  *
	  * @return array - coupon details
	  */
	 public function get_coupon_details( $uid ){
		$coupon_id = $this->get_coupon_by_user_id( $uid );
		if(!$coupon_id) return null;
		$coupon_post = get_post($coupon_id);
		return ['coupon' => [
				'id' => $coupon_id,
				'code' => $coupon_post->post_title,
				'value' => get_post_meta($coupon_id,'coupon_amount',true),
				'blocked' => ('pending' === get_post_status($coupon_id))

		]];
	}

	/*
	* Get store credit or set it to blocked or unblocked status
	*@param $ids - IDs of customers or 'block' / 'unblock' parameter
	*@param $customerId - Id of a customer to block / unblock
	*@return an array of coupon's data
	*/
	
	public function get_or_block_credit($ids = [], $customerId = 0){
		if(!is_array($ids)){ // block / unblock
			if( strpos($ids,'block') !== false ) {
				$this->set_coupon_status( $ids, $this->get_coupon_by_user_id($customerId) );
				return $this->get_coupon_details( $customerId );
			}
			else
				if(strpos($ids, 'details')!==false) { //coupon details
					return $this->get_coupon_details( $customerId );
				}
				else $ids = [(int)$ids];
		}
		$r = array();
		foreach($ids as $id){
			$coupon_id = $this->get_coupon_by_user_id($id);
			if($coupon_id)
				$r[] = [
					'customerId'    => (string)$id,
					'balance'       => (float)get_post_meta($coupon_id,'coupon_amount',true),
					'blocked'		=> ('pending' === get_post_status($coupon_id))
				];
		}
	 	return $r;
	}

	 /**
	  * Get all store credits from the shop
	  *
	  * @param int $size
	  * @param int $page
      * @param string $order
	  *
	  * @return array
	  */
	 public function get_all_credits( $size = 100, $page = 0, $order = 'asc'){
        $order = ( 'asc' === $order ? 'ASC' : 'DESC' );
		if (!$size&&!$page)
			$args = array(
				'post_type'        => 'shop_coupon',
				'post_status'	   => ['pending','publish'],
				'nopaging'	 => true,
				'suppress_filters'  => 1,
				'orderby'          => 'ID',
				'order'            => $order,
				'meta_query' => array(
													array(
														'key'   => 'rtnd_customer'
													)
												)
			);
		else $args = array(
			'post_type'        => 'shop_coupon',
			'post_status'	   => ['pending','publish'],
			'posts_per_page' => $size,
			'paged' => $page,
			'orderby'          => 'ID',
			'order'            => $order,
			'suppress_filters'  => 1,
			'meta_query' => array(
													array(
														'key'   => 'rtnd_customer'
													)
												)
		);
		//WPML support
		global $RTND_Processor;
		$RTND_Processor->wpml(0);
		$posts_array = get_posts( $args );
		$res=array();
		foreach($posts_array as $o)
			$res[]=$this->get_or_block_credit([get_post_meta($o->ID,'rtnd_customer',true)])[0];
		$RTND_Processor->wpml(1);
		return $res;
	}

	public function fetch_currencies(){
	 	$cids = array();
		//aelia
		if (class_exists('WC_Aelia_CurrencySwitcher')) {
			$cids_q = get_option('wc_aelia_currency_switcher');
			$cids = $cids_q['enabled_currencies'];
		}
		//wcpbc
		if(class_exists('WC_Product_Price_Based_Country')){
			$countries = get_option('wc_price_based_country_regions');
			if(is_array($countries))
				foreach($countries as $country_id=>$country)
					$cids[] = $country['currency'];
		}
		//woocs
        if(class_exists('WOOCS')){
		    global $WOOCS;
		    $cids = array_keys( $WOOCS->get_currencies() );
        }
		//add default currency
		$def_currency = get_option('woocommerce_currency');
		if(!in_array($def_currency,$cids)) $cids[] = $def_currency;
		return $cids;
	}

	 /**
	  * Returns WP installation details
	  *  - currencies
	  *  - WC settings
	  *  - RTND settings
	  */
	 public function get_env_details(){
	 	$rates = [];
	 	$rates_option = get_option('rtnd_currency_rates');
	 	if($rates_option)
	 	    $rates = json_decode($rates_option,true)['rates'];
	 	return [
	 		'currency'  =>  [
	 			    'all'       => $this->fetch_currencies(),
		            'default'   => get_option('woocommerce_currency'),
			        'rates'     => $rates
			    ],
		    'woocommerce' => [
		    	'guest_mode_enabled'    => ('yes' === get_option('woocommerce_enable_guest_checkout')),
		        'prices_include_tax'    => ('yes' === get_option('woocommerce_prices_include_tax')),
		        'price_precision'       => get_option('woocommerce_price_num_decimals'),
			    'dimension_unit'        => get_option('woocommerce_dimension_unit'),
			    'weight_unit'           => get_option('woocommerce_weight_unit'),
			    'taxes_enabled'         => ('yes' === get_option('woocommerce_calc_taxes')),
		        'allowed_countries'     => get_option('woocommerce_allowed_countries'),
		        'default_country'       => get_option('woocommerce_default_country'),
			    ],
		    'returnado' => [
		    	    'shop_id'        => $this->options['rtnd_shop_id'],
			        'remote_sync_host'        => $this->options['rtnd_remote_host'],
			        'remote_widget_host'      => $this->options['rtnd_remote_widget_host'],
			        'remote_admin_host'       => $this->options['rtnd_remote_admin_host'],
			        'auto_sync'      => ('yes' === $this->options['rtnd_sync_enabled']),
			        'auto_stock'     => ('yes' === $this->options['rtnd_update_stock']),
			        'auto_refund'    => ('yes' === $this->options['rtnd_api_refund']),
			        'virtual_item'   => ('1' === $this->options['rtnd_virt_item']?'original':'virtual'),
			        'store_credit'   => ('yes' === $this->options['rtnd_use_coupons']),
			        'original_prices'=> ('yes' === $this->options['rtnd_original_prices'])
		        ]
	    ];
	 }

	 /**
	  * Helper total counter for synchronization
	  *
      * @param bool $full_sync
	  * @return int
	  */

	 public function count_sync_data( $full_sync = false ){
		 return $this->count_all_categories() +
                $this->count_all_orders(!$full_sync) +
                $this->count_all_customers(!$full_sync) +
                $this->count_all_products(!$full_sync);
	 }

	 /**
	 * Helper counter for synchronization
	 *
	 * count products
	 *
     * @param bool $sync
	 * @return number of records to post
	 */

	 public function count_all_products( $sync = false ){
		 global $wpdb;
		 $r = $wpdb->get_var(
		     "SELECT COUNT(ID) FROM {$wpdb->prefix}posts t1".($sync?"
                    LEFT JOIN {$wpdb->prefix}postmeta t2
                         ON  t2.post_id = t1.ID
                         AND UNIX_TIMESTAMP( STR_TO_DATE( t1.post_modified, '%Y-%m-%d %h:%i:%s' ) ) > t2.meta_value
                         AND t2.meta_key = '_rtnd_synced'
                    LEFT JOIN {$wpdb->prefix}postmeta t3
                         ON t3.post_id = t1.ID
                         AND t3.meta_key = '_rtnd_synced'":'')."
                WHERE t1.post_type = 'product'".($sync?"
                  AND ( t2.post_id IS NOT NULL OR t3.post_id IS NULL )":'')
         );
		 return empty($r)?0:(int)$r;
	 }


	 /**
	  * Helper counter for synchronization
	  *
	  * count all customers
	  *
	  * @param bool $sync
	  * @return number of records to post
	  */

	 public function count_all_customers( $sync = false ){
		 global $wpdb;
		 //count customers
		 $r  = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}users t1" . ($sync?"
                    LEFT JOIN {$wpdb->prefix}usermeta t2_1
                        ON  t2_1.user_id = t1.ID 
                        AND t2_1.meta_key = '_rtnd_synced'
                    LEFT JOIN {$wpdb->prefix}usermeta t2_2
                        ON  t2_2.user_id = t1.ID 
                        AND t2_2.meta_key = 'last_update'
                        AND t2_2.meta_value > t2_1.meta_value
                    LEFT JOIN {$wpdb->prefix}usermeta t3
                        ON t3.user_id = t1.ID 
                        AND t3.meta_key = '_rtnd_synced'
                    WHERE t2_2.user_id IS NOT NULL OR t3.user_id IS NULL":'') );
		 return empty($r)?0:(int)$r;
	 }

	 /**
	  * Helper counter for synchronization
	  *
	  * count all orders
	  *
      * @param bool $sync
	  * @return number of records to post
	  */

	 public function count_all_orders( $sync = false ){
		 global $wpdb;
		 $r = $wpdb->get_var(
		     "SELECT COUNT(ID) FROM {$wpdb->prefix}posts t1
                ".($sync?"
                    LEFT JOIN {$wpdb->prefix}postmeta t2
                        ON  t2.post_id = t1.ID 
                        AND UNIX_TIMESTAMP( STR_TO_DATE( t1.post_modified, '%Y-%m-%d %h:%i:%s' ) ) > t2.meta_value
                        AND t2.meta_key = '_rtnd_synced'
                    LEFT JOIN {$wpdb->prefix}postmeta t3
                        ON t3.post_id = t1.ID 
                        AND t3.meta_key = '_rtnd_synced'
                ":'')."
                WHERE 1=1
                AND t1.post_type = 'shop_order'
                AND t1.post_status IN ('wc-completed', 'wc-exchanged')
                ".($sync?   "AND ( t2.post_id IS NOT NULL OR t3.post_id IS NULL )":'')
         );
		 return empty($r)?0:(int)$r;
	 }

	 /*
	  * Helper counter for synchronization
	  *
	  * @return number of records to post
	  */

	 public function count_all_categories(){
		 global $wpdb;
		 //count categories
		 $r = $wpdb->get_var("SELECT COUNT(term_id) FROM {$wpdb->prefix}term_taxonomy WHERE taxonomy = 'product_cat'");
		 return empty($r)?0:(int)$r;
	 }

 }