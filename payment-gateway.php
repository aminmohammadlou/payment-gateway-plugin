<?php

use Ramsey\Uuid\Uuid;

/**
 * Plugin Name: FooPay
 * Plugin URI:  https://admin-stage.payment-controller.com
 * Author:      FooPay
 * Description: A simple gateway to handle your payments
 * Version:     1.0.0
 */

// Enable WC block feature for plugin
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
});

// Add FooPay to WC payment gateways
add_filter('woocommerce_payment_gateways', 'foopay_add_gateway_class');
function foopay_add_gateway_class($gateways)
{
	$gateways[] = 'FooPay_Gateway';
	return $gateways;
}

// Add Blocks support for FooPay
add_action('woocommerce_blocks_loaded', function () {
	if (!class_exists('WC_Foopay_Blocks')) {
		require_once plugin_dir_path(__FILE__) . 'includes/class-wc-foopay-gateway-blocks-support.php';
	}

	add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
		if (
			class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry') &&
			class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')
		) {

			$registry->register(new WC_Foopay_Blocks());
		}
	});
});

// Initialize FooPay plugin
add_action('plugins_loaded', 'foopay_init_gateway_class');
function foopay_init_gateway_class()
{
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	class FooPay_Gateway extends WC_Payment_Gateway
	{
		protected string $foopay_panel_url = "https://admin-stage.payment-controller.com/auth/signin";
		protected string $foopay_payment_app_url = "https://ezpin-payment-app-service-stage-ckbcd9ekc7bzcjfx.westus-01.azurewebsites.net";
		protected string $foopay_payment_api_url = "https://api-stage.payment-controller.com";
		protected string $app_id;
		protected string $bot_token;

		public function __construct()
		{
			$this->id = 'foopay';
			$this->has_fields = false; // In case you need a custom credit card form
			$this->method_title = 'FooPay';
			$this->method_description = 'A simple gateway to handle your payments';

			// Gateways can support subscriptions, refunds, saved payment methods
			// $this->supports = array(
			// 	'products'
			// );

			$this->init_form_fields();
			$this->init_settings(); // Load the settings

			$this->app_id = $this->get_option('app_id');
			$this->bot_token = $this->get_option('bot_token');
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->enabled = $this->get_option('enabled');

			// Hook for saving settings
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

			// Hooks for handling webhooks
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

			// Handles payment state and order status on order received (thank you) page
			add_action('woocommerce_thankyou', array($this, 'thankyou_page_handler'));
		}

		// Plugin options
		public function init_form_fields()
		{
			$return_url = home_url('/?wc-api=foopay_setup');
			$setup_url = $this->foopay_panel_url . '?returnUrl=' . urlencode($return_url) . '&grantAuthorization=' . urlencode('true');

			$saved_settings = get_option('woocommerce_' . $this->id . '_settings', array());
			$app_id = $saved_settings['app_id'] ?? '';
			$bot_token = $saved_settings['bot_token'] ?? '';

			$setup_completed = !(empty($app_id)) && !(empty($bot_token));

			$setup_button_html = $setup_completed
				? '<button class="button button-primary" disabled>Setup completed</button>'
				: '<a href="' . esc_url($setup_url) . '" class="button button-primary" target=_blank>Setup</a>';

			$this->form_fields = array(
				'enabled' => array(
					'title' => 'Enable/Disable',
					'type' => 'checkbox',
					'label' => 'Enable FooPay',
					'default' => 'no'
				),
				'setup_button' => array(
					'title' => 'Setup status',
					'type' => 'checkbox',
					'label' => $setup_button_html,
					'disabled' => true,
					'default' => 'no',
					'description' => $setup_completed ? 'Setup completed successfully ✅' : 'Once you click on button, you will be redirected to FooPay to complete the setup process.',
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

		public function foopay_setup_handler()
		{
			// Read params
			$appId = isset($_GET['appId']) ? wp_unslash($_GET['appId']) : '';
			$authorizationCode = isset($_GET['authorizationCode']) ? wp_unslash($_GET['authorizationCode']) : '';

			$this->log('Starting setup process', 'info', [
				'app_id' => $appId
			]);

			// Validate params
			if (empty($authorizationCode) || empty($appId)) {
				$this->foopay_render_admin_error_page(
					'missing_parameters',
					'authorizationCode and appId are required'
				);
				exit;
			}

			// Save appId to DB
			$option_key = 'woocommerce_' . $this->id . '_settings';
			$settings = get_option($option_key, array());

			if (!is_array($settings)) {
				$settings = array();
			}

			$settings['app_id'] = $appId;
			update_option($option_key, $settings);

			// Get bot token
			$bot_token = $this->foopay_exchange_authorization_code_for_bot_token(
				sanitize_text_field($authorizationCode),
				$appId
			);

			if (is_wp_error($bot_token)) {
				$this->foopay_render_admin_error_page(
					$bot_token->get_error_code(),
					$bot_token->get_error_message()
				);
				exit;
			}

			// Save bot token to DB
			$settings['bot_token'] = sanitize_text_field($bot_token);
			update_option($option_key, $settings);

			// Set payment webhook in payment service
			$webhook_url = home_url('/?wc-api=payment_webhook');
			$webhook_token = $this->webhook_token_generator($option_key, $settings);

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
				$this->foopay_payment_app_url . '/api/apps/' . $appId,
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

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if (is_wp_error($response) || $status_code !== 200) {
				$this->log('Error in setting webhook URL', 'error', [
					'status_code' => $status_code,
					'body' => $body
				]);
				$this->foopay_render_admin_error_page(
					$response->get_error_code(),
					$response->get_error_message()
				);
				exit;
			}

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
			$this->log('Starting exchange authorization code for bot token', 'info');

			// Call FooPay API to exchange code for token
			$response = wp_remote_post(
				$this->foopay_payment_app_url . '/api/apps/' . $app_id . '/generate-bot-token',
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $authorization_code,
						'Accept' => 'text/plain',
					),
				)
			);

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if (is_wp_error($response) || $status_code !== 200) {
				$this->log('Error in  exchange authorization code for bot token', 'error', [
					'status_code' => $status_code,
					'body' => $body
				]);
				return new WP_Error(
					'Error in  exchange authorization code for bot token',
					$body
				);
			}

			$bot_token = trim($body);

			if (empty($bot_token)) {
				$this->log('Bot token is empty', 'error');

				return new WP_Error(
					'Invalid bot token',
					'Bot token is empty'
				);
			}

			return $bot_token;
		}

		// Method that processes the payment
		function process_payment($order_id)
		{
			$order = wc_get_order($order_id);
			$customer_id = $order->get_customer_id();

			$this->log('Starting payment process. order_id: ' . $order_id, 'info');

			// Generate request body
			$items = [];

			foreach ($order->get_items() as $item) {
				/** @var WC_Order_Item_Product $item */
				$product = $item->get_product();

				$items[] = [
					'name' => $item->get_name(),
					'description' => $product ? $product->get_short_description() : '',
					'amount' => (float) $order->get_item_total($item, false),
					'quantity' => (int) $item->get_quantity(),
					'tax' => (float) $order->get_item_tax($item),
					'sku' => $product ? $product->get_sku() : '',
					'category' => $product && $product->is_virtual()
						? 'DigitalGoods'
						: 'PhysicalGoods',
				];
			}

			$body = array(
				'referenceId' => "$order_id",
				'amount' => $order->get_total(),
				'currency' => $order->get_currency(),
				'autoCapture' => true,
				'webhookUrl' => home_url('/?wc-api=payment_webhook'),
				'returnUrl' => $this->get_return_url($order),
				'customerOrder' => [
					'customer' => [
						'customerId' => $customer_id > 0 ? "$customer_id" : Uuid::uuid4()->toString(),
						'accountCreatedTime' => $order->get_date_created()
							? $order->get_date_created()->date('c')
							: null,
						'firstName' => $order->get_billing_first_name(),
						'lastName' => $order->get_billing_last_name(),
						'email' => $order->get_billing_email(),
						'phoneNumber' => $order->get_billing_phone(),
						'mobileNumber' => $order->get_billing_phone(),
						'address' => [
							'streetAddressLine1' => $order->get_billing_address_1(),
							'streetAddressLine2' => $order->get_billing_address_2(),
							'zipCode' => $order->get_billing_postcode(),
							'city' => $order->get_billing_city(),
							'state' => $order->get_billing_state(),
							'country' => WC()->countries->countries[$order->get_billing_country()] ?? '',
							'countryCode' => $order->get_billing_country(),
						],
						'orderId' => "$order_id",
						'description' => sprintf('Order #%s from %s', $order->get_id(), get_bloginfo('name')),
						'customId' => (string) $order->get_order_key(),
						'amount' => [
							'total' => (float) $order->get_total(),
							'handling' => 0,
							'insurance' => 0,
							'discount' => (float) $order->get_total_discount(),
							'shipping' => (float) $order->get_shipping_total(),
							'shippingDiscount' => 0,
							'totalTax' => (float) $order->get_total_tax(),
						],
						'items' => $items
					]
				]
			);

			$args = array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $this->bot_token,
				),
				'body' => wp_json_encode($body),
			);

			$response = wp_remote_post($this->foopay_payment_api_url . '/api/v1/apps/' . $this->app_id . '/payments/hosted-page', $args);

			$staus_code = wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if ($staus_code === 201) {
				$this->log('Payment created successfully. order_id: ' . $order_id, 'info');

				$redirect_url = $body['redirectUrl'];

				$order->update_status('on-hold', 'Pending payment in FooPay.');

				return array(
					'result' => 'success',
					'redirect' => $redirect_url,
				);
			} else {
				$this->log('Error in creating payment', 'error', [
					'status_code' => $staus_code,
					'body' => $body
				]);

				wc_add_notice('Error in payment process', 'Call admin for support.');
				return;
			}
		}

		public function payment_webhook_handler()
		{
			$this->log('Payment webhook received', 'info');

			// Validate authorization header
			$saved_settings = get_option('woocommerce_' . $this->id . '_settings', array());
			$webhook_token = $saved_settings['webhook_token'] ?? '';

			if (empty($webhook_token)) {
				$this->log('Webhook token not set in DB', 'error');

				status_header(400);
				exit('Internal error');
			}

			$headers = getallheaders();
			$auth_header = trim($headers['Authorization'] ?? '');
			$token = preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m) ? $m[1] : '';

			if (empty($token) || !hash_equals($webhook_token, $token)) {
				$this->log('Invalid token in webhook request', 'error');
				status_header(401);
				exit('Unauthorized');
			}

			// Get data from request
			$raw_body = file_get_contents('php://input');

			if (empty($raw_body)) {
				$this->log('Empty webhook body', 'error');

				status_header(400);
				exit('Empty body');
			}

			// Decode JSON
			$data = json_decode($raw_body, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->log('Invalid JSON in webhook', 'error');

				status_header(400);
				exit('Invalid JSON');
			}

			$order_id = $data['payment']['referenceId'] ?? '';

			if (empty($order_id)) {
				$this->log('Missing referenceId in webhook', 'error');

				status_header(400);
				exit('Missing referenceId');
			}

			$this->update_order_status($order_id);
		}

		protected function payment_state_handler($order_status, $payment_state)
		{
			if ($order_status === 'completed' || $order_status === 'cancelled' || $order_status === 'refunded' || $order_status === 'failed' || $order_status === 'processing') {
				return $order_status;
			}

			switch ($payment_state) {
				case 'Created':
				case 'Authorized':
				case 'Authorizing':
				case 'Approved':
				case 'Capturing':
				case 'SaleInProgress':
				case 'ProviderAuthorizedHold':
				case 'Cancelling':
				case 'CapturedHold':
				case 'Refunding':
					return 'on-hold';

				case 'Captured':
					return 'processing';

				case 'Failed':
				case 'Disputed':
					return 'failed';

				case 'Refunded':
					return 'refunded';

				default:
					return $order_status;
			}
		}

		public function update_order_status($order_id)
		{
			$order = wc_get_order($order_id);
			$order_status = $order->get_status();

			// Get payment state
			$response = wp_remote_get(
				$this->foopay_payment_api_url . '/api/v1/apps/' . $this->app_id . '/payments/referenceId:' . $order_id,
				[
					'timeout' => 20,
					'headers' => [
						'Authorization' => 'Bearer ' . $this->bot_token,
					],
				]
			);

			$staus_code = wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if ($staus_code == 200) {
				$this->log('Get payment from payment service successfully', 'info', [
					'order_id' => $order_id,
					'order_status' => $order_status,
					'payment_state' => $body['paymentState'] ?? ''
				]);

				$payment_state = $body['paymentState'] ?? '';
				$new_order_status = $this->payment_state_handler(
					$order_status,
					$payment_state
				);

				if ($new_order_status == 'processing') {
					$order->payment_complete();
				} else {
					$order->update_status($new_order_status, 'Payment state updated on thank you page.');
				}

				$this->log('Order status updated', 'info', [
					'order_id' => $order_id,
					'order_status' => $order_status,
					'new_order_status' => $new_order_status,
					'payment_state' => $body['paymentState'] ?? ''
				]);

			} else {
				$this->log('Error in fetching payment details', 'error', [
					'status_code' => $staus_code,
					'body' => $body
				]);
				return;
			}
		}

		public function thankyou_page_handler($order_id)
		{
			$this->log('Payment service redirected to thnak you page successfully. order_id: ' . $order_id, 'info');

			$this->update_order_status($order_id);
		}

		protected function webhook_token_generator($option_key, $settings)
		{
			$webhook_token = $settings['webhook_token'] ?? '';

			if (empty($webhook_token)) {
				$webhook_token = wp_generate_password(64, false);
				$settings['webhook_token'] = $webhook_token;
				update_option($option_key, $settings);
			}

			return $webhook_token;
		}

		protected function foopay_render_admin_error_page($code, $message)
		{
			status_header(400);
			nocache_headers();

			?>
			<!DOCTYPE html>
			<html lang="en">

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

		protected function log($message, $level = 'info', $context = [])
		{
			if (!isset($this->logger)) {
				$this->logger = wc_get_logger();
			}

			$context = array_merge(
				['source' => 'foopay'], // IMPORTANT
				$context
			);

			$this->logger->log($level, $message, $context);
		}
	}
}