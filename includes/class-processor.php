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
     * استخراج بهترین تصویر ممکن برای خبر
     */
    private static function get_high_quality_image($article_url, $content, $item) {

        // 1) OG IMAGE از صفحه‌ی اصلی خبر
        $response = wp_remote_get($article_url, [
            'timeout' => 6,
            'redirection' => 2,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ]);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);

            if (preg_match('/<meta\s+(?:property|name)="og:image"\s+content="([^"]+)"/i', $body, $matches)) {

                $img = $matches[1];

                // اگر لینک نسبی بود
                if (strpos($img, 'http') !== 0) {
                    $p = parse_url($article_url);
                    $img = $p['scheme'] . '://' . $p['host'] . $img;
                }

                $img = self::clean_img_url($img);
                return $img;
            }
        }

        // 2) media:content یا media:thumbnail
        $media = $item->get_item_tags("http://search.yahoo.com/mrss/", "content");
        if ($media && isset($media[0]["attribs"][""]["url"])) {
            return self::clean_img_url($media[0]["attribs"][""]["url"]);
        }

        $thumb = $item->get_item_tags("http://search.yahoo.com/mrss/", "thumbnail");
        if ($thumb && isset($thumb[0]["attribs"][""]["url"])) {
            return self::clean_img_url($thumb[0]["attribs"][""]["url"]);
        }

        // 3) enclosure داخل RSS
        if ($item->get_enclosure() && $item->get_enclosure()->get_link()) {
            return self::clean_img_url($item->get_enclosure()->get_link());
        }

        // 4) اولین IMG از محتوای HTML خبر
        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
            return self::clean_img_url($m[1]);
        }

        return false;
    }

    /**
     * حذف سایزهای کوچک مثل -150x150 از لینک تصویر
     */
    private static function clean_img_url($url) {

        // حذف سایزهای وردپرسی مثل -150x150
        $url = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|webp))/i', '', $url);

        // حذف query string از URL
        $url = preg_replace('/\?.*/', '', $url);

        return $url;
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

            // استخراج بهترین تصویر ممکن
            $image_url = self::get_high_quality_image($link, $content, $item);

            // دانلود و ست کردن تصویر شاخص
            if ($image_url) {
                PRSS_Helper::download_featured_image($post_id, $image_url);
            }


            $feed['imported']++;
            $imported_now++;
        }

        return $imported_now;
    }
}
