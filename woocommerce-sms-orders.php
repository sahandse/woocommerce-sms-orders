<?php
/**
 * Plugin Name: پیامک سفارشات ووکامرس
 * Plugin URI: https://github.com/sahandse/woocommerce-sms-orders
 * Description: ارسال و مدیریت پیامک وضعیت سفارش‌های ووکامرس با لاگ، ارسال آزمایشی و پشتیبانی از چند سرویس پیامک.
 * Version: 1.2.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: woocommerce-sms-orders
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class WSO_Plugin {
    const VERSION = '1.2.0';
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
        add_action('admin_post_wso_test_sms', [$this, 'test_sms']);
        add_action('admin_post_wso_manual_sms', [$this, 'manual_sms']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'manual_sms_box']);
        add_filter('s_store_sms_send', [$this, 'external_send_sms'], 10, 4);
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
            'message_template' => 'سفارش #{order_id} در وضعیت «{status}» قرار گرفت. مبلغ: {total}',
            'send_mode' => 'text',
            'pattern_code' => '',
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
            'message_template' => sanitize_textarea_field($in['message_template'] ?? $d['message_template']),
            'send_mode' => in_array($in['send_mode'] ?? '', ['text','pattern'], true) ? $in['send_mode'] : 'text',
            'pattern_code' => sanitize_text_field($in['pattern_code'] ?? ''),
        ];
    }

    public function admin_menu() {
        if (function_exists('s_store_register_submenu')) {
            s_store_register_submenu(
                'woocommerce-sms-orders',
                'پیامک سفارشات',
                [$this, 'settings_page'],
                'manage_woocommerce',
                'پیامک سفارشات'
            );
            add_submenu_page(
                's-store',
                'لاگ پیامک‌ها',
                '↳ لاگ پیامک‌ها',
                'manage_woocommerce',
                'woocommerce-sms-orders-logs',
                [$this, 'logs_page']
            );
            return;
        }

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
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $valid_pages = ['woocommerce-sms-orders', 'woocommerce-sms-orders-logs'];

        if (!in_array($page, $valid_pages, true) && false === strpos($hook, 'woocommerce-sms-orders')) {
            return;
        }

        wp_enqueue_style('wso-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        ?>
        <div class="wrap wso-admin">
            <?php if(!empty($_GET['wso_notice'])):?><div class="notice notice-info"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['wso_notice'])))); ?></p></div><?php endif; ?>
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
                        <h2>متن پیام</h2>
                        <label>حالت ارسال
                            <select name="<?php echo self::OPTION; ?>[send_mode]">
                                <option value="text" <?php selected($s['send_mode'],'text'); ?>>متن عادی</option>
                                <option value="pattern" <?php selected($s['send_mode'],'pattern'); ?>>Pattern (فراز/IPPanel)</option>
                            </select>
                        </label>
                        <label>کد Pattern
                            <input type="text" name="<?php echo self::OPTION; ?>[pattern_code]" value="<?php echo esc_attr($s['pattern_code']); ?>">
                        </label>
                        <label>قالب پیام
                            <textarea rows="5" name="<?php echo self::OPTION; ?>[message_template]"><?php echo esc_textarea($s['message_template']); ?></textarea>
                            <small>متغیرها: {order_id} {status} {total} {name}</small>
                        </label>
                        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wso_test_sms'),'wso_test_sms')); ?>">ارسال آزمایشی به اولین شماره مدیر</a></p>
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
                        <p>ارسال واقعی API برای سرویس‌های اصلی فعال است. برای ارسال، Credential و خط فرستنده را در همین صفحه وارد کنید.</p>
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

    private function normalize_phone($phone) {
        $phone = preg_replace('/\D+/', '', (string)$phone);
        if (0 === strpos($phone,'0098')) $phone = substr($phone,4);
        if (0 === strpos($phone,'98') && strlen($phone) > 10) $phone = '0' . substr($phone,2);
        return $phone;
    }

    private function render_message($order,$status) {
        $s=$this->settings();
        return strtr($s['message_template'],[
            '{order_id}'=>(string)$order->get_id(),
            '{status}'=>wc_get_order_status_name($status),
            '{total}'=>wp_strip_all_tags($order->get_formatted_order_total()),
            '{name}'=>trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
        ]);
    }

    private function send_sms($phone,$message,$params=[]) {
        $s=$this->settings();
        $phone=$this->normalize_phone($phone);
        if(!$phone) return new WP_Error('wso_phone','شماره گیرنده معتبر نیست.');
        if('none'===$s['provider']) return new WP_Error('wso_provider','سرویس پیامک انتخاب نشده است.');

        $provider=$s['provider'];
        $args=['timeout'=>20,'headers'=>[]];

        if('kavenegar'===$provider){
            if(!$s['api_key']) return new WP_Error('wso_auth','API Key کاوه‌نگار وارد نشده است.');
            $url='https://api.kavenegar.com/v1/'.rawurlencode($s['api_key']).'/sms/send.json';
            $args['body']=['receptor'=>$phone,'sender'=>$s['sender'],'message'=>$message];
            $res=wp_remote_post($url,$args);
        } elseif('smsir'===$provider){
            if(!$s['api_key']) return new WP_Error('wso_auth','API Key SMS.ir وارد نشده است.');
            $url='https://api.sms.ir/v1/send/bulk';
            $args['headers']=['Content-Type'=>'application/json','X-API-KEY'=>$s['api_key']];
            $args['body']=wp_json_encode(['lineNumber'=>$s['sender'],'messageText'=>$message,'mobiles'=>[$phone]]);
            $res=wp_remote_post($url,$args);
        } elseif('ghasedak'===$provider){
            if(!$s['api_key']) return new WP_Error('wso_auth','API Key قاصدک وارد نشده است.');
            $url='https://gateway.ghasedak.me/rest/api/v1/WebService/SendSingleSMS';
            $args['headers']=['Content-Type'=>'application/json','ApiKey'=>$s['api_key']];
            $args['body']=wp_json_encode(['message'=>$message,'lineNumber'=>$s['sender'],'receptor'=>$phone]);
            $res=wp_remote_post($url,$args);
        } elseif('farazsms'===$provider){
            if(!$s['api_key']) return new WP_Error('wso_auth','Token فراز/IPPanel وارد نشده است.');
            $url='https://edge.ippanel.com/v1/api/send';
            $to='+98'.ltrim($phone,'0');
            $from=$s['sender'] ? $s['sender'] : '';
            $args['headers']=['Content-Type'=>'application/json','Authorization'=>$s['api_key']];
            if('pattern'===$s['send_mode'] && $s['pattern_code']){
                $args['body']=wp_json_encode([
                    'sending_type'=>'pattern',
                    'from_number'=>$from,
                    'code'=>$s['pattern_code'],
                    'recipients'=>[$to],
                    'params'=>$params ?: ['message'=>$message]
                ]);
            } else {
                $args['body']=wp_json_encode(['sending_type'=>'peer_to_peer','from_number'=>$from,'params'=>[['recipients'=>[$to],'message'=>$message]]]);
            }
            $res=wp_remote_post($url,$args);
        } elseif('melipayamak'===$provider){
            if(!$s['username']||!$s['password']) return new WP_Error('wso_auth','نام کاربری/رمز ملی‌پیامک وارد نشده است.');
            $url='https://rest.payamak-panel.com/api/SendSMS/SendSMS';
            $args['headers']=['Content-Type'=>'application/json'];
            $args['body']=wp_json_encode(['username'=>$s['username'],'password'=>$s['password'],'to'=>$phone,'from'=>$s['sender'],'text'=>$message,'isFlash'=>false]);
            $res=wp_remote_post($url,$args);
        } else {
            return new WP_Error('wso_provider','سرویس پشتیبانی‌نشده است.');
        }

        if(is_wp_error($res)) return $res;
        $code=(int)wp_remote_retrieve_response_code($res);
        $body=wp_remote_retrieve_body($res);
        if($code<200||$code>=300) return new WP_Error('wso_http','خطای سرویس پیامک: HTTP '.$code.' '.$body);
        return ['code'=>$code,'body'=>$body];
    }

    public function test_sms() {
        if(!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('wso_test_sms');
        $s=$this->settings();
        $numbers=array_values(array_filter(array_map('trim',explode(',',$s['admin_numbers']))));
        if(!$numbers) wp_die('ابتدا یک شماره مدیر وارد کنید.');
        $r=$this->send_sms($numbers[0],'پیام آزمایشی افزونه پیامک سفارشات ووکامرس');
        $msg=is_wp_error($r)?$r->get_error_message():'پیام آزمایشی ارسال شد.';
        wp_safe_redirect(add_query_arg(['page'=>'woocommerce-sms-orders','wso_notice'=>rawurlencode($msg)],admin_url('admin.php'))); exit;
    }

    public function order_status_changed($order_id, $old_status, $new_status, $order) {
        $s = $this->settings();
        $key = 'status_' . $new_status;
        if (!isset($s[$key]) || 'yes' !== $s[$key]) return;
        if(!$order instanceof WC_Order) $order=wc_get_order($order_id);
        if(!$order) return;

        $message=$this->render_message($order,$new_status);

        if ('yes' === $s['send_to_customer']) {
            $phone = $order->get_billing_phone();
            if ($phone) {
                $r=$this->send_sms($phone,$message,['order_id'=>(string)$order_id,'status'=>wc_get_order_status_name($new_status),'total'=>(string)$order->get_total(),'name'=>trim($order->get_billing_first_name().' '.$order->get_billing_last_name())]);
                $this->log_event($order_id,$new_status,$phone,is_wp_error($r)?'error: '.$r->get_error_message():'sent');
            }
        }

        if ('yes' === $s['send_to_admin']) {
            $admins = array_filter(array_map('trim', explode(',', $s['admin_numbers'])));
            foreach ($admins as $phone) {
                $r=$this->send_sms($phone,$message);
                $this->log_event($order_id,$new_status,$phone,is_wp_error($r)?'error: '.$r->get_error_message():'sent');
            }
        }
    }

    public function external_send_sms($result,$phone,$message,$context='') {
        if(null!==$result) return $result;
        return $this->send_sms($phone,$message,['context'=>(string)$context]);
    }

    public function manual_sms_box($order) {
        if(!$order instanceof WC_Order || !current_user_can('manage_woocommerce')) return;
        echo '<div class="wso-manual-box" style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd"><h4>ارسال پیامک دستی</h4>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wso_manual_sms"><input type="hidden" name="order_id" value="'.esc_attr($order->get_id()).'">';
        wp_nonce_field('wso_manual_sms_'.$order->get_id(),'wso_nonce');
        echo '<p><input type="text" name="phone" value="'.esc_attr($order->get_billing_phone()).'" placeholder="شماره موبایل" style="width:100%"></p>';
        echo '<p><textarea name="message" rows="3" style="width:100%" placeholder="متن پیام" required></textarea></p>';
        echo '<p><button class="button">ارسال پیامک</button></p></form></div>';
    }

    public function manual_sms() {
        if(!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        $order_id=absint($_POST['order_id']??0);
        $nonce=sanitize_text_field(wp_unslash($_POST['wso_nonce']??''));
        if(!wp_verify_nonce($nonce,'wso_manual_sms_'.$order_id)) wp_die('درخواست نامعتبر');
        $phone=sanitize_text_field(wp_unslash($_POST['phone']??''));
        $message=sanitize_textarea_field(wp_unslash($_POST['message']??''));
        $r=$this->send_sms($phone,$message,['order_id'=>(string)$order_id,'message'=>$message]);
        $this->log_event($order_id,'manual',$phone,is_wp_error($r)?'error: '.$r->get_error_message():'sent');
        $url=wp_get_referer()?:admin_url('edit.php?post_type=shop_order');
        wp_safe_redirect(add_query_arg('wso_manual',is_wp_error($r)?'error':'sent',$url)); exit;
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
