<?php
/**
 * Plugin Name: Minimal Meta (Facebook) CAPI Conversion (No Pixel)
 * Description: Captures fbclid from Meta ads, stores it as a first-party cookie, and sends a single server-side conversion via Meta Conversions API when triggered by a shortcode.
 * Version: 0.1.0
 * Author: You
 */

if (!defined('ABSPATH')) exit;

class Minimal_Meta_CAPI_No_Pixel {
  const OPT_KEY = 'mmcapi_settings';
  const COOKIE_KEY = 'mmcapi_fbclid';
  const COOKIE_DAYS = 7;

  public function __construct() {
    add_action('init', [$this, 'capture_fbclid'], 1);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_shortcode('meta_capi_conversion', [$this, 'shortcode_fire_conversion']);
    add_action('woocommerce_thankyou', [$this, 'woocommerce_thankyou'], 10);
  }

  public function capture_fbclid() {
    if (is_admin()) return;

    if (!empty($_GET['fbclid'])) {
      $fbclid = sanitize_text_field(wp_unslash($_GET['fbclid']));
      // Store only what we need, as a first-party cookie.
      $expire = time() + (self::COOKIE_DAYS * DAY_IN_SECONDS);
      // Secure/HTTPOnly where possible.
      $secure = is_ssl();
      setcookie(self::COOKIE_KEY, $fbclid, $expire, COOKIEPATH ?: '/', '', $secure, true);
      // Keep PHP superglobal in sync for this request.
      $_COOKIE[self::COOKIE_KEY] = $fbclid;

      $this->debug_log('Captured fbclid from URL: ' . $fbclid . ' | URL: ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown'));
    }
  }

  public function admin_menu() {
    add_options_page(
      'Minimal Meta Conversions',
      'Minimal Meta Conversions',
      'manage_options',
      'minimal-meta-capi',
      [$this, 'settings_page']
    );
  }

  public function register_settings() {
    register_setting('mmcapi_group', self::OPT_KEY, [$this, 'sanitize_settings']);

    add_settings_section('mmcapi_main', 'Settings', function () {
      echo '<p>Configure your Meta Pixel ID and a Conversions API access token.</p>';
    }, 'minimal-meta-capi');

    $fields = [
      'pixel_id' => 'Pixel ID',
      'access_token' => 'Access Token',
      'test_event_code' => 'Test Event Code (optional)',
    ];

    foreach ($fields as $key => $label) {
      add_settings_field(
        $key,
        esc_html($label),
        function () use ($key) {
          $opts = get_option(self::OPT_KEY, []);
          $val  = isset($opts[$key]) ? $opts[$key] : '';
          printf(
            '<input type="text" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr(self::OPT_KEY),
            esc_attr($key),
            esc_attr($val)
          );
          if ($key === 'test_event_code') {
            echo '<p class="description">For testing only. Generate code in Meta Events Manager > Test Events. Leave blank for production.</p>';
          }
        },
        'minimal-meta-capi',
        'mmcapi_main'
      );
    }

    // Event name dropdown with standard Meta event names
    add_settings_field(
      'event_name',
      esc_html('Event Name'),
      function () {
        $opts = get_option(self::OPT_KEY, []);
        $val  = isset($opts['event_name']) ? $opts['event_name'] : 'Purchase';

        $standard_events = [
          'Lead' => 'Lead',
          'Purchase' => 'Purchase',
          'CompleteRegistration' => 'Complete Registration',
          'Contact' => 'Contact',
          'SubmitApplication' => 'Submit Application',
          'AddToCart' => 'Add to Cart',
          'InitiateCheckout' => 'Initiate Checkout',
          'AddPaymentInfo' => 'Add Payment Info',
          'Subscribe' => 'Subscribe',
          'StartTrial' => 'Start Trial',
          'ViewContent' => 'View Content',
          'Search' => 'Search',
          'AddToWishlist' => 'Add to Wishlist',
          'Schedule' => 'Schedule',
        ];

        printf('<select name="%s[event_name]" class="regular-text">', esc_attr(self::OPT_KEY));
        foreach ($standard_events as $event_value => $event_label) {
          printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($event_value),
            selected($val, $event_value, false),
            esc_html($event_label)
          );
        }
        echo '</select>';
        echo '<p class="description">Select the standard Meta event to track.</p>';
      },
      'minimal-meta-capi',
      'mmcapi_main'
    );

    // WooCommerce integration checkbox
    add_settings_field(
      'woocommerce_enabled',
      esc_html('WooCommerce Integration'),
      function () {
        $opts = get_option(self::OPT_KEY, []);
        $checked = !empty($opts['woocommerce_enabled']);
        printf(
          '<label><input type="checkbox" name="%s[woocommerce_enabled]" value="1" %s /> Enable on WooCommerce thank you page</label>',
          esc_attr(self::OPT_KEY),
          checked($checked, true, false)
        );
        echo '<p class="description">Automatically fire conversion events on order completion.</p>';
      },
      'minimal-meta-capi',
      'mmcapi_main'
    );

    // Include user data checkbox
    add_settings_field(
      'include_user_data',
      esc_html('Include User Data'),
      function () {
        $opts = get_option(self::OPT_KEY, []);
        $checked = !empty($opts['include_user_data']);
        printf(
          '<label><input type="checkbox" name="%s[include_user_data]" value="1" %s /> Send IP address and user agent to Meta</label>',
          esc_attr(self::OPT_KEY),
          checked($checked, true, false)
        );
        echo '<p class="description">Improves event matching but shares more user data. Uncheck for minimal tracking.</p>';
      },
      'minimal-meta-capi',
      'mmcapi_main'
    );

    // Debug logging checkbox
    add_settings_field(
      'debug_logging',
      esc_html('Debug Logging'),
      function () {
        $opts = get_option(self::OPT_KEY, []);
        $checked = !empty($opts['debug_logging']);
        printf(
          '<label><input type="checkbox" name="%s[debug_logging]" value="1" %s /> Enable debug logging</label>',
          esc_attr(self::OPT_KEY),
          checked($checked, true, false)
        );
        echo '<p class="description">Log fbclid captures and API calls to <code>wp-content/minimal-conversions.log</code>.</p>';
      },
      'minimal-meta-capi',
      'mmcapi_main'
    );
  }

  public function sanitize_settings($in) {
    return [
      'pixel_id'            => isset($in['pixel_id']) ? preg_replace('/\D+/', '', $in['pixel_id']) : '',
      'access_token'        => isset($in['access_token']) ? sanitize_text_field($in['access_token']) : '',
      'event_name'          => isset($in['event_name']) ? sanitize_text_field($in['event_name']) : 'Purchase',
      'test_event_code'     => isset($in['test_event_code']) ? sanitize_text_field($in['test_event_code']) : '',
      'woocommerce_enabled' => !empty($in['woocommerce_enabled']) ? 1 : 0,
      'include_user_data'   => !empty($in['include_user_data']) ? 1 : 0,
      'debug_logging'       => !empty($in['debug_logging']) ? 1 : 0,
    ];
  }

  public static function is_woocommerce_enabled() {
    $opts = get_option(self::OPT_KEY, []);
    return !empty($opts['woocommerce_enabled']);
  }

  private function debug_log($message) {
    $opts = get_option(self::OPT_KEY, []);
    if (!empty($opts['debug_logging'])) {
      $log_file = WP_CONTENT_DIR . '/minimal-conversions.log';
      $timestamp = current_time('Y-m-d H:i:s');
      $log_entry = sprintf("[%s] %s\n", $timestamp, $message);
      error_log($log_entry, 3, $log_file);
    }
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>Minimal Meta Conversions</h1>';
    echo '<p class="description" style="max-width: 800px; margin-bottom: 20px;">';
    echo 'This plugin provides privacy-focused, server-side conversion tracking for Meta (Facebook) ads without requiring the Meta Pixel. ';
    echo 'When visitors click a Meta ad, the <code>fbclid</code> parameter is captured and stored as a first-party cookie. ';
    echo 'When they reach the conversion page (containing the shortcode), a server-side event is sent to Meta\'s Conversions API, ';
    echo 'allowing Meta to attribute the conversion while minimizing client-side tracking and improving compatibility with ad blockers.';
    echo '</p>';
    echo '<p class="description">For documentation see <a href="https://github.com/vitalseeds/minimal-conversions#">readme</a>.</p>';
    echo '<form method="post" action="options.php">';
    settings_fields('mmcapi_group');
    do_settings_sections('minimal-meta-capi');
    submit_button();
    echo '</form>';
    echo '<hr />';
    echo '<p><strong>Usage:</strong> Add shortcode <code>[meta_capi_conversion]</code> to your conversion/thank-you page.</p>';
    echo '<p>This plugin stores <code>fbclid</code> in a first-party cookie and sends one server-side event when the shortcode renders.</p>';
    echo '</div>';
  }

  public function shortcode_fire_conversion($atts) {
    // Prevent accidental firing in admin/editor previews.
    if (is_admin()) return '';

    // Parse shortcode attributes
    $atts = shortcode_atts(['order_id' => 0], $atts);
    $order_id = absint($atts['order_id']);

    $opts = get_option(self::OPT_KEY, []);
    $pixel_id = isset($opts['pixel_id']) ? $opts['pixel_id'] : '';
    $token    = isset($opts['access_token']) ? $opts['access_token'] : '';
    $event    = !empty($opts['event_name']) ? $opts['event_name'] : 'Purchase';
    $testcode = !empty($opts['test_event_code']) ? $opts['test_event_code'] : '';

    if (empty($pixel_id) || empty($token)) return '';

    // Ensure we only fire once per browser session for this page load.
    // (Very simple guard; customize if you need stronger dedupe.)
    $once_key = 'mmcapi_fired_' . md5($event . '|' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
    if (!empty($_COOKIE[$once_key])) return '';

    $fbclid = isset($_COOKIE[self::COOKIE_KEY]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_KEY])) : '';
    if (empty($fbclid)) return ''; // Minimal: only report conversions that came from Meta click-through.

    $fbc = $this->make_fbc_from_fbclid($fbclid);

    $url = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');

    // Build user_data - always include fbc, optionally include IP and user agent
    $user_data = ['fbc' => $fbc];

    if (!empty($opts['include_user_data'])) {
      $user_data['client_ip_address'] = $this->get_client_ip();
      $user_data['client_user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 1000) : '';
    }

    // Build event data
    $event_data = [
      'event_name'       => $event,
      'event_time'       => time(),
      'action_source'    => 'website',
      'event_source_url' => $url,
      'user_data'        => array_filter($user_data),
    ];

    // Add custom_data for Purchase events with WooCommerce order data
    if ($order_id > 0 && function_exists('wc_get_order')) {
      $order = wc_get_order($order_id);
      if ($order) {
        $event_data['custom_data'] = [
          'currency' => $order->get_currency(),
          'value'    => floatval($order->get_total()),
        ];
      }
    }

    $payload = [
      'data' => [$event_data],
    ];

    if (!empty($testcode)) $payload['test_event_code'] = $testcode;

    $endpoint = sprintf('https://graph.facebook.com/v19.0/%s/events?access_token=%s', rawurlencode($pixel_id), rawurlencode($token));

    $this->debug_log('Firing conversion event: ' . $event . ' | fbclid: ' . $fbclid . ' | URL: ' . $url);
    $this->debug_log('API Payload: ' . wp_json_encode($payload));

    $resp = wp_remote_post($endpoint, [
      'timeout' => 5,
      'headers' => ['Content-Type' => 'application/json'],
      'body'    => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
      $this->debug_log('API Error: ' . $resp->get_error_message());
    } else {
      $response_code = wp_remote_retrieve_response_code($resp);
      $response_body = wp_remote_retrieve_body($resp);
      $this->debug_log('API Response [' . $response_code . ']: ' . $response_body);
    }

    // Set "fired" cookie for 1 hour to reduce accidental re-fires.
    $expire = time() + HOUR_IN_SECONDS;
    $secure = is_ssl();
    setcookie($once_key, '1', $expire, COOKIEPATH ?: '/', '', $secure, true);

    // Silent by default; return empty to not affect page output.
    return '';
  }

  public function woocommerce_thankyou($order_id) {
    // Only fire if WooCommerce integration is enabled
    if (self::is_woocommerce_enabled()) {
      echo do_shortcode('[meta_capi_conversion order_id="' . absint($order_id) . '"]');
    }
  }

  private function make_fbc_from_fbclid($fbclid) {
    // Meta format commonly used: "fb.1.<timestamp>.<fbclid>"
    return 'fb.1.' . time() . '.' . $fbclid;
  }

  private function get_client_ip() {
    // Minimal + reasonably safe: prefer REMOTE_ADDR.
    // (If you're behind a trusted proxy/CDN, adapt this carefully.)
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
  }
}

new Minimal_Meta_CAPI_No_Pixel();
