<?php
if (!defined('ABSPATH')) exit;

class PRSS_Cron {

    public static function schedule() {

        if (!wp_next_scheduled('prss_cron_event')) {
            wp_schedule_event(time(), 'hourly', 'prss_cron_event');
        }

        add_action('prss_cron_event', ['PRSS_Processor', 'run']);
    }

    public static function clear() {
        wp_clear_scheduled_hook('prss_cron_event');
    }

}
