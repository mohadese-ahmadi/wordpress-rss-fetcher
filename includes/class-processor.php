<?php
if (!defined('ABSPATH')) exit;

class PRSS_Processor {

    /**
     * اجرای کلی همه فیدها
     * اگر $return_count = true باشد، تعداد خبرهای واردشده را برمی‌گرداند
     */
    public static function run($return_count = false) {

        $feeds = PRSS_Feeds::get_feeds();
        $total_imported = 0;

        if (get_transient('prss_running')) {
            return ($return_count ? 0 : null);
        }

        set_transient('prss_running', true, 60);

        $start = time();

        foreach ($feeds as &$feed) {

            if (!$feed['active']) continue;

            if (time() - $start > 45) break;

            $total_imported += self::process_feed($feed);

            $feed['last_run'] = current_time('mysql');
        }

        update_option('prss_feeds_list', $feeds);
        delete_transient('prss_running');

        if ($return_count) {
            return $total_imported;
        }
    }



    /**
     * اجرای دستی یک فید خاص
     */
    public static function run_single($feed_id) {

        $feeds = PRSS_Feeds::get_feeds();
        $total = 0;

        if (get_transient('prss_running')) {
            return 0;
        }

        set_transient('prss_running', true, 60);

        foreach ($feeds as &$feed) {
            if ($feed['id'] == $feed_id) {
                $total = self::process_feed($feed);
                $feed['last_run'] = current_time('mysql');
                break;
            }
        }

        update_option('prss_feeds_list', $feeds);
        delete_transient('prss_running');

        return $total;
    }



    /**
     * پردازش یک فید
     * خروجی: تعداد آیتم واردشده
     */
    private static function process_feed(&$feed) {

        include_once ABSPATH . WPINC . '/feed.php';

        $rss = fetch_feed($feed['url']);

        if (is_wp_error($rss)) {
            PRSS_Logger::log("Feed error: " . $feed['url']);
            return 0;
        }

        $items = $rss->get_items(0, $feed['items']);
        $imported_now = 0;

        foreach ($items as $item) {

            $title = $item->get_title();
            $content = $item->get_content();
            $link = $item->get_link();

            // Duplicate check (hash)
            $hash = md5($link);
            if (get_page_by_title($title, OBJECT, 'post')) continue;
            if (get_posts(['meta_key' => 'prss_hash', 'meta_value' => $hash])) continue;

            // Filter words
            if (!empty($feed['filter'])) {
                $bad_words = explode(',', $feed['filter']);
                foreach ($bad_words as $bad) {
                    if (stripos($title, trim($bad)) !== false ||
                        stripos($content, trim($bad)) !== false) {
                        continue 2;
                    }
                }
            }
            $publish_date = $item->get_date('Y-m-d H:i:s');
            // Insert post
            $post_id = wp_insert_post([
                'post_title'   => wp_strip_all_tags($title),
                'post_content' => PRSS_Helper::clean_html($content),
                'post_excerpt' => PRSS_Helper::make_excerpt($content),
                'post_status'  => $feed['status'],
                'post_author'  => $feed['author'],
                'post_category'=> [$feed['cat']],
                'post_date' => $publish_date
            ]);

            if (!$post_id) continue;

            // metadata
            // گزینه‌ها
            $opts = isset($feed['options']) ? $feed['options'] : [];

            // چکیده
            if (!isset($opts['excerpt']) || $opts['excerpt']) {
                // هیچ اقدامی لازم نیست، چکیده خودکار درست می‌شود
            } else {
                wp_update_post([
                    'ID' => $post_id,
                    'post_excerpt' => ''
                ]);
            }

            // لینک منبع
            if (!empty($opts['source_link'])) {
                update_post_meta($post_id, 'source_link', $link);
            }

            // نام منبع
            if (!empty($opts['source_name'])) {
                update_post_meta($post_id, 'source_name', $rss->get_title());
            }

            // تاریخ خبر
            if (!empty($opts['source_date'])) {
                update_post_meta($post_id, 'source_date', $publish_date);
            }

            // Download featured image
            PRSS_Helper::download_featured_image($item, $post_id);

            $feed['imported']++;
            $imported_now++;
        }

        return $imported_now;
    }
}
