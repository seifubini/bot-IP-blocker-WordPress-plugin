<?php
/*
Plugin Name: IP Blocker
Description: A custom plugin that adds honeypot protection to the WooCommerce checkout form and blocks bot IPs for 1 week. This is a plugin made in-house by our team at Chiropedic.
Version: 1.0
Author: Biniam Shiferaw
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Constants
define('IP_BLOCK_DURATION', WEEK_IN_SECONDS);

// Step 1: Add Honeypot Field to WooCommerce Checkout Form
add_action('woocommerce_after_checkout_billing_form', 'add_honeypot_field');

function add_honeypot_field() {
    echo '<div style="display:block;">';
    echo '<label for="honeypot">Additional Message <abbr class="required" title="required">*</abbr> </label>';
    echo '<input type="text" id="name_on_card" name="name_on_card" value="" />';
    echo '</div>';
}

// Step 2: Create JavaScript to detect honeypot field being filled and trigger the shortcode
add_action('wp_footer', 'honeypot_detection_js');

function honeypot_detection_js() {
    if (is_checkout()) { ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                var honeypot = document.querySelector('#name_on_card');

                if (honeypot) {
                    honeypot.addEventListener('input', function() {
                        if (honeypot.value !== "") {
                            // Call the IP block shortcode when honeypot is filled
                            window.location.href = '<?php echo site_url(); ?>/wp-admin/admin-ajax.php?action=block_ip_shortcode';
                        }
                    });
                }
            });
        </script>
    <?php }
}

// Step 3: Create the Shortcode to Block the IP and Reload the Page
add_action('wp_ajax_block_ip_shortcode', 'block_ip_via_shortcode');
add_action('wp_ajax_nopriv_block_ip_shortcode', 'block_ip_via_shortcode');

function block_ip_via_shortcode() {
    // Get the user's IP address
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_ip = sanitize_text_field( $ip_address );

    // Get the blocked IPs option from the database
    $blocked_ips = get_option('blocked_ips', []);
    $current_time = current_time('timestamp');

    // Add the user's IP address to the blocked list with a timestamp
    $blocked_ips[$user_ip] = $current_time;

    // Save the updated list back to the database
    update_option('blocked_ips', $blocked_ips);

    // Reload the page to apply the block
    wp_redirect(home_url());
    exit;
}


// Step 2 backup: Validate the honeypot field on checkout process and block IP if filled
add_action('woocommerce_checkout_process', 'check_honeypot_and_block_ip');

function check_honeypot_and_block_ip() {
    if (!empty($_POST['name_on_card'])) {
        // Get user's IP address
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_ip = sanitize_text_field( $ip_address );

        // Get the current blocked IPs from the database
        $blocked_ips = get_option('blocked_ips', []);
        $current_time = current_time('timestamp');

        // Block the IP by adding it to the blocked IPs array with a timestamp
        $blocked_ips[$user_ip] = $current_time;
        update_option('blocked_ips', $blocked_ips);

        // Show a message to the bot (user will never see it)
        wc_add_notice(__('There was an issue processing your checkout. Please try again later.'), 'error');

        // Trigger a page reload using JavaScript
        add_action('wp_footer', 'trigger_page_reload_js');

        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }
}

// Step 3: Block IP addresses on every page load
add_action('template_redirect', 'block_ip_addresses');

// JavaScript function to reload the page
function trigger_page_reload_js() {
    ?>
    <script type="text/javascript">
        // Reload the page after blocking the IP
        window.onload = function() {
            window.location.reload();
        };
    </script>
    <?php
}


// Step 4: Block IP Addresses on Every Page Load
add_action('template_redirect', 'block_ip_addresses');

function block_ip_addresses() {
    $blocked_ips = get_option('blocked_ips', []); // Get the blocked IPs from the database
    $current_time = current_time('timestamp'); // Get the current timestamp

    // Loop through blocked IPs and check the time of block
    foreach ($blocked_ips as $ip => $block_time) {
        if ($block_time + IP_BLOCK_DURATION < $current_time) {
            unset($blocked_ips[$ip]); // Unblock IP if the duration exceeds 1 week
        }
    }

    // Update the option to remove expired IPs
    update_option('blocked_ips', $blocked_ips);

    // Get the user's IP address
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_ip = sanitize_text_field( $ip_address );
    //$user_ip = $_SERVER['REMOTE_ADDR'];

    // If the user's IP is blocked, deny access
    if (isset($blocked_ips[$user_ip])) {
        wp_die('You are blocked from accessing this website.');
        exit();
    }
}

// Step 5: Admin Page for Managing Blocked IPs
add_action('admin_menu', 'ip_blocker_create_menu');

function ip_blocker_create_menu() {
    add_menu_page(
        'IP Blocker',
        'IP Blocker',
        'manage_options',
        'ip-blocker-settings',
        'ip_blocker_settings_page',
        'dashicons-shield-alt',
        100
    );
}

function ip_blocker_settings_page() {
    $blocked_ips = get_option('blocked_ips', []); // Fetch the blocked IPs
    $current_time = current_time('timestamp');

    // Handle removal of a blocked IP
    if (isset($_POST['remove_ip'])) {
        $ip_to_remove = sanitize_text_field($_POST['remove_ip']);
        if (isset($blocked_ips[$ip_to_remove])) {
            unset($blocked_ips[$ip_to_remove]); // Remove the IP from the blocked list
            update_option('blocked_ips', $blocked_ips);
            echo '<div class="updated"><p>IP Address removed successfully.</p></div>';
        }
    }

    // Handle adding a new IP
    if (isset($_POST['new_ip']) && !empty($_POST['new_ip'])) {
        $new_ip = sanitize_text_field($_POST['new_ip']);
        if (!isset($blocked_ips[$new_ip])) {
            $blocked_ips[$new_ip] = $current_time; // Add new IP with current timestamp
            update_option('blocked_ips', $blocked_ips);
            echo '<div class="updated"><p>New IP Address added successfully.</p></div>';
        }
    }

    // Display table of blocked IPs
    ?>
    <div class="wrap">
        <h1>IP Blocker Management</h1>

        <h2>Add New IP Address</h2>
        <form method="post">
            <input type="text" name="new_ip" placeholder="Enter IP address" required />
            <button type="submit" class="button-primary">Add IP</button>
        </form>

        <h2>Blocked IP Addresses</h2>
        <?php if (!empty($blocked_ips)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Block Time</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($blocked_ips as $ip => $block_time): ?>
                    <tr>
                        <td><?php echo esc_html($ip); ?></td>
                        <td><?php echo date('Y-m-d H:i:s', $block_time); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="remove_ip" value="<?php echo esc_attr($ip); ?>" />
                                <button type="submit" class="button">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No IP addresses are currently blocked.</p>
        <?php endif; ?>

    </div>
    <?php
}
