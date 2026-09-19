<?php
/**
 * Plugin Name: بهینه‌ساز رسانه
 * Plugin URI: https://github.com/sahandse/media-optimizer
 * Description: بهینه‌سازی تصاویر وردپرس با پردازش دسته‌ای، تبدیل WebP/AVIF، انتخاب کیفیت، Restore و گزارش صرفه‌جویی.
 * Version: 1.0.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: media-optimizer
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

final class MO_Plugin {
    const VERSION = '1.0.0';
    const OPTION  = 'mo_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_filter('attachment_fields_to_edit', [$this, 'media_fields'], 10, 2);
        add_action('add_attachment', [$this, 'maybe_auto_optimize']);
    }

    public function defaults() {
        return [
            'auto_optimize' => 'yes',
            'format' => 'webp',
            'quality' => 82,
            'batch_size' => 30,
            'keep_original' => 'yes',
            'delete_extra_sizes' => 'no',
            'scheduled' => 'no',
            'accent' => '#111827',
        ];
    }

    public function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('mo_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $d = $this->defaults();
        return [
            'auto_optimize' => !empty($in['auto_optimize']) ? 'yes' : 'no',
            'format' => in_array($in['format'] ?? '', ['webp','avif','none'], true) ? $in['format'] : $d['format'],
            'quality' => min(100, max(10, absint($in['quality'] ?? 82))),
            'batch_size' => in_array((int)($in['batch_size'] ?? 30), [30,50], true) ? (int)$in['batch_size'] : 30,
            'keep_original' => !empty($in['keep_original']) ? 'yes' : 'no',
            'delete_extra_sizes' => !empty($in['delete_extra_sizes']) ? 'yes' : 'no',
            'scheduled' => !empty($in['scheduled']) ? 'yes' : 'no',
            'accent' => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
        ];
    }

    public function admin_menu() {
        add_menu_page(
            'بهینه‌ساز رسانه',
            'بهینه‌ساز رسانه',
            'upload_files',
            'media-optimizer',
            [$this, 'settings_page'],
            'dashicons-format-image',
            61
        );
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'media-optimizer')) return;
        wp_enqueue_style('mo-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public function settings_page() {
        if (!current_user_can('upload_files')) return;
        $s = $this->settings();
        ?>
        <div class="wrap mo-admin">
            <div class="mo-hero">
                <div>
                    <h1>بهینه‌ساز رسانه</h1>
                    <p>بهینه‌سازی تصاویر، تبدیل فرمت و کنترل حجم فایل‌های رسانه‌ای.</p>
                </div>
                <span>v<?php echo esc_html(self::VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('mo_group'); ?>
                <div class="mo-grid">
                    <section class="mo-card">
                        <h2>تنظیمات اصلی</h2>
                        <label class="mo-switch"><span>بهینه‌سازی خودکار تصاویر جدید</span><input type="checkbox" name="<?php echo self::OPTION; ?>[auto_optimize]" value="1" <?php checked($s['auto_optimize'],'yes'); ?>></label>
                        <label>فرمت خروجی
                            <select name="<?php echo self::OPTION; ?>[format]">
                                <option value="webp" <?php selected($s['format'],'webp'); ?>>WebP</option>
                                <option value="avif" <?php selected($s['format'],'avif'); ?>>AVIF</option>
                                <option value="none" <?php selected($s['format'],'none'); ?>>بدون تبدیل</option>
                            </select>
                        </label>
                        <label>کیفیت
                            <input type="number" min="10" max="100" name="<?php echo self::OPTION; ?>[quality]" value="<?php echo esc_attr($s['quality']); ?>">
                        </label>
                        <label>اندازه پردازش دسته‌ای
                            <select name="<?php echo self::OPTION; ?>[batch_size]">
                                <option value="30" <?php selected((int)$s['batch_size'],30); ?>>۳۰ تصویر</option>
                                <option value="50" <?php selected((int)$s['batch_size'],50); ?>>۵۰ تصویر</option>
                            </select>
                        </label>
                    </section>

                    <section class="mo-card">
                        <h2>نسخه‌ها و بازیابی</h2>
                        <label class="mo-switch"><span>نگهداری نسخه اصلی</span><input type="checkbox" name="<?php echo self::OPTION; ?>[keep_original]" value="1" <?php checked($s['keep_original'],'yes'); ?>></label>
                        <label class="mo-switch"><span>حذف نسخه‌های اضافی</span><input type="checkbox" name="<?php echo self::OPTION; ?>[delete_extra_sizes]" value="1" <?php checked($s['delete_extra_sizes'],'yes'); ?>></label>
                        <label class="mo-switch"><span>بهینه‌سازی زمان‌بندی‌شده</span><input type="checkbox" name="<?php echo self::OPTION; ?>[scheduled]" value="1" <?php checked($s['scheduled'],'yes'); ?>></label>
                    </section>

                    <section class="mo-card">
                        <h2>ظاهر</h2>
                        <label>رنگ اصلی
                            <input type="color" name="<?php echo self::OPTION; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>">
                        </label>
                    </section>

                    <section class="mo-card">
                        <h2>پردازش دسته‌ای</h2>
                        <p>زیرساخت تنظیمات آماده است. موتور پردازش، Restore واقعی و گزارش حجم در نسخه تکمیلی همین Repo فعال می‌شود.</p>
                    </section>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
        <?php
    }

    public function media_fields($fields, $post) {
        if (!wp_attachment_is_image($post->ID)) return $fields;

        $saved = (int)get_post_meta($post->ID, '_mo_saved_bytes', true);

        $fields['mo_status'] = [
            'label' => 'بهینه‌سازی',
            'input' => 'html',
            'html' => '<div style="padding:8px 0">' .
                ($saved > 0
                    ? 'صرفه‌جویی: <strong>' . esc_html(size_format($saved)) . '</strong>'
                    : 'هنوز گزارشی ثبت نشده است.') .
                '</div>',
        ];

        return $fields;
    }

    public function maybe_auto_optimize($attachment_id) {
        if ('yes' !== $this->settings()['auto_optimize']) return;
        if (!wp_attachment_is_image($attachment_id)) return;

        // Placeholder hook for the real image processor.
        update_post_meta($attachment_id, '_mo_optimizer_pending', 1);
    }
}

new MO_Plugin();
