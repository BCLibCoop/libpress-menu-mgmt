<?php

/**
 * LibPress Menu Management
 *
 * Adds functionality for self-management of menus, network-wide exporting for backup
 *
 * PHP Version 7
 *
 * @package           BCLibCoop\MenuMgmt
 * @author            Jonathan Schatz <jonathan.schatz@bc.libraries.coop>
 * @author            Sam Edwards <sam.edwards@bc.libraries.coop>
 * @copyright         2020-2021 BC Libraries Cooperative
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       LibPress Menu Management
 * Description:       Adds functionality for self-management of menus, network-wide exporting for backup
 * Version:           2.0.0
 * Network:           true
 * Requires at least: 5.2
 * Requires PHP:      7.0
 * Author:            BC Libraries Cooperative
 * Author URI:        https://bc.libraries.coop
 * Text Domain:       libpress-menu-mgmt
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * -------------------------
 * Action hook setup section
 * -------------------------
 */

/**
 * Register custom hook
 */
function libpress_menu_mgmt_activate($network_wide)
{
    if (is_multisite() && $network_wide) {
        foreach (get_sites() as $site) {
            switch_to_blog($site->blog_id);
            do_action('libpress_menu_mgmt_activation');
            restore_current_blog();
        }
    } else {
        do_action('libpress_menu_mgmt_activation');
    }
}
register_activation_hook(__FILE__, 'libpress_menu_mgmt_activate');

/**
 * Add action for new blog created
 */
function libpress_menu_mgmt_new_blog()
{
    if (is_plugin_active_for_network('libpress-menu-mgmt/libpress_menu_mgmt.php')) {
        do_action('libpress_menu_mgmt_activation');
    }
}
add_action('wpmu_new_blog', 'libpress_menu_mgmt_new_blog');

/**
 * Add the Site Manager Plus role via custom hook
 */
function libpress_menu_mgmt_add_role()
{
    $siteManager = get_role('site_manager');

    if ($siteManager && !is_wp_error($siteManager)) {
        $siteManagerCaps = $siteManager->capabilities;

        // Add edit_theme_options to the caps that site_manager already has
        $siteManagerPlusCaps = array_merge($siteManagerCaps, ['edit_theme_options' => true]);

        add_role('site_manager_plus', 'Site & Menu Manager', $siteManagerPlusCaps);
    }
}
add_action('libpress_menu_mgmt_activation', 'libpress_menu_mgmt_add_role');

/**
 * Don't load Widgets customizer panel
 */
function libpress_menu_mgmt_remove_customizer_panel($components)
{
    $user = wp_get_current_user();

    if (in_array('site_manager_plus', $user->roles)) {
        $i = array_search('widgets', $components);

        if (false !== $i) {
            unset($components[$i]);
        }
    }

    return $components;
}
add_filter('customize_loaded_components', 'libpress_menu_mgmt_remove_customizer_panel');

/**
 * Remove Widgets from the admin bar
 */
function libpress_menu_mgmt_remove_toolbar_node($wp_admin_bar)
{
    $user = wp_get_current_user();

    if (in_array('site_manager_plus', $user->roles) && !user_can($user, 'libpress_appearance')) {
        $wp_admin_bar->remove_node('widgets');
    }
}
add_action('admin_bar_menu', 'libpress_menu_mgmt_remove_toolbar_node', 500);

/**
 * Remove theme support to disable more widget things on the backend
 */
add_action('init', function () {
    if (!wp_doing_ajax() && is_admin()) {
        $user = wp_get_current_user();

        if (in_array('site_manager_plus', $user->roles) && !user_can($user, 'libpress_appearance')) {
            _remove_theme_support('widgets');
        }
    }
}, 100);

/**
 * -------------------
 * CLI Command section
 * -------------------
 */

/**
 * Define export directory constant
 */
defined('MENU_MGMT_EXPORT_DIR') or define('MENU_MGMT_EXPORT_DIR', '/home/siteuser/libpress_menu_backups/');

/**
 * Custom CLI commands
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('libpress-export-blogmenus', 'libpress_menu_mgmt_export_blog_menu');
    WP_CLI::add_command('libpress-import-blogmenu', 'libpress_menu_mgmt_import_blog_menu');
}

/**
 * Backup a given blog's menu structure.
 *
 * @param array $args
 * @param array $assoc_args
 *
 * Usage: `wp libpress-export-blog-menus --id=123`
 */
function libpress_menu_mgmt_export_blog_menu($args = [], $assoc_args = [])
{
    // Get arguments.
    $arguments = wp_parse_args(
        $assoc_args,
        [
            'blogid'    => 1,
            'network'   => false
        ]
    );

    if ($arguments['network'] == true) {
        // ignore blogid, loop through all blog sites
        $sites = get_sites([
            'public' => 1,
            'archived' => 0,
            'deleted' => 0,
        ]);

        foreach ($sites as $site) {
            libpress_export_runner($site);
        }
    } else {
        $blog_id = (int) $arguments['blogid'];
        WP_CLI::debug("Using blogid: {$blog_id}");

        // blogid defaults to 1 but could be set to non-numeric or null
        if ($blog_id > 0 && $arguments['network'] == false) {
            // Load the $blog object from blogid
            if ($blog = get_blog_details($blog_id)) {
                libpress_export_runner($blog);
            } else {
                WP_CLI::warning('Invalid blog id.');
            }
        } else {
            WP_CLI::warning('Could not complete network run due to bad arguments.');
        }
    }
}

/**
 * Runs the export command with canned params given a WP_Site object
 *
 * @param \WP_Site $blog
 */
function libpress_export_runner($blog)
{
    //Setup
    $timestamp = date("Ymd_His");
    $dir = MENU_MGMT_EXPORT_DIR . $blog->domain . '/';
    $command = "export --url=$blog->siteurl --dir=$dir --post_type=nav_menu_item --skip_comments=TRUE --filename_format='menu_backup.$timestamp.xml'";

    $options = array();

    // @todo add cronjob to ensure only last 3 exist in each dir
    if (!is_dir($dir)) {
        mkdir($dir, 0774, true);
    }

    //Run composed export command
    try {
        WP_CLI::debug("Ran command" . $command);
        WP_CLI::runcommand($command, $options);

        // Show success message.
        WP_CLI::success("Menus for blogID: $blog->blogname ($blog->blog_id) successfully.");

        return true;
    } catch (Exception $e) {
        // Arguments not okay, show an error.
        WP_CLI::error("Failed with $e->getMessage(). Check the value given for blogID ($blog->blog_id).");
    }
}

/**
 * The CLI import command. Will delete existing menus, import from the file
 * specified, then assign the imported menus to the correct menu locations.
 *
 * @param string $filepath
 */
function libpress_menu_mgmt_import_blog_menu($filepath)
{
    if (empty($filepath)) {
        WP_CLI::error("Please specify a file to import");
    }

    if (!is_readable($filepath[0])) {
        WP_CLI::error("Cannot read file for import: " . $filepath[0]);
    }

    $menu_locations = [
        'main-menu' => 'primary',
        'footer-menu' => 'secondary'
    ];

    $xml = simplexml_load_file($filepath[0]);

    if ($xml === false) {
        WP_CLI::error("Could not parse export file");
    }

    $blog_url = reset($xml->channel->link);

    if (!empty($blog_url)) {
        // Ask
        WP_CLI::confirm(WP_CLI::colorize("%mMain, footer menus for $blog_url will be deleted.%n Proceed?"));
        try {
            // Delete the existing menus first so we get a clean import.
            // TODO: Rename and remove from location rather than delete?
            foreach ($menu_locations as $menu => $location) {
                WP_CLI::runcommand("menu delete $menu --url=$blog_url");
                WP_CLI::line("Deleted existing menus.");
            }

            WP_CLI::runcommand("import $filepath[0] --authors=skip --url=$blog_url"); # No success call needed, has it's own.

            foreach ($menu_locations as $menu => $location) {
                WP_CLI::runcommand("menu location assign $menu $location --url=$blog_url");
                WP_CLI::line("Assigned imported menus to their respective locations.");
            }
        } catch (Exception $e) {
            WP_CLI::error("Failed to import with $e->getMessage().");
        }
    } else {
        WP_CLI::error("Could not determine blog URL from file");
    }
}

/**
 * -------------------------
 * Menu Change Watch Section
 * -------------------------
 * TODO: Make these actually do something. Can't just use the WPCLI functions
 * as they won't work from a web context.
 */

/**
 * Look for changes to the menu. This will catch anything from the `nav-menus.php`
 * page, but not from the customizer.
 */
add_action('load-nav-menus.php', function () {
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : null;
    $nav_menu_selected_id = isset($_REQUEST['menu']) ? (int) $_REQUEST['menu'] : 0;
    $menu_item_id = isset($_REQUEST['menu-item']) ? (int) $_REQUEST['menu-item'] : 0;

    if ($action && ($nav_menu_selected_id || $menu_item_id)) {
        switch (WP_ENV) {
            case 'production':
                // Backup
                break;
            case 'staging':
                // Don't Backup
                break;
            case 'development':
            default:
                // Log
                error_log('Menus Changed');
        }
    }
});

/**
 * Catch when menu changes are being published through the customizer
 */
add_action('customize_save', function ($customizer) {
    $changeset_setting_ids = array_keys($customizer->unsanitized_post_values([
        'exclude_post_data' => true,
        'exclude_changeset' => false,
    ]));

    if (!empty(preg_grep('/nav_menu/', $changeset_setting_ids))) {
        switch (WP_ENV) {
            case 'production':
                // Backup
                break;
            case 'staging':
                // Don't Backup
                break;
            case 'development':
            default:
                // Log
                error_log('Menus Changed');
        }
    }
});

/**
 * ---------------------
 * Import Helper Section
 * ---------------------
 *
 * The WP Importer wants all menu link destinations (terms/posts) to be included
 * in the import file, since it's assuming this is a fresh import into a new site,
 * but we're importing to the same site and assuming most/all IDs are the same.
 * The following two hooks will fake the term/post data needed to make the
 * importer happy.
 */

/**
 * WP Importer term helper. Injects all current terms into the importer so they
 * get added to its internal mapping array.
 */
add_filter('wp_import_terms', function ($terms) {
    $all_nav_menu = true;
    $fake_processed_terms = [];

    foreach ($terms as $term) {
        if ($term['term_taxonomy'] !== 'nav_menu') {
            $all_nav_menu = false;
            break;
        }
    }

    if ($all_nav_menu) {
        // We have no way to determine what terms/taxonomies might be in use
        // by the menu. The only solution I can think of is to include all of them.

        $all_terms = get_terms([
            'get' => 'all',
        ]);

        foreach ($all_terms as $all_term) {
            // Only what's required for the importer mapping
            $fake_processed_terms[] = [
                'term_id' => $all_term->term_id,
                'slug' => $all_term->slug,
                'term_taxonomy' => $all_term->taxonomy,
            ];
        }

        $terms = array_merge($terms, $fake_processed_terms);
    }

    return $terms;
});

/**
 * WP Importer post helper. Adds any post id referenced by a menu to the array
 * of posts to be imported so they'll be added to the importer's internal mapping
 * array.
 *
 * Tried to make this also add an invalid ID for targets that don't exist on the
 * site anymore, but that didn't seem possible in a clean way, so only throwing
 * HTML errors instead.
 */
add_filter('wp_import_posts', function ($posts) {
    $all_nav_menu = true;
    $fake_processed_posts = [];

    foreach ($posts as $post) {
        if ($post['post_type'] !== 'nav_menu_item') {
            $all_nav_menu = false;
            break;
        }
    }

    if ($all_nav_menu) {
        foreach ($posts as $item) {
            // Set up postmeta as variables
            foreach ($item['postmeta'] as $meta) {
                ${$meta['key']} = $meta['value'];
            }

            if ($_menu_item_type === 'post_type') {
                $post_id = intval($_menu_item_object_id);

                // Check if we've already inserted this post into the array
                if (array_search($post_id, array_column($fake_processed_posts, 'post_id')) === false) {
                    // To make a long story short, if the post doesn't actually exist, then it's going
                    // to be a problem down the road, so only actually insert fake entries for posts
                    // that are still around.
                    if (get_post($post_id)) {
                        // If not, create the minimum post-like array needed to do what we need
                        $fake_processed_posts[] = [
                            // We'll check for this later
                            'menu_import_post' => true,
                            'status' => 'publish',
                            'post_id' => $post_id,
                            'post_type' => $_menu_item_object,
                            // By passing through no title or date, we can totally skip
                            // the DB check, saving some time
                            'post_title' => '',
                            'post_date' => '',
                            // Just set these as empty arrays to be safe
                            'terms' => [],
                            'comments' => [],
                            'postmeta' => [],
                        ];
                    } else {
                        // TODO: Keep track of posts we've checked and aren't found
                        printf(
                            'Menu Item &#8220;%s&#8221; skipped due to nonexistent post ID %s',
                            esc_html($item['post_title']),
                            $post_id
                        );
                        echo '<br />';
                    }
                }
            } elseif ($_menu_item_type === 'taxonomy') {
                // All known taxonomies added to the relevant matching array in the other helper,
                // so this is only here to generate errors.
                $check_term = get_term($_menu_item_object_id, $_menu_item_object);

                if (!$check_term || is_wp_error($check_term)) {
                    printf(
                        'Menu Item &#8220;%s&#8221; skipped due to nonexistent term ID %s in taxonomy %s',
                        esc_html($item['post_title']),
                        $_menu_item_object_id,
                        $_menu_item_object
                    );
                    echo '<br />';
                }
            }
        }

        $posts = array_merge($posts, $fake_processed_posts);
    }

    return $posts;
});

/**
 * When we detect a post that we manually inserted into the import array,
 * simply return the ID that we set. We've already checked that the post exists
 * in the DB, so this should be safe to do.
 */
add_filter('wp_import_existing_post', function ($post_exists, $post) {
    if ($post_exists === 0 && isset($post['menu_import_post']) && $post['menu_import_post']) {
        $post_exists = $post['post_id'];
    }

    return $post_exists;
}, 10, 2);
