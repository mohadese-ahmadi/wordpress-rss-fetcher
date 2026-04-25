<?php

class PRSS_Helper {

    public static function clean_html($html) {
        return wp_kses_post($html);
    }

    public static function make_excerpt($html, $len = 200) {
        return wp_trim_words(strip_tags($html), $len);
    }

    public static function download_featured_image($item, $post_id) {

        $enclosure = $item->get_enclosure();
        if (!$enclosure) return;

        $img = $enclosure->get_link();
        if (!$img) return;

        $tmp = download_url($img);
        if (is_wp_error($tmp)) return;

        $file = [
            'name' => basename($img),
            'tmp_name' => $tmp
        ];

        $id = media_handle_sideload($file, $post_id);

        if (!is_wp_error($id)) {
            set_post_thumbnail($post_id, $id);
        }
    }
}
