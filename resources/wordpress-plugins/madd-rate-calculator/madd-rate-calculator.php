<?php
/**
 * Plugin Name: MADD Rate Calculator
 * Description: ฟอร์มเช็คราคาค่าส่งระหว่างประเทศ (UPS / DHL) และติดตามพัสดุ จากระบบ MADD — shortcode [madd_rate_calculator] และ [madd_tracking]
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Author: MADD
 * Text Domain: madd-rate-calculator
 *
 * The browser only ever talks to this WordPress site (admin-ajax.php). This plugin then calls
 * the MADD Public Rate API server-to-server, so the API key never reaches the visitor.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Madd_Rate_Calculator
{
    const OPTION = 'madd_rate_calculator';
    const VERSION = '1.1.0';
    const COUNTRIES_CACHE = 'madd_rate_countries';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_shortcode('madd_rate_calculator', [__CLASS__, 'shortcode']);
        add_action('wp_ajax_madd_rates', [__CLASS__, 'ajax_rates']);
        add_action('wp_ajax_nopriv_madd_rates', [__CLASS__, 'ajax_rates']);
        add_shortcode('madd_tracking', [__CLASS__, 'tracking_shortcode']);
        add_action('wp_ajax_madd_track', [__CLASS__, 'ajax_track']);
        add_action('wp_ajax_nopriv_madd_track', [__CLASS__, 'ajax_track']);
    }

    /** @return array{api_url:string, api_key:string, turnstile_site_key:string, turnstile_secret:string, contact_url:string, contact_label:string} */
    public static function settings()
    {
        $saved = get_option(self::OPTION, []);
        $settings = wp_parse_args(is_array($saved) ? $saved : [], [
            'api_url' => '',
            'api_key' => '',
            'turnstile_site_key' => '',
            'turnstile_secret' => '',
            'contact_url' => '',
            'contact_label' => 'ติดต่อจองส่งพัสดุ',
        ]);
        // Prefer constants from wp-config.php so the key never sits in the database.
        if (defined('MADD_RATE_API_URL')) {
            $settings['api_url'] = MADD_RATE_API_URL;
        }
        if (defined('MADD_RATE_API_KEY')) {
            $settings['api_key'] = MADD_RATE_API_KEY;
        }

        return $settings;
    }

    // ---------------------------------------------------------------- Admin settings

    public static function admin_menu()
    {
        add_options_page('MADD Rate Calculator', 'MADD Rate Calculator', 'manage_options', 'madd-rate-calculator', [__CLASS__, 'settings_page']);
    }

    public static function register_settings()
    {
        register_setting('madd_rate_calculator', self::OPTION, [
            'sanitize_callback' => function ($input) {
                $old = get_option(self::OPTION, []);
                $clean = [
                    'api_url' => esc_url_raw(trim($input['api_url'] ?? '')),
                    'turnstile_site_key' => sanitize_text_field($input['turnstile_site_key'] ?? ''),
                    'contact_url' => esc_url_raw(trim($input['contact_url'] ?? '')),
                    'contact_label' => sanitize_text_field($input['contact_label'] ?? ''),
                    // Secret fields: an empty submit keeps the stored value (they are never echoed back).
                    'api_key' => trim($input['api_key'] ?? '') !== '' ? sanitize_text_field($input['api_key']) : ($old['api_key'] ?? ''),
                    'turnstile_secret' => trim($input['turnstile_secret'] ?? '') !== '' ? sanitize_text_field($input['turnstile_secret']) : ($old['turnstile_secret'] ?? ''),
                ];
                delete_transient(self::COUNTRIES_CACHE);

                return $clean;
            },
        ]);
    }

    public static function settings_page()
    {
        $s = self::settings();
        $test = null;
        if (isset($_POST['madd_test']) && check_admin_referer('madd_rate_test')) {
            $test = self::api('GET', '/public/v1/countries');
        } ?>
        <div class="wrap">
            <h1>MADD Rate Calculator</h1>
            <p>ใส่ฟอร์มในหน้าใดก็ได้ด้วย shortcode <code>[madd_rate_calculator]</code> (เช็คราคา) และ <code>[madd_tracking]</code> (ติดตามพัสดุ — ลิงก์ตรงได้ด้วย <code>?tn=เลขTracking</code>)</p>
            <form method="post" action="options.php">
                <?php settings_fields('madd_rate_calculator'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="madd_api_url">MADD API URL</label></th>
                        <td>
                            <input id="madd_api_url" name="<?php echo esc_attr(self::OPTION); ?>[api_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="https://api.example.com/api" <?php disabled(defined('MADD_RATE_API_URL')); ?>>
                            <p class="description">URL ของ MADD backend ที่ลงท้ายด้วย <code>/api</code><?php echo defined('MADD_RATE_API_URL') ? ' — กำหนดไว้ใน wp-config.php แล้ว' : ''; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madd_api_key">API Key</label></th>
                        <td>
                            <input id="madd_api_key" name="<?php echo esc_attr(self::OPTION); ?>[api_key]" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $s['api_key'] ? esc_attr(substr($s['api_key'], 0, 12).'… (บันทึกไว้แล้ว)') : 'madd_...'; ?>" <?php disabled(defined('MADD_RATE_API_KEY')); ?>>
                            <p class="description">สร้างที่ MADD › System Settings › Public API · แนะนำให้ใส่ใน wp-config.php แทน: <code>define('MADD_RATE_API_KEY', 'madd_...');</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cloudflare Turnstile (กันบอท)</th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[turnstile_site_key]" type="text" class="regular-text" value="<?php echo esc_attr($s['turnstile_site_key']); ?>" placeholder="Site key"><br>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[turnstile_secret]" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $s['turnstile_secret'] ? 'Secret key (บันทึกไว้แล้ว)' : 'Secret key'; ?>" style="margin-top:6px">
                            <p class="description">ไม่บังคับ แต่แนะนำ — เว้นว่างเพื่อปิด</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ปุ่มติดต่อใต้ผลราคา</th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[contact_label]" type="text" class="regular-text" value="<?php echo esc_attr($s['contact_label']); ?>" placeholder="ข้อความบนปุ่ม"><br>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[contact_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['contact_url']); ?>" placeholder="เช่น https://line.me/R/ti/p/@yourshop" style="margin-top:6px">
                            <p class="description">เว้นว่าง = ไม่แสดงปุ่ม</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <form method="post">
                <?php wp_nonce_field('madd_rate_test'); ?>
                <p><button type="submit" name="madd_test" value="1" class="button">ทดสอบการเชื่อมต่อ</button></p>
            </form>
            <?php if ($test !== null) : ?>
                <?php if (is_wp_error($test)) : ?>
                    <div class="notice notice-error inline"><p>เชื่อมต่อไม่สำเร็จ: <?php echo esc_html($test->get_error_message()); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-success inline"><p>เชื่อมต่อสำเร็จ — ได้รายชื่อประเทศ <?php echo (int) count($test['countries'] ?? []); ?> ประเทศ</p></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------- MADD API

    /** @return array|WP_Error decoded JSON body */
    private static function api($method, $path, $body = null)
    {
        $s = self::settings();
        if (! $s['api_url'] || ! $s['api_key']) {
            return new WP_Error('madd_not_configured', 'ยังไม่ได้ตั้งค่า MADD API URL / API Key');
        }
        $args = [
            'method' => $method,
            'timeout' => 45, // carriers can take a while to quote
            'headers' => [
                'Authorization' => 'Bearer '.$s['api_key'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-End-User-IP' => self::visitor_ip(),
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(untrailingslashit($s['api_url']).$path, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $message = $data['error']['message'] ?? 'MADD API ตอบกลับ HTTP '.$code;

            return new WP_Error($data['error']['code'] ?? 'madd_http_'.$code, $message, ['status' => $code, 'fields' => $data['error']['fields'] ?? null]);
        }

        return is_array($data) ? $data : new WP_Error('madd_bad_response', 'รูปแบบข้อมูลจาก MADD API ไม่ถูกต้อง');
    }

    private static function visitor_ip()
    {
        // REMOTE_ADDR only — forwarded headers are client-controlled unless a trusted proxy sets them.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function countries()
    {
        $cached = get_transient(self::COUNTRIES_CACHE);
        if (is_array($cached)) {
            return $cached;
        }
        $data = self::api('GET', '/public/v1/countries');
        if (is_wp_error($data)) {
            return [];
        }
        $countries = $data['countries'] ?? [];
        set_transient(self::COUNTRIES_CACHE, $countries, DAY_IN_SECONDS);

        return $countries;
    }

    // ---------------------------------------------------------------- Front end

    public static function shortcode()
    {
        $s = self::settings();
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('madd-rate-calculator', $base.'assets/madd-rate.css', [], self::VERSION);
        wp_enqueue_script('madd-rate-calculator', $base.'assets/madd-rate.js', [], self::VERSION, true);
        if ($s['turnstile_site_key']) {
            wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
        }
        wp_localize_script('madd-rate-calculator', 'MaddRate', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('madd_rates'),
            'contactUrl' => $s['contact_url'],
            'contactLabel' => $s['contact_label'],
        ]);

        $countries = self::countries();
        ob_start(); ?>
        <div class="madd-rate">
            <form class="madd-rate__form" novalidate>
                <div class="madd-rate__row">
                    <label class="madd-rate__field">
                        <span>ส่งไปประเทศ</span>
                        <select name="country" required>
                            <option value="">— เลือกประเทศ —</option>
                            <?php foreach ($countries as $c) : ?>
                                <option value="<?php echo esc_attr($c['iso2']); ?>"><?php echo esc_html($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="madd-rate__field">
                        <span>เมือง (ถ้ามี)</span>
                        <input name="city" type="text" maxlength="100">
                    </label>
                    <label class="madd-rate__field madd-rate__field--sm">
                        <span>รหัสไปรษณีย์ปลายทาง</span>
                        <input name="postcode" type="text" maxlength="20">
                    </label>
                </div>

                <div class="madd-rate__type">
                    <label><input type="radio" name="shipment_type" value="parcel" checked> พัสดุ / กล่อง</label>
                    <label><input type="radio" name="shipment_type" value="document"> เอกสาร</label>
                </div>

                <div class="madd-rate__packages"></div>
                <button type="button" class="madd-rate__add">+ เพิ่มกล่อง</button>

                <?php if ($s['turnstile_site_key']) : ?>
                    <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($s['turnstile_site_key']); ?>"></div>
                <?php endif; ?>

                <button type="submit" class="madd-rate__submit">เช็คราคา</button>
                <p class="madd-rate__error" role="alert" hidden></p>
            </form>
            <div class="madd-rate__results" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function tracking_shortcode()
    {
        $s = self::settings();
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('madd-rate-calculator', $base.'assets/madd-rate.css', [], self::VERSION);
        wp_enqueue_script('madd-tracking', $base.'assets/madd-tracking.js', [], self::VERSION, true);
        if ($s['turnstile_site_key']) {
            wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
        }
        wp_localize_script('madd-tracking', 'MaddTrack', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('madd_track'),
        ]);
        $prefill = isset($_GET['tn']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_GET['tn']))) : '';

        ob_start(); ?>
        <div class="madd-track">
            <form class="madd-rate__form madd-track__form" novalidate>
                <div class="madd-rate__row">
                    <label class="madd-rate__field">
                        <span>เลข Tracking (UPS / DHL)</span>
                        <input name="tracking_number" type="text" maxlength="40" value="<?php echo esc_attr($prefill); ?>" placeholder="เช่น 5084355500" autocomplete="off" required>
                    </label>
                </div>
                <?php if ($s['turnstile_site_key']) : ?>
                    <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($s['turnstile_site_key']); ?>"></div>
                <?php endif; ?>
                <button type="submit" class="madd-rate__submit">ติดตามพัสดุ</button>
                <p class="madd-rate__error" role="alert" hidden></p>
            </form>
            <div class="madd-track__result" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_track()
    {
        if (! check_ajax_referer('madd_track', 'nonce', false)) {
            wp_send_json_error(['message' => 'หน้าเว็บหมดอายุ กรุณารีเฟรชแล้วลองใหม่'], 403);
        }
        self::verify_turnstile();

        $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST['tracking_number'] ?? '')));
        if (strlen($number) < 8) {
            wp_send_json_error(['message' => 'กรุณากรอกเลข Tracking ให้ถูกต้อง'], 422);
        }

        $result = self::api('GET', '/public/v1/tracking/'.rawurlencode($number));
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && ! empty($data['status']) ? (int) $data['status'] : 502;
            $message = in_array($status, [401, 403], true) || $result->get_error_code() === 'madd_not_configured'
                ? 'ระบบติดตามพัสดุไม่พร้อมใช้งานชั่วคราว'
                : $result->get_error_message();
            wp_send_json_error(['message' => $message], in_array($status, [404, 422, 429], true) ? $status : 502);
        }

        wp_send_json_success($result);
    }

    private static function verify_turnstile()
    {
        $s = self::settings();
        if (! $s['turnstile_secret']) {
            return;
        }
        $verify = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['body' => [
            'secret' => $s['turnstile_secret'],
            'response' => sanitize_text_field(wp_unslash($_POST['turnstile'] ?? '')),
            'remoteip' => self::visitor_ip(),
        ]]);
        $ok = ! is_wp_error($verify) && ! empty(json_decode(wp_remote_retrieve_body($verify), true)['success']);
        if (! $ok) {
            wp_send_json_error(['message' => 'ยืนยันว่าไม่ใช่บอทไม่สำเร็จ กรุณาลองใหม่'], 400);
        }
    }

    public static function ajax_rates()
    {
        if (! check_ajax_referer('madd_rates', 'nonce', false)) {
            wp_send_json_error(['message' => 'หน้าเว็บหมดอายุ กรุณารีเฟรชแล้วลองใหม่'], 403);
        }
        self::verify_turnstile();

        $packages = [];
        $raw = json_decode(wp_unslash($_POST['packages'] ?? '[]'), true);
        foreach (array_slice(is_array($raw) ? $raw : [], 0, 20) as $p) {
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
                'country' => strtoupper(sanitize_text_field(wp_unslash($_POST['country'] ?? ''))),
                'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
                'postcode' => sanitize_text_field(wp_unslash($_POST['postcode'] ?? '')),
            ]),
            'shipment_type' => ($_POST['shipment_type'] ?? '') === 'document' ? 'document' : 'parcel',
            'packages' => $packages,
        ]);

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && ! empty($data['status']) ? (int) $data['status'] : 502;
            // Don't show configuration problems (bad key etc.) to visitors.
            $message = in_array($status, [401, 403], true) || $result->get_error_code() === 'madd_not_configured'
                ? 'ระบบเช็คราคาไม่พร้อมใช้งานชั่วคราว'
                : $result->get_error_message();
            wp_send_json_error(['message' => $message, 'fields' => is_array($data) ? ($data['fields'] ?? null) : null], $status === 422 ? 422 : ($status === 429 ? 429 : 502));
        }

        wp_send_json_success($result);
    }
}

Madd_Rate_Calculator::init();
