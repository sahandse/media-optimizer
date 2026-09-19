<?php
/**
 * Plugin Name: بهینه‌ساز رسانه
 * Plugin URI: https://github.com/sahandse/media-optimizer
 * Description: بهینه‌سازی تصاویر وردپرس با پردازش دسته‌ای، تبدیل WebP/AVIF، انتخاب کیفیت، Restore و گزارش صرفه‌جویی.
 * Version: 1.1.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: media-optimizer
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

final class MO_Plugin {
    const VERSION = '1.0.1';
    const OPTION  = 'mo_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_filter('attachment_fields_to_edit', [$this, 'media_fields'], 10, 2);
        add_action('add_attachment', [$this, 'maybe_auto_optimize']);
        add_action('admin_post_mo_batch_optimize', [$this, 'batch_optimize']);
        add_action('admin_post_mo_restore', [$this, 'restore_image']);
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
        if (function_exists('s_store_register_submenu')) {
            s_store_register_submenu('media-optimizer', 'بهینه‌ساز رسانه', [$this, 'settings_page'], 'upload_files', 'بهینه‌ساز رسانه');
            return;
        }
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
                        <p>پردازش واقعی تصاویر با Image Editor وردپرس، نگهداری Backup و گزارش میزان صرفه‌جویی.</p>
                        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mo_batch_optimize'),'mo_batch_optimize')); ?>">بهینه‌سازی دسته‌ای</a></p>
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

        $restore = wp_nonce_url(admin_url('admin-post.php?action=mo_restore&attachment='.$post->ID),'mo_restore_'.$post->ID);
        $fields['mo_status'] = [
            'label' => 'بهینه‌سازی',
            'input' => 'html',
            'html' => '<div style="padding:8px 0">' .
                ($saved > 0
                    ? 'صرفه‌جویی: <strong>' . esc_html(size_format($saved)) . '</strong>'
                    : 'هنوز گزارشی ثبت نشده است.') .
                (get_post_meta($post->ID,'_mo_backup_path',true) ? ' · <a href="' . esc_url($restore) . '">Restore</a>' : '') .
                '</div>',
        ];

        return $fields;
    }

    private function optimize_attachment($attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) return new WP_Error('mo_not_image','فایل تصویر نیست.');

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return new WP_Error('mo_missing','فایل پیدا نشد.');

        $s = $this->settings();
        $before = filesize($file);
        $backup = get_post_meta($attachment_id,'_mo_backup_path',true);

        if ('yes' === $s['keep_original'] && !$backup) {
            $backup = $file . '.mo-original';
            if (!@copy($file,$backup)) return new WP_Error('mo_backup','ساخت Backup ناموفق بود.');
            update_post_meta($attachment_id,'_mo_backup_path',$backup);
            update_post_meta($attachment_id,'_mo_original_file',$file);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) return $editor;
        $editor->set_quality((int)$s['quality']);

        $target = $file;
        $mime = get_post_mime_type($attachment_id);

        if ('webp' === $s['format'] || 'avif' === $s['format']) {
            $new_mime = 'webp' === $s['format'] ? 'image/webp' : 'image/avif';
            $new_ext  = 'webp' === $s['format'] ? 'webp' : 'avif';
            $target   = preg_replace('/\.[^.]+$/', '.' . $new_ext, $file);
            $saved = $editor->save($target,$new_mime);
            if (is_wp_error($saved)) {
                $saved = $editor->save($file);
                if (is_wp_error($saved)) return $saved;
                $target = $file;
            } else {
                update_attached_file($attachment_id,$target);
                wp_update_post(['ID'=>$attachment_id,'post_mime_type'=>$new_mime]);
                if ($target !== $file && file_exists($file) && 'yes' !== $s['keep_original']) @unlink($file);
            }
        } else {
            $saved = $editor->save($file);
            if (is_wp_error($saved)) return $saved;
        }

        clearstatcache(true,$target);
        $after = file_exists($target) ? filesize($target) : $before;
        update_post_meta($attachment_id,'_mo_saved_bytes',max(0,$before-$after));
        delete_post_meta($attachment_id,'_mo_optimizer_pending');

        $meta = wp_generate_attachment_metadata($attachment_id,$target);
        if ($meta) wp_update_attachment_metadata($attachment_id,$meta);

        return ['before'=>$before,'after'=>$after,'saved'=>max(0,$before-$after)];
    }

    public function maybe_auto_optimize($attachment_id) {
        if ('yes' !== $this->settings()['auto_optimize']) return;
        if (!wp_attachment_is_image($attachment_id)) return;
        $this->optimize_attachment($attachment_id);
    }

    public function batch_optimize() {
        if (!current_user_can('upload_files')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('mo_batch_optimize');
        $limit=(int)$this->settings()['batch_size'];
        $ids=get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>$limit,'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
        $done=0;
        foreach($ids as $id){ $r=$this->optimize_attachment($id); if(!is_wp_error($r)) $done++; }
        wp_safe_redirect(add_query_arg(['page'=>'media-optimizer','mo_done'=>$done],admin_url('admin.php'))); exit;
    }

    public function restore_image() {
        if (!current_user_can('upload_files')) wp_die('دسترسی غیرمجاز');
        $id=absint($_GET['attachment']??0); check_admin_referer('mo_restore_'.$id);
        $backup=get_post_meta($id,'_mo_backup_path',true);
        $original=get_post_meta($id,'_mo_original_file',true);
        if(!$backup||!file_exists($backup)||!$original) wp_die('Backup پیدا نشد.');
        if(!@copy($backup,$original)) wp_die('Restore ناموفق بود.');
        update_attached_file($id,$original);
        $type=wp_check_filetype($original);
        if(!empty($type['type'])) wp_update_post(['ID'=>$id,'post_mime_type'=>$type['type']]);
        require_once ABSPATH.'wp-admin/includes/image.php';
        $meta=wp_generate_attachment_metadata($id,$original); if($meta) wp_update_attachment_metadata($id,$meta);
        delete_post_meta($id,'_mo_saved_bytes');
        wp_safe_redirect(admin_url('upload.php')); exit;
    }
}

new MO_Plugin();
