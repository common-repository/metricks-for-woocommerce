<?php
/**
 * Plugin Name: Metricks for WooCommerce
 * Plugin URI: https://github.com/Gratis-digital-world/woocomerce-metricks
 * Description: Automatically integrate your Woocommerce store with Metricks app
 * Author: Metricks
 * Author URI: https://www.metricks.io
 * Text Domain: woocommerce-metricks
 * Version: 1.0.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    die('Direct access is prohibited.');
}

if (preg_grep("/\/woocommerce.php$/", apply_filters('active_plugins', get_option('active_plugins'))) !== null) {
    if (!class_exists('WC_Metricks')) {
        class WC_Metricks {
            public function __construct() {
                add_action('plugins_loaded', array($this, 'init'));
            }

            public function init() {
                if (class_exists('WC_Integration')) {
                    autoload_classes();
                    add_filter('woocommerce_integrations', [$this, 'add_integration']);
                } else {
                    add_action('admin_notices', 'missing_prerequisite_notification');
                }

                load_plugin_textdomain('woocommerce-metricks', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            }

            public function add_integration($integrations) {
                $integrations[] = 'WC_Metricks_Integration';

                return $integrations;
            }
        }

        $WC_Metricks = new WC_Metricks(__FILE__);
    }

    function autoload_classes() {
        $files = scandir(dirname(__FILE__) . '/includes');
        $valid_extensions = ['php'];
        foreach ($files as $index => $file) {
            if (in_array(pathinfo($file)['extension'], $valid_extensions)) {
                require_once('includes/' . pathinfo($file)['basename']);
            }
        }
    }

    function wc_metricks_plugin_activate() {
        add_option('wc_metricks_plugin_do_activation_redirect', true);
    }

    function wc_metricks_plugin_redirect() {
        if (get_option('wc_metricks_plugin_do_activation_redirect')) {
            delete_option('wc_metricks_plugin_do_activation_redirect');

            if (!isset($_GET['activate-multi'])) {
                $setup_url = admin_url("admin.php?page=wc-settings&tab=integration&section=metricks");
                wp_redirect($setup_url);

                exit;
            }
        }
    }

    function missing_prerequisite_notification() {
        $message = 'Metricks <strong>requires</strong> Woocommerce to be installed and activated';
        printf('<div class="notice notice-error"><p>%1$s</p></div>', $message);
    }

    function rc_plugin_links($links) {
        $rc_tab_url = "admin.php?page=wc-settings&tab=integration&section=metricks";
        $settings_link = "<a href='". esc_url( get_admin_url(null, $rc_tab_url) ) ."'>Settings</a>";

        array_unshift($links, $settings_link);

        return $links;
    }

    register_activation_hook(__FILE__, 'wc_metricks_plugin_activate');
    add_action('admin_init', 'wc_metricks_plugin_redirect');
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'rc_plugin_links');
}
