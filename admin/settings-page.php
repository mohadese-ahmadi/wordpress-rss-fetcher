<?php
if (!defined('ABSPATH')) exit;

class PRSS_Admin_Page {

    public static function init() {

        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_prss_add_feed', [__CLASS__, 'add_feed']);
        add_action('admin_post_prss_delete_feed', [__CLASS__, 'delete_feed']);
        add_action('admin_post_prss_toggle_feed', [__CLASS__, 'toggle_feed']);
        add_action('admin_post_prss_manual_fetch', [__CLASS__, 'manual_fetch']);
        add_action('admin_post_prss_manual_single', [__CLASS__, 'manual_single']);
    }

    public static function menu() {

        add_menu_page(
            'واکشی RSS حرفه‌ای',
            'RSS حرفه‌ای',
            'manage_options',
            'prss-feeds',
            [__CLASS__, 'page'],
            'dashicons-rss',
            25
        );

    }

    public static function page() {

        $feeds = PRSS_Feeds::get_feeds();
        $cats  = get_categories(['hide_empty' => false]);
        $authors = get_users(['who' => 'authors']);

        // اگر manual fetch انجام شده باشد
        if (isset($_GET['manual_done'])) {
            echo '<div class="notice notice-success"><p>' . intval($_GET['manual_done']) . ' خبر جدید با موفقیت وارد شد.</p></div>';
        }
        ?>

        <div class="wrap" style="direction:rtl; text-align:right;">

        <h1 style="margin-bottom:25px;">مدیریت واکشی RSS حرفه‌ای</h1>

        <div style="display:flex; gap:30px;">

            <!-- ستون چپ: افزودن فید -->
            <div style="flex:1; background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <h2>افزودن فید جدید</h2>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">

                    <input type="hidden" name="action" value="prss_add_feed">

                    <table class="form-table">

                        <tr>
                            <th>آدرس فید RSS</th>
                            <td><input type="url" name="feed_url" required style="width:100%;"></td>
                        </tr>

                        <tr>
                            <th>دسته‌بندی</th>
                            <td>
                                <select name="category" required style="width:100%;">
                                    <option value="">انتخاب دسته</option>
                                    <?php foreach ($cats as $c): ?>
                                        <option value="<?php echo $c->term_id; ?>"><?php echo $c->name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th>تعداد آیتم در هر اجرا</th>
                            <td><input type="number" name="items" value="10" style="width:100%;"></td>
                        </tr>

                        <tr>
                            <th>وضعیت انتشار</th>
                            <td>
                                <select name="status" style="width:100%;">
                                    <option value="publish">انتشار</option>
                                    <option value="draft">پیش‌نویس</option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th>نویسنده</th>
                            <td>
                                <select name="author" style="width:100%;">
                                    <?php foreach ($authors as $a): ?>
                                        <option value="<?php echo $a->ID; ?>"><?php echo $a->display_name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th>کلمات فیلتر</th>
                            <td>
                                <input type="text" name="filter" style="width:100%;" placeholder="مثال: قتل، قمار، سیاسی">
                            </td>
                        </tr>

                    </table>

                    <?php submit_button('افزودن فید'); ?>

                </form>
            </div>

            <!-- ستون راست: جدول فیدها -->
            <div style="flex:2;">

                <div style="background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <h2 style="margin-top:0;">فهرست فیدها</h2>

                    <!-- دکمه Fetch کلی -->
                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-bottom:15px;">
                        <input type="hidden" name="action" value="prss_manual_fetch">
                        <?php submit_button('واکشی فوری همه فیدها', 'primary', '', false); ?>
                    </form>

                    <table class="widefat striped" style="margin-top:20px;">
                        <thead>
                            <tr>
                                <th>فید</th>
                                <th>وضعیت</th>
                                <th>تعداد</th>
                                <th>وارد شده</th>
                                <th>آخرین اجرا</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (!$feeds): ?>
                            <tr><td colspan="6">هنوز فیدی ثبت نشده است.</td></tr>

                        <?php else: foreach ($feeds as $feed): ?>
                        
                            <tr>
                                <td><?php echo esc_html($feed['url']); ?></td>

                                <td>
                                    <?php echo $feed['active']
                                        ? '<span style="color:green;font-weight:bold;">فعال</span>'
                                        : '<span style="color:red;font-weight:bold;">غیرفعال</span>'; ?>
                                </td>

                                <td><?php echo intval($feed['items']); ?></td>
                                <td><?php echo intval($feed['imported']); ?></td>
                                <td><?php echo $feed['last_run'] ?: '—'; ?></td>

                                <td>

                                    <!-- Fetch تکی -->
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="prss_manual_single">
                                        <input type="hidden" name="id" value="<?php echo $feed['id']; ?>">
                                        <?php submit_button('اجرای فوری', 'primary small', '', false); ?>
                                    </form>

                                    <!-- فعال/غیرفعال -->
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="prss_toggle_feed">
                                        <input type="hidden" name="id" value="<?php echo $feed['id']; ?>">
                                        <?php submit_button($feed['active'] ? 'غیرفعال' : 'فعال‌سازی', 'secondary small', '', false); ?>
                                    </form>

                                    <!-- حذف -->
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="prss_delete_feed">
                                        <input type="hidden" name="id" value="<?php echo $feed['id']; ?>">
                                        <?php submit_button('حذف', 'delete small', '', false); ?>
                                    </form>

                                </td>
                            </tr>

                        <?php endforeach; endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        </div>

        <?php
    }

    public static function add_feed() {

        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');

        PRSS_Feeds::add_feed(
            $_POST['feed_url'],
            intval($_POST['category']),
            intval($_POST['items']),
            sanitize_text_field($_POST['status']),
            intval($_POST['author']),
            sanitize_text_field($_POST['filter'])
        );

        wp_redirect(admin_url('admin.php?page=prss-feeds'));
        exit;
    }

    public static function delete_feed() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        PRSS_Feeds::delete_feed($_POST['id']);
        wp_redirect(admin_url('admin.php?page=prss-feeds'));
        exit;
    }

    public static function toggle_feed() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        PRSS_Feeds::toggle_active($_POST['id']);
        wp_redirect(admin_url('admin.php?page=prss-feeds'));
        exit;
    }

    // واکشی فوری همه فیدها
    public static function manual_fetch() {

        if (!current_user_can('manage_options')) wp_die('Permission denied');

        $count = PRSS_Processor::run(true);

        wp_redirect(admin_url('admin.php?page=prss-feeds&manual_done=' . $count));
        exit;
    }

    // واکشی فوری یک فید
    public static function manual_single() {

        if (!current_user_can('manage_options')) wp_die('Permission denied');

        $id = sanitize_text_field($_POST['id']);
        $count = PRSS_Processor::run_single($id);

        wp_redirect(admin_url('admin.php?page=prss-feeds&manual_done=' . $count));
        exit;
    }

}

PRSS_Admin_Page::init();
