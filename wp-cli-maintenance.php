<?php
/*
Plugin Name: WP-CLI Maintenance plugin
Description: Enables the "wp maintenance" CLI command
Version: 1.0
Author: Laure Guicherd
Author URI: https://twitter.com/justpearly
Author Email: laure.guicherd@gmail.com
*/

namespace WPCliMaintenance;
use WP_CLI;



class Plugin {


    protected $_maintenanceFile;
    protected $_tplFile;

    protected $_synopsis = [
        'shortdesc' => 'Handle maintenance mode.',
        'synopsis' => [
            [
                'type' => 'positional',
                'name' => 'command',
                'optional' => false,
                'options' => [ 'on', 'off', 'info' ],
                'multiple' => false,
                'description' => 'Wether to activate, deactivate or check maintenance mode.'
            ],
            [
                'type' => 'assoc',
                'name' => 'duration',
                'optional' => true,
                'description' => "When activating, specify the maintenance duration, in minutes.\nIf not specfied, will activate maintenance forever (until deactivation)."
            ],
            [
                'type' => 'assoc',
                'name' => 'template',
                'optional' => true,
                'description' => "When activating, specify the maintenance template to display.\nThe path will be evaluated in the wp-content folder."
            ]
        ],
        'when' => 'before_wp_load',
    ];


    public function __construct() {

        $this->_maintenanceFile = ABSPATH . '/.maintenance';
        $this->_tplFile = WP_CONTENT_DIR . '/maintenance.php';

        add_action( 'init', [ $this, 'registerCommand' ] );

    }


    protected function on( $duration=false, $template=false ) {

        if ( $template ) {

            $tpl = $template[0]=='/' ? $template : WP_CONTENT_DIR . '/' . $template;

            if ( $tpl != $this->_tplFile ) {

                if ( ! file_exists($tpl) ) {
                    WP_CLI::error( "We did nothing because the template file '$tpl' does not exist." );
                }
                else {
                    $str = "<?php /* BEGIN ADDED BY WP-CLI maintenance command */\n";
                    $str .= "include '$tpl'; die();\n";
                    $str .= "/* END ADDED BY WP-CLI maintenance command */ ?>\n";
                    if ( file_exists( $this->_tplFile ) ) {
                        $str .= file_get_contents( $this->_tplFile );
                    }
                    file_put_contents( $this->_tplFile, $str );
                }

            }

        }

        if ( $fh = fopen( $this->_maintenanceFile, 'w' ) ) {

            $duration_str = 'time()';
            if ( $duration ) {
                $duration_str = strtotime( 'now +' . ($duration-10) . ' minutes' );
            }
            fwrite( $fh, '<?php $upgrading = ' . $duration_str . ';' );
            fclose( $fh );

            WP_CLI::success( 'Maintenance mode is now activated'
                . ( $duration ? sprintf( _n( ' for %d minute', ' for %d minutes', $duration ), $duration ) : '' )
                . '.'
            );

        }
        else {
            WP_CLI::error( 'Could not activate maintenance mode.' );
        }

    }


    protected function off() {

        if ( $this->isMaintenanceOn() ) {

            // Delete .maintenance file
            @unlink( $this->_maintenanceFile );

            // Clean the template file
            if ( file_exists( $this->_tplFile ) ) {

                // Remove the added include
                $str = file_get_contents( $this->_tplFile );
                if ( preg_match( '/<\?php \/\* BEGIN ADDED BY WP-CLI.*\/\* END ADDED BY WP-CLI maintenance command \*\/ \?>\n/s', $str, $matches ) ) {
                    $str = str_replace( $matches[0], '', $str );
                    file_put_contents( $this->_tplFile, $str );

                    // if file is now empty, delete it
                    $str = file_get_contents( $this->_tplFile );
                    if ( ! trim($str) ) {
                        unlink( $this->_tplFile );
                    }
                }

            }

            WP_CLI::success( 'Maintenance mode is now deactivated.' );
        }
        else {
            WP_CLI::error( 'We did nothing because maintenance mode was not activated.' );
        }

    }


    protected function info() {

        if ( $this->isMaintenanceOn() ) {
            $str = file_get_contents( $this->_maintenanceFile );
            $end_str = 'indefinitely';
            if ( preg_match( '/\$upgrading\s*=\s*([0-9]+)\s*;/', $str, $matches ) ) {
                $timestamp = $matches[1];

                $date = new \DateTime();
                $date->setTimestamp( $timestamp );
                $date->modify('+10 minutes');
                $end_str = 'until ' . $date->format('r');

                $now = new \DateTime();
                $remaining = $date->diff($now, true);
                $remaining_str = sprintf( '%d hours, %d minutes and %d seconds', $remaining->format('%H'), $remaining->format('%i'), $remaining->format('%s') );

            }
            WP_CLI::line(
                'Maintenance mode is currently ' . WP_CLI::colorize('%9' . 'on' . '%n') . ' ' . $end_str . ' (' . $remaining_str . ' to go)' );
        }
        else {
            WP_CLI::line( 'Maintenance mode is currently ' . WP_CLI::colorize('%9' . 'off' . '%n') . '.' );
        }

    }


    protected function isMaintenanceOn() {

        if ( ! file_exists( $this->_maintenanceFile ) ) {
            return false;
        }

        $txt = file_get_contents( $this->_maintenanceFile );

        if ( preg_match( '/\$upgrading\s*=\s*([0-9]+)/', $txt, $matches ) ) {
            $deactivationTime = $matches[1] + 10 * MINUTE_IN_SECONDS;
            return $deactivationTime > time();
        }

        return true;

    }


    public function execCommand( $args, $assoc_args ) {

        list( $cmd ) = $args;

            if ( $cmd=='on' ) {
                $duration = array_key_exists('duration', $assoc_args) ? $assoc_args['duration'] : false;
                $template = array_key_exists('template', $assoc_args) ? $assoc_args['template'] : false;
                $this->on( $duration, $template );
            }
            elseif ( $cmd=='off' ) {
                $this->off();
            }
            elseif ( $cmd=='info' ) {
                $this->info();
            }
            else {
                WP_CLI::error( WP_CLI\SynopsisParser::parse( $this->_synopsis ) );
            }

    }


    public function registerCommand() {

        if ( defined('WP_CLI') && WP_CLI ) {
            WP_CLI::add_command( 'maintenance', [ $this, 'execCommand' ], $this->_synopsis );
        }

    }


}

new Plugin;
