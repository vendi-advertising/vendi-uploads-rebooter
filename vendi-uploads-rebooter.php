<?php
/*
Plugin Name: Vendi Uploads Rebooter
Description: Only bring back files from Uploads if they are requested.
Plugin URI: https://www.vendiadvertising.com/
Author: Vendi Advertising (Chris Haas)
Version: 0.0.1
Author URI: https://www.vendiadvertising.com/
Text Domain: vendi-uploads-rebooter
Domain Path: /languages
*/

if( defined( 'WP_INSTALLING' ) && WP_INSTALLING )
{
    return;
}

//Folder relative to uploads that we've moved all year-based folders to
define( 'VENDI_UPLOADS_REBOOTER_BACKUP_FOLDER', '__vendi_uploads_rebooter__' );

//URL key that we redirect to and abort if found to avoid infinite loops
define( 'VENDI_UPLOADS_REBOOTER_URL_KEY',       'vur_reboot_attempt' );

class vendi_404_handler
{

    private static $_instance;

    /**
     * Parses the current URL into component properties.
     *
     * @return array|false Array of various URL components or false on any failure.
     */
    private function get_state_variables()
    {

        //Sanity check some global server variables
        if( ! isset( $_SERVER ) || ! is_array( $_SERVER ) || ! array_key_exists( 'REQUEST_URI', $_SERVER ) || ! array_key_exists( 'HTTP_HOST', $_SERVER ) )
        {
            return false;
        }

        //Grab and sanity check the curent path
        $path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        if( false === $path || null === $path )
        {
            //parse_url couldn't figure out our path, bail
            return false;
        }

        //Get the site host
        $host = $_SERVER[ 'HTTP_HOST' ];
        if( false === $host || null === $host || '' === trim( $host ) )
        {
            //weird, bail
            return false;
        }

        //Get the protocol
        //TODO: Something special for IIS? Can't remember
        $scheme = ( array_key_exists( 'HTTPS', $_SERVER ) && isset( $_SERVER[ 'HTTPS' ] ) ? 'https' : 'http' );

        //Split the path into an array at forward slashes and remove empty items and then re-index it so that it is zero-based
        $path_parts = array_values( array_filter( explode( '/', $path ) ) );

        //Grab the last item in the list and URL decode it
        $file_name = urldecode( end( $path_parts ) );

        //Grab the QS or default to an empty array
        $query_string = isset( $_GET ) && is_array( $_GET ) ? $_GET : array();

        return array(
                        'path'          => $path,
                        'host'          => $host,
                        'scheme'        => $scheme,
                        'path_parts'    => $path_parts,
                        'file_name'     => $file_name,
                        'query_string'  => $query_string,
                );
    }

    public function __construct()
    {
        $this->register_actions();
    }

    public static function get_instance()
    {
        if( ! self::$_instance )
        {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function register_actions()
    {
        //template_redirect is called for many things but specifically
        //we're looking for 404's which we'll handle later
        add_filter( 'template_redirect', array( $this, 'template_redirect' ) );
    }

    public function template_redirect()
    {
        //The global WordPress query
        global $wp_query;

        //See if we're in a 404
        if( ! $wp_query->is_404 )
        {
            //We only want 404's
            return false;
        }

        //Attempt to load various state variables such as HOST and PATH
        $state_vars = self::get_state_variables();

        //If the above fails our state is unknown, bail
        if( false === $state_vars )
        {
            return;
        }

        //Dump variables into our local state
        extract( $state_vars );

        //When we redirect below we append this to the query string. If this
        //key exists that means we're redirecting again which means that
        //something below is broken. In that scenario (which I'm not sure
        //if it can actually happen) then bail.
        if( array_key_exists( VENDI_UPLOADS_REBOOTER_URL_KEY, $query_string ) )
        {
            return;
        }

        $path_parts = explode( '/', trim( $path, '/' ) );

        //We currently only support wp-content/uploads/year/month/file
        //TODO: Maybe detect UPLOADS or use wp_upload_dir
        if( 5 !== count( $path_parts ) )
        {
            return;
        }

        if( 'wp-content' !== $path_parts[ 0 ] || 'uploads' !== $path_parts[ 1 ] )
        {
            return;
        }

        //Remove wp-content
        array_shift( $path_parts );

        //Grab the various other parts into explicit variables
        $uploads  = array_shift( $path_parts );
        $year     = array_shift( $path_parts );
        $month    = array_shift( $path_parts );
        $filename = array_shift( $path_parts );

        //This is the path that we're going to test for a file
        $full_path_to_test_file             = trailingslashit( WP_CONTENT_DIR ) . implode( '/', [ $uploads, VENDI_UPLOADS_REBOOTER_BACKUP_FOLDER, $year, $month, $filename ] );

        //If the above worked, we're going to move (rename) it to this file name
        $full_path_to_move_file_to          = trailingslashit( WP_CONTENT_DIR ) . implode( '/', [ $uploads, $year, $month, $filename ] );

        //This is the folder that we might possibly need to create for the new file
        $full_path_to_move_directory_only   = trailingslashit( WP_CONTENT_DIR ) . implode( '/', [ $uploads, $year, $month ] );

        //TODO: maybe use clearstatcache( true, $full_path_to_test_file )
        //see https://secure.php.net/manual/en/function.clearstatcache.php

        //See if we can read this file in the first place
        if( ! is_readable( $full_path_to_test_file ) )
        {
            return;
        }

        //If the new folder doesn't exist try creating it
        if( ! is_dir( $full_path_to_move_directory_only ) )
        {
            @mkdir( $full_path_to_move_directory_only, 0777, true );

            //The result of the above doesn't really matter, just check if
            //we've got a directory now
            if( ! is_dir( $full_path_to_move_directory_only ) )
            {
                return;
            }
        }

        //Try renaming the file
        @rename( $full_path_to_test_file, $full_path_to_move_file_to );

        //Once again, we don't care if the above failed, we just want to know
        //if we can read the file in the new location
        if( ! is_readable( $full_path_to_move_file_to ) )
        {
            return;
        }

        //TODO: We could perform readfile() instead but I don't really want to
        //deal headers, I'd rather the web server do that for us instead.

        //Finally, we've move the files, redirect this request to the new file
        //Future requestors won't 404 so there should only be a performance hit
        //for the first requestor ever.
        $redirect_url = sprintf(
                                    '%1$s://%2$s%3$s?%4$s=true',
                                    $scheme,
                                    $host,
                                    $path,
                                    VENDI_UPLOADS_REBOOTER_URL_KEY
                            );

        //Perform a 302 redirect so that no one caches it
        wp_safe_redirect( $redirect_url, 302 );
        exit;
    }
}

//Boot the above
vendi_404_handler::get_instance();
