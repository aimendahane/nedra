<?php
/**
 * Notifications Telegram (version 1.2.0) — sans extension, sans webhook.
 *
 * Mise en place (الإعدادات → عام → Telegram) :
 *   1. la الإدارة crée un bot avec @BotFather et colle son « token » ; l'application vérifie le token (getMe)
 *      et retient le nom du bot ;
 *   2. chaque utilisateur clique « ربط Telegram » (cloche 🔔) : un lien t.me/<bot>?start=<code> s'ouvre,
 *      il appuie sur « Start » ; l'application lit ce message (getUpdates — aucune adresse publique nécessaire,
 *      fonctionne sur HostGator comme en local) et enregistre son identifiant de discussion (chat_id).
 *
 * Ensuite il reçoit sur Telegram : les notifications 🔔 de ses projets, les tâches qui lui sont confiées
 * (avec le message vocal éventuel) et le résumé du matin. « /stop » dans Telegram ou « فك الربط » arrête tout.
 *
 * Sécurité (1.6.0) : le token est défini dans wp-config.php — define( 'PS_TELEGRAM_TOKEN', '123456:ABC…' ); —
 * jamais dans le code, le dépôt, la sauvegarde JSON ni le HTML. Les sites configurés avant 1.6.0 gardent leur
 * token dans l'option « ps_telegram_token » (même protection) tant que la constante n'est pas définie.
 * Le code de liaison est court (8 caractères), aléatoire, à usage unique, valable 10 minutes : l'utilisateur
 * envoie « /start CODE » au bot (ou ouvre le lien t.me qui le fait pour lui).
 * Un échec d'envoi (Telegram indisponible, bot bloqué) n'empêche jamais l'enregistrement dans l'application.
 */

defined( 'ABSPATH' ) || exit;

const PS_TG_CODE_TTL = 10 * MINUTE_IN_SECONDS;

/** Caractères du code de liaison (sans 0/O/1/I/L, faciles à recopier). */
const PS_TG_CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/** Token du bot : constante PS_TELEGRAM_TOKEN de wp-config.php (1.6.0), sinon l'ancienne option. */
function ps_tg_token(): string {
	if ( defined( 'PS_TELEGRAM_TOKEN' ) && '' !== trim( (string) PS_TELEGRAM_TOKEN ) ) {
		return trim( (string) PS_TELEGRAM_TOKEN );
	}
	return (string) get_option( 'ps_telegram_token', '' );
}

/** D'où vient le token : 'config' (wp-config.php), 'option' (ancienne configuration) ou '' (aucun). */
function ps_tg_token_source(): string {
	if ( defined( 'PS_TELEGRAM_TOKEN' ) && '' !== trim( (string) PS_TELEGRAM_TOKEN ) ) {
		return 'config';
	}
	return '' !== (string) get_option( 'ps_telegram_token', '' ) ? 'option' : '';
}

/** Telegram actif : réglage activé ET token valide enregistré. */
function ps_tg_enabled(): bool {
	return (bool) ps_setting( 'telegram_enabled' ) && '' !== ps_tg_token() && '' !== ps_tg_bot();
}

/**
 * Nom du bot (sans @), retenu lors de la vérification du token. Avec la constante de wp-config.php, il est
 * demandé à Telegram (getMe) la première fois, puis à chaque changement de token (empreinte, jamais le token).
 */
function ps_tg_bot(): string {
	if ( 'config' === ps_tg_token_source() ) {
		$hash = substr( hash( 'sha256', ps_tg_token() ), 0, 16 );
		if ( get_option( 'ps_telegram_bot_for' ) !== $hash ) {
			if ( get_transient( 'ps_tg_getme_fail' ) ) {
				return '';
			}
			$me = ps_tg_api( 'getMe' );
			if ( is_wp_error( $me ) ) {
				set_transient( 'ps_tg_getme_fail', 1, 10 * MINUTE_IN_SECONDS ); // Telegram injoignable : pas de nouvel essai avant 10 min
				return '';
			}
			update_option( 'ps_telegram_bot', (string) ( $me['username'] ?? '' ), false );
			update_option( 'ps_telegram_bot_for', $hash, false );
			delete_option( 'ps_telegram_offset' );
		}
	}
	return (string) get_option( 'ps_telegram_bot', '' );
}

/**
 * Appel de l'API Telegram.
 *
 * @param array       $params Paramètres de la méthode.
 * @param array|null  $file   [ 'field' => 'voice', 'path' => …, 'name' => …, 'mime' => … ] pour envoyer un fichier.
 * @return array|WP_Error Résultat (« result ») ou erreur lisible.
 */
function ps_tg_api( string $method, array $params = array(), ?array $file = null, ?string $token = null ) {
	$token = $token ?? ps_tg_token();
	if ( '' === $token ) {
		return new WP_Error( 'ps_tg_token', ps__( 'لم يتم إعداد بوت Telegram' ) );
	}
	$args = array( 'timeout' => 20 );
	if ( $file ) {
		// Envoi d'un fichier : corps multipart construit à la main (wp_remote_post ne le fait pas).
		$boundary = 'ps' . wp_generate_password( 24, false );
		$body     = '';
		foreach ( $params as $k => $v ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n" . ( is_array( $v ) ? wp_json_encode( $v ) : $v ) . "\r\n";
		}
		$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"" . str_replace( '"', '', $file['name'] ) . "\"\r\nContent-Type: {$file['mime']}\r\n\r\n" . file_get_contents( $file['path'] ) . "\r\n--$boundary--\r\n"; // phpcs:ignore
		$args['headers'] = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
		$args['body']    = $body;
	} else {
		$args['headers'] = array( 'Content-Type' => 'application/json' );
		$args['body']    = wp_json_encode( $params );
	}
	$res = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/' . $method, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'ps_tg_http', ps__( 'تعذّر الاتصال بـ Telegram: ' ) . $res->get_error_message() );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['ok'] ) ) {
		return new WP_Error( 'ps_tg_api', 'Telegram: ' . ( $data['description'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	}
	return (array) ( $data['result'] ?? array() );
}

/**
 * Vérifie un token (getMe) et l'enregistre. @return string|WP_Error Nom du bot.
 */
function ps_tg_save_token( string $token ) {
	$token = trim( $token );
	if ( ! preg_match( '/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token ) ) {
		return new WP_Error( 'ps_tg_token', ps__( 'رمز البوت غير صالح (انسخه كاملًا من @BotFather)' ) );
	}
	$me = ps_tg_api( 'getMe', array(), null, $token );
	if ( is_wp_error( $me ) ) {
		return $me;
	}
	update_option( 'ps_telegram_token', $token, false );
	update_option( 'ps_telegram_bot', (string) ( $me['username'] ?? '' ), false );
	delete_option( 'ps_telegram_offset' );
	return (string) ( $me['username'] ?? '' );
}

/* ---------------------------------------------------------------------- */
/* Liaison compte ↔ Telegram                                               */
/* ---------------------------------------------------------------------- */

function ps_tg_chat_id( int $user_id ): string {
	return (string) get_user_meta( $user_id, 'ps_tg_chat_id', true );
}

/** Nouveau code de liaison (8 caractères, valable 10 minutes, usage unique). */
function ps_tg_new_code( int $user_id ): string {
	$code = '';
	for ( $i = 0; $i < 8; $i++ ) {
		$code .= PS_TG_CODE_CHARS[ random_int( 0, strlen( PS_TG_CODE_CHARS ) - 1 ) ];
	}
	update_user_meta( $user_id, 'ps_tg_code', $code . '|' . ( time() + PS_TG_CODE_TTL ) );
	return $code;
}

/** Lien de liaison (nouveau code à chaque appel). */
function ps_tg_link_url( int $user_id, ?string $code = null ): string {
	$code = $code ?? ps_tg_new_code( $user_id );
	return 'https://t.me/' . rawurlencode( ps_tg_bot() ) . '?start=' . $code;
}

/**
 * Lie le compte dont le code correspond (« /start CODE »). @return WP_User|null Compte lié, null si code invalide/expiré.
 */
function ps_tg_link_by_code( string $given, string $chat ): ?WP_User {
	$given = strtoupper( trim( $given ) );
	if ( ! preg_match( '/^[A-Z0-9]{6,24}$/', $given ) ) {
		return null;
	}
	foreach ( get_users( array( 'meta_key' => 'ps_tg_code', 'meta_value' => $given . '|', 'meta_compare' => 'LIKE', 'number' => 5 ) ) as $user ) {
		list( $code, $until ) = array_pad( explode( '|', (string) get_user_meta( $user->ID, 'ps_tg_code', true ) ), 2, 0 );
		if ( hash_equals( strtoupper( $code ), $given ) && time() <= (int) $until && ! get_user_meta( $user->ID, 'ps_disabled', true ) ) {
			update_user_meta( $user->ID, 'ps_tg_chat_id', $chat );
			delete_user_meta( $user->ID, 'ps_tg_code' );
			return $user;
		}
	}
	return null;
}

function ps_tg_unlink( int $user_id ): void {
	delete_user_meta( $user_id, 'ps_tg_chat_id' );
	delete_user_meta( $user_id, 'ps_tg_code' );
}

/**
 * Lit les nouveaux messages reçus par le bot (getUpdates) : « /start <code> » lie un compte,
 * « /stop » le délie. Appelée par le bouton « تحقق », par l'écran de liaison et par le Cron.
 *
 * @return int Nombre de comptes liés pendant cet appel.
 */
function ps_tg_poll(): int {
	if ( '' === ps_tg_token() ) {
		return 0;
	}
	$offset  = (int) get_option( 'ps_telegram_offset', 0 );
	$updates = ps_tg_api( 'getUpdates', array( 'offset' => $offset, 'timeout' => 0, 'allowed_updates' => array( 'message' ) ) );
	if ( is_wp_error( $updates ) ) {
		return 0;
	}
	$linked = 0;
	foreach ( $updates as $u ) {
		update_option( 'ps_telegram_offset', (int) $u['update_id'] + 1, false );
		$msg  = $u['message'] ?? null;
		$chat = isset( $msg['chat']['id'] ) ? (string) $msg['chat']['id'] : '';
		$text = trim( (string) ( $msg['text'] ?? '' ) );
		if ( '' === $chat || 'private' !== ( $msg['chat']['type'] ?? '' ) ) {
			continue;
		}
		if ( preg_match( '/^\/start(?:@\w+)?\s+([A-Za-z0-9]{6,24})$/', $text, $m ) ) {
			$user = ps_tg_link_by_code( $m[1], $chat );
			if ( $user ) {
				++$linked;
				ps_with_lang( $user, static fn() => ps_tg_api( 'sendMessage', array( 'chat_id' => $chat, 'parse_mode' => 'HTML', 'text' => '✅ <b>' . esc_html( $user->display_name ) . '</b>' . "\n" . ps__( 'تم ربط حسابك بتطبيق «متابعة المشاريع والعروض». ستصلك هنا الإشعارات والمهام.' ) . "\n" . ps__( 'لإيقافها: /stop' ) ) ) );
			} else {
				ps_tg_api( 'sendMessage', array( 'chat_id' => $chat, 'text' => ps__( '⚠️ رمز الربط غير صالح أو منتهي (صالح 10 دقائق). اطلب رمزًا جديدًا من التطبيق (🔔 ← ربط Telegram).' ) ) );
			}
		} elseif ( '/stop' === $text ) {
			foreach ( get_users( array( 'meta_key' => 'ps_tg_chat_id', 'meta_value' => $chat ) ) as $user ) {
				ps_tg_unlink( $user->ID );
			}
			ps_tg_api( 'sendMessage', array( 'chat_id' => $chat, 'text' => ps__( '🔕 تم إيقاف الإشعارات وفك ربط الحساب.' ) ) );
		}
	}
	return $linked;
}

/* ---------------------------------------------------------------------- */
/* Envoi                                                                   */
/* ---------------------------------------------------------------------- */

/**
 * Bouton « فتح » sous le message (Telegram n'accepte que des adresses https publiques).
 */
function ps_tg_button( string $label, string $url ): ?array {
	return str_starts_with( $url, 'https://' ) && ! str_contains( $url, 'localhost' ) ? array( 'inline_keyboard' => array( array( array( 'text' => $label, 'url' => $url ) ) ) ) : null;
}

/**
 * Message texte (HTML Telegram : <b>, <i>, <a> seulement — tout le reste doit être échappé).
 *
 * @return bool true si envoyé.
 */
function ps_tg_send_user( WP_User $u, string $html, ?string $url = null, string $button = 'فتح في التطبيق' ): bool {
	$chat = ps_tg_chat_id( $u->ID );
	if ( '' === $chat || ! ps_tg_enabled() || get_user_meta( $u->ID, 'ps_disabled', true ) ) {
		return false;
	}
	$p = array( 'chat_id' => $chat, 'text' => mb_substr( $html, 0, 4000 ), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true );
	if ( $url && ( $kb = ps_tg_button( ps__( $button ), $url ) ) ) {
		$p['reply_markup'] = $kb;
	}
	$r = ps_tg_api( 'sendMessage', $p );
	$GLOBALS['ps_tg_last_error'] = is_wp_error( $r ) ? $r->get_error_message() : '';
	if ( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'blocked' ) ) {
		ps_tg_unlink( $u->ID ); // l'utilisateur a bloqué le bot : on arrête d'essayer
	}
	return ! is_wp_error( $r );
}

/**
 * Fichier audio (message vocal d'une tâche). OGG/Opus → « voice » (lecture directe dans Telegram),
 * autres formats (webm, mp4/m4a) → « audio » puis « document » en dernier recours.
 */
function ps_tg_send_voice( WP_User $u, string $path, string $mime, string $caption ): bool {
	$chat = ps_tg_chat_id( $u->ID );
	if ( '' === $chat || ! ps_tg_enabled() || ! is_readable( $path ) ) {
		return false;
	}
	$base = array( 'chat_id' => $chat, 'caption' => mb_substr( $caption, 0, 1000 ), 'parse_mode' => 'HTML' );
	// WebM (Chrome, Android) : Telegram ne le lit pas → on envoie sa copie Ogg/Opus (vrai message vocal).
	$ogg = function_exists( 'ps_voice_ogg_path' ) ? ps_voice_ogg_path( $path ) : $path;
	if ( $ogg !== $path && ( is_readable( $ogg ) || ps_voice_webm_to_ogg( $path, $ogg ) ) ) {
		$path = $ogg;
		$mime = 'audio/ogg';
	}
	$ext = pathinfo( $path, PATHINFO_EXTENSION );
	// sendVoice accepte Ogg/Opus, MP3 et M4A ; sinon fichier audio, puis simple document.
	$try = str_contains( $mime, 'ogg' ) ? array( 'sendVoice' => 'voice', 'sendDocument' => 'document' )
		: ( str_contains( $mime, 'webm' ) ? array( 'sendDocument' => 'document' ) : array( 'sendVoice' => 'voice', 'sendAudio' => 'audio', 'sendDocument' => 'document' ) );
	foreach ( $try as $method => $field ) {
		$r = ps_tg_api( $method, $base, array( 'field' => $field, 'path' => $path, 'name' => 'voice.' . $ext, 'mime' => $mime ) );
		if ( ! is_wp_error( $r ) ) {
			$GLOBALS['ps_tg_voice_result'] = $method; // pour « اختبار الصوت » (1.3.3)
			return true;
		}
		$GLOBALS['ps_tg_voice_result'] = $r->get_error_message();
	}
	return false;
}

/** Texte Telegram d'une notification 🔔 (projet + liste des changements). */
function ps_tg_notification_html( string $title, array $lines, ?string $actor ): string {
	$h = '🔔 <b>' . esc_html( $title ) . '</b>' . ( $actor ? "\n<i>" . esc_html( $actor ) . '</i>' : '' ) . "\n";
	foreach ( array_slice( $lines, 0, 12 ) as $l ) {
		$h .= '• ' . esc_html( $l ) . "\n";
	}
	return $h;
}

/** Résumé du matin pour Telegram (mêmes blocs que مهامي, version courte). */
function ps_tg_digest_html( WP_User $u, array $tasks, bool $admin ): string {
	$h  = '☀️ <b>' . esc_html( ( $admin ? ps__( 'ملخص الفريق' ) : ps__( 'مهامك اليوم' ) ) . ' — ' . wp_date( 'd/m/Y' ) ) . '</b>' . "\n";
	$bl = array(
		'assigned'     => array( ps__( '📌 مهام مُسندة' ), fn( $r ) => $r['title'] . ( $r['dueDate'] ? ' · ' . $r['dueDate'] : '' ) ),
		'lateOffers'   => array( ps__( '⚠️ عروض متأخرة — لازم قرار' ), fn( $r ) => $r['projectNo'] . ' — ' . $r['projectName'] . ' (' . (int) $r['daysLate'] . ps__( ' يوم)' ) ),
		'pendingDecision' => array( ps__( '⏳ بانتظار القرار' ), fn( $r ) => $r['projectNo'] . ' — ' . $r['projectName'] . ' (' . (int) $r['daysWaiting'] . ps__( ' يوم)' ) ), // 1.9.1
		'followupsDue' => array( ps__( '📞 متابعات اليوم' ), fn( $r ) => $r['projectNo'] . ' — ' . $r['projectName'] ),
		'deliveries'   => array( ps__( '🚚 تسليمات الأسبوع' ), fn( $r ) => $r['projectNo'] . ' — ' . $r['deliveryDueDate'] ),
		'noFollowup'   => array( ps__( '🕒 بدون متابعة' ), fn( $r ) => $r['projectNo'] . ' — ' . $r['projectName'] ),
	);
	$any = false;
	foreach ( $bl as $k => list( $label, $fmt ) ) {
		if ( empty( $tasks[ $k ] ) ) {
			continue;
		}
		$any = true;
		$h  .= "\n<b>" . esc_html( $label ) . ' (' . count( $tasks[ $k ] ) . ')</b>' . "\n";
		foreach ( array_slice( $tasks[ $k ], 0, 8 ) as $r ) {
			$h .= '• ' . esc_html( $fmt( $r ) ) . "\n";
		}
		if ( count( $tasks[ $k ] ) > 8 ) {
			$h .= '… +' . ( count( $tasks[ $k ] ) - 8 ) . "\n";
		}
	}
	return $any ? $h : $h . ps__( "\n✔ لا توجد مهام متأخرة اليوم." );
}
