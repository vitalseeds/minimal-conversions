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
    }
  }

  public function admin_menu() {
    add_options_page(
      'Minimal Meta CAPI',
      'Minimal Meta CAPI',
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
      'event_name' => 'Event Name (e.g. Lead, Purchase)',
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
          if ($key === 'event_name') {
            echo '<p class="description">Default is "Lead" if left blank.</p>';
          }
        },
        'minimal-meta-capi',
        'mmcapi_main'
      );
    }
  }

  public function sanitize_settings($in) {
    return [
      'pixel_id'         => isset($in['pixel_id']) ? preg_replace('/\D+/', '', $in['pixel_id']) : '',
      'access_token'     => isset($in['access_token']) ? sanitize_text_field($in['access_token']) : '',
      'event_name'       => isset($in['event_name']) ? sanitize_text_field($in['event_name']) : 'Lead',
      'test_event_code'  => isset($in['test_event_code']) ? sanitize_text_field($in['test_event_code']) : '',
    ];
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>Minimal Meta CAPI</h1>';
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

    $opts = get_option(self::OPT_KEY, []);
    $pixel_id = isset($opts['pixel_id']) ? $opts['pixel_id'] : '';
    $token    = isset($opts['access_token']) ? $opts['access_token'] : '';
    $event    = !empty($opts['event_name']) ? $opts['event_name'] : 'Lead';
    $testcode = !empty($opts['test_event_code']) ? $opts['test_event_code'] : '';

    if (empty($pixel_id) || empty($token)) return '';

    // Ensure we only fire once per browser session for this page load.
    // (Very simple guard; customize if you need stronger dedupe.)
    $once_key = 'mmcapi_fired_' . md5($event . '|' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
    if (!empty($_COOKIE[$once_key])) return '';

    $fbclid = isset($_COOKIE[self::COOKIE_KEY]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_KEY])) : '';
    if (empty($fbclid)) return ''; // Minimal: only report conversions that came from Meta click-through.

    $fbc = $this->make_fbc_from_fbclid($fbclid);

    $ip = $this->get_client_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 1000) : '';
    $url = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');

    $payload = [
      'data' => [[
        'event_name'       => $event,
        'event_time'       => time(),
        'action_source'    => 'website',
        'event_source_url' => $url,
        'user_data'        => array_filter([
          'fbc'              => $fbc,
          'client_ip_address'=> $ip,
          'client_user_agent'=> $ua,
        ]),
      ]],
    ];

    if (!empty($testcode)) $payload['test_event_code'] = $testcode;

    $endpoint = sprintf('https://graph.facebook.com/v19.0/%s/events?access_token=%s', rawurlencode($pixel_id), rawurlencode($token));

    $resp = wp_remote_post($endpoint, [
      'timeout' => 5,
      'headers' => ['Content-Type' => 'application/json'],
      'body'    => wp_json_encode($payload),
    ]);

    // Set "fired" cookie for 1 hour to reduce accidental re-fires.
    $expire = time() + HOUR_IN_SECONDS;
    $secure = is_ssl();
    setcookie($once_key, '1', $expire, COOKIEPATH ?: '/', '', $secure, true);

    // Silent by default; return empty to not affect page output.
    return '';
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
