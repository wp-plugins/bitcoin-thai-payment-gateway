<?php
add_action('plugins_loaded', 'bitcointhai_woocommerce_gateway_class', 0);
function bitcointhai_woocommerce_gateway_class(){
	if(!class_exists('WC_Payment_Gateway')) return;
	
	include_once('includes/bitcointhai.php');
	
	class WC_Bitcointhai extends WC_Payment_Gateway{
		public function __construct(){
			$this->id = 'bitcointhai';
			$this->medthod_title = __( 'Bitcoin Thai', 'woocommerce' );
			$this->has_fields = true;
			
			$this->init_form_fields();
			
			$this->init_settings();
			
			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			$this->api_id = $this->settings['api_id'];
			$this->api_key = $this->settings['api_key'];
			
			$this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Bitcointhai', home_url( '/' ) ) );
			
			$this->msg['message'] = "";
			$this->msg['class'] = "";
			  
			$this->api = new bitcointhaiAPI;
			
			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_bitcointhai', array( $this, 'check_ipn_response' ) );
		}
	   
	   
		function admin_options() {
			?>
			<h3><?php _e('Bitcoin Thai - Bitcoin.in.th','woocommerce'); ?></h3>
			<p><?php _e('Accept bitcoin payments with your bitcoin.in.th merchant account', 'woocommerce' ); ?></p>
			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table> <?php
		}


		/**
		 * Initialise Gateway Settings Form Fields
		 *
		 * @access public
		 * @return void
		 */
		function init_form_fields() {
			global $woocommerce;
	
			$shipping_methods = array();
	
			if ( is_admin() )
				foreach ( $woocommerce->shipping->load_shipping_methods() as $method ) {
					$shipping_methods[ $method->id ] = $method->get_title();
				}
	
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable Bitcoin', 'woocommerce' ),
					'type' => 'checkbox',
					'description' => '',
					'default' => 'no'
				),
				'title' => array(
					'title' => __( 'Title', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'Payment method title that the customer will see on your website.', 'woocommerce' ),
					'default' => __( 'Bitcoin', 'woocommerce' ),
					'desc_tip'      => true,
				),
				'api_id' => array(
					'title' => __( 'API ID', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'Get your API ID from <a href="http://bitcoin.in.th/merchant-account/" target="_blank">http://bitcoin.in.th/merchant-account/</a>', 'woocommerce' )
				),
				'api_key' => array(
					'title' => __( 'API Key', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'Get your API Key from <a href="http://bitcoin.in.th/merchant-account/" target="_blank">http://bitcoin.in.th/merchant-account/</a>', 'woocommerce' )
				)
		   );
		}
		
		function is_available() {
			global $woocommerce;
			if(!$this->api->init($this->api_id, $this->api_key)){
				return false;
			}elseif(!$this->api->validate($woocommerce->cart->total,get_woocommerce_currency())){
				return false;
			}
			return true;
		}
		
		function payment_fields() {
			global $woocommerce;
			
			$this->api->order_id = $woocommerce->session->bitcoin_order_id;
			$data = array('amount' => $woocommerce->cart->total,
						  'currency' => get_woocommerce_currency(),
						  'ipn' => $this->notify_url);
			if(!$paybox = $this->api->paybox($data)){
				echo '<p class="error">'.__( 'Sorry Bitcoin payments are currently unavailable', 'woocommerce' ).'</p>';
			}
			$woocommerce->session->bitcoin_order_id = $this->api->order_id;
			$btc_url = 'bitcoin:'.$paybox->address.'?amount='.$paybox->btc_amount.'&label='.urlencode(get_bloginfo('name'));
			
			?>
            <div>
                <input type="hidden" name="bitcointhai_order_id" value="<?php echo $paybox->order_id;?>">
                <div>
                <p>
                    <strong>Bitcoin Address: </strong><?php echo '<a href="'.$btc_url.'">'.$paybox->address.'</a>';?><br>
                    <strong>Bitcoin Amount: </strong><?php echo $paybox->btc_amount;?> BTC
                </p>
                </div>
                <div style="float:left; margin:10px;">
                    <a href="<?php echo $btc_url;?>"><img src="data:image/png;base64,<?php echo $paybox->qr_data;?>" width="200" alt="Send to <?php echo $paybox->address;?>" border="0"></a>
                </div>
                <p><?php echo sprintf(__('You must send <strong>%s</strong> Bitcoins to the address: %s', 'woocommerce'),$paybox->btc_amount,$paybox->address);?></p>
                <p><?php echo __('After you have completed payment please click the PLACE ORDER button', 'woocommerce');?></p>
                <?php 
                echo $this->api->countDown($paybox->expire,'div',__('You must send the bitcoins within the next %s Minutes %s Seconds', 'woocommerce'),__('Bitcoin payment time has expired, please refresh the page to get a new address', 'woocommerce'));
                ?>
            </div>
            <?php
		}
		
		
		
		function process_payment ($order_id) {
			global $woocommerce;
	
			$order = new WC_Order( $order_id );
			
			$result = $this->api->checkorder($_POST['bitcointhai_order_id'], $order_id);
			if(!$result || $result->error != ''){
				if(!$result){
					$e = __('Sorry Bitcoin payments are currently unavailable', 'woocommerce');
				}else{
					$e = $result->error;
					if(isset($result->order_id)){
					  $woocommerce->session->bitcoin_order_id = $result->order_id;
					}
				}
				$woocommerce->add_error(__('Payment error:', 'woothemes'). ' ' . $e);
				return;
			}
	
			// Mark as on-hold (we're awaiting the cheque)
			$order->update_status('on-hold', __( 'Bitcoin: Payment awaiting confirmation', 'woocommerce' ));
	
			// Reduce stock levels
			$order->reduce_order_stock();
			
			// Remove cart
			$woocommerce->cart->empty_cart();
	
			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))))
			);
		}
		
		function check_ipn_response() {
			@ob_clean();
						
			$data = $_POST;
			
			if($ipn = $this->api->verifyIPN($data)){
				
				$order = new WC_Order( $data['reference_id'] );
				
				if (isset( $order->id ) ) {
					update_post_meta( $order->id, 'Transaction ID', $data['order_id'] );
					
					if($data['success'] == 1){
						// Payment completed
						$order->add_order_note( __('Bitcoin IPN: '.$data['message'], 'woocommerce' ) );
						add_filter('woocommerce_payment_complete_reduce_order_stock',function(){return false;});
						$order->payment_complete();
					}else{
						$order->update_status('on-hold', __('Bitcoin IPN: '.$data['message'], 'woocommerce' ));
					}
					echo 'IPN Done';
				}else{
					header("HTTP/1.0 403 Forbidden");
					echo 'IPN Failed: Order missing';
				}
			}else{
				header("HTTP/1.0 403 Forbidden");
				echo 'IPN Failed';
			}
			exit();
		}
		
	
	} // End Class
	/**
	 * Add the Gateway to WooCommerce
	 **/
	function woocommerce_add_bitcointhai_gateway($methods) {
		$methods[] = 'WC_Bitcointhai';
		return $methods;
	}
	
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_bitcointhai_gateway' );
}
?>