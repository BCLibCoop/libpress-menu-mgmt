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

//Custom hook
function libpress_menu_mgmt_activate() {
        do_action( 'libpress_menu_mgmt_activation' );
}
register_activation_hook( __FILE__, 'libpress_menu_mgmt_activate');

//Add the Site Manager Plus role via custom hook
function libpress_menu_mgmt_add_role() {
        $siteManagerCaps = get_role( 'site_manager' )->capabilities;
        $siteManagerPlusCaps = array_merge($siteManagerCaps, array('edit_theme_options' => true, 'customize' => true));
        add_role( 'site_manager_plus', 'Site & Menu Manager', $siteManagerPlusCaps  );
}
add_action( 'libpress_menu_mgmt_activation', 'libpress_menu_mgmt_add_role');

//Remove Widget access from the Admin toolbar, Customizer
//@todo Move these to the hook utlity?
function libpress_menu_mgmt_remove_toolbar_node($wp_admin_bar) {
        $user = wp_get_current_user();
        if ( in_array('site_manager_plus', $user->roles) ) {
                $wp_admin_bar->remove_node('widgets');
        }

}
add_action('admin_bar_menu', 'libpress_menu_mgmt_remove_toolbar_node', 500);

function libpress_menu_mgmt_remove_customizer_panel( $wp_customize ) {
        $user = wp_get_current_user();
        if ( in_array('site_manager_plus', $user->roles) ) {
                $wp_customize->remove_panel('widgets');
        }
}
add_action( 'customize_register', 'libpress_menu_mgmt_remove_customizer_panel' );


//Define a constant
defined( 'MENU_MGMT_EXPORT_DIR' ) or define( 'MENU_MGMT_EXPORT_DIR', '/home/siteuser/libpress_menu_backups/' );

//Custom CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
        WP_CLI::add_command( 'libpress-export-blogmenus', 'libpress_menu_mgmt_export_blog_menu' );
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
                WP_CLI::line( "Using arg : {$blog_id}" );

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

function libpress_export_runner( $blog ){
        $command = 'export';
        $dir = MENU_MGMT_EXPORT_DIR . "{{ $blog->blogname }}/";

        $options = array(
                'dir' => $dir,
                'post_type' => 'nav_menu_item',
                'url' => $blog->siteurl,
        );

        //@todo add shell script to ensure only last 3 exist in each dir

        //Run composed export command like so
        // wp export --dir=~/menus/$ID --post_type=nav_menu_item --url=$URL
        try {
                WP_CLI::runcommand( $command, $options );

                // Show success message.
                WP_CLI::success( "Menus for blog ID: $blog->blogname ($blog->blog_id) successfully." );
                return TRUE;
        } catch (Exception $e) {
        // Arguments not okay, show an error.
        WP_CLI::error( 'Likely invalid argument for blogid.' );
        }
}
