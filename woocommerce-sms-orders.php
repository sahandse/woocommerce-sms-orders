<?php
/**
 * Plugin Name: پیامک سفارشات ووکامرس
 * Plugin URI: https://github.com/sahandse/woocommerce-sms-orders
 * Description: ارسال و مدیریت پیامک وضعیت سفارش‌های ووکامرس با لاگ، ارسال آزمایشی و پشتیبانی از چند سرویس پیامک.
 * Version: 1.0.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: woocommerce-sms-orders
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class WSO_Plugin {
    const VERSION = '1.0.0';
    const OPTION  = 'wso_settings';
    const LOG_OPTION = 'wso_sms_logs';

    public function __construct() {
        add_action('before_woocommerce_init', [$this, 'declare_hpos']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function declare_hpos() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('woocommerce_order_status_changed', [$this, 'order_status_changed'], 10, 4);
    }

    public function woocommerce_notice() {
        echo '<div class="notice notice-error"><p>افزونه پیامک سفارشات برای اجرا به WooCommerce نیاز دارد.</p></div>';
    }

    public function defaults() {
        return [
            'provider' => 'none',
            'api_key' => '',
            'username' => '',
            'password' => '',
            'sender' => '',
            'admin_numbers' => '',
            'send_to_customer' => 'yes',
            'send_to_admin' => 'yes',
            'status_pending' => 'yes',
            'status_processing' => 'yes',
            'status_completed' => 'yes',
            'status_cancelled' => 'yes',
            'status_failed' => 'yes',
            'accent' => '#111827',
        ];
    }

    public function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('wso_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $d = $this->defaults();
        $providers = ['none','melipayamak','farazsms','smsir','kavenegar','ghasedak'];

        return [
            'provider' => in_array($in['provider'] ?? '', $providers, true) ? $in['provider'] : $d['provider'],
            'api_key' => sanitize_text_field($in['api_key'] ?? ''),
            'username' => sanitize_text_field($in['username'] ?? ''),
            'password' => sanitize_text_field($in['password'] ?? ''),
            'sender' => sanitize_text_field($in['sender'] ?? ''),
            'admin_numbers' => sanitize_text_field($in['admin_numbers'] ?? ''),
            'send_to_customer' => !empty($in['send_to_customer']) ? 'yes' : 'no',
            'send_to_admin' => !empty($in['send_to_admin']) ? 'yes' : 'no',
            'status_pending' => !empty($in['status_pending']) ? 'yes' : 'no',
            'status_processing' => !empty($in['status_processing']) ? 'yes' : 'no',
            'status_completed' => !empty($in['status_completed']) ? 'yes' : 'no',
            'status_cancelled' => !empty($in['status_cancelled']) ? 'yes' : 'no',
            'status_failed' => !empty($in['status_failed']) ? 'yes' : 'no',
            'accent' => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
        ];
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'پیامک سفارشات',
            'پیامک سفارشات',
            'manage_woocommerce',
            'woocommerce-sms-orders',
            [$this, 'settings_page']
        );

        add_submenu_page(
            'woocommerce',
            'لاگ پیامک‌ها',
            'لاگ پیامک‌ها',
            'manage_woocommerce',
            'woocommerce-sms-orders-logs',
            [$this, 'logs_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'woocommerce-sms-orders')) return;
        wp_enqueue_style('wso-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        ?>
        <div class="wrap wso-admin">
            <div class="wso-hero">
                <div>
                    <h1>پیامک سفارشات ووکامرس</h1>
                    <p>مدیریت سرویس پیامک، وضعیت‌های سفارش و دریافت‌کنندگان.</p>
                </div>
                <span>v<?php echo esc_html(self::VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('wso_group'); ?>
                <div class="wso-grid">
                    <section class="wso-card">
                        <h2>سرویس پیامک</h2>
                        <label>سرویس
                            <select name="<?php echo self::OPTION; ?>[provider]">
                                <option value="none" <?php selected($s['provider'],'none'); ?>>انتخاب نشده</option>
                                <option value="melipayamak" <?php selected($s['provider'],'melipayamak'); ?>>ملی‌پیامک</option>
                                <option value="farazsms" <?php selected($s['provider'],'farazsms'); ?>>فراز SMS</option>
                                <option value="smsir" <?php selected($s['provider'],'smsir'); ?>>SMS.ir</option>
                                <option value="kavenegar" <?php selected($s['provider'],'kavenegar'); ?>>کاوه‌نگار</option>
                                <option value="ghasedak" <?php selected($s['provider'],'ghasedak'); ?>>قاصدک</option>
                            </select>
                        </label>
                        <label>API Key
                            <input type="password" name="<?php echo self::OPTION; ?>[api_key]" value="<?php echo esc_attr($s['api_key']); ?>" autocomplete="off">
                        </label>
                        <label>نام کاربری
                            <input type="text" name="<?php echo self::OPTION; ?>[username]" value="<?php echo esc_attr($s['username']); ?>">
                        </label>
                        <label>رمز عبور
                            <input type="password" name="<?php echo self::OPTION; ?>[password]" value="<?php echo esc_attr($s['password']); ?>" autocomplete="off">
                        </label>
                        <label>شماره/خط فرستنده
                            <input type="text" name="<?php echo self::OPTION; ?>[sender]" value="<?php echo esc_attr($s['sender']); ?>">
                        </label>
                    </section>

                    <section class="wso-card">
                        <h2>دریافت‌کنندگان</h2>
                        <label class="wso-switch"><span>ارسال به مشتری</span><input type="checkbox" name="<?php echo self::OPTION; ?>[send_to_customer]" value="1" <?php checked($s['send_to_customer'],'yes'); ?>></label>
                        <label class="wso-switch"><span>ارسال به مدیر</span><input type="checkbox" name="<?php echo self::OPTION; ?>[send_to_admin]" value="1" <?php checked($s['send_to_admin'],'yes'); ?>></label>
                        <label>شماره مدیران
                            <input type="text" name="<?php echo self::OPTION; ?>[admin_numbers]" value="<?php echo esc_attr($s['admin_numbers']); ?>">
                            <small>چند شماره را با کاما جدا کنید.</small>
                        </label>
                    </section>

                    <section class="wso-card">
                        <h2>وضعیت‌های سفارش</h2>
                        <label class="wso-switch"><span>در انتظار پرداخت</span><input type="checkbox" name="<?php echo self::OPTION; ?>[status_pending]" value="1" <?php checked($s['status_pending'],'yes'); ?>></label>
                        <label class="wso-switch"><span>در حال پردازش</span><input type="checkbox" name="<?php echo self::OPTION; ?>[status_processing]" value="1" <?php checked($s['status_processing'],'yes'); ?>></label>
                        <label class="wso-switch"><span>تکمیل شده</span><input type="checkbox" name="<?php echo self::OPTION; ?>[status_completed]" value="1" <?php checked($s['status_completed'],'yes'); ?>></label>
                        <label class="wso-switch"><span>لغو شده</span><input type="checkbox" name="<?php echo self::OPTION; ?>[status_cancelled]" value="1" <?php checked($s['status_cancelled'],'yes'); ?>></label>
                        <label class="wso-switch"><span>ناموفق</span><input type="checkbox" name="<?php echo self::OPTION; ?>[status_failed]" value="1" <?php checked($s['status_failed'],'yes'); ?>></label>
                    </section>

                    <section class="wso-card">
                        <h2>ظاهر</h2>
                        <label>رنگ اصلی
                            <input type="color" name="<?php echo self::OPTION; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>">
                        </label>
                    </section>

                    <section class="wso-card wso-wide">
                        <h2>وضعیت توسعه</h2>
                        <p>هسته تشخیص تغییر وضعیت و سیستم لاگ آماده است. ارسال واقعی API، Pattern/OTP، اعتبار پنل و ارسال دستی از صفحه سفارش در نسخه‌های بعدی همین Repo تکمیل می‌شود.</p>
                    </section>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
        <?php
    }

    public function logs_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $logs = (array)get_option(self::LOG_OPTION, []);
        ?>
        <div class="wrap wso-admin">
            <div class="wso-hero">
                <div>
                    <h1>لاگ پیامک‌ها</h1>
                    <p>آخرین رویدادهای ثبت‌شده پیامک.</p>
                </div>
            </div>

            <div class="wso-card">
                <?php if (!$logs): ?>
                    <p>هنوز لاگی ثبت نشده است.</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>زمان</th>
                                <th>سفارش</th>
                                <th>وضعیت</th>
                                <th>گیرنده</th>
                                <th>نتیجه</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse(array_slice($logs, -100)) as $log): ?>
                                <tr>
                                    <td><?php echo esc_html($log['time'] ?? ''); ?></td>
                                    <td><?php echo esc_html($log['order_id'] ?? ''); ?></td>
                                    <td><?php echo esc_html($log['status'] ?? ''); ?></td>
                                    <td><?php echo esc_html($log['recipient'] ?? ''); ?></td>
                                    <td><?php echo esc_html($log['result'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function order_status_changed($order_id, $old_status, $new_status, $order) {
        $s = $this->settings();
        $key = 'status_' . $new_status;

        if (!isset($s[$key]) || 'yes' !== $s[$key]) return;

        if ('yes' === $s['send_to_customer']) {
            $phone = $order->get_billing_phone();
            if ($phone) {
                $this->log_event($order_id, $new_status, $phone, 'pending-provider');
            }
        }

        if ('yes' === $s['send_to_admin']) {
            $admins = array_filter(array_map('trim', explode(',', $s['admin_numbers'])));
            foreach ($admins as $phone) {
                $this->log_event($order_id, $new_status, $phone, 'pending-provider');
            }
        }
    }

    private function log_event($order_id, $status, $recipient, $result) {
        $logs = (array)get_option(self::LOG_OPTION, []);
        $logs[] = [
            'time' => current_time('mysql'),
            'order_id' => (int)$order_id,
            'status' => sanitize_text_field($status),
            'recipient' => sanitize_text_field($recipient),
            'result' => sanitize_text_field($result),
        ];

        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }

        update_option(self::LOG_OPTION, $logs, false);
    }
}

new WSO_Plugin();
