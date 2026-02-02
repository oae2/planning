<?php
class MRB_Booking {
    public function create_booking( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mrb_bookings';
        $wpdb->insert( $table, $data );
    }
}
