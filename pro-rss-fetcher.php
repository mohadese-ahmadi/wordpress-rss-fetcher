<?php
/*
Plugin Name: Pro RSS Fetcher (Speed Optimized Edition)
Description: افزونه پیشرفته فیدخوان با زمان‌بندی ۲ ساعته، بارگذاری تنبل عکس‌ها (Lazy Load)، و ترمز اضطراری برای جلوگیری از کندی سایت.
Version: 3.3
Author: Mohadese Ahmadi
Text Domain: pro-rss-fetcher
*/

if (!defined('ABSPATH')) { exit; }

class Pro_RSS_Fetcher {

    private static $instance = null;
    private $option_name = 'pro_rss_enterprise_feeds';

    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        add_action('pro_rss_enterprise_cron', array($this, 'process_all_feeds'));
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_form_submissions'));

        add_filter('http_request_args', array($this, 'spoof_user_agent'), 10, 2);

        // اضافه کردن ویژگی Lazy Load برای افزایش سرعت لود سایت
        add_filter('the_content', array($this, 'optimize_content_images_lazy_load'), 99);
    }

    // فیلتر برای اضافه کردن Lazy Load به عکس‌های داخل متن خبر
    public function optimize_content_images_lazy_load($content) {
        if (strpos($content, '<img') !== false) {
            $content = preg_replace('/<img(.*?)src=/i', '<img loading="lazy"$1src=', $content);
            // جلوگیری از دو بار اضافه شدن کلمه lazy
            $content = str_replace('loading="lazy" loading="lazy"', 'loading="lazy"', $content);
        }
        return $content;
    }

    public function spoof_user_agent($args, $url) {
        $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        $args['sslverify']          = false; 
        $args['reject_unsafe_urls'] = false; 
        
        if (!isset($args['headers'])) $args['headers'] = array();
        $args['headers']['Accept']          = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
        $args['headers']['Accept-Language'] = 'fa-IR,fa;q=0.9,en-US;q=0.8';
        $args['headers']['Connection']      = 'keep-alive';

        return $args;
    }

    public function activate() {
        // پاک کردن زمان‌بندی قبلی (۳۰ دقیقه‌ای)
        wp_clear_scheduled_hook('pro_rss_enterprise_cron');
        
        // تنظیم زمان‌بندی جدید (۲ ساعته)
        if (!wp_next_scheduled('pro_rss_enterprise_cron')) {
            wp_schedule_event(time(), 'two_hours', 'pro_rss_enterprise_cron');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('pro_rss_enterprise_cron');
    }

    public function add_cron_intervals($schedules) {
        // تعریف بازه زمانی ۲ ساعته (۷۲۰۰ ثانیه)
        $schedules['two_hours'] = array(
            'interval' => 7200,
            'display'  => 'هر ۲ ساعت (بهینه شده)'
        );
        return $schedules;
    }

    public function add_admin_menu() {
        add_menu_page(
            'مدیریت فیدخوان پیشرفته',
            'فیدخوان Enterprise',
            'manage_options',
            'pro-rss-enterprise',
            array($this, 'render_admin_page'),
            'dashicons-rss',
            30
        );
    }

    public function handle_form_submissions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'pro-rss-enterprise') return;

        if (isset($_POST['submit_feed']) && check_admin_referer('save_pro_rss_feed', 'pro_rss_nonce')) {
            $feeds = get_option($this->option_name, array());
            $feeds[] = array(
                'id'          => uniqid('feed_'),
                'url'         => esc_url_raw($_POST['feed_url']),
                'cat'         => intval($_POST['feed_category']),
                'author'      => intval($_POST['feed_author']),
                'status'      => sanitize_text_field($_POST['post_status']),
                'keywords'    => sanitize_text_field($_POST['keyword_filter']),
                'opt_excerpt' => isset($_POST['opt_excerpt']) ? 1 : 0,
                'opt_link'    => isset($_POST['opt_link']) ? 1 : 0,
                'opt_author'  => isset($_POST['opt_author']) ? 1 : 0,
                'opt_date'    => isset($_POST['opt_date']) ? 1 : 0,
                'last_update' => 'هرگز',
                'last_count'  => 0
            );
            update_option($this->option_name, $feeds);
            add_settings_error('pro_rss_messages', 'feed_added', 'فید جدید با موفقیت اضافه شد.', 'updated');
        }

        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['feed_id'])) {
            check_admin_referer('delete_feed_' . $_GET['feed_id']);
            $feeds = get_option($this->option_name, array());
            foreach ($feeds as $key => $feed) {
                if ($feed['id'] === $_GET['feed_id']) { unset($feeds[$key]); }
            }
            update_option($this->option_name, array_values($feeds));
            wp_redirect(admin_url('admin.php?page=pro-rss-enterprise&deleted=1'));
            exit;
        }

        if (isset($_POST['force_run']) && check_admin_referer('force_run_rss', 'pro_rss_nonce')) {
            $count = $this->process_all_feeds();
            add_settings_error('pro_rss_messages', 'feed_run', "عملیات انجام شد. {$count} خبر جدید اضافه گردید.", 'updated');
        }
    }

    public function render_admin_page() {
        $feeds = get_option($this->option_name, array());
        $categories = get_categories(array('hide_empty' => 0));
        $users = get_users(array('role__in' => array('administrator', 'editor', 'author')));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">فیدخوان پیشرفته (نسخه 3.3 - Speed Optimized)</h1>
            <hr class="wp-header-end">
            <?php settings_errors('pro_rss_messages'); ?>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <!-- ستون اصلی: لیست فیدها -->
                    <div id="post-body-content">
                        <div class="meta-box-sortables ui-sortable">
                            <div class="postbox">
                                <div class="postbox-header"><h2 class="hndle">فیدهای فعال (اجرا هر ۲ ساعت)</h2></div>
                                <div class="inside">
                                    <table class="wp-list-table widefat fixed striped">
                                        <thead>
                                            <tr>
                                                <th>آدرس فید</th>
                                                <th>دسته / نویسنده</th>
                                                <th>وضعیت</th>
                                                <th>آمار</th>
                                                <th>عملیات</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($feeds)) : ?>
                                                <tr><td colspan="5">هیچ فیدی وجود ندارد.</td></tr>
                                            <?php else : ?>
                                                <?php foreach ($feeds as $feed) : ?>
                                                    <tr>
                                                        <td style="direction:ltr; text-align:left;"><b><?php echo esc_url($feed['url']); ?></b></td>
                                                        <td>
                                                            <?php echo get_cat_name($feed['cat']); ?><br>
                                                        </td>
                                                        <td><?php echo $feed['status']; ?></td>
                                                        <td>
                                                            آپدیت: <span style="direction:ltr;display:inline-block;"><?php echo $feed['last_update']; ?></span><br>
                                                            دریافتی: <b><?php echo $feed['last_count']; ?> خبر</b>
                                                        </td>
                                                        <td>
                                                            <?php $delete_url = wp_nonce_url(admin_url('admin.php?page=pro-rss-enterprise&action=delete&feed_id=' . $feed['id']), 'delete_feed_' . $feed['id']); ?>
                                                            <a href="<?php echo $delete_url; ?>" class="button button-small" style="color:#b32d2e; border-color:#b32d2e;">حذف</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                    <form method="post" style="margin-top:15px;">
                                        <?php wp_nonce_field('force_run_rss', 'pro_rss_nonce'); ?>
                                        <button type="submit" name="force_run" class="button button-primary">دریافت آنی اخبار</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ستون کناری: فرم افزودن -->
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <div class="postbox-header"><h2 class="hndle">افزودن منبع جدید</h2></div>
                            <div class="inside">
                                <form method="post">
                                    <?php wp_nonce_field('save_pro_rss_feed', 'pro_rss_nonce'); ?>
                                    <p><label><b>آدرس فید:</b></label><br><input type="url" name="feed_url" class="large-text" required></p>
                                    <p><label><b>دسته‌بندی:</b></label><br>
                                        <select name="feed_category" class="large-text">
                                            <?php foreach ($categories as $cat) : ?><option value="<?php echo $cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option><?php endforeach; ?>
                                        </select>
                                    </p>
                                    <p><label><b>نویسنده:</b></label><br>
                                        <select name="feed_author" class="large-text">
                                            <?php foreach ($users as $user) : ?><option value="<?php echo $user->ID; ?>"><?php echo esc_html($user->display_name); ?></option><?php endforeach; ?>
                                        </select>
                                    </p>
                                    <p><label><b>وضعیت:</b></label><br>
                                        <select name="post_status" class="large-text">
                                            <option value="publish">انتشار مستقیم</option>
                                            <option value="draft">پیش‌نویس</option>
                                        </select>
                                    </p>
                                    <p><label><b>فیلتر کلمات:</b></label><br><input type="text" name="keyword_filter" class="large-text"></p>
                                    <hr>
                                    <label><input type="checkbox" name="opt_excerpt" checked> چکیده خبر</label><br>
                                    <label><input type="checkbox" name="opt_link" checked> لینک منبع</label><br>
                                    <label><input type="checkbox" name="opt_author"> نام منبع</label><br>
                                    <label><input type="checkbox" name="opt_date"> تاریخ انتشار</label>
                                    <p><button type="submit" name="submit_feed" class="button button-primary" style="width:100%;">ذخیره فید</button></p>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_high_quality_image_from_url($article_url) {
        // کاهش شدید Timeout برای جلوگیری از افت سرعت سایت
        $response = wp_remote_get($article_url, array(
            'timeout'     => 5, // کاهش از 20 به 5 ثانیه
            'redirection' => 2,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'sslverify'   => false
        ));

        if (is_wp_error($response)) return false;
        $body = wp_remote_retrieve_body($response);

        if (preg_match('/<meta\s+(?:property|name)="og:image"\s+content="([^"]+)"/i', $body, $matches)) {
            $image_url = $matches[1];
            if (strpos($image_url, 'http') !== 0) {
                $parsed_url = parse_url($article_url);
                $image_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $image_url;
            }
            return $image_url;
        }
        return false;
    }

    public function process_all_feeds() {
        include_once(ABSPATH . WPINC . '/feed.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $feeds = get_option($this->option_name, array());
        if (empty($feeds)) return 0;

        $total_added = 0;
        $updated_feeds = array();
        global $wpdb;

        // ذخیره زمان شروع برای سیستم ترمز اضطراری
        $start_time = time(); 

        foreach ($feeds as $feed_data) {
            add_filter('wp_feed_cache_transient_lifetime', '__return_zero');
            $rss = fetch_feed($feed_data['url']);
            remove_filter('wp_feed_cache_transient_lifetime', '__return_zero');

            $count_for_this_feed = 0;

            if (!is_wp_error($rss)) {
                // کاهش تعداد پردازش در هر نوبت به حداکثر 8 خبر تا سایت کند نشود
                $maxitems = $rss->get_item_quantity(8); 
                $rss_items = $rss->get_items(0, $maxitems);

                $keywords = !empty($feed_data['keywords']) ? array_map('trim', explode(',', $feed_data['keywords'])) : array();

                foreach ($rss_items as $item) {
                    // --- سیستم ترمز اضطراری ---
                    // اگر اجرای افزونه بیشتر از 15 ثانیه طول کشید، عملیات را قطع کن تا سایت نمیرد
                    if ((time() - $start_time) > 15) {
                        error_log('Pro RSS Fetcher: عملیات به دلیل طولانی شدن (بیش از 15 ثانیه) متوقف شد تا سایت کند نشود.');
                        break 2; // خروج کامل از هر دو حلقه
                    }

                    $title = wp_strip_all_tags($item->get_title());
                    $raw_content = $item->get_content();
                    $excerpt = $item->get_description();
                    $link = $item->get_permalink();
                    $guid = $item->get_id() ? $item->get_id() : $link; 

                    if (!empty($keywords)) {
                        $found_keyword = false;
                        $searchable_text = $title . ' ' . wp_strip_all_tags($raw_content);
                        foreach ($keywords as $kw) {
                            if (mb_stripos($searchable_text, $kw) !== false) {
                                $found_keyword = true;
                                break;
                            }
                        }
                        if (!$found_keyword) continue; 
                    }

                    $exists = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pro_rss_guid' AND meta_value = %s LIMIT 1", $guid));
                    if ($exists) continue; 

                    $pubDate = $item->get_date('Y-m-d H:i:s');
                    $display_date = $item->get_date('j F Y - H:i'); 
                    
                    $author_name = '';
                    if ($author = $item->get_author()) $author_name = $author->get_name() ? $author->get_name() : $author->get_email();

                    $final_content = '';
                    if (!empty($feed_data['opt_excerpt']) && !empty($excerpt)) {
                        $final_content .= '<div class="rss-excerpt" style="font-weight:bold; margin-bottom:15px; padding:15px; background:#f0f6fc; border-right:4px solid #0073aa; border-radius:4px;">' . $excerpt . '</div>';
                    }
                    $final_content .= $raw_content;

                    $meta_info = array();
                    if (!empty($feed_data['opt_author']) && !empty($author_name)) $meta_info[] = '<b>نویسنده/منبع:</b> ' . $author_name;
                    if (!empty($feed_data['opt_date'])) $meta_info[] = '<b>تاریخ انتشار اصلی:</b> ' . $display_date;
                    if (!empty($feed_data['opt_link'])) $meta_info[] = '<a href="'.$link.'" target="_blank" rel="nofollow" class="rss-source-link">🔗 مشاهده لینک اصلی خبر</a>';

                    if (!empty($meta_info)) {
                        $final_content .= '<div style="clear:both; margin-top:20px;"></div>';
                        $final_content .= '<div class="rss-meta-info" style="font-size:13px; color:#444; background:#f9f9f9; padding:15px; border-radius:4px; border:1px solid #ddd; line-height:2;">' . implode('<br>', $meta_info) . '</div>';
                    }

                    $image_url = $this->get_high_quality_image_from_url($link);

                    if (empty($image_url)) {
                        if ($enclosure = $item->get_enclosure()) {
                            $image_url = $enclosure->get_link();
                        } else {
                            preg_match('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $raw_content, $matches);
                            if (isset($matches[1])) { $image_url = $matches[1]; }
                        }
                    }

                    $post_id = wp_insert_post(array(
                        'post_title'    => $title,
                        'post_content'  => $final_content,
                        'post_excerpt'  => wp_strip_all_tags($excerpt),
                        'post_status'   => $feed_data['status'] ? $feed_data['status'] : 'publish',
                        'post_date'     => $pubDate,
                        'post_type'     => 'post',
                        'post_author'   => $feed_data['author'] ? $feed_data['author'] : 1,
                        'post_category' => array($feed_data['cat'])
                    ));

                    if ($post_id && !is_wp_error($post_id)) {
                        update_post_meta($post_id, '_pro_rss_guid', $guid);
                        $count_for_this_feed++;
                        $total_added++;

                        if (!empty($image_url)) {
                            $thumbnail_id = media_sideload_image($image_url, $post_id, $title, 'id');
                            if (!is_wp_error($thumbnail_id)) {
                                set_post_thumbnail($post_id, $thumbnail_id);
                            }
                        }
                    }
                }
            }

            $feed_data['last_update'] = current_time('Y/m/d H:i');
            $feed_data['last_count'] = $count_for_this_feed;
            $updated_feeds[] = $feed_data;
        }

        update_option($this->option_name, $updated_feeds);
        return $total_added;
    }
}

// برای اعمال زمانبندی جدید، افزونه باید یک بار غیرفعال و فعال شود
// یا با این ترفند، تغییر زمانبندی را اجباری می‌کنیم:
$plugin_instance = Pro_RSS_Fetcher::get_instance();
if (wp_next_scheduled('pro_rss_enterprise_cron') && wp_get_schedule('pro_rss_enterprise_cron') === 'half_hourly') {
    $plugin_instance->activate(); // ریست کردن زمانبندی به 2 ساعت
}
