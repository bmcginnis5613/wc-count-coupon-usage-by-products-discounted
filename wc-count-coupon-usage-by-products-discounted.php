<?php
/*
Plugin Name: WooCommerce Count Coupon Usage by Products Ordered
Description: Increment the coupon usage by amount of discounted products ordered.
Author: FirstTracks Marketing
Author URI: https://firsttracksmarketing.com/
Version: 1.1.0
*/

/**
 * MINOR BUG: If more than one coupon code is used at checkout, the usage for each is incremented incorrectly.
 * Work around for now is we prevent the coupon from being used with any other coupons.
 * Did try to solve, but it is being a PITA. :)
 *
 * WHAT THIS DOES
 * Take for example we create a coupon code for 100% off with an 8 usage limit. We want the code to only be used to order a
 * grand total of 8 discounted products.
 *
 * This solves the user being able to create 8 orders with more than 8 discounted products.
 * For example, you create 8 orders and each has 2 discounted products. That is 16 discounted products instead of the 8 limit we want with the coupon code.
 * Woo counts the usage of the coupon code by order, and not per product in the order.
 *
 * We also check the coupon usage limit and only apply to X amount of products at checkout.
 * For example, if the coupon code allows for a 3 usage limit and the user adds 4 of the products tied to the coupon code to their cart,
 * only a total of 3 are discounted. The coupon usage is also only incremented by 3 and not 4.
 *
 * If there are different products that can be used with the code, the highest priced products are discounted first.
 */

/**
 * Check if WooCommerce is active before plugin activation
 */
function wc_coupon_quantity_usage_activate() {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('WooCommerce Count Coupon Usage by Products Ordered requires WooCommerce to be installed and active. Please install and activate WooCommerce first.', 'wc-coupon-quantity-usage'),
            'Plugin dependency check',
            array('back_link' => true)
        );
    }
}
register_activation_hook(__FILE__, 'wc_coupon_quantity_usage_activate');

/**
 * Check if plugin is active (helper function)
 */
if (!function_exists('is_plugin_active')) {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

/**
 * Show admin notice if WooCommerce is deactivated and plugin is active
 */
function wc_coupon_quantity_usage_admin_notice() {
    if (!class_exists('WooCommerce')) {
        ?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce Count Coupon Usage by Products Ordered requires WooCommerce to be installed and activated.', 'wc-coupon-quantity-usage'); ?></p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'wc_coupon_quantity_usage_admin_notice');

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new WC_Coupon_Quantity_Usage();
    }
});

class WC_Coupon_Quantity_Usage {

    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Add checkbox to coupon usage limit settings
        add_action('woocommerce_coupon_options_usage_limit', array($this, 'add_quantity_usage_checkbox'), 10, 2);

        // Save the checkbox value
        add_action('woocommerce_coupon_options_save', array($this, 'save_quantity_usage_checkbox'), 10, 2);

        // Add JavaScript to handle checkbox synchronization
        add_action('admin_footer', array($this, 'add_checkbox_sync_script'));

        // Reset discount counter before cart calculations
        add_action('woocommerce_before_calculate_totals', array($this, 'reset_coupon_discount_counter'), 5);

        // Limit discount based on usage and quantity
        add_filter('woocommerce_coupon_get_discount_amount', array($this, 'limit_coupon_discount_by_usage_and_quantity'), 10, 5);

        // Count usage by item quantity when order is completed
        add_action('woocommerce_order_status_processing', array($this, 'count_coupon_usage_by_item_quantity'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'count_coupon_usage_by_item_quantity'), 10, 1);
    }

    /**
     * Add checkbox to coupon usage limit settings
     */
    public function add_quantity_usage_checkbox($coupon_id, $coupon) {
        // Get the coupon object properly
        if (is_numeric($coupon_id)) {
            $coupon = new WC_Coupon($coupon_id);
        }

        $checked_value = $coupon->get_meta('count_usage_by_quantity', true);

        woocommerce_wp_checkbox(
            array(
                'id' => 'count_usage_by_quantity',
                'label' => __('Count usage by quantity', 'wc-coupon-quantity-usage'),
                'description' => __('Count coupon usage by total products discounted in the order instead of per order. This will automatically enable the "Individual use only" setting in "Usage restriction".', 'wc-coupon-quantity-usage'),
                'desc_tip' => false,
                'value' => wc_bool_to_string($checked_value)
            )
        );
    }

    /**
     * Save the checkbox value and set individual use
     */
    public function save_quantity_usage_checkbox($post_id, $coupon) {
        // Get the coupon object properly
        if (is_numeric($post_id)) {
            $coupon = new WC_Coupon($post_id);
        }

        $count_by_quantity = isset($_POST['count_usage_by_quantity']) ? 'yes' : 'no';
        $coupon->update_meta_data('count_usage_by_quantity', $count_by_quantity);

        // If count by quantity is enabled, also enable individual use only
        if ($count_by_quantity === 'yes') {
            $coupon->set_individual_use(true);
        }

        $coupon->save();
    }

    /**
     * Add JavaScript to sync checkboxes in admin
     */
    public function add_checkbox_sync_script() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'shop_coupon') {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // When count_usage_by_quantity is checked, enable individual_use
                $('#count_usage_by_quantity').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#individual_use').prop('checked', true);
                    } else {
                        // When unchecked, also uncheck individual_use
                        $('#individual_use').prop('checked', false);
                    }
                });

                // Prevent unchecking individual_use when count_usage_by_quantity is checked
                $('#individual_use').on('change', function() {
                    if (!$(this).is(':checked') && $('#count_usage_by_quantity').is(':checked')) {
                        $(this).prop('checked', true);
                        alert('"Individual use only" cannot be disabled when "Count usage by quantity" is enabled.');
                    }
                });
            });
            </script>
            <?php
        }
    }

    /**
     * Reset coupon discount counter before calculations
     */
    public function reset_coupon_discount_counter($cart) {
        global $coupon_items_discounted;
        $coupon_items_discounted = array();
    }

    /**
     * Limit coupon discount by usage and quantity
     */
    public function limit_coupon_discount_by_usage_and_quantity($discount, $discounting_amount, $cart_item, $single, $coupon) {
        global $coupon_items_discounted;

        // Check if this coupon has quantity-based usage enabled
        $count_by_quantity = $coupon->get_meta('count_usage_by_quantity', true);
        if ($count_by_quantity !== 'yes') {
            return $discount; // Standard behavior
        }

        // Initialize global if not set
        if (!isset($coupon_items_discounted)) {
            $coupon_items_discounted = array();
        }

        // Get coupon's remaining usage
        $usage_limit = $coupon->get_usage_limit();
        if (!$usage_limit) {
            return $discount; // No limit set
        }

        $usage_count = $coupon->get_usage_count();
        $remaining_usage = $usage_limit - $usage_count;

        if ($remaining_usage <= 0) {
            return 0; // No usage left
        }

        // Track discounted items per coupon across all cart items
        $coupon_code = $coupon->get_code();

        if (!isset($coupon_items_discounted[$coupon_code])) {
            $coupon_items_discounted[$coupon_code] = 0;
        }

        // Calculate how many of this cart item should get discount
        $item_quantity = $cart_item['quantity'];
        $can_discount = $remaining_usage - $coupon_items_discounted[$coupon_code];

        if ($can_discount <= 0) {
            return 0; // Already used up remaining discounts
        }

        // Limit discount to remaining usage
        $quantity_to_discount = min($item_quantity, $can_discount);
        $coupon_items_discounted[$coupon_code] += $quantity_to_discount;

        // Calculate proportional discount
        if ($quantity_to_discount < $item_quantity) {
            $discount = ($discount / $item_quantity) * $quantity_to_discount;
        }

        return $discount;
    }

    /**
     * Count usage by item quantity when order is completed
     */
    public function count_coupon_usage_by_item_quantity($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Prevent double-adjusting this order
        if ($order->get_meta('_coupon_usage_adjusted')) {
            return;
        }

        $coupons = $order->get_coupon_codes();

        foreach ($coupons as $coupon_code) {
            $coupon = new WC_Coupon($coupon_code);
            $coupon_id = $coupon->get_id();

            // Check if this coupon has quantity-based usage enabled
            $count_by_quantity = $coupon->get_meta('count_usage_by_quantity', true);
            if ($count_by_quantity !== 'yes') {
                continue; // Skip if not enabled for this coupon
            }

            // Count how many items in this order were discounted
            $discounted_quantity = 0;
            foreach ($order->get_items() as $item) {
                $item_total = $item->get_total();
                $item_subtotal = $item->get_subtotal();

                if ($item_subtotal > $item_total) {
                    // Item was discounted — add its quantity
                    $discounted_quantity += $item->get_quantity();
                }
            }

            if ($discounted_quantity > 0) {
                $current_usage = (int) get_post_meta($coupon_id, 'usage_count', true);

                // WooCommerce already added +1 for this order, so adjust:
                // Subtract that +1, then add the actual number of discounted units.
                $new_usage = max(0, $current_usage - 1 + $discounted_quantity);

                update_post_meta($coupon_id, 'usage_count', $new_usage);
            }
        }

        // Mark this order as adjusted
        $order->update_meta_data('_coupon_usage_adjusted', true);
        $order->save();
    }
}
