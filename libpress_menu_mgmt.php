<?php

/*
Plugin Name: LibPress Menu Management
Plugin URI: https://github.com/BCLibCoop/libpress-menu-mgmt
Description: Adds functionality for self-management of menus, network-wide exporting for backup
Author: Jonathan Schatz
Author URI: https://github.com/wissensschatz
Version: 0.1
Text Domain: libpress_menu_management
*/


/** Action hook setup section **/

function libpress_menu_mgmt_activate() {
        do_action( 'libpress_menu_mgmt_activation' );
}
//Register custom hook
register_activation_hook( __FILE__, 'libpress_menu_mgmt_activate');

function libpress_menu_mgmt_add_role() {
        $siteManagerCaps = get_role( 'site_manager' )->capabilities;
        $siteManagerPlusCaps = array_merge($siteManagerCaps, array('edit_theme_options' => true, 'customize' => true));
        add_role( 'site_manager_plus', 'Site & Menu Manager', $siteManagerPlusCaps  );
}
//Add the Site Manager Plus role via custom hook
add_action( 'libpress_menu_mgmt_activation', 'libpress_menu_mgmt_add_role');

function libpress_menu_mgmt_remove_admin_submenus(){
        $user = wp_get_current_user();
        if ( in_array('site_manager_plus', $user->roles) ) {
                 remove_submenu_page( 'themes.php', 'theme-editor.php' );
                 remove_submenu_page( 'themes.php', 'themes.php' );
                 remove_submenu_page( 'themes.php', 'widgets.php' );
        }
}

function libpress_menu_mgmt_remove_customizer_panel( $wp_customize ) {
        $user = wp_get_current_user();
        if ( in_array('site_manager_plus', $user->roles) ) {
                $wp_customize->remove_panel('widgets');
        }
}


function libpress_menu_mgmt_remove_toolbar_node($wp_admin_bar) {
        $user = wp_get_current_user();
        if ( in_array('site_manager_plus', $user->roles) ) {
                $wp_admin_bar->remove_node('widgets');
        }

}

//Remove Widget & Theme links from the Admin sidebar, Admin toolbar, Customizer
add_action( 'admin_menu', 'libpress_menu_mgmt_remove_admin_submenus');
add_action( 'customize_register', 'libpress_menu_mgmt_remove_customizer_panel' );
add_action( 'admin_bar_menu', 'libpress_menu_mgmt_remove_toolbar_node', 500);


/** CLI Command section **/

//Define export directory constant
defined( 'MENU_MGMT_EXPORT_DIR' ) or define( 'MENU_MGMT_EXPORT_DIR', '/home/siteuser/libpress_menu_backups/' );

//Custom CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
        WP_CLI::add_command( 'libpress-export-blogmenus', 'libpress_menu_mgmt_export_blog_menu' );
        WP_CLI::add_command( 'libpress-import-blogmenu', 'libpress_menu_mgmt_import_blog_menu');
}

/**
 * Backup a given blog's menu structure.
 *
 * @param array $args
 * @param array $assoc_args
 *
 * Usage: `wp libpress-export-blog-menus --id=123`
 */
function libpress_menu_mgmt_export_blog_menu( $args = array(), $assoc_args = array() ) {

        // Get arguments.
        $arguments = wp_parse_args(
                $assoc_args,
                array( //defaults
                        'blogid'    => 1,
                        'network' => false
                )
        );

        if ($arguments['network'] == true) {
                //ignore blogid, loop through all blogs
                $blogs = get_sites( array( 'public' => 1 ) );

                foreach ($sites as $site) {
                        if ($blog = get_blog_details($site->blog_id))
                                libpress_export_runner($blog);
                }
        } else {
                $blog_id = (int) $arguments['blogid'];
                WP_CLI::debug( "Using blogid: {$blog_id}" );

                //blogid defaults to 1 but could be set to non-numeric or null
                if ( is_int($blog_id) && $arguments['network'] == false) {

                        //Load the $blog object from blogid
                        $blog = get_blog_details( $blog_id );
                        libpress_export_runner($blog);
                } else {
                        WP_CLI::warning( 'Could not complete run due to bad arguments.');
                }
        }
}

/**
 * Runs the export command with canned params given a WP_Site object
 * @param \WP_Site $blog
 */

function libpress_export_runner( $blog ) {

        //Setup
        $timestamp = date("Ymd_His");
        $dir = MENU_MGMT_EXPORT_DIR . $blog->domain . '/';
        $command = "export --url=$blog->siteurl --dir=$dir --post_type=nav_menu_item --skip_comments=TRUE --filename_format='menu_backup.$timestamp.xml'";

        $options = array();

        //@todo add cronjob to ensure only last 3 exist in each dir
        if (! is_dir($dir) ) mkdir($dir, 0774, TRUE);

        //Run composed export command
        try {
                WP_CLI::debug( "Ran command" . $command);
                WP_CLI::runcommand( $command, $options );

                // Show success message.
                WP_CLI::success( "Menus for blogID: $blog->blogname ($blog->blog_id) successfully." );
                return TRUE;
        } catch (Exception $e) {
                // Arguments not okay, show an error.
                WP_CLI::error( "Failed with $e->getMessage(). Check the value given for blogID ($blog->blog_id)." );
        }
}

function libpress_menu_mgmt_import_blog_menu( $filepath ) {

        //get blog from $filepath
        $xml = simplexml_load_file($filepath);
        $base_blog_url = reset($xml->channel->link) ?: 'maple.bc.libraries.coop';
        $blog_url = str_replace(array("http://", "https://"), "", $base_blog_url);

        //Ask
        WP_CLI::confirm( "Main, footer menus for $blog_url will be deleted. Proceed?", $assoc_args );
        try {
                foreach (array('main-menu' => 'primary', 'footer-menu' => 'secondary') as $menu => $location) {
                        WP_CLI::runcommand("menu delete $menu --url=$blog_url");
                        WP_CLI::line("Deleted existing menus.");
                }
                WP_CLI::runcommand("import $file --authors=skip");
                WP_CLI::success("Finished import of menu backup.");
                foreach (array('main-menu', 'footer-menu') as $menu => $location) {
                        WP_CLI::runcommand("menu location assign $menu $location --url=$blog_url");
                        WP_CLI::line("Assigned imported menus to their respective locations.");
                }
        } catch (Exception $e) {
                WP_CLI::error ("Failed to import with $e->getMessage().");
        }
}
