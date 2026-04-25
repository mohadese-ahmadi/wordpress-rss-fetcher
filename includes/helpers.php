<?php

class PRSS_Helper {

    public static function clean_html($html) {
        return wp_kses_post($html);
    }

    public static function make_excerpt($html, $len = 200) {
        return wp_trim_words(strip_tags($html), $len);
    }

    public static function download_featured_image($post_id, $img_url) {

        if (!$img_url) return;

        // دانلود تصویر در فایل tmp
        $tmp = download_url($img_url);
        if (is_wp_error($tmp)) return;

        $file = [
            'name' => basename($img_url),
            'tmp_name' => $tmp
        ];

        // بارگذاری تصویر در رسانه وردپرس
        $id = media_handle_sideload($file, $post_id);

        // اگر موفق بود → thumbnail ست کن
        if (!is_wp_error($id)) {
            set_post_thumbnail($post_id, $id);
        }
    }

}
