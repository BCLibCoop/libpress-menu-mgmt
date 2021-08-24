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
 * Version:           1.1.0
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
 * Action hook setup section
 **/

// Register custom hook
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

// Add action for new blog created
function libpress_menu_mgmt_new_blog($blog_id)
{
    if (is_plugin_active_for_network('libpress-menu-mgmt/libpress_menu_mgmt.php')) {
        do_action('libpress_menu_mgmt_activation');
    }
}
add_action('wpmu_new_blog', 'libpress_menu_mgmt_new_blog');

// Add the Site Manager Plus role via custom hook
function libpress_menu_mgmt_add_role()
{
    $siteManagerCaps = get_role('site_manager')->capabilities;
    $siteManagerPlusCaps = array_merge($siteManagerCaps, array('edit_theme_options' => true, 'customize' => true));
    add_role('site_manager_plus', 'Site & Menu Manager', $siteManagerPlusCaps);
}
add_action('libpress_menu_mgmt_activation', 'libpress_menu_mgmt_add_role');

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

function libpress_menu_mgmt_remove_toolbar_node($wp_admin_bar)
{
    $user = wp_get_current_user();

    if (in_array('site_manager_plus', $user->roles)) {
        $wp_admin_bar->remove_node('widgets');
    }
}

// Remove Widget & Theme links from the Admin sidebar, Admin toolbar, Customizer
add_filter('customize_loaded_components', 'libpress_menu_mgmt_remove_customizer_panel');
add_action('admin_bar_menu', 'libpress_menu_mgmt_remove_toolbar_node', 500);

// Remove theme support to disable more widget things on the backend
add_action('init', function () {
    if (!wp_doing_ajax() && is_admin()) {
        $user = wp_get_current_user();

        if (in_array('site_manager_plus', $user->roles)) {
            _remove_theme_support('widgets');
        }
    }
}, 100);

/**
 * CLI Command section
 **/

//Define export directory constant
defined('MENU_MGMT_EXPORT_DIR') or define('MENU_MGMT_EXPORT_DIR', '/home/siteuser/libpress_menu_backups/');

//Custom CLI commands
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
function libpress_menu_mgmt_export_blog_menu($args = array(), $assoc_args = array())
{
    // Get arguments.
    $arguments = wp_parse_args(
        $assoc_args,
        array( //defaults
            'blogid'    => 1,
            'network' => false
        )
    );

    if ($arguments['network'] == true) {
        //ignore blogid, loop through all blog sites
        $sites = get_sites(array('public' => 1));

        foreach ($sites as $site) {
            if ($blog = get_blog_details($site->blog_id)) {
                libpress_export_runner($blog);
            }
        }
    } else {
        $blog_id = (int) $arguments['blogid'];
        WP_CLI::debug("Using blogid: {$blog_id}");

        //blogid defaults to 1 but could be set to non-numeric or null
        if (is_int($blog_id) && $arguments['network'] == false) {
            //Load the $blog object from blogid
            $blog = get_blog_details($blog_id);
            libpress_export_runner($blog);
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

function libpress_menu_mgmt_import_blog_menu($filepath)
{
    //get blog from $filepath
    if (!isset($filepath[0])) {
        return false;
    }

    $xml = simplexml_load_file($filepath[0]);
    $base_blog_url = reset($xml->channel->link) ?: 'maple.bc.libraries.coop';
    $blog_url = str_replace(array("http://", "https://"), "", $base_blog_url);
    $menu_locations = array('main-menu' => 'primary', 'footer-menu' => 'secondary');

    //Ask
    WP_CLI::confirm(WP_CLI::colorize("%mMain, footer menus for $blog_url will be deleted.%n Proceed?"));
    try {
        foreach ($menu_locations as $menu => $location) {
            WP_CLI::runcommand("menu delete $menu --url=$blog_url");
            WP_CLI::line("Deleted existing menus.");
        }

        WP_CLI::runcommand("import $filepath[0] --authors=skip"); # No success call needed, has it's own.

        foreach ($menu_locations as $menu => $location) {
            WP_CLI::runcommand("menu location assign $menu $location --url=$blog_url");
            WP_CLI::line("Assigned imported menus to their respective locations.");
        }
    } catch (Exception $e) {
        WP_CLI::error("Failed to import with $e->getMessage().");
    }
}
