<?php
/**
 * API des notifications 🔔 (1.0.2) — chaque utilisateur ne voit que les siennes.
 *
 *   GET  /notifications ?limit=30 (0 = seulement le nombre de non lues ; appel « passif » possible)
 *   POST /notifications/read  { ids: [..] } ou { all: true }
 * La préférence « بريد فوري عند كل تغيير » passe par POST /me/prefs { instantEmail }.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_notification_routes' );
function ps_register_notification_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/notifications',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function ( WP_REST_Request $r ) {
				$uid   = get_current_user_id();
				$limit = null === $r['limit'] ? 30 : max( 0, min( 100, (int) $r['limit'] ) );
				return rest_ensure_response( array( 'unread' => ps_notifications_unread( $uid ), 'items' => ps_notifications_for( $uid, $limit ) ) );
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/notifications/read',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function ( WP_REST_Request $r ) {
				$uid = get_current_user_id();
				$n   = ps_notifications_mark_read( $uid, $r['all'] ? null : (array) $r['ids'] );
				return rest_ensure_response( array( 'marked' => $n, 'unread' => ps_notifications_unread( $uid ) ) );
			},
		)
	);
}
