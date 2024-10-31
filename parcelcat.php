<?php
/**
 * @package Parcelcat
 */
/*
Plugin Name: Parcelcat
Plugin URI: https://parcelcat.com/
Description: Integration with a smart logistics warehouse that enables an e-shop to outsource the storage, administration and dispatch of all goods to us.
Version: 1.1.0
Author: Parcelcat.com
Author URI: https://parcelcat.com
License: GPLv2 or later
Text Domain: parcelcat
*/

defined('ABSPATH') or exit;
if (!in_array('woocommerce/woocommerce.php',apply_filters('active_plugins',get_option('active_plugins')))) return;

// settings
function parcelcat_settings_register() {
	register_setting('parcelcat-settings-api','parcelcat_enabled');
	register_setting('parcelcat-settings-api','parcelcat_pid');
}
add_action('admin_menu','parcelcat_menu');
function parcelcat_menu() {
	add_menu_page('Parcelcat','Parcelcat','administrator',__FILE__,'parcelcat_settings_page',plugins_url('/img/parcelcatwp-logo.svg', __FILE__));
	add_action('admin_init','parcelcat_settings_register');
}

function parcelcat_settings_page(){
	// save settings
	if ($_POST['submit']) {
		update_option('parcelcat_enabled',$_POST['parcelcat_enabled']=='on' ? 1 : 0);
		update_option('parcelcat_pid',sanitize_text_field(trim($_POST['parcelcat_pid'])));
		$html .= '<div class="updated"><p>Settings saved successfully</p></div>';
	}
	// init variables
	$parcelcat_enabled = get_option('parcelcat_enabled');
	$parcelcat_pid = get_option('parcelcat_pid');
	$parcelcat_enabled_html = $parcelcat_enabled ? ' checked' : '';
	// settings form
	?>
		<div class="wrap"><h1>Parcelcat integration</h1>
			<form method="post" action="/wp-admin/admin.php?page=parcelcat/parcelcat.php">
				<?php
					settings_fields('parcelcat-settings-api');
					do_settings_sections('parcelcat-settings-api');
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"></th>
						<td>
							<label for="parcelcat_enabled">
							<input name="parcelcat_enabled" type="checkbox" id="parcelcat_enabled"<?php echo esc_attr($parcelcat_enabled_html) ?>>Enable integration</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Project ID</th>
						<td><input type="password" class="regular-text" name="parcelcat_pid" value="<?php echo esc_attr($parcelcat_pid) ?>" autocomplete="false"></td>
					</tr>
					<tr>
						<th scope="row">What information is sent to us?</th>
						<td><p class="description">Parcelcat.com receives information about Woocommerce purchases. We need this information for providing our services to you. We receive order number, payment transaction ID number, order status at the moment of purchase, order currency name, billing and shipping information. The information is sent using secure HTTPS connection.</p></td>
					</tr>
				</table>
				<?php submit_button() ?>
			</form>
		</div>
<?php
}

// define the woocommerce_new_order callback
add_action('woocommerce_order_status_processing','parcelcat_process');
function parcelcat_process($order_id){
	// init
	$order = wc_get_order($order_id);
	$pid = esc_attr(get_option('parcelcat_pid'));
	// details
	$billing_details = [
		'firstName' => sanitize_text_field($order->get_billing_first_name()) ?: null,
		'lastName' => sanitize_text_field($order->get_billing_last_name()) ?: null,
		'phone' => sanitize_text_field($order->get_billing_phone()) ?: null,
		'email' => sanitize_email($order->get_billing_email()) ?: null,
		'company' => sanitize_text_field($order->get_billing_company()) ?: null,
		'address' => sanitize_text_field($order->get_billing_address_1()) ?: null,
		'secondaryAddress' => sanitize_text_field($order->get_billing_address_2()) ?: null,
		'city' => sanitize_text_field($order->get_billing_city()) ?: null,
		'state' => sanitize_text_field($order->get_billing_state()) ?: null,
		'postCode' => sanitize_text_field($order->get_billing_postcode()) ?: null,
		'country' => $order->get_billing_country() ?: null,
	];
	$shipping_details = [
		'firstName' => sanitize_text_field($order->get_shipping_first_name()) ?: null,
		'lastName' => sanitize_text_field($order->get_shipping_last_name()) ?: null,
		'phone' => sanitize_text_field($order->get_billing_phone()),
		'email' => sanitize_email($order->get_billing_email()),
		'company' => sanitize_text_field($order->get_shipping_company()) ?: null,
		'address' => sanitize_text_field($order->get_shipping_address_1()) ?: null,
		'secondaryAddress' => sanitize_text_field($order->get_shipping_address_2()) ?: null,
		'city' => sanitize_text_field($order->get_shipping_city()) ?: null,
		'state' => sanitize_text_field($order->get_shipping_state()) ?: null,
		'postCode' => sanitize_text_field($order->get_shipping_postcode()) ?: null,
		'country' => $order->get_shipping_country() ?: null,
	];
	if (count(array_filter($shipping_details)) < 3) {
		$shipping_details['phone'] = null;
		$shipping_details['email'] = null;
	}	
	// items
	$items = [];
	foreach ($order->get_items() as $item_id => $item) {
		$items[] = (object)[
			'productId' => sanitize_text_field($item->get_product()->get_sku()) ?: null,
			'name' => sanitize_text_field($item->get_product()->get_name()) ?: null,
			// 'regularPrice' => $item->get_product()->get_regular_price() ?: null,
			// 'salePrice' => $item->get_product()->get_sale_price() ?: null,
			'price' => $item->get_product()->get_price() ?: null,
			'quantity' => $item->get_quantity() ?: null,
		];
	}
	// request
	$data_json = json_encode([
		'externalId' => (string)$order_id ?: null,
		// 'totalPrice' => (string)$order->get_total(),
		// 'totalShipping' => (string)$order->get_shipping_total(),
		// 'totalTax' => (string)$order->get_total_tax(),
		// 'totalDiscount' => (string)$order->get_total_discount(),
		// 'transactionId' => (string)$order->get_transaction_id() ?: null,
		// 'currency' => $order->get_currency(),
		// 'customerNote' => sanitize_text_field($order->get_customer_note()) ?: null,
		'billingAddress' => (object)$billing_details,
		'shippingAddress' => (object)$shipping_details,
		'items' => $items,
	]);

	$response = wp_remote_post('https://parcelcat.ababa.tech/api/orders',[
		'method' => 'POST',
		'sslverify' => true,
		'headers' => [ 
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
			'api-key' => $pid,
		],
		'body' => $data_json,
	]);
	$response = wp_remote_retrieve_body($response);
	if (is_object($data=json_decode($response))) {
		if (isset($data->status)) {
			if ($data->status == 'NEW') {
				$order->add_order_note('Parcelcat.com received purchase details');
			} elseif ($data->status > 0) {
				$order->add_order_note("ERROR: Parcelcat hasn't received information about this purchase. Parcelcat response: ".sanitize_text_field($data->message));
			}
		}
	}

}
