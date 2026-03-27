<?php
class MRB_Public {
    public function __construct() {
        add_shortcode( 'mrb_booking_form', array( $this, 'booking_form' ) );
    }

    public function booking_form() {
        ob_start();
        include plugin_dir_path( __FILE__ ) . 'partials/booking-form.php';
        return ob_get_clean();
    }
}
