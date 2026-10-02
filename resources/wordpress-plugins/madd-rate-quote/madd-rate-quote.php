<?php
/**
 * Plugin Name: MADD Rate Quote
 * Description: ฟอร์มเช็คราคาค่าส่งระหว่างประเทศ (UPS / DHL) ราคาเดียวกับหน้าร้าน จากระบบ MADD — shortcode [madd_rate_quote] (ภาษาอังกฤษ: [madd_rate_quote lang="en"])
 * Version: 1.3.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: MADD
 * Text Domain: madd-rate-quote
 *
 * The visitor's browser asks MADD directly (the site's Origin must be registered on the API key
 * in MADD) so every quote isn't funnelled through this server's single IP. If that isn't set up,
 * it falls back to admin-ajax.php, which calls MADD server-to-server with the API key.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Madd_Rate_Quote
{
    const OPTION = 'madd_rate_quote';
    const VERSION = '1.3.1';
    const NONCE = 'madd_rate_quote';
    const COUNTRIES_CACHE = 'madd_rate_quote_countries';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), [__CLASS__, 'action_links']);
        add_shortcode('madd_rate_quote', [__CLASS__, 'shortcode']);
        if (! shortcode_exists('madd_stats')) {
            add_shortcode('madd_stats', [__CLASS__, 'stats_shortcode']);
        }
        foreach (['madd_quote_rates' => 'ajax_rates', 'madd_quote_countries' => 'ajax_countries'] as $action => $method) {
            add_action('wp_ajax_'.$action, [__CLASS__, $method]);
            add_action('wp_ajax_nopriv_'.$action, [__CLASS__, $method]);
        }
    }

    /** @return array<string,string> */
    public static function settings()
    {
        $saved = get_option(self::OPTION, []);
        $settings = wp_parse_args(is_array($saved) ? $saved : [], [
            'api_url' => '',
            'api_key' => '',
            'contact_url' => '',
            'contact_label' => '',
        ]);
        if (defined('MADD_RATE_API_URL')) {
            $settings['api_url'] = MADD_RATE_API_URL;
        }
        if (defined('MADD_RATE_API_KEY')) {
            $settings['api_key'] = MADD_RATE_API_KEY;
        }
        // Reuse the MADD Tracking plugin's connection settings when this one has none.
        $tracking = get_option('madd_tracking', []);
        if (is_array($tracking)) {
            $settings['api_url'] = $settings['api_url'] ?: ($tracking['api_url'] ?? '');
            $settings['api_key'] = $settings['api_key'] ?: ($tracking['api_key'] ?? '');
        }

        return $settings;
    }

    private static function base_url($url)
    {
        return preg_replace('#/public(/v1)?(/.*)?$#', '', untrailingslashit(trim((string) $url)));
    }

    // ------------------------------------------------------------------ Admin

    public static function action_links($links)
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page=madd-rate-quote')).'">ตั้งค่า</a>');

        return $links;
    }

    public static function admin_menu()
    {
        add_options_page('MADD Rate Quote', 'MADD Rate Quote', 'manage_options', 'madd-rate-quote', [__CLASS__, 'settings_page']);
    }

    public static function register_settings()
    {
        register_setting('madd_rate_quote', self::OPTION, [
            'sanitize_callback' => function ($input) {
                $old = get_option(self::OPTION, []);
                delete_transient(self::COUNTRIES_CACHE);

                return [
                    'api_url' => esc_url_raw(self::base_url($input['api_url'] ?? '')),
                    'api_key' => trim($input['api_key'] ?? '') !== '' ? sanitize_text_field($input['api_key']) : ($old['api_key'] ?? ''),
                    'contact_url' => esc_url_raw(trim($input['contact_url'] ?? '')),
                    'contact_label' => sanitize_text_field($input['contact_label'] ?? ''),
                ];
            },
        ]);
    }

    public static function settings_page()
    {
        $s = self::settings();
        $name = esc_attr(self::OPTION); ?>
        <div class="wrap">
            <h1>MADD Rate Quote</h1>
            <p>ใส่ฟอร์มในหน้าใดก็ได้ด้วย shortcode <code>[madd_rate_quote]</code> · ภาษาอังกฤษ <code>[madd_rate_quote lang="en"]</code></p>
            <div class="notice notice-info inline"><p>
                ที่ MADD › Public API › แก้ไข Key: ติ๊ก <b>ใช้เช็คราคาได้</b> และใส่เว็บไซต์นี้ (<code><?php echo esc_html(home_url()); ?></code>) ในช่อง
                <b>เว็บไซต์ที่ให้ Browser ของลูกค้าเรียกได้โดยตรง</b> — ถ้าติดตั้ง MADD Tracking ไว้แล้ว ใช้ URL / API Key ชุดเดียวกันได้เลย (ไม่ต้องกรอกซ้ำ)
            </p></div>
            <form method="post" action="options.php">
                <?php settings_fields('madd_rate_quote'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mq_api_url">MADD API URL</label></th>
                        <td>
                            <input id="mq_api_url" name="<?php echo $name; ?>[api_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="https://backend.madd-admin.com/api" <?php disabled(defined('MADD_RATE_API_URL')); ?>>
                            <p class="description">ใส่แค่ถึง <code>/api</code> — เว้นว่าง = ใช้ค่าจากปลั๊กอิน MADD Tracking</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mq_api_key">API Key</label></th>
                        <td>
                            <input id="mq_api_key" name="<?php echo $name; ?>[api_key]" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $s['api_key'] ? esc_attr(substr($s['api_key'], 0, 12).'… (มีแล้ว)') : 'madd_...'; ?>" <?php disabled(defined('MADD_RATE_API_KEY')); ?>>
                            <p class="description">ใช้เฉพาะทางสำรองผ่าน Server — เว้นว่าง = ใช้ Key ของปลั๊กอิน MADD Tracking</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ปุ่มใต้ราคา</th>
                        <td>
                            <input name="<?php echo $name; ?>[contact_label]" type="text" class="regular-text" value="<?php echo esc_attr($s['contact_label']); ?>" placeholder="เช่น Book via LINE / จองผ่าน LINE"><br>
                            <input name="<?php echo $name; ?>[contact_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['contact_url']); ?>" placeholder="https://line.me/R/ti/p/@yourshop" style="margin-top:6px">
                            <p class="description">เว้นว่าง = ไม่แสดงปุ่ม</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ Server-side fallback

    /** @return array|WP_Error */
    private static function api($method, $path, $body = null)
    {
        $s = self::settings();
        if (! $s['api_url'] || ! $s['api_key']) {
            return new WP_Error('madd_not_configured', 'MADD API URL / API Key is not set', ['status' => 0]);
        }
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '';
        $args = [
            'method' => $method,
            'timeout' => 45,
            'headers' => ['Authorization' => 'Bearer '.$s['api_key'], 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-End-User-IP' => $ip],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(self::base_url($s['api_url']).$path, $args);
        if (is_wp_error($response)) {
            return new WP_Error('madd_unreachable', $response->get_error_message(), ['status' => 0]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error($data['error']['code'] ?? 'http_'.$code, $data['error']['message'] ?? 'HTTP '.$code, ['status' => $code, 'fields' => $data['error']['fields'] ?? null]);
        }

        return is_array($data) ? $data : new WP_Error('bad_response', 'Bad response', ['status' => 0]);
    }

    public static function ajax_countries()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $cached = get_transient(self::COUNTRIES_CACHE);
        if (is_array($cached)) {
            wp_send_json_success(['countries' => $cached]);
        }
        $data = self::api('GET', '/public/v1/countries');
        if (is_wp_error($data)) {
            wp_send_json_error(['code' => $data->get_error_code()], 502);
        }
        set_transient(self::COUNTRIES_CACHE, $data['countries'] ?? [], DAY_IN_SECONDS);
        wp_send_json_success(['countries' => $data['countries'] ?? []]);
    }

    public static function ajax_rates()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $in = wp_unslash($_POST);
        $packages = [];
        foreach (array_slice(is_array($in['packages'] ?? null) ? $in['packages'] : [], 0, 20) as $p) {
            $packages[] = array_filter([
                'weight' => isset($p['weight']) ? (float) $p['weight'] : null,
                'length' => ! empty($p['length']) ? (float) $p['length'] : null,
                'width' => ! empty($p['width']) ? (float) $p['width'] : null,
                'height' => ! empty($p['height']) ? (float) $p['height'] : null,
                'quantity' => ! empty($p['quantity']) ? (int) $p['quantity'] : 1,
            ], fn ($v) => $v !== null);
        }
        $result = self::api('POST', '/public/v1/rates', [
            'destination' => array_filter([
                'country' => strtoupper(sanitize_text_field($in['destination']['country'] ?? '')),
                'city' => sanitize_text_field($in['destination']['city'] ?? ''),
                'postcode' => sanitize_text_field($in['destination']['postcode'] ?? ''),
            ]),
            'shipment_type' => ($in['shipment_type'] ?? '') === 'document' ? 'document' : 'parcel',
            'packages' => $packages,
        ]);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = (int) ($data['status'] ?? 0);
            wp_send_json_error([
                'code' => $result->get_error_code(),
                'admin' => current_user_can('manage_options') ? $result->get_error_message() : null,
            ], in_array($status, [422, 429], true) ? $status : 502);
        }
        wp_send_json_success($result);
    }

    // ------------------------------------------------------------------ Front end

    /**
     * [madd_stats] — live usage counter from MADD. Provided by both MADD plugins; whichever loads
     * first registers it. Attributes: lang (en|th), show (quotes,tracked,visitors,views),
     * min (hide numbers below this).
     */
    public static function stats_shortcode($atts = [])
    {
        $atts = shortcode_atts(['lang' => '', 'show' => 'quotes,tracked,visitors', 'min' => '0'], $atts, 'madd_stats');
        $lang = strtolower(preg_replace('/[^a-z]/i', '', (string) $atts['lang']));
        if (! in_array($lang, ['th', 'en'], true)) {
            $site = function_exists('pll_current_language') ? pll_current_language('slug') : (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : get_locale());
            $lang = strpos(strtolower((string) $site), 'th') === 0 ? 'th' : 'en';
        }
        $s = self::settings();
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('madd-stats', $base.'assets/madd-stats.css', [], self::VERSION);
        wp_enqueue_script('madd-stats', $base.'assets/madd-stats.js', [], self::VERSION, true);
        wp_localize_script('madd-stats', 'MaddStats', ['apiUrl' => $s['api_url'] ? self::base_url($s['api_url']) : '']);

        return sprintf(
            '<div class="madd-stats" data-lang="%s" data-show="%s" data-min="%d" hidden></div>',
            esc_attr($lang),
            esc_attr(preg_replace('/[^a-z,]/', '', strtolower((string) $atts['show']))),
            (int) $atts['min']
        );
    }

    public static function shortcode($atts = [])
    {
        $atts = shortcode_atts(['lang' => '', 'title' => '', 'ai' => 'on'], $atts, 'madd_rate_quote');
        // Letters only — tolerates lang=”en” when an editor turned the quotes into curly ones.
        $lang = strtolower(preg_replace('/[^a-z]/i', '', (string) $atts['lang']));
        if (! in_array($lang, ['th', 'en'], true)) {
            $site = function_exists('pll_current_language') ? pll_current_language('slug') : (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : get_locale());
            $lang = strpos(strtolower((string) $site), 'th') === 0 ? 'th' : 'en';
        }
        $s = self::settings();
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('madd-rate-quote', $base.'assets/madd-rate-quote.css', [], self::VERSION);
        wp_enqueue_script('madd-rate-quote', $base.'assets/madd-rate-quote.js', [], self::VERSION, true);
        wp_localize_script('madd-rate-quote', 'MaddRateQuote', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'apiUrl' => $s['api_url'] ? self::base_url($s['api_url']) : '',
            'contactUrl' => $s['contact_url'],
            'contactLabel' => $s['contact_label'],
        ]);

        ob_start(); ?>
        <div class="madd-quote" data-lang="<?php echo esc_attr($lang); ?>" data-ai="<?php echo preg_replace('/[^a-z]/i', '', strtolower((string) $atts['ai'])) === 'off' ? 'off' : 'on'; ?>">
            <?php if ($atts['title']) : ?><h3 class="madd-quote__heading"><?php echo esc_html($atts['title']); ?></h3><?php endif; ?>
            <form class="madd-quote__form" novalidate></form>
            <div class="madd-quote__result" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}

Madd_Rate_Quote::init();
