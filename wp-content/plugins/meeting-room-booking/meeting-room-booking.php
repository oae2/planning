<?php
/**
 * Plugin Name: Meeting Room Booking
 * Description: Basic meeting room booking system.
 * Version: 0.1.0
 * Author: Example Author
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-mrb-activator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mrb-deactivator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mrb-database.php';
require_once plugin_dir_path( __FILE__ ) . 'public/class-mrb-public.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/class-mrb-admin.php';

function mrb_activate() {
    \MRB_Activator::activate();
}

function mrb_deactivate() {
    \MRB_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'mrb_activate' );
register_deactivation_hook( __FILE__, 'mrb_deactivate' );

function mrb_init() {
    $database = new \MRB_Database();
    $database->maybe_create_tables();

    if ( is_admin() ) {
        new \MRB_Admin();
    } else {
        new \MRB_Public();
    }
}
add_action( 'plugins_loaded', 'mrb_init' );
