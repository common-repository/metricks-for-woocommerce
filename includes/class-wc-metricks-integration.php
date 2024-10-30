<?php
/**
 * WooCommerce Metrciks Integration.
 *
 * @package  WC_Metricks_Integration
 * @category Integration
 * @author   Metrciks
 */

if (!defined('ABSPATH')) {
    die('Direct access is prohibited.');
}

if (!class_exists('WC_Metricks_Integration')) {
    class WC_Metricks_Integration extends WC_Integration {
        public function __construct() {
            global $woocommerce;

            $this->id                 = 'metricks';
            $this->method_title       = __('Metricks', 'woocommerce-metricks');
            $this->method_description = __('Paste <a target="_blank" href="https://app.metricks.io/dashboard/account-settings/profile-settings">your Metrciks Api Keys</a> below:', 'woocommerce-metricks');

            // Load the settings.
            $this->init_form_fields();

            // Define user set variables.
            $this->api_key           = $this->get_option('api_key');
            $this->app_domain           = $this->get_option('app_domain');
            // $this->app_id           = $this->get_option('app_id');
            // $this->secret_key       = $this->get_option('secret_key');
            $this->status_to        = str_replace('wc-', '', $this->get_option('order_status'));
            $this->tracking_page    = $this->get_option('tracking_page');

            // Actions.
            add_action('woocommerce_update_options_integration_' . $this->id,   [$this, 'process_admin_options']);
            add_action('admin_notices',                                         [$this, 'check_plugin_requirements']);
            add_action('wp_enqueue_scripts',                                    [$this, 'render_tracking_code']);
            add_action('woocommerce_thankyou',                                  [$this, 'render_conversion_code']);
            add_action('save_post',                                             [$this, 'add_order_meta_data']);
            add_action('woocommerce_order_status_' . $this->status_to,          [$this, 'rc_submit_purchase'], 10, 1);
            add_action('woocommerce_review_order_before_submit',                [$this, 'render_accepts_marketing_field']);
            add_action('woocommerce_checkout_update_order_meta',                [$this, 'capture_accepts_marketing_value']);

            // Filters.
            add_filter('woocommerce_settings_api_sanitized_fields_' . $this->id, [$this, 'sanitize_settings']);
        }

        public function init_form_fields() {
            $published_pages = get_pages(['status' => ['publish']]);
            $tracking_page_options = [];
            foreach ($published_pages as $page) {
                $tracking_page_options[$page->post_name] = $page->post_title;
            }

            $this->form_fields = [
                'api_key' => [
                    'title'             => __('Api Key', 'woocommerce-metricks'),
                    'type'              => 'text',
                    'desc'              => __('You can find your Api Key on https://www.metricks.io/settings', 'woocommerce-metricks'),
                    'desc_tip'          => true,
                    'default'           => ''
                ],
                'app_domain' => [
                    'title'             => __('Your Metricks Url', 'woocommerce-metricks'),
                    'type'              => 'text',
                    'desc'              => __('Add your metricks url here e.g your_name.metricks.io', 'woocommerce-metricks'),
                    'desc_tip'          => true,
                    'default'           => ''
                ],
                'order_status' => [
                    'title'             => __('Process orders with status', 'woocommerce-metricks'),
                    'type'              => 'select',
                    'options'           => wc_get_order_statuses(),
                    'desc'              => __('Orders with this status are sent to Metricks', 'woocommerce-metricks'),
                    'desc_tip'          => true,
                    'default'           => 'wc-completed'
                ],
                'tracking_page' => [
                    'title'             => __('Render tracking code on', 'woocommerce-metricks'),
                    'type'              => 'select',
                    'options'           => $tracking_page_options,
                    'desc'              => __('Render the tracking code on the selected pages', 'woocommerce-metricks'),
                    'desc_tip'          => true,
                    'default'           => 'checkout'
                ],
                'enable_marketing_checkbox' => [
                    'title'             => __('Enable accepts marketing checkbox on checkout', 'woocommerce-metricks'),
                    'type'              => 'checkbox',
                    'desc'              => __('Switch on/off the additional accepts marketing checkbox on checkout.\n
                                               NOTE: Turning this off would mark all customers as unsubscribed upon checkout by default', 'woocommerce-metricks'),
                    'desc_tip'          => true,
                    'default'           => 'yes'
                ],
                'accepts_marketing_label' => [
                    'title'             => __('Accepts marketing checkbox label', 'woocommerce-metricks'),
                    'type'              => 'text',
                    'css'               => 'width: 50%',
                    'desc'              => __('Render the tracking code on the selected pages', 'woocommerce-metricks'),
                    'desc_tip'          => true,
                    'default'           => 'I would like to receive affiliate marketing and promotional emails'
                ]
            ];
        }

        public function sanitize_settings($settings) {
            return $settings;
        }

        private function is_option_enabled($option_name) {
            return $this->get_option($option_name) == 'yes'? true : false;
        }

        public function render_accepts_marketing_field( $checkout ) {
            if ( $this->is_option_enabled('enable_marketing_checkbox') == true ) {
                echo "<div style='width: 100%;'>";
                woocommerce_form_field( 'rc_accepts_marketing', array(
                    'type'          => 'checkbox',
                    'label'         => $this->get_option('accepts_marketing_label'),
                    'required'      => false,
                ), false);
                echo "</div>";
            }
        }

        public function capture_accepts_marketing_value( $order_id ) {
            if ( ! empty( $_POST['rc_accepts_marketing'] ) ) {
                update_post_meta( $order_id, 'rc_accepts_marketing', sanitize_text_field( $_POST['rc_accepts_marketing'] ) );
            }
        }

        public function check_plugin_requirements() {
            $message = "<strong>Metricks</strong>: Please make sure the following settings are configured for your integration to work properly:";
            $integration_incomplete = false;
            $keys_to_check = [
                // 'API Access ID' => $this->api_id,
                'Api Key'        => $this->api_key,
                'App Domain'    => $this->app_domain
            ];

            foreach($keys_to_check as $key => $value) {
                if (empty($value)) {
                    $integration_incomplete = true;
                    $message .= "<br> - $key";
                }
            }

            if (get_option('timezone_string') == null) {
                $integration_incomplete = true;
                $message .= "<br> - Store TimeZone (i.e. Asia/Singapore)";
            }

            $valid_statuses = array_keys(wc_get_order_statuses());
            if (!in_array($this->get_option('order_status'), $valid_statuses)) {
                $integration_incomplete = true;
                $message .= "<br> - Please re-select your preferred order status to be sent to us and save your settings";
            }

            if ($integration_incomplete == true) {
                printf('<div class="notice notice-warning"><p>%s</p></div>', $message);
            }
        }

        public function add_order_meta_data($post_id) {
            try {
                if (in_array(get_post($post_id)->post_type, ['shop_order', 'shop_subscription'])) {
                    // prevent admin cookies from automatically adding a referrer_id; this can be done manually though
                    if (is_admin() == false) {
                        // set order locale
                        update_post_meta($post_id, 'rc_loc', $this->get_current_locale());

                        // set order referrer
                        if (isset($_COOKIE['__metr_ref'])) {
                            $COOKIE = sanitize_key($_COOKIE['__metr_ref']);
                          update_post_meta($post_id, 'rc_ref', $COOKIE);
                        }
                    }
                }
            } catch(Exception $e) {
                error_log($e);
            }
        }

        public function rc_submit_purchase($order_id) {
            $met_order = new MET_Order($order_id, $this);
            $met_order->submit_purchase();
        }

        public function render_tracking_code() {
            $shouldRenderTrackingCode = is_front_page();
            if ($shouldRenderTrackingCode) {
                $tracking_code = '<script type="text/javascript">
                !function(e,t,n,a,c,s,r){e[a]||((c=e[a]=function(){c.process?c.process.apply(c,arguments):c.queue.push(arguments)}).queue=[],
                c.t=+new Date,(s=t.createElement(n)).async=1,s.src="https://script.metricks.io/tracker.min.js?event=detect&apikey='. $this->api_key .'&t="+864e5*Math.ceil(new Date/864e5),
                (r=t.getElementsByTagName(n)[0]).parentNode.insertBefore(s,r))}(window,document,"script","metr"),
                metr("apikey","'. $this->api_key .'",),metr("event","detect");
                </script>';
                echo $tracking_code;
            }
        }

        public function render_conversion_code($order_id) {
            $shouldRenderTrackingCode = is_order_received_page() || (is_order_received_page() && is_page($this->tracking_page));
            if ($shouldRenderTrackingCode) {
                $met_order = new MET_Order($order_id, $this);
                $amount = (int)$met_order->total;
                $tracking_code = '<script type="text/javascript">
                !function(e,t,n,a,c,s,r){e[a]||((c=e[a]=function(){c.process?c.process.apply(c,arguments):c.queue.push(arguments)}).queue=[],
                c.t=+new Date,(s=t.createElement(n)).async=1,s.src="https://script.metricks.io/tracker.min.js?t="+864e5*Math.ceil(new Date/864e5),
                (r=t.getElementsByTagName(n)[0]).parentNode.insertBefore(s,r))}(window,document,"script","metr"),
                metr("init","'. $this->api_key .'"),metr("event","conversion", {"order_id":"'. $order_id .'","amount":'. $amount .',"currency":"'. $met_order->currency .'","customer_id":"'. $met_order->email .'","email":"'. $met_order->email .'"});
                </script>';
                echo $tracking_code;
            }
        }

        public function get_current_locale() {
            $localeMapping = [ // Map to Metricks format
                'zh_CN' => 'zh-CN',
                'zh_HK' => 'zh-HK',
                'zh_TW' => 'zh-TW',
                'pt_BR' => 'pt-BR'
            ];

            try {
                $locale = get_user_locale();

                if (!empty($locale)) {
                    if (key_exists($locale, $localeMapping)) {
                        $locale = $localeMapping[$locale];
                    } else {
                        $locale = strstr($locale, '_', true); // Example: en_US > en
                    }
                }
            }
            catch(Exception $e) {
                $locale = 'en';
            }

            return $locale;
        }
    }
}