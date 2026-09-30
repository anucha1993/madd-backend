<?php
/**
 * Plugin Name: MADD Tracking
 * Description: ฟอร์มติดตามพัสดุ (UPS / DHL) จากระบบ MADD — ใส่ในหน้าใดก็ได้ด้วย shortcode [madd_tracking] · ลิงก์ตรง ?tn=เลขTracking
 * Version: 1.0.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: MADD
 * Text Domain: madd-tracking
 *
 * The visitor's browser only talks to this WordPress site (admin-ajax.php); this plugin calls
 * the MADD Public Tracking API server-to-server, so the API key never reaches the browser.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Madd_Tracking
{
    const OPTION = 'madd_tracking';
    const VERSION = '1.0.1';
    const AJAX_ACTION = 'madd_tracking_lookup';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), [__CLASS__, 'action_links']);

        // The full "MADD Rate Calculator" plugin also provides [madd_tracking] — don't clash.
        if (class_exists('Madd_Rate_Calculator')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning"><p><b>MADD Tracking:</b> ปลั๊กอิน MADD Rate Calculator มีฟอร์ม Tracking อยู่แล้ว — ปิดใช้งานปลั๊กอินตัวใดตัวหนึ่ง</p></div>';
            });

            return;
        }
        add_shortcode('madd_tracking', [__CLASS__, 'shortcode']);
        add_action('wp_ajax_'.self::AJAX_ACTION, [__CLASS__, 'ajax_lookup']);
        add_action('wp_ajax_nopriv_'.self::AJAX_ACTION, [__CLASS__, 'ajax_lookup']);
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
            'contact_label' => 'ติดต่อเจ้าหน้าที่',
        ]);
        // Constants in wp-config.php win, so the key never has to sit in the database.
        if (defined('MADD_RATE_API_URL')) {
            $settings['api_url'] = MADD_RATE_API_URL;
        }
        if (defined('MADD_RATE_API_KEY')) {
            $settings['api_key'] = MADD_RATE_API_KEY;
        }

        return $settings;
    }

    // ------------------------------------------------------------------ Admin

    public static function action_links($links)
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page=madd-tracking')).'">ตั้งค่า</a>');

        return $links;
    }

    public static function admin_menu()
    {
        add_options_page('MADD Tracking', 'MADD Tracking', 'manage_options', 'madd-tracking', [__CLASS__, 'settings_page']);
    }

    public static function register_settings()
    {
        register_setting('madd_tracking', self::OPTION, [
            'sanitize_callback' => function ($input) {
                $old = get_option(self::OPTION, []);

                return [
                    'api_url' => esc_url_raw(self::base_url($input['api_url'] ?? '')),
                    'turnstile_site_key' => sanitize_text_field($input['turnstile_site_key'] ?? ''),
                    'contact_url' => esc_url_raw(trim($input['contact_url'] ?? '')),
                    'contact_label' => sanitize_text_field($input['contact_label'] ?? ''),
                    // Secrets: an empty submit keeps the stored value (never echoed back to the form).
                    'api_key' => trim($input['api_key'] ?? '') !== '' ? sanitize_text_field($input['api_key']) : ($old['api_key'] ?? ''),
                    'turnstile_secret' => trim($input['turnstile_secret'] ?? '') !== '' ? sanitize_text_field($input['turnstile_secret']) : ($old['turnstile_secret'] ?? ''),
                ];
            },
        ]);
    }

    public static function settings_page()
    {
        $s = self::settings();
        $name = esc_attr(self::OPTION);
        $test = null;
        if (isset($_POST['madd_test']) && check_admin_referer('madd_tracking_test')) {
            $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST['madd_test_number'] ?? '')));
            $test = self::api('/public/v1/tracking/'.rawurlencode($number ?: 'TEST00000000'));
            // 404 = the key works, the number just isn't a MADD shipment.
            if (is_wp_error($test) && ($test->get_error_data()['status'] ?? 0) === 404 && ! $number) {
                $test = ['connected' => true];
            }
        } ?>
        <div class="wrap">
            <h1>MADD Tracking</h1>
            <p>ใส่ฟอร์มในหน้าใดก็ได้ด้วย shortcode <code>[madd_tracking]</code> · ลิงก์ตรงให้ลูกค้า: <code><?php echo esc_html(home_url('/ชื่อหน้า/?tn=5084355500')); ?></code></p>

            <form method="post" action="options.php">
                <?php settings_fields('madd_tracking'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="madd_api_url">MADD API URL</label></th>
                        <td>
                            <input id="madd_api_url" name="<?php echo $name; ?>[api_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="https://backend.example.com/api" <?php disabled(defined('MADD_RATE_API_URL')); ?>>
                            <p class="description">ใส่แค่ถึง <code>/api</code> เช่น <code>https://backend.madd-admin.com/api</code> (ไม่ต้องใส่ /public/v1/...)<?php echo defined('MADD_RATE_API_URL') ? ' — กำหนดไว้ใน wp-config.php แล้ว' : ''; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madd_api_key">API Key</label></th>
                        <td>
                            <input id="madd_api_key" name="<?php echo $name; ?>[api_key]" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $s['api_key'] ? esc_attr(substr($s['api_key'], 0, 12).'… (บันทึกไว้แล้ว)') : 'madd_...'; ?>" <?php disabled(defined('MADD_RATE_API_KEY')); ?>>
                            <p class="description">สร้างที่ MADD › System Settings › Public API (Website) — เปิด "ใช้ติดตามพัสดุได้" · แนะนำใส่ใน wp-config.php: <code>define('MADD_RATE_API_KEY', 'madd_...');</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cloudflare Turnstile (กันบอท)</th>
                        <td>
                            <input name="<?php echo $name; ?>[turnstile_site_key]" type="text" class="regular-text" value="<?php echo esc_attr($s['turnstile_site_key']); ?>" placeholder="Site key"><br>
                            <input name="<?php echo $name; ?>[turnstile_secret]" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $s['turnstile_secret'] ? 'Secret key (บันทึกไว้แล้ว)' : 'Secret key'; ?>" style="margin-top:6px">
                            <p class="description">ไม่บังคับ — เว้นว่างเพื่อปิด (ถ้าเปิด ลิงก์ ?tn= จะให้ลูกค้ากดยืนยันก่อนค้นหา)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ปุ่มติดต่อใต้ผลการค้นหา</th>
                        <td>
                            <input name="<?php echo $name; ?>[contact_label]" type="text" class="regular-text" value="<?php echo esc_attr($s['contact_label']); ?>" placeholder="ข้อความบนปุ่ม"><br>
                            <input name="<?php echo $name; ?>[contact_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['contact_url']); ?>" placeholder="เช่น https://line.me/R/ti/p/@yourshop" style="margin-top:6px">
                            <p class="description">เว้นว่าง = ไม่แสดงปุ่ม</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>ทดสอบ</h2>
            <form method="post">
                <?php wp_nonce_field('madd_tracking_test'); ?>
                <input name="madd_test_number" type="text" class="regular-text" placeholder="เลข Tracking จริง (เว้นว่าง = ทดสอบแค่การเชื่อมต่อ)">
                <button type="submit" name="madd_test" value="1" class="button">ทดสอบ</button>
            </form>
            <?php if ($test !== null) : ?>
                <?php if (is_wp_error($test)) : ?>
                    <div class="notice notice-error inline" style="margin-top:12px"><p>ไม่สำเร็จ: <?php echo esc_html($test->get_error_message()); ?></p></div>
                <?php elseif (! empty($test['connected'])) : ?>
                    <div class="notice notice-success inline" style="margin-top:12px"><p>เชื่อมต่อ MADD สำเร็จ — API Key ใช้งานได้</p></div>
                <?php else : ?>
                    <div class="notice notice-success inline" style="margin-top:12px"><p>พบ <?php echo esc_html($test['tracking_number'].' — '.$test['carrier'].' · '.$test['status_text']); ?> (<?php echo (int) count($test['events'] ?? []); ?> จุดสแกน)</p></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ MADD API

    /** @return array|WP_Error */
    private static function api($path)
    {
        $s = self::settings();
        if (! $s['api_url'] || ! $s['api_key']) {
            return new WP_Error('madd_not_configured', 'ยังไม่ได้ตั้งค่า MADD API URL / API Key', ['status' => 0]);
        }
        $response = wp_remote_get(self::base_url($s['api_url']).$path, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.$s['api_key'],
                'Accept' => 'application/json',
                'X-End-User-IP' => self::visitor_ip(),
            ],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('madd_unreachable', 'เชื่อมต่อ MADD ไม่ได้: '.$response->get_error_message(), ['status' => 0]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error($data['error']['code'] ?? 'madd_http_'.$code, $data['error']['message'] ?? 'MADD API ตอบกลับ HTTP '.$code, ['status' => $code]);
        }

        return is_array($data) ? $data : new WP_Error('madd_bad_response', 'รูปแบบข้อมูลจาก MADD ไม่ถูกต้อง', ['status' => 0]);
    }

    /** The configured URL, trimmed back to ".../api" if a full endpoint was pasted in. */
    private static function base_url($url)
    {
        return preg_replace('#/public(/v1)?(/.*)?$#', '', untrailingslashit(trim($url)));
    }

    private static function visitor_ip()
    {
        // REMOTE_ADDR only — X-Forwarded-For is client-controlled unless a trusted proxy sets it.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function clean_number($value)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    // ------------------------------------------------------------------ Front end

    public static function shortcode($atts = [])
    {
        $atts = shortcode_atts(['title' => ''], $atts, 'madd_tracking');
        $s = self::settings();
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('madd-tracking', $base.'assets/madd-tracking.css', [], self::VERSION);
        wp_enqueue_script('madd-tracking', $base.'assets/madd-tracking.js', [], self::VERSION, true);
        if ($s['turnstile_site_key']) {
            wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
        }
        wp_localize_script('madd-tracking', 'MaddTracking', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::AJAX_ACTION),
            'contactUrl' => $s['contact_url'],
            'contactLabel' => $s['contact_label'],
        ]);
        $prefill = isset($_GET['tn']) ? self::clean_number(wp_unslash($_GET['tn'])) : '';
        $input_id = 'madd-tn-'.wp_unique_id();

        ob_start(); ?>
        <div class="madd-tracking">
            <?php if ($atts['title']) : ?>
                <h3 class="madd-tracking__heading"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>
            <form class="madd-tracking__form" novalidate>
                <label class="madd-tracking__label" for="<?php echo esc_attr($input_id); ?>">เลข Tracking (UPS / DHL)</label>
                <div class="madd-tracking__bar">
                    <input id="<?php echo esc_attr($input_id); ?>" name="tracking_number" type="text" maxlength="40" value="<?php echo esc_attr($prefill); ?>" placeholder="เช่น 5084355500" autocomplete="off" inputmode="text" required>
                    <button type="submit" class="madd-tracking__submit">ติดตาม</button>
                </div>
                <?php if ($s['turnstile_site_key']) : ?>
                    <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($s['turnstile_site_key']); ?>"></div>
                <?php endif; ?>
                <p class="madd-tracking__error" role="alert" hidden></p>
            </form>
            <div class="madd-tracking__result" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_lookup()
    {
        if (! check_ajax_referer(self::AJAX_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'หน้าเว็บหมดอายุ กรุณารีเฟรชแล้วลองใหม่'], 403);
        }

        $s = self::settings();
        if ($s['turnstile_secret']) {
            $verify = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['body' => [
                'secret' => $s['turnstile_secret'],
                'response' => sanitize_text_field(wp_unslash($_POST['turnstile'] ?? '')),
                'remoteip' => self::visitor_ip(),
            ]]);
            if (is_wp_error($verify) || empty(json_decode(wp_remote_retrieve_body($verify), true)['success'])) {
                wp_send_json_error(['message' => 'ยืนยันว่าไม่ใช่บอทไม่สำเร็จ กรุณาลองใหม่'], 400);
            }
        }

        $number = self::clean_number(wp_unslash($_POST['tracking_number'] ?? ''));
        if (strlen($number) < 8 || strlen($number) > 40) {
            wp_send_json_error(['message' => 'กรุณากรอกเลข Tracking ให้ถูกต้อง'], 422);
        }

        $result = self::api('/public/v1/tracking/'.rawurlencode($number));
        if (is_wp_error($result)) {
            $status = (int) ($result->get_error_data()['status'] ?? 0);
            if ($status === 404 || $status === 422 || $status === 429) {
                wp_send_json_error(['message' => $result->get_error_message()], $status);
            }
            // Configuration / connectivity problems are for the site admin, not the visitor.
            if (current_user_can('manage_options')) {
                wp_send_json_error(['message' => '[Admin] '.$result->get_error_message()], 502);
            }
            wp_send_json_error(['message' => 'ระบบติดตามพัสดุไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง'], 502);
        }

        wp_send_json_success($result);
    }
}

Madd_Tracking::init();
