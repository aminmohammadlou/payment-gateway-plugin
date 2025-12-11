<?php
/*
 * Plugin Name: FooPay
 * Plugin URI: https://admin-stage.payment-controller.com
 * Description: A simple gateway to handle your payments.
 * Author: AM10
 * Version: 1
 */

// Add gateway to wc payment gateways
add_filter('woocommerce_payment_gateways', 'foopay_add_gateway_class');
function foopay_add_gateway_class($gateways)
{
	$gateways[] = 'FooPay_Gateway';
	return $gateways;
}

// Set settings oof plugin
add_action('plugins_loaded', 'foopay_init_gateway_class');

/**
 * Helper: send JSON error and terminate.
 */
function foopay_send_json_error($code = 'error', $message = 'Error', $status = 400)
{
	$payload = array('error' => $code, 'message' => $message);
	wp_send_json($payload, $status);
}


function foopay_init_gateway_class()
{
	class FooPay_Gateway extends WC_Payment_Gateway
	{
		public function __construct()
		{
			$this->id = 'foopay';
			$this->has_fields = false; // in case you need a custom credit card form
			$this->method_title = 'FooPay';
			$this->method_description = 'A simple gateway to handle your payments.'; // will be displayed on the options page

			// gateways can support subscriptions, refunds, saved payment methods,
			// but in this tutorial we begin with simple payments
			$this->supports = array(
				'products'
			);

			// Method with all the options fields
			$this->init_form_fields();

			// Load the settings
			$this->init_settings();
			$this->app_id = $this->get_option('app_id');
			$this->authorization_code = $this->get_option('foopay_authorization_code');
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->enabled = $this->get_option('enabled');
			$this->testmode = 'yes' === $this->get_option('testmode');
			$this->private_key = $this->testmode ? $this->get_option('test_private_key') : $this->get_option('private_key');
			$this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');

			// This action hook saves the settings
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		}
		/**
		 * Plugin options
		 */
		public function init_form_fields()
		{
			$saved_settings = get_option('woocommerce_' . $this->id . '_settings', array());
			$panel_url = get_option('foopay_panel_url', '');
			$code = get_option('foopay_authorization_code', '');
			$return_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/?wc-api=foopay_setup';
			$app_id = $saved_settings['app_id'];
			$setup_disabled = empty($app_id);
			$setup_url = $panel_url . '?returnUrl=' . urlencode($return_url) . '&appId=' . urlencode($app_id) . '&grantAuthorization=' . urlencode('true');
			$setup_button_html = $setup_disabled
				? '<button class="button button-primary" disabled>Setup</button>'
				: '<a href="' . esc_url($setup_url) . '" target="_blank" class="button button-primary">Setup</a>';

			$this->form_fields = array(
				'setup_button' => array(
					'title' => 'Setup status',
					'label' => $setup_button_html,
					'type' => 'checkbox',
					'description' => 'You should set App ID first to enter setup proccess.<br>Once you click this, you will be redirected to FooPay to complete the setup process.',
					'default' => 'no',
					'disabled' => true
				),
				'app_id' => array(
					'title' => 'App ID',
					'type' => 'text',
					'description' => 'Your ID to set in FooPay.',
					'custom_attributes' => array('required' => 'required'),
					'default' => ''
				),
				'title' => array(
					'title' => 'Title',
					'type' => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default' => 'FooPay',
				),
				'description' => array(
					'title' => 'Description',
					'type' => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default' => 'Simply pay via our payment gateway.',
				),
				'testmode' => array(
					'title' => 'Test mode',
					'label' => 'Enable Test Mode',
					'type' => 'checkbox',
					'description' => 'Place the payment gateway in test mode using test API keys.',
					'default' => 'no',
				),
				'test_publishable_key' => array(
					'title' => 'Test Publishable Key',
					'type' => 'text'
				),
				'test_private_key' => array(
					'title' => 'Test Private Key',
					'type' => 'password',
				),
				'publishable_key' => array(
					'title' => 'Live Publishable Key',
					'type' => 'text'
				),
				'private_key' => array(
					'title' => 'Live Private Key',
					'type' => 'password'
				),
			);
		}

		/*
		 * We're processing the payments here
		 */
		public function process_payment($order_id)
		{

			// we need it to get any order detailes
			$order = wc_get_order($order_id);

			$return_url = '';

			$body = array(
				'amount' => $order->get_total(),
				'currency' => $order->get_currency(),
				'order_id' => $order_id,
				'customer_email' => $order->get_billing_email(),
				'return_url' => $return_url,
				// ... هر فیلد دیگری که API شما می‌خواهد
			);

			// 3) درخواست به API با wp_remote_post
			$api_url = $this->get_option('api_url');
			$api_key = $this->get_option('api_key');

			$args = array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $api_key, // اگر API شما توکن می‌خواهد
				),
				'body' => wp_json_encode($body),
			);

			$response = wp_remote_post($api_url, $args);


			if (200 === wp_remote_retrieve_response_code($response)) {

				$body = json_decode(wp_remote_retrieve_body($response), true);

				// it could be different depending on your payment processor
				if ('APPROVED' === $body['response']['responseCode']) {

					// we received the payment
					$order->payment_complete();
					$order->reduce_order_stock();

					// some notes to customer (replace true with false to make it private)
					$order->add_order_note('Hey, your order is paid! Thank you!', true);

					// Empty cart
					WC()->cart->empty_cart();

					// Redirect to the thank you page
					return array(
						'result' => 'success',
						'redirect' => $this->get_return_url($order),
					);

				} else {
					wc_add_notice('Please try again.', 'error');
					return;
				}

			} else {
				wc_add_notice('Connection error.', 'error');
				return;
			}

		}

		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		// public function webhook() {

		// ...

		// }
	}
}

register_activation_hook(__FILE__, 'foopay_activation');
function foopay_activation()
{
	$FOOPAY_PANEL_URL = 'https://admin-stage.payment-controller.com/auth/signin';
	$FOOPAY_APP_URL = 'https://ezpin-payment-app-service-stage-ckbcd9ekc7bzcjfx.westus-01.azurewebsites.net';

	// sanitize before saving
	if (filter_var($FOOPAY_PANEL_URL, FILTER_VALIDATE_URL) && filter_var($FOOPAY_APP_URL, FILTER_VALIDATE_URL)) {
		// use a unique option name that won't collide with WC settings
		add_option('foopay_panel_url', $FOOPAY_PANEL_URL, '', 'no');
		add_option('foopay_app_url', $FOOPAY_PANEL_URL, '', 'no');
	}
}

add_action('woocommerce_api_test', 'test_endpoint_handler');

function test_endpoint_handler()
{
	echo 'hello';
	$gateway_id = 'foopay';

	// Read params
	$authorizationCode_raw = isset($_GET['authorizationCode']) ? wp_unslash($_GET['authorizationCode']) : '';

	// Basic validation
	if (empty($authorizationCode_raw)) {
		foopay_send_json_error('missing_authorizationCode', 'authorizationCode parameter is required', 400);
	}

	// Fetch existing gateway settings (so we won't clobber them)
	$option_key = 'woocommerce_' . $gateway_id . '_settings';
	$settings = get_option($option_key, array());

	if (!is_array($settings)) {
		$settings = array();
	}

	// mark the setup checkbox as checked
	$settings['setup_button'] = 'yes';

	add_option('foopay_authorization_code', $authorizationCode_raw, '', 'no');

	// Save updated settings back to DB
	update_option($option_key, $settings);

	// Return success response (JSON)
	wp_send_json_success(array(
		'message' => 'FooPay setup values saved successfully.',
	), 200);
}
