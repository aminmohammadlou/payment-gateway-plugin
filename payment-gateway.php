<?php

/**
 * Plugin Name: FooPay
 * Plugin URI:  https://admin-stage.payment-controller.com
 * Author:      FooPay
 * Description: A simple gateway to handle your payments
 * Version:     1.0.0
 */

// Add FooPay to WC payment gateways
add_filter('woocommerce_payment_gateways', 'foopay_add_gateway_class');
function foopay_add_gateway_class($gateways)
{
	$gateways[] = 'FooPay_Gateway';
	return $gateways;
}

// Initialize FooPay plugin
add_action('plugins_loaded', 'foopay_init_gateway_class');
function foopay_init_gateway_class()
{
	class FooPay_Gateway extends WC_Payment_Gateway
	{
		protected string $foopay_panel_url = "https://admin-stage.payment-controller.com/auth/signin";
		protected string $foopay_api_url = "https://ezpin-payment-app-service-stage-ckbcd9ekc7bzcjfx.westus-01.azurewebsites.net";
		protected string $app_id;
		protected bool $setup_completed;
		protected string $bot_token;

		public function __construct()
		{
			$this->id = 'foopay';
			$this->has_fields = false; // In case you need a custom credit card form
			$this->method_title = 'FooPay';
			$this->method_description = 'A simple gateway to handle your payments';

			// Gateways can support subscriptions, refunds, saved payment methods
			$this->supports = array(
				'products'
			);

			$this->init_form_fields();
			$this->init_settings(); // Load the settings

			$this->app_id = $this->get_option('app_id');
			$this->setup_completed = $this->get_option('setup_completed');
			$this->bot_token = $this->get_option('bot_token');
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->enabled = $this->get_option('enabled');

			// Hook for saving settings
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

			// Hooks for handling setup and webhooks
			add_action('woocommerce_api_foopay_setup', array($this, 'foopay_setup_handler'));
			add_action('woocommerce_api_payment_webhook', array($this, 'payment_webhook_handler'));

			// Admin notice on successful setup
			add_action('admin_notices', function () {
				if (
					isset($_GET['foopay_setup']) &&
					$_GET['foopay_setup'] === 'success'
				) {
					?>
					<div class="notice notice-success is-dismissible">
						<p><strong>FooPay:</strong> Setup completed successfully.</p>
					</div>
					<?php
				}
			});
		}

		// Plugin options
		public function init_form_fields()
		{
			$saved_settings = get_option('woocommerce_' . $this->id . '_settings', array());
			$app_id = $saved_settings['app_id'];
			$setup_completed = $saved_settings['setup_completed'];
			$setup_button_disabled = empty($app_id) || $setup_completed;

			$return_url = home_url('/?wc-api=foopay_setup');
			$setup_url = $this->foopay_panel_url . '?returnUrl=' . urlencode($return_url) . '&appId=' . urlencode($app_id) . '&grantAuthorization=' . urlencode('true');

			$setup_button_html = $setup_button_disabled
				? '<button class="button button-primary" disabled>Setup</button>'
				: '<a href="' . esc_url($setup_url) . '" class="button button-primary" target=_blank>Setup</a>';

			$this->form_fields = array(
				'setup_button' => array(
					'title' => 'Setup status',
					'type' => 'checkbox',
					'label' => $setup_button_html,
					'disabled' => true,
					'default' => 'yes',
					'description' => $setup_completed ? 'Setup completed successfully ✅' : 'You should set App ID to enter setup proccess.<br>Once you click on button, you will be redirected to FooPay to complete the setup process.',
				),
				'app_id' => array(
					'title' => 'App ID',
					'type' => 'text',
					'description' => 'Your App ID to set in FooPay.',
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
				)
			);
		}

		protected function foopay_setup_handler()
		{
			// Read params
			$authorizationCode_raw = isset($_GET['authorizationCode']) ? wp_unslash($_GET['authorizationCode']) : '';
			$appId_raw = isset($_GET['appId']) ? wp_unslash($_GET['appId']) : '';

			// Validate params
			if (empty($authorizationCode_raw)) {
				$this->foopay_render_admin_error_page(
					'missing_authorizationCode',
					'authorizationCode parameter is required'
				);
			}

			if (empty($appId_raw) || $appId_raw !== $this->app_id) {
				$this->foopay_render_admin_error_page(
					'wrong_appId',
					'appId parameter is wrong'
				);
			}

			// Get bot token
			$bot_token = $this->foopay_exchange_authorization_code_for_bot_token(
				sanitize_text_field($authorizationCode_raw),
				$appId_raw
			);

			if (is_wp_error($bot_token)) {
				$this->foopay_render_admin_error_page(
					'error_token',
					'Error in exchanging authorization code for token'
				);
			}

			// Set payment webhook in payment service
			$webhook_url = home_url('/?wc-api=payment_webhook');
			$webhook_token = $this->webhook_token_generator();

			$payload = [
				'paymentWebhookUrl' => [
					'value' => $webhook_url
				],
				'webhookAuthorizationHeaderScheme' => [
					'value' => 'Bearer'
				],
				'webhookAuthorizationHeaderParameter' => [
					'value' => $webhook_token
				]
			];

			$response = wp_remote_request(
				$this->foopay_api_url . '/api/apps/' . $this->app_id,
				[
					'method' => 'PATCH',
					'headers' => [
						'Content-Type' => 'application/json',
						'Authorization' => 'Bearer ' . $bot_token,
					],
					'body' => wp_json_encode($payload),
					'timeout' => 20,
				]
			);

			if (is_wp_error($response)) {
				$this->foopay_render_admin_error_page(
					'error_webhook',
					'Error in setting webhook URL'
				);
			}

			// Save bot token and mark setup as completed
			$option_key = 'woocommerce_' . $this->id . '_settings';
			$settings = get_option($option_key, array());

			if (!is_array($settings)) {
				$settings = array();
			}

			$settings['bot_token'] = sanitize_text_field($bot_token);
			$settings['setup_completed'] = true;

			// Save updated settings back to DB
			update_option($option_key, $settings);

			$redirect_url = add_query_arg(
				array(
					'foopay_setup' => 'success',
				),
				admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id)
			);

			wp_safe_redirect($redirect_url);
			exit;
		}

		protected function foopay_exchange_authorization_code_for_bot_token($authorization_code, $app_id)
		{
			// Validate input
			if (empty($authorization_code) || empty($app_id)) {
				return new WP_Error(
					'missing_authorization_code_or_app_id',
					'Authorization code or App ID is missing'
				);
			}

			// Call FooPay API to exchange code for token
			$response = wp_remote_post(
				$this->foopay_api_url . '/api/apps/' . $app_id . '/generate-bot-token',
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $authorization_code,
						'Accept' => 'text/plain',
					),
				)
			);

			if (is_wp_error($response)) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($status_code !== 200) {
				return new WP_Error(
					'foopay_token_error',
					'Token request failed',
					array(
						'status' => $status_code,
						'body' => $body,
					)
				);
			}

			$token = trim($body);

			if (empty($token)) {
				return new WP_Error(
					'empty_token',
					'Token response was empty'
				);
			}

			return $token;
		}

		protected function webhook_token_generator()
		{
			$option_key = 'woocommerce_' . $this->id . '_settings';
			$settings = get_option($option_key, array());

			if (!is_array($settings)) {
				$settings = array();
			}

			$webhook_token = $settings['webhook_token'];

			if (!$webhook_token) {
				$webhook_token = wp_generate_password(64, false, false);
				update_option($option_key, $settings);
			}

			return $webhook_token;
		}

		public function payment_webhook_handler()
		{
			echo 'OK';
			exit;
		}

		protected function foopay_render_admin_error_page($code, $message)
		{
			status_header(400);
			nocache_headers();

			?>
			<!DOCTYPE html>
			<html>

			<head>
				<meta charset="utf-8">
				<title>FooPay Error</title>
				<?php wp_admin_css('install', true); ?>
				<style>
					body {
						background: #f0f0f1;
					}

					.foopay-error {
						max-width: 600px;
						margin: 80px auto;
						background: #fff;
						padding: 30px;
						border-left: 4px solid #d63638;
					}

					pre {
						background: #f6f7f7;
						padding: 12px;
						overflow: auto;
					}
				</style>
			</head>

			<body>
				<div class="foopay-error">
					<h1>Something went wrong</h1>
					<p>Please call plugin admin.</p>

					<h3>Error details</h3>
					<pre><?php
					echo esc_html(wp_json_encode(
						array(
							'error' => $code,
							'message' => $message,
							'status' => 400,
						),
						JSON_PRETTY_PRINT
					));
					?></pre>
				</div>
			</body>

			</html>
			<?php
			exit;
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