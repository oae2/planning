<?php
class MRB_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    public function register_menu() {
        add_menu_page(
            __( 'Meeting Rooms', 'mrb' ),
            __( 'Meeting Rooms', 'mrb' ),
            'manage_options',
            'mrb-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-building'
        );
    }

    public function render_dashboard() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Meeting Room Booking', 'mrb' ) . '</h1></div>';
    }
}
