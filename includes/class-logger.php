<?php
if (!defined('ABSPATH')) exit;

class PRSS_Logger {

    public static function log($msg) {
        if (!WP_DEBUG || !WP_DEBUG_LOG) return;

        $line = "PRSS [" . current_time('mysql') . "] " . $msg;
        error_log($line);
    }
}
