<?php
/*
Plugin Name: Lydia Gateway
Plugin URI: http://www.wpstriker.com/plugins
Description: Plugin for Lydia Payment Gatway
Version: 1.0
Author: wpstriker
Author URI: http://www.wpstriker.com
License: GPLv2
Copyright 2019 wpstriker (email : wpstriker@gmail.com)
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define('LYDIA_GATEWAY_URL', plugin_dir_url(__FILE__));
define('LYDIA_GATEWAY_DIR', plugin_dir_path(__FILE__));

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

// Make sure WooCommerce is active
if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {	
	return;
}

function wc_lydia_gateway_init() {
	
	class WC_Gateway_Lydia extends WC_Payment_Gateway {
		
		/**
		 * Logger instance
		 *
		 * @var WC_Logger
		 */
		public static $log = false;
	
		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			
			$this->init();
		}
		
		public function init(){
						
			$this->id  				  = 'lydiapay';
			$this->has_fields         = false;
			$this->order_button_text  = __('Pay via Lydia', 'woocommerce');
			$this->method_title       = __('Lydia Pay', 'woocommerce');
			$this->supports           = ['products'];
			
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
			
			// checkout config
			$this->title        = $this->get_option('title');
			$this->description  = $this->get_option('description');
					
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
			
			add_action( 'woocommerce_api_wc_gateway_lydia_confim', array( $this, 'gateway_lydia_confim' ) );
			
			add_action( 'woocommerce_api_wc_gateway_lydia_cancel', array( $this, 'gateway_lydia_cancel' ) );
			
		}
		
		protected function get_lydia_order( $order_ref ) {
			
			$order_ref = explode( '___', $order_ref );
			
			if ( $order_ref && is_array( $order_ref ) ) {
				$order_id  = $order_ref[0];
				$order_key = $order_ref[1];
			} else {
				// Nothing was found.
				$this->log( 'Order ID and key were not found in "order_ref".', 'error' );
				return false;
			}
	
			$order = wc_get_order( $order_id );
	
			if ( ! $order ) {
				// We have an invalid $order_id, probably because invoice_prefix has changed.
				$order_id = wc_get_order_id_by_order_key( $order_key );
				$order    = wc_get_order( $order_id );
			}
	
			if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
				$this->log( 'Order Keys do not match.', 'error' );
				return false;
			}
	
			return $order;
		}
	
		public function gateway_lydia_confim() {
			$posted	= $_REQUEST;
			
			$this->log( 'Checking IPN response is valid inside lydia confim' );
			
			$order = ! empty( $posted['order_ref'] ) ? $this->get_lydia_order( $posted['order_ref'] ) : false;
					
			if( $order ) {
				$this->log( 'Found order #' . $order->get_id() );	
				
				$this->log( $posted	);
				
				$txn_id		= get_post_meta( $order->get_id(), 'lydia_request_uuid', true );
				$order->payment_complete( $txn_id );
			}
		}
		
		public function gateway_lydia_cancel() {
			$posted	= $_REQUEST;
			
			$this->log( 'Checking IPN response is valid inside lydia cancel' );
			
			$order = ! empty( $posted['order_ref'] ) ? $this->get_lydia_order( $posted['order_ref'] ) : false;
					
			if( $order ) {
				$this->log( 'Found order #' . $order->get_id() );
				
				$this->log( $posted	);
				
				$reason	= 'Payment failed!';
				$order->update_status( 'cancelled', $reason );	
			}
		}
		
		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields()
		{
			$this->form_fields = apply_filters( 'wc_lydia_form_fields', array(
				'enabled' => array(
					'title'   => __('Enable/Disable', 'woocommerce'),
					'type'    => 'checkbox',
					'label'   => __('Enable Lydia Gateway', 'woocommerce'),
					'default' => 'yes',
				),
				'title' => array(
					'title'       => __('Title', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
					'default'     => __('Lydia Pay', 'woocommerce'),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __('Description', 'woocommerce'),
					'type'        => 'textarea',
					'desc_tip'    => true,
					'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
				
				),
				'api_details' => array(
					'title'       => __('API Credentials', 'woocommerce'),
					'type'        => 'title',
					'description' => sprintf(__('Enter your Lydia API credentials to process payment via Lydia.') ), 				 ),
				'public_token' => array(
					'title'       => __('Your Public Token', 'woocommerce'),
					'type'        => 'text',
					'default'     => '',
					'desc_tip'    => true,
					'placeholder' => __('Your Public Token', 'woocommerce'),
				),
				'private_token' => array(
					'title'       => __('Your Private Token', 'woocommerce'),
					'type'        => 'text',
					'default'     => '',
					'desc_tip'    => true,
					'placeholder' => __('Your Private Token', 'woocommerce'),
				),
				
			) );
	
		} //End init_form_fields function
		
		
		public function process_payment( $order_id ) {
			global $woocommerce;
			
			$lydia_url 	= 'https://homologation.lydia-app.com/api/request/do';
			
			$order 	= new WC_Order( $order_id );
			
			$data 	= array(
					'vendor_token'		=> $this->get_option('public_token'),
					'amount'			=> $order->get_total(),
					'currency'			=> $order->get_currency(),
					'recipient'			=> $order->get_billing_phone(),
					'type'				=> "phone",
					'payment_method'	=> "lydia",
					'order_ref'			=> $order->get_id() . '___' . $order->get_order_key(),
					'browser_success_url'	=> esc_url_raw( $order->get_checkout_order_received_url() ),
					'end_mobile_url'	=> esc_url_raw( $order->get_checkout_order_received_url() ),
					'browser_fail_url'	=> esc_url_raw( $order->get_cancel_order_url_raw() ),
					'confirm_url'		=> WC()->api_request_url( 'WC_Gateway_Lydia_Confim' ),
					'cancel_url'		=> WC()->api_request_url( 'WC_Gateway_Lydia_Cancel' )
					);
			
			$response = wp_remote_post( $lydia_url, array(
				'method'      => 'POST',
				'timeout'     => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(),
				'body'        => $data,
				'cookies'     => array()
				)
			);
			
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				wc_add_notice('Something went wrong: ' . $error_message, $notice_type = 'error' );				
			} else {
				
				$ob			= simplexml_load_string( $response['body'] );
				$json  		= json_encode( $ob);
				$bodyData 	= json_decode( $json, true );
		
				if( $bodyData['error'] > 0 ) {
					$this->log( 'ERORR: ' . $bodyData['message'] );
					wc_add_notice('ERORR: ' . $bodyData['message'], $notice_type = 'error' );			
				} else {
					$lydia_url	= $bodyData['mobile_url'];
						
					$order->add_order_note( __( 'lydia url: ' . $lydia_url, 'woocommerce' ) );
					
					$order->update_status( 'on-hold' );
					
					update_post_meta( $order_id, 'lydia_mobile_url', $bodyData['mobile_url'] );
					update_post_meta( $order_id, 'lydia_request_id', $bodyData['request_id'] );
					update_post_meta( $order_id, 'lydia_request_uuid', $bodyData['request_uuid'] );
					
					return array(
							'result'	=> 'success',
							'redirect'	=> $lydia_url
					);
				}
										
			}

		}
		
		public static function log( $message, $level = 'info' ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			
			$message	= function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $message ) : $message;
		
			$message	= ( is_array( $message ) || is_object( $message ) ) ? print_r( $message, 1 ) : $message;
		
			self::$log->log( $level, $message, array( 'source' => 'lydia' ) );			
		}
				
	}

}
add_action( 'plugins_loaded', 'wc_lydia_gateway_init', 11 );

function wc_lydia_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Lydia';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_lydia_add_to_gateways' );