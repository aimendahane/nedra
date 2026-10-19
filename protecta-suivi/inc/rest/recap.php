<?php
/**
 * API du Recap (الإدارة seulement — décision client n°2).
 *
 *   GET /recap?year=2026&month=&followerEmpId=&placeId=&sourceId=
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_recap_routes' );
function ps_register_recap_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/recap',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission( 'ps_view_recap' ),
			'callback'            => static function ( WP_REST_Request $r ) {
				$filters = array();
				foreach ( array( 'year', 'month', 'followerEmpId', 'placeId', 'sourceId' ) as $k ) {
					$v = $r->get_param( $k );
					if ( null !== $v && '' !== $v ) {
						$filters[ $k ] = (int) $v;
					}
				}
				return rest_ensure_response( ps_recap( $filters ) );
			},
		)
	);
}
