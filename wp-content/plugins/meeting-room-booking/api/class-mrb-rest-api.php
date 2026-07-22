<?php
class MRB_REST_API {
    public function register_routes() {
        register_rest_route( 'mrb/v1', '/rooms', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_rooms' ),
        ) );
    }

    public function get_rooms( $request ) {
        return rest_ensure_response( array() );
    }
}
