<?php
if (!defined('ABSPATH')) exit;

class PRSS_Feeds {

    private static $option = 'prss_feeds_list';

    public static function get_feeds() {
        return get_option(self::$option, []);
    }

    private static function save($feeds) {
        update_option(self::$option, $feeds);
    }

    public static function add_feed($url, $cat, $items, $status, $author, $filter, $options = []) {

        $feeds = self::get_feeds();

        $feeds[] = [
            'id'        => uniqid('feed_'),
            'url'       => trim($url),
            'active'    => true,
            'cat'       => intval($cat),
            'items'     => intval($items),
            'status'    => $status,
            'author'    => $author,
            'filter'    => $filter,
            'imported'  => 0,
            'last_run'  => null,
            
            'options'   => [
                'excerpt'      => !empty($options['excerpt']),
                'source_link'  => !empty($options['source_link']),
                'source_name'  => !empty($options['source_name']),
                'source_date'  => !empty($options['source_date']),
            ]
        ];

        update_option('prss_feeds', $feeds);
        self::save($feeds);
    }


    public static function delete_feed($id) {

        $feeds = self::get_feeds();
        $feeds = array_filter($feeds, fn($f) => $f['id'] !== $id);

        self::save($feeds);
    }

    public static function toggle_active($id) {

        $feeds = self::get_feeds();

        foreach ($feeds as &$f) {
            if ($f['id'] === $id) {
                $f['active'] = !$f['active'];
            }
        }

        self::save($feeds);
    }

}
