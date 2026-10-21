<?php
/**
 * API Telegram (1.2.0) — règles dans inc/services/telegram.php.
 *
 *   GET  /me/telegram          → { enabled, bot, linked }
 *   POST /me/telegram/link     → { url } (lien t.me/<bot>?start=<code>, valable 2 h)
 *   POST /me/telegram/check    → lit les messages du bot puis { linked }
 *   POST /me/telegram/unlink   → délie le compte
 *   POST /settings/telegram-test (الإدارة) → message d'essai au compte connecté
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_telegram_routes' );
function ps_register_telegram_routes(): void {
	$me = static fn() => array(
		'enabled' => ps_tg_enabled(),
		'bot'     => ps_tg_bot(),
		'linked'  => '' !== ps_tg_chat_id( get_current_user_id() ),
	);
	register_rest_route( PS_REST_NS, '/me/telegram', array( 'methods' => 'GET', 'permission_callback' => ps_rest_permission(), 'callback' => static fn() => rest_ensure_response( $me() ) ) );
	register_rest_route(
		PS_REST_NS,
		'/me/telegram/link',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function () {
				if ( ! ps_tg_enabled() ) {
					return new WP_Error( 'ps_tg_off', ps__( 'إشعارات Telegram غير مفعّلة. اطلب من الإدارة إعدادها (الإعدادات ← عام).' ), array( 'status' => 409 ) );
				}
				return rest_ensure_response( array( 'url' => ps_tg_link_url( get_current_user_id() ), 'bot' => ps_tg_bot() ) );
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/me/telegram/check',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function () use ( $me ) {
				ps_tg_poll();
				return rest_ensure_response( $me() );
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/me/telegram/unlink',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function () use ( $me ) {
				ps_tg_unlink( get_current_user_id() );
				return rest_ensure_response( $me() );
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/settings/telegram-test',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission( 'ps_manage_settings' ),
			'callback'            => static function () {
				$u = wp_get_current_user();
				ps_tg_poll();
				if ( '' === ps_tg_chat_id( $u->ID ) ) {
					return new WP_Error( 'ps_tg_unlinked', ps__( 'اربط حسابك أولًا: 🔔 ← «ربط Telegram».' ), array( 'status' => 409 ) );
				}
				return ps_tg_send_user( $u, '✅ <b>' . ps__( 'رسالة تجريبية' ) . '</b>' . "\n" . ps__( 'إشعارات Telegram تعمل.' ), home_url( '/' ) )
					? rest_ensure_response( array( 'ok' => true ) )
					: new WP_Error( 'ps_tg_send', ps__( 'تعذّر الإرسال إلى Telegram.' ), array( 'status' => 500 ) );
			},
		)
	);
}
