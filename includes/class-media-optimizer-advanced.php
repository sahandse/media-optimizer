<?php
defined('ABSPATH') || exit;

final class MO_Advanced {
    const VERSION='1.3.1';
    const OPT='mo_advanced_settings';
    const QUEUE='mo_processing_queue';
    const NONCE='mo_adv_nonce';

    public static function init(){
        add_action('admin_menu',[__CLASS__,'replace_page'],99);
        add_action('admin_enqueue_scripts',[__CLASS__,'admin_assets']);
        foreach(['save','stats','queue_start','queue_next','queue_pause','queue_resume','queue_reset','scan','cleanup','restore'] as $a){
            add_action('wp_ajax_moa_'.$a,[__CLASS__,'ajax_'.$a]);
        }
        add_action('add_attachment',[__CLASS__,'auto_optimize'],80);
        add_filter('manage_media_columns',[__CLASS__,'columns']);
        add_action('manage_media_custom_column',[__CLASS__,'column'],10,2);
        add_filter('wp_get_attachment_image_attributes',[__CLASS__,'lazy_attrs'],10,3);
        add_filter('the_content',[__CLASS__,'lazy_content'],30);
        add_filter('cron_schedules',[__CLASS__,'cron_schedules']);
        add_action('moa_scheduled',[__CLASS__,'scheduled']);
        add_action('init',[__CLASS__,'sync_cron']);
    }
    public static function defaults(){return[
        'quality'=>82,'mode'=>'smart','format'=>'webp','batch'=>30,'auto'=>1,'max_width'=>2560,
        'strip_metadata'=>1,'min_size_kb'=>80,'min_saving'=>3,'keep_backup'=>1,'backup_days'=>30,
        'schedule'=>0,'schedule_frequency'=>'daily','lazy_images'=>1,'lazy_iframes'=>1,
        'woocommerce'=>1,'dark'=>0,'exclude'=>'','cleanup_extra'=>0
    ];}
    public static function settings(){return wp_parse_args((array)get_option(self::OPT,[]),self::defaults());}
    public static function replace_page(){
        remove_submenu_page('upload.php','media-optimizer');
        add_media_page('بهینه‌ساز رسانه','بهینه‌ساز رسانه','manage_options','media-optimizer',[__CLASS__,'page']);
    }
    public static function admin_assets($hook){
        if($hook!=='media_page_media-optimizer' && $hook!=='upload.php') return;
        wp_enqueue_script('jquery');
    }
    private static function guard(){check_ajax_referer(self::NONCE,'nonce');if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'دسترسی غیرمجاز'],403);}
    private static function q(){return wp_parse_args((array)get_option(self::QUEUE,[]),['status'=>'idle','ids'=>[],'total'=>0,'done'=>0,'failed'=>0,'saved'=>0,'current'=>'','processed'=>0]);}
    private static function qpub($q){$left=count((array)$q['ids']);$total=(int)$q['total'];$p=$total?min(100,round(((int)$q['processed']/$total)*100)):0;return['status'=>$q['status'],'total'=>$total,'done'=>(int)$q['done'],'failed'=>(int)$q['failed'],'left'=>$left,'processed'=>(int)$q['processed'],'saved'=>size_format((int)$q['saved'],1),'current'=>$q['current'],'percent'=>$p];}
    private static function candidates($force=false){
        $args=['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC'];
        if(!$force)$args['meta_query']=[['key'=>'_moa_done','compare'=>'NOT EXISTS']];
        return get_posts($args);
    }
    public static function ajax_queue_start(){self::guard();$ids=self::candidates(!empty($_POST['force']));$q=['status'=>count($ids)?'running':'done','ids'=>$ids,'total'=>count($ids),'done'=>0,'failed'=>0,'saved'=>0,'current'=>'','processed'=>0];update_option(self::QUEUE,$q,false);wp_send_json_success(['queue'=>self::qpub($q)]);}
    public static function ajax_queue_pause(){self::guard();$q=self::q();$q['status']='paused';update_option(self::QUEUE,$q,false);wp_send_json_success(['queue'=>self::qpub($q)]);}
    public static function ajax_queue_resume(){self::guard();$q=self::q();if($q['ids'])$q['status']='running';update_option(self::QUEUE,$q,false);wp_send_json_success(['queue'=>self::qpub($q)]);}
    public static function ajax_queue_reset(){self::guard();$q=['status'=>'idle','ids'=>[],'total'=>0,'done'=>0,'failed'=>0,'saved'=>0,'current'=>'','processed'=>0];update_option(self::QUEUE,$q,false);wp_send_json_success(['queue'=>self::qpub($q)]);}
    public static function ajax_queue_next(){
        self::guard();$q=self::q();if($q['status']!=='running')wp_send_json_success(['queue'=>self::qpub($q)]);
        $id=array_shift($q['ids']);if(!$id){$q['status']='done';update_option(self::QUEUE,$q,false);wp_send_json_success(['queue'=>self::qpub($q)]);}
        $q['current']=basename((string)get_attached_file($id));$r=self::optimize($id);$q['processed']++;
        if(is_wp_error($r)){$q['failed']++;update_post_meta($id,'_moa_error',$r->get_error_message());}
        else{$q['done']++;$q['saved']+=(int)$r['saved'];delete_post_meta($id,'_moa_error');}
        if(!$q['ids'])$q['status']='done';update_option(self::QUEUE,$q,false);
        wp_send_json_success(['queue'=>self::qpub($q),'message'=>is_wp_error($r)?$r->get_error_message():'بهینه‌سازی شد: '.get_the_title($id)]);
    }
    private static function quality($file,$s){
        if($s['mode']==='lossless')return 100;if($s['mode']==='maximum')return 68;if($s['mode']==='balanced')return 80;
        if($s['mode']==='smart'){[$w,$h]=@getimagesize($file)?:[0,0];$px=$w*$h;return $px>12000000?76:($px>5000000?80:84);}
        return max(30,min(100,(int)$s['quality']));
    }
    private static function excluded($file,$s){$raw=trim((string)$s['exclude']);if(!$raw)return false;foreach(array_filter(array_map('trim',explode(',',$raw))) as $p)if(stripos(wp_normalize_path($file),$p)!==false)return true;return false;}
    private static function backup($id,$file){
        $old=get_post_meta($id,'_moa_backup',true);if($old&&is_file($old))return $old;
        $u=wp_upload_dir();$dir=trailingslashit($u['basedir']).'media-optimizer-backups/'.date('Y/m');wp_mkdir_p($dir);
        $dst=trailingslashit($dir).$id.'-'.wp_basename($file);if(@copy($file,$dst)){update_post_meta($id,'_moa_backup',$dst);return $dst;}return false;
    }
    public static function optimize($id){
        if(!wp_attachment_is_image($id))return new WP_Error('not_image','فایل تصویر نیست.');
        $file=get_attached_file($id);if(!$file||!is_file($file))return new WP_Error('missing','فایل پیدا نشد.');
        $s=self::settings();if(self::excluded($file,$s))return new WP_Error('excluded','طبق قوانین Exclude رد شد.');
        $before=(int)filesize($file);if($before<((int)$s['min_size_kb']*1024))return new WP_Error('small','حجم فایل کمتر از حداقل تعیین‌شده است.');
        if(!empty($s['keep_backup']))self::backup($id,$file);
        require_once ABSPATH.'wp-admin/includes/image.php';
        $editor=wp_get_image_editor($file);if(is_wp_error($editor))return $editor;
        $size=$editor->get_size();$mw=max(0,(int)$s['max_width']);if($mw && !empty($size['width']) && $size['width']>$mw)$editor->resize($mw,null,false);
        $editor->set_quality(self::quality($file,$s));
        if(!empty($s['strip_metadata']) && method_exists($editor,'get_image')){
            $img=$editor->get_image();if($img instanceof Imagick){try{$img->stripImage();}catch(Throwable $e){}}
        }
        $format=$s['format'];$target=$file;$mime=get_post_mime_type($id);
        if(in_array($format,['webp','avif'],true)){
            $newmime=$format==='avif'?'image/avif':'image/webp';
            if(!wp_image_editor_supports(['mime_type'=>$newmime]))return new WP_Error('unsupported',strtoupper($format).' روی این هاست پشتیبانی نمی‌شود.');
            $target=preg_replace('/\.[^.]+$/','.'.$format,$file);$saved=$editor->save($target,$newmime);
        }else{$saved=$editor->save($file,$mime);}
        if(is_wp_error($saved))return $saved;$after=(int)filesize($target);$gain=max(0,$before-$after);$pct=$before?($gain/$before*100):0;
        if($pct<(float)$s['min_saving'] && $target!==$file){@unlink($target);return new WP_Error('low_gain','کاهش حجم کمتر از حداقل تعیین‌شده بود.');}
        if($target!==$file){
            update_attached_file($id,$target);wp_update_post(['ID'=>$id,'post_mime_type'=>$newmime]);
            if(empty($s['keep_backup'])&&is_file($file))@unlink($file);
        }
        $meta=wp_generate_attachment_metadata($id,$target);if($meta)wp_update_attachment_metadata($id,$meta);
        update_post_meta($id,'_moa_before',$before);update_post_meta($id,'_moa_after',$after);update_post_meta($id,'_moa_saved',$gain);update_post_meta($id,'_moa_done',current_time('mysql'));
        return['saved'=>$gain,'percent'=>round($pct,1)];
    }
    public static function restore($id){
        $backup=get_post_meta($id,'_moa_backup',true);if(!$backup||!is_file($backup))return new WP_Error('no_backup','نسخه پشتیبان پیدا نشد.');
        $u=wp_upload_dir();$rel=str_replace(trailingslashit($u['basedir']).'media-optimizer-backups/','',$backup);$name=preg_replace('/^\d+-/','',basename($rel));
        $current=get_attached_file($id);$dir=dirname($current);$dest=trailingslashit($dir).$name;if(!@copy($backup,$dest))return new WP_Error('copy','بازیابی فایل ناموفق بود.');
        update_attached_file($id,$dest);$type=wp_check_filetype($dest);if(!empty($type['type']))wp_update_post(['ID'=>$id,'post_mime_type'=>$type['type']]);
        require_once ABSPATH.'wp-admin/includes/image.php';$meta=wp_generate_attachment_metadata($id,$dest);if($meta)wp_update_attachment_metadata($id,$meta);
        delete_post_meta($id,'_moa_done');delete_post_meta($id,'_moa_saved');return true;
    }
    public static function ajax_restore(){self::guard();$id=absint($_POST['id']??0);$r=self::restore($id);is_wp_error($r)?wp_send_json_error(['message'=>$r->get_error_message()]):wp_send_json_success(['message'=>'نسخه اصلی بازگردانی شد.']);}
    public static function auto_optimize($id){$s=self::settings();if(!empty($s['auto'])&&wp_attachment_is_image($id))self::optimize($id);}
    public static function stats(){
        $total=(int)wp_count_posts('attachment')->inherit;$optimized=(int)self::count_meta('_moa_done');$errors=(int)self::count_meta('_moa_error');$saved=(int)get_option('moa_saved_cache',0);
        global $wpdb;$saved=(int)$wpdb->get_var("SELECT COALESCE(SUM(meta_value+0),0) FROM {$wpdb->postmeta} WHERE meta_key='_moa_saved'");
        return['total'=>$total,'optimized'=>$optimized,'remaining'=>max(0,$total-$optimized),'errors'=>$errors,'saved'=>size_format($saved,1),'queue'=>self::qpub(self::q())];
    }
    private static function count_meta($key){global $wpdb;return $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s",$key));}
    public static function ajax_stats(){self::guard();wp_send_json_success(self::stats());}
    public static function ajax_save(){self::guard();$d=self::defaults();$in=wp_unslash($_POST);$out=$d;foreach($d as $k=>$v){if(is_int($v))$out[$k]=isset($in[$k])?absint($in[$k]):0;else$out[$k]=sanitize_text_field($in[$k]??$v);}if(!in_array($out['format'],['none','webp','avif'],true))$out['format']='webp';if(!in_array($out['mode'],['smart','manual','lossless','balanced','maximum'],true))$out['mode']='smart';$out['quality']=max(30,min(100,(int)$out['quality']));update_option(self::OPT,$out,false);self::sync_cron();wp_send_json_success(['message'=>'تنظیمات ذخیره شد.']);}
    public static function ajax_scan(){
        self::guard();$ids=self::candidates(true);$hashes=[];$dups=[];$unused=[];
        foreach($ids as $id){$f=get_attached_file($id);if(!$f||!is_file($f))continue;$h=@sha1_file($f);if($h){if(isset($hashes[$h]))$dups[]=['id'=>$id,'of'=>$hashes[$h],'title'=>get_the_title($id)];else$hashes[$h]=$id;}
            if(!self::is_used($id))$unused[]=['id'=>$id,'title'=>get_the_title($id)];
        }
        update_option('moa_scan',['duplicates'=>$dups,'unused'=>$unused,'time'=>time()],false);wp_send_json_success(['duplicates'=>$dups,'unused'=>$unused,'scanned'=>count($ids)]);
    }
    private static function is_used($id){
        if(get_post_thumbnail_id() && (int)get_post_thumbnail_id()===$id)return true;global $wpdb;$url=wp_get_attachment_url($id);$needle=wp_basename($url);
        $found=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s LIMIT 1",'%'.$wpdb->esc_like($needle).'%'));if($found)return true;
        $meta=$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1",'%'.$wpdb->esc_like((string)$id).'%'));return(bool)$meta;
    }
    public static function ajax_cleanup(){self::guard();$days=max(1,(int)self::settings()['backup_days']);$u=wp_upload_dir();$base=trailingslashit($u['basedir']).'media-optimizer-backups';$n=0;if(is_dir($base)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&$f->getMTime()<(time()-$days*DAY_IN_SECONDS)){@unlink($f->getPathname());$n++;}}}wp_send_json_success(['message'=>$n.' بکاپ قدیمی پاک شد.']);}
    public static function columns($c){$c['moa_saved']='بهینه‌سازی';return$c;}
    public static function column($col,$id){if($col!=='moa_saved')return;$s=(int)get_post_meta($id,'_moa_saved',true);$e=get_post_meta($id,'_moa_error',true);echo $e?'<span style="color:#b42318">خطا</span>':($s?'<strong>'.esc_html(size_format($s,1)).'</strong>':'—');}
    public static function lazy_attrs($attr){if(!empty(self::settings()['lazy_images']))$attr['loading']='lazy';return$attr;}
    public static function lazy_content($c){$s=self::settings();if(!empty($s['lazy_iframes']))$c=preg_replace('/<iframe(?![^>]*loading=)/i','<iframe loading="lazy"',$c);return$c;}
    public static function cron_schedules($s){$s['moa_hourly']=['interval'=>HOUR_IN_SECONDS,'display'=>'Hourly'];return$s;}
    public static function sync_cron(){
        $s=self::settings();$next=wp_next_scheduled('moa_scheduled');if(empty($s['schedule'])){if($next)wp_clear_scheduled_hook('moa_scheduled');return;}
        $freq=$s['schedule_frequency']==='hourly'?'moa_hourly':($s['schedule_frequency']==='weekly'?'weekly':'daily');
        if(!isset(wp_get_schedules()[$freq]))$freq='daily';if(!$next)wp_schedule_event(time()+300,$freq,'moa_scheduled');
    }
    public static function scheduled(){$s=self::settings();$ids=array_slice(self::candidates(false),0,max(1,(int)$s['batch']));foreach($ids as $id)self::optimize($id);}
    public static function page(){
        if(!current_user_can('manage_options'))return;$s=self::settings();$q=self::qpub(self::q());$scan=(array)get_option('moa_scan',[]);
        $webp=wp_image_editor_supports(['mime_type'=>'image/webp']);$avif=wp_image_editor_supports(['mime_type'=>'image/avif']);
        ?>
        <div class="wrap moa-wrap <?php echo !empty($s['dark'])?'dark':'';?>" dir="rtl">
        <style>
        .moa-wrap{--bg:#f6f7f9;--card:#fff;--text:#111827;--muted:#667085;--line:#e5e7eb;--accent:#111827;max-width:1320px}.moa-wrap.dark{--bg:#101214;--card:#181b20;--text:#f8fafc;--muted:#98a2b3;--line:#30343b;--accent:#f8fafc}.moa-wrap{background:var(--bg);color:var(--text);padding:18px;border-radius:20px;margin-top:18px}.moa-hero,.moa-card{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:0 5px 18px rgba(16,24,40,.05)}.moa-hero{padding:24px;display:flex;justify-content:space-between;align-items:center}.moa-hero h1{margin:3px 0 6px;font-size:28px}.moa-hero p,.moa-muted{color:var(--muted)}.moa-badges{display:flex;gap:8px}.moa-badge{padding:6px 10px;border-radius:999px;background:#edfdf3;color:#067647}.moa-badge.bad{background:#fef3f2;color:#b42318}.moa-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:14px 0}.moa-stat,.moa-card{padding:18px}.moa-stat{background:var(--card);border:1px solid var(--line);border-radius:15px}.moa-stat span{display:block;color:var(--muted);font-size:12px}.moa-stat strong{font-size:24px}.moa-grid{display:grid;grid-template-columns:1.25fr .75fr;gap:14px}.moa-progress{height:12px;background:#eaecf0;border-radius:999px;overflow:hidden}.moa-progress i{display:block;height:100%;background:var(--accent);width:0}.moa-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.moa-settings{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.moa-field{border:1px solid var(--line);border-radius:12px;padding:12px}.moa-field label{display:block;font-weight:600;margin-bottom:6px}.moa-field input,.moa-field select{width:100%}.moa-table{width:100%;border-collapse:collapse}.moa-table td,.moa-table th{padding:9px;border-bottom:1px solid var(--line);text-align:right}.moa-log{max-height:220px;overflow:auto;background:rgba(127,127,127,.06);padding:10px;border-radius:12px;margin-top:10px}.moa-log div{padding:6px;border-bottom:1px solid var(--line)}@media(max-width:1000px){.moa-stats{grid-template-columns:repeat(3,1fr)}.moa-grid{grid-template-columns:1fr}.moa-settings{grid-template-columns:1fr 1fr}}@media(max-width:650px){.moa-stats{grid-template-columns:1fr 1fr}.moa-settings{grid-template-columns:1fr}.moa-hero{align-items:flex-start;flex-direction:column;gap:12px}}
        </style>
        <div class="moa-hero"><div><small>MEDIA OPTIMIZER · v<?php echo esc_html(self::VERSION);?></small><h1>مرکز بهینه‌سازی رسانه</h1><p>وضعیت تصاویر انجام‌شده، باقی‌مانده، خطاها و روند پردازش در یک صفحه.</p></div><div class="moa-badges"><span class="moa-badge <?php echo $webp?'':'bad';?>">WebP</span><span class="moa-badge <?php echo $avif?'':'bad';?>">AVIF</span></div></div>
        <div class="moa-stats"><div class="moa-stat"><span>کل تصاویر</span><strong id="moa-total">—</strong></div><div class="moa-stat"><span>انجام‌شده</span><strong id="moa-done">—</strong></div><div class="moa-stat"><span>باقی‌مانده</span><strong id="moa-left">—</strong></div><div class="moa-stat"><span>خطا</span><strong id="moa-errors">—</strong></div><div class="moa-stat"><span>فضای ذخیره‌شده</span><strong id="moa-saved">—</strong></div><div class="moa-stat"><span>روند صف</span><strong id="moa-pct"><?php echo (int)$q['percent'];?>%</strong></div></div>
        <div class="moa-grid"><section class="moa-card"><h2>صف پردازش</h2><div class="moa-progress"><i id="moa-bar" style="width:<?php echo (int)$q['percent'];?>%"></i></div><p id="moa-current" class="moa-muted"><?php echo esc_html($q['current']?:'در انتظار شروع'); ?></p><div class="moa-actions"><button class="button button-primary" id="moa-start">شروع / ساخت صف</button><button class="button" id="moa-pause">توقف</button><button class="button" id="moa-resume">ادامه</button><button class="button" id="moa-reset">پاک کردن صف</button><label><input type="checkbox" id="moa-force"> پردازش مجدد همه</label></div><div class="moa-log" id="moa-log"></div></section>
        <section class="moa-card"><h2>ابزار رسانه</h2><p>اسکن فایل‌های تکراری و تصاویر احتمالاً بدون استفاده.</p><div class="moa-actions"><button class="button" id="moa-scan">اسکن کتابخانه</button><button class="button" id="moa-clean">پاک‌سازی بکاپ‌های قدیمی</button></div><div id="moa-scan-results" class="moa-muted"><?php if($scan)echo count($scan['duplicates']??[]).' تکراری · '.count($scan['unused']??[]).' بدون‌استفاده احتمالی';?></div><hr><h3>سلامت سیستم</h3><table class="moa-table"><tr><td>Imagick</td><td><?php echo extension_loaded('imagick')?'فعال':'غیرفعال';?></td></tr><tr><td>GD</td><td><?php echo extension_loaded('gd')?'فعال':'غیرفعال';?></td></tr><tr><td>Memory</td><td><?php echo esc_html(ini_get('memory_limit'));?></td></tr><tr><td>PHP</td><td><?php echo esc_html(PHP_VERSION);?></td></tr></table></section></div>
        <section class="moa-card" style="margin-top:14px"><h2>تنظیمات</h2><form id="moa-form"><div class="moa-settings">
        <?php
        $fields=[
        'mode'=>['حالت کیفیت','select',['smart'=>'Smart','manual'=>'دستی','lossless'=>'Lossless','balanced'=>'Balanced','maximum'=>'Maximum']],
        'quality'=>['کیفیت دستی','number'], 'format'=>['فرمت خروجی','select',['none'=>'بدون تبدیل','webp'=>'WebP','avif'=>'AVIF']],
        'batch'=>['اندازه صف زمان‌بندی','select',['30'=>'30','50'=>'50']], 'max_width'=>['حداکثر عرض','number'],'min_size_kb'=>['حداقل حجم KB','number'],
        'min_saving'=>['حداقل صرفه‌جویی %','number'],'backup_days'=>['نگهداری بکاپ (روز)','number'],'schedule_frequency'=>['زمان‌بندی','select',['hourly'=>'ساعتی','daily'=>'روزانه','weekly'=>'هفتگی']],
        'exclude'=>['Exclude (کاما)','text']];
        foreach($fields as $k=>$f){echo '<div class="moa-field"><label>'.esc_html($f[0]).'</label>';if($f[1]==='select'){echo '<select name="'.esc_attr($k).'">';foreach($f[2] as $v=>$l)echo '<option value="'.esc_attr($v).'" '.selected((string)$s[$k],(string)$v,false).'>'.esc_html($l).'</option>';echo '</select>';}else echo '<input type="'.esc_attr($f[1]).'" name="'.esc_attr($k).'" value="'.esc_attr($s[$k]).'">';echo '</div>';}
        $checks=['auto'=>'بهینه‌سازی خودکار آپلودهای جدید','strip_metadata'=>'حذف EXIF/Metadata','keep_backup'=>'Backup و Restore','schedule'=>'پردازش زمان‌بندی‌شده','lazy_images'=>'Lazy Load تصاویر','lazy_iframes'=>'Lazy Load iframe','woocommerce'=>'WooCommerce Mode','cleanup_extra'=>'پاک‌سازی نسخه‌های اضافی','dark'=>'حالت تیره پنل'];
        foreach($checks as $k=>$l)echo '<div class="moa-field"><label><input type="checkbox" name="'.esc_attr($k).'" value="1" '.checked(!empty($s[$k]),true,false).'> '.esc_html($l).'</label></div>';
        ?></div><p><button class="button button-primary" type="submit">ذخیره تنظیمات</button> <span id="moa-save-status"></span></p></form></section>
        </div>
        <script>
        jQuery(function($){const A='<?php echo esc_js(admin_url('admin-ajax.php'));?>',N='<?php echo esc_js(wp_create_nonce(self::NONCE));?>';let loop=false;
        function req(a,d){return $.post(A,Object.assign({action:'moa_'+a,nonce:N},d||{}));}
        function q(x){if(!x)return;$('#moa-pct').text(x.percent+'%');$('#moa-bar').css('width',x.percent+'%');$('#moa-current').text((x.current||'—')+' · انجام '+x.done+' · باقی '+x.left+' · خطا '+x.failed+' · ذخیره '+x.saved);}
        function stats(){req('stats').done(r=>{if(r.success){let d=r.data;$('#moa-total').text(d.total);$('#moa-done').text(d.optimized);$('#moa-left').text(d.remaining);$('#moa-errors').text(d.errors);$('#moa-saved').text(d.saved);q(d.queue);}});}
        function next(){if(!loop)return;req('queue_next').done(r=>{if(!r.success){loop=false;return}q(r.data.queue);$('#moa-log').prepend('<div>'+ $('<div>').text(r.data.message||'').html()+'</div>');if(r.data.queue.status==='running')setTimeout(next,180);else{loop=false;stats();}}).fail(()=>loop=false);}
        $('#moa-start').click(()=>req('queue_start',{force:$('#moa-force').is(':checked')?1:0}).done(r=>{if(r.success){q(r.data.queue);loop=true;next();}}));
        $('#moa-pause').click(()=>{loop=false;req('queue_pause').done(r=>r.success&&q(r.data.queue));});$('#moa-resume').click(()=>req('queue_resume').done(r=>{if(r.success){q(r.data.queue);loop=true;next();}}));$('#moa-reset').click(()=>req('queue_reset').done(r=>r.success&&q(r.data.queue)));
        $('#moa-scan').click(function(){let b=$(this).prop('disabled',true).text('در حال اسکن…');req('scan').done(r=>{if(r.success)$('#moa-scan-results').text(r.data.duplicates.length+' تکراری · '+r.data.unused.length+' بدون‌استفاده احتمالی');}).always(()=>b.prop('disabled',false).text('اسکن کتابخانه'));});
        $('#moa-clean').click(()=>req('cleanup').done(r=>alert(r.success?r.data.message:'خطا')));
        $('#moa-form').submit(function(e){e.preventDefault();let d={};$(this).serializeArray().forEach(x=>d[x.name]=x.value);$(this).find('input[type=checkbox]').each(function(){d[this.name]=$(this).is(':checked')?1:0});req('save',d).done(r=>{if(r.success){$('#moa-save-status').text('✓ ذخیره شد');setTimeout(()=>location.reload(),500)}});});
        stats();});</script>
        <?php
    }
}
MO_Advanced::init();
