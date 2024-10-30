<?php
/**
 * WooCommerce Metricks Integration.
 *
 * @package  MET_Order
 * @category Integration
 * @author   Metricks
 */

class MET_Order {
    private $order;
    public $base_url = 'https://api.metricks.io/api/v1';
    public $wc_pre_30 = false;
    public $api_key;
    public $app_domain;
    public $first_name;
    public $last_name;
    public $email;
    public $locale;
    public $discount_code;
    public $total;
    public $currency;
    public $order_number;
    public $order_timestamp;
    public $browser_ip;
    public $user_agent;
    public $accepts_marketing;
    public $referrer_id;

    public function __construct($wc_order_id, WC_Metricks_Integration $integration) {
        $this->wc_pre_30 = version_compare(WC_VERSION, '3.0.0', '<');
        $this->order     = new WC_Order($wc_order_id);

        if ($this->wc_pre_30) {
            $this->order_timestamp = time();
            if (get_option('timezone_string') != null) {
                $timezone_string = get_option('timezone_string');
                $this->order_timestamp = DateTime::createFromFormat('Y-m-d H:i:s', $this->order->order_date, new DateTimeZone($timezone_string))->getTimestamp();
            }

            $this->first_name        = $this->order->billing_first_name;
            $this->last_name         = $this->order->billing_last_name;
            $this->email             = $this->order->billing_email;
            $this->total             = $this->order->get_total();
            $this->currency          = $this->order->get_order_currency();
            $this->order_number      = $wc_order_id;
            $this->browser_ip        = $this->order->customer_ip_address;
            $this->user_agent        = $this->order->customer_user_agent;
            $this->accepts_marketing = get_post_meta($wc_order_id, 'rc_accepts_marketing', true) ? 'true' : 'false';
            $this->referrer_id       = get_post_meta($wc_order_id, 'rc_ref', true);
            $this->locale            = get_post_meta($wc_order_id, 'rc_loc', true);
        } else {
            $order_data = $this->order->get_data();

            $this->first_name        = $order_data['billing']['first_name'];
            $this->last_name         = $order_data['billing']['last_name'];
            $this->email             = $order_data['billing']['email'];
            $this->total             = $order_data['total'];
            $this->currency          = $order_data['currency'];
            $this->order_number      = $wc_order_id;
            $this->order_timestamp   = $order_data['date_created']->getTimestamp();
            $this->browser_ip        = $order_data['customer_ip_address'];
            $this->user_agent        = $order_data['customer_user_agent'];
            $this->accepts_marketing = $this->order->get_meta('rc_accepts_marketing', true, 'view') ? 'true' : 'false';
            $this->referrer_id       = $this->order->get_meta('rc_ref', true, 'view');
            $this->locale            = $this->order->get_meta('rc_loc', true, 'view');
        }

        $this->api_key           = $integration->api_key;
        $this->app_domain       = $integration->app_domain;
    }

    private function generate_post_fields($specific_keys = [], $additional_keys = []) {
        $post_fields = [
            'api_key'               => $this->api_key,
            'accepts_marketing'     => $this->accepts_marketing,
            'first_name'            => $this->first_name,
            'last_name'             => $this->last_name,
            'name'                  => $this->first_name . ' ' . $this->last_name,
            'customer_id'           => $this->order->get_customer_id(),
            'email'                 => $this->email,
            'locale'                => $this->locale,
            'order_timestamp'       => $this->order_timestamp,
            'ip'                    => $this->browser_ip,
            'user_agent'            => $this->user_agent,
            'amount'                => $this->total,
            'currency'              => $this->currency,
            'order_id'              => $this->order_number,
            'timestamp'             => time(),
        ];

        // only add referrer_id if present
        if ($this->referrer_id != null) {
            $post_fields['unique_link'] = $this->referrer_id;
        }

        // check if we need only specific post fields from the default
        if ($specific_keys != null && count($specific_keys) > 0) {
            $new_post_fields = [];
            foreach($post_fields as $field => $value) {
                if (in_array($field, $specific_keys)) {
                    $new_post_fields[$field] = $value;
                }
            }

            // only overwrite post fields if at least one key is retreived
            if ($new_post_fields != null && count($new_post_fields) > 0) {
                $post_fields = $new_post_fields;
            }
        }

        // check if there are additional keys we want to add to the payload
        if ($additional_keys != null && count($additional_keys) > 0) {
            $post_fields = array_merge($post_fields, $additional_keys);
        }

        // sort keys
        ksort($post_fields);

        return $post_fields;
    }

    // created this function because PHP's http_build_query function converts 'timestamp' to 'xstamp'
    private function prepParams(Array $params) {
        $preppedParams = '';
        foreach($params as $key => $value) {
            $preppedParams .= "$key=$value";
        }

        return $preppedParams;
    }

    private function generate_request_body($post_fields) {
        if (!empty($this->api_key) && !empty($this->app_domain)) {
            $params = [
                'body' => $post_fields
            ];
            $params['body']['signature'] = md5($this->api_key . $this->prepParams($post_fields));

            return $params;
        }
    }

    // https://www.metricks.io/api#conversion
    public function submit_purchase() {
        $endpoint = join('/', [$this->base_url, 'event_conversion']);

        if (!empty($this->api_key) && !empty($this->app_domain)) {
            // $params         = $this->generate_request_body($this->generate_post_fields());
            $args = array(
                'headers' => array(
                  'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'body' => $this->generate_post_fields(),
              );
            $response       = wp_safe_remote_post($endpoint, $args);

            if (is_wp_error($response)) {
                return error_log(print_r($response, TRUE));
            }

            $response_body  = json_decode($response['body']);

            if ($response_body->message == 'success') {
                $this->order->add_order_note('Order sent to Metricks');
            }
        }
    }
}
