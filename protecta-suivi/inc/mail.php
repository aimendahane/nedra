<?php
/**
 * Envoi des e-mails (sans extension) — version 1.0.1.
 *
 * Sur un hébergement mutualisé, l'envoi par défaut de PHP (« mail() ») part souvent
 * avec l'adresse wordpress@domaine, qui n'existe pas : le message est refusé ou classé
 * en spam. Ce fichier permet, depuis الإعدادات → عام :
 *   - de choisir l'adresse et le nom de l'expéditeur ;
 *   - d'envoyer par SMTP avec un vrai compte e-mail (ex. cPanel → Email Accounts) ;
 *   - de connaître la VRAIE raison d'un échec (message de PHPMailer).
 *
 * Le mot de passe SMTP est enregistré dans l'option « ps_smtp_pass » (hors sauvegarde JSON)
 * et n'est jamais renvoyé au navigateur.
 */

defined( 'ABSPATH' ) || exit;

/** Dernière erreur d'envoi (remplie par le crochet wp_mail_failed). */
function ps_mail_last_error( ?string $set = null ): ?string {
	static $error = null;
	if ( null !== $set ) {
		$error = '' === $set ? null : $set;
	}
	return $error;
}

add_action(
	'wp_mail_failed',
	static function ( WP_Error $e ) {
		ps_mail_last_error( $e->get_error_message() );
	}
);

/**
 * Adresse de l'expéditeur : celle des réglages, sinon l'utilisateur SMTP (les serveurs
 * refusent souvent un expéditeur différent du compte connecté), sinon celle de WordPress.
 */
add_filter(
	'wp_mail_from',
	static function ( $from ) {
		$s = ps_settings();
		if ( is_email( $s['mail_from_email'] ) ) {
			return $s['mail_from_email'];
		}
		if ( ! empty( $s['smtp_enabled'] ) && is_email( $s['smtp_user'] ) ) {
			return $s['smtp_user'];
		}
		return $from;
	}
);

add_filter(
	'wp_mail_from_name',
	static function ( $name ) {
		$n = trim( (string) ps_setting( 'mail_from_name' ) );
		return '' !== $n ? $n : $name;
	}
);

/**
 * Bascule PHPMailer en SMTP quand c'est activé dans الإعدادات.
 */
add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$s = ps_settings();
		if ( empty( $s['smtp_enabled'] ) || '' === trim( (string) $s['smtp_host'] ) ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host    = trim( $s['smtp_host'] );
		$phpmailer->Port    = (int) $s['smtp_port'];
		$phpmailer->Timeout = 20;
		if ( in_array( $s['smtp_secure'], array( 'ssl', 'tls' ), true ) ) {
			$phpmailer->SMTPSecure = $s['smtp_secure'];
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}
		if ( '' !== trim( (string) $s['smtp_user'] ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = trim( $s['smtp_user'] );
			$phpmailer->Password = (string) get_option( 'ps_smtp_pass', '' );
		}
	}
);

/**
 * Envoie un e-mail HTML.
 *
 * @return true|WP_Error L'erreur contient le message réel du serveur (en anglais).
 */
function ps_mail_send( string $to, string $subject, string $html ) {
	if ( ! is_email( $to ) ) {
		return new WP_Error( 'ps_mail_to', ps__( 'عنوان البريد غير صالح: ' ) . $to );
	}
	ps_mail_last_error( '' );
	$ok = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	if ( $ok ) {
		return true;
	}
	return new WP_Error( 'ps_mail', ps_mail_last_error() ?: ps__( 'رفضت الاستضافة الإرسال (دالة mail غير متاحة).' ) );
}
