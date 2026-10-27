<?php
/**
 * Alertes des factures (version 1.6.0) — envoyées chaque matin par le Cron (inc/cron.php).
 *
 * Pour chaque facture encore « في الانتظار » (offre d'un projet « مشروع = نعم », non supprimé) :
 *   - « before » : rappel 3 jours avant آخر أجل للرد (une seule fois) ;
 *   - « due »    : le jour de l'échéance ;
 *   - « late »   : après l'échéance, UNE alerte par jour au maximum tant que la réponse n'est pas enregistrée.
 * Règles de calcul : ps_calc_invoice_alert_kind() (inc/calculs.php).
 *
 * Destinataire : le الموظف المتابع de l'offre (comptes actifs liés à cet employé) ; à défaut, المسؤول عن المشروع.
 * Canal : Telegram si le compte est lié ; sinon e-mail (s'il a une adresse).
 *
 * Fiabilité :
 *   - chaque envoi est enregistré dans ps_alerts (clé unique personne + facture + type + jour) → jamais deux fois
 *     le même jour pour la même personne, même si le Cron tourne plusieurs fois ;
 *   - Telegram indisponible → échec journalisé (status failed + erreur), e-mail de secours immédiat si l'utilisateur a
 *     une adresse, et nouvel essai Telegram à la prochaine exécution du Cron (5 essais au plus). Rien n'est perdu.
 *
 * Confidentialité : le message contient seulement le numéro de facture, le numéro de projet, la date limite, le type
 * d'alerte et le lien vers la fiche (#/factures/ID). Ni client, ni montant, ni détail du projet.
 *
 * Interrupteur global : réglage « alerts_enabled » (« تفعيل التنبيهات »).
 */

defined( 'ABSPATH' ) || exit;

const PS_ALERT_MAX_TRIES   = 5;
const PS_ALERT_BEFORE_DAYS = 3;

/** Lien vers la fiche de la facture dans l'application. */
function ps_alert_url( int $invoice_id ): string {
	return home_url( '/#/factures/' . $invoice_id );
}

/**
 * Titre + lignes du message (dans la langue du destinataire, appelée via ps_with_lang).
 *
 * @return array [ titre, lignes[] ]
 */
function ps_alert_message( string $kind, array $inv, string $deadline, string $today ): array {
	$fmt   = static fn( $d ) => substr( $d, 8, 2 ) . '/' . substr( $d, 5, 2 ) . '/' . substr( $d, 0, 4 );
	$title = array(
		'before' => '⏰ ' . sprintf( ps__( 'تذكير: آخر أجل للرد على الفاتورة بعد %d أيام' ), ps_calc_days_between( $today, $deadline ) ),
		'due'    => '📅 ' . ps__( 'اليوم آخر أجل للرد على الفاتورة' ),
		'late'   => '⚠️ ' . sprintf( ps__( 'فاتورة متأخرة: لا رد منذ %d يوم' ), ps_calc_days_between( $deadline, $today ) ),
	)[ $kind ];
	return array(
		$title,
		array(
			ps__( 'رقم الفاتورة:' ) . ' ' . $inv['invoice_no'],
			ps__( 'رقم المشروع:' ) . ' ' . $inv['project_no'],
			ps__( 'آخر أجل للرد:' ) . ' ' . $fmt( $deadline ),
			ps_alert_url( (int) $inv['id'] ),
		),
	);
}

/** Comptes destinataires : المتابع de l'offre, sinon المسؤول عن المشروع (comptes actifs liés à l'employé). */
function ps_alert_recipients( array $inv ): array {
	foreach ( array( 'follower_emp_id', 'responsible_emp_id' ) as $col ) {
		$emp = ! empty( $inv[ 'i_' . $col ] ) ? (int) $inv[ 'i_' . $col ] : (int) $inv[ $col ];
		if ( $emp ) {
			$users = ps_users_for_employee( $emp );
			if ( $users ) {
				return $users;
			}
		}
	}
	return array();
}

/** Envoi Telegram d'une alerte. @return string '' si envoyé, sinon le message d'erreur. */
function ps_alert_send_telegram( WP_User $u, string $kind, array $inv, string $deadline, string $today ): string {
	$ok = ps_with_lang(
		$u,
		static function () use ( $u, $kind, $inv, $deadline, $today ) {
			list( $title, $lines ) = ps_alert_message( $kind, $inv, $deadline, $today );
			$html                  = '<b>' . esc_html( $title ) . '</b>' . "\n" . implode( "\n", array_map( 'esc_html', $lines ) );
			return ps_tg_send_user( $u, $html, ps_alert_url( (int) $inv['id'] ) );
		}
	);
	return $ok ? '' : ( (string) ( $GLOBALS['ps_tg_last_error'] ?? '' ) ?: 'telegram' );
}

/** Envoi par e-mail (canal principal sans Telegram, ou secours). @return bool */
function ps_alert_send_email( WP_User $u, string $kind, array $inv, string $deadline, string $today ): bool {
	if ( ! is_email( $u->user_email ) ) {
		return false;
	}
	return (bool) ps_with_lang(
		$u,
		static function () use ( $u, $kind, $inv, $deadline, $today ) {
			list( $title, $lines ) = ps_alert_message( $kind, $inv, $deadline, $today );
			$url  = array_pop( $lines );
			$body = '<div dir="' . ( 'ar' === ps_lang() ? 'rtl' : 'ltr' ) . '" style="font-family:Tahoma,Arial,sans-serif;font-size:15px"><p><strong>' . esc_html( $title ) . '</strong></p><p>'
				. implode( '<br>', array_map( 'esc_html', $lines ) ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html( ps__( 'فتح في التطبيق' ) ) . '</a></p></div>';
			return ! is_wp_error( ps_mail_send( $u->user_email, $title, $body ) );
		}
	);
}

/**
 * Exécution du jour (Cron). @return array Compte rendu : status, sent, emails, failed, retried, skipped.
 */
function ps_alerts_run( ?string $today = null ): array {
	global $wpdb;
	$today  = $today ?? ps_today();
	$report = array( 'status' => 'ok', 'sent' => 0, 'emails' => 0, 'failed' => 0, 'retried' => 0, 'skipped' => 0 );
	if ( ! ps_setting( 'alerts_enabled' ) ) {
		$report['status'] = 'off';
		return $report;
	}
	$at   = ps_table( 'alerts' );
	$days = ps_invoice_reply_days();
	$rows = $wpdb->get_results(
		'SELECT v.*, p.project_no, p.responsible_emp_id, p.follower_emp_id, i.responsible_emp_id AS i_responsible_emp_id, i.follower_emp_id AS i_follower_emp_id
		 FROM ' . ps_table( 'invoices' ) . ' v JOIN ' . ps_table( 'items' ) . ' i ON i.id = v.item_id JOIN ' . ps_table( 'projects' ) . " p ON p.id = v.project_id
		 WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND p.is_project = 1 AND v.answer = 'pending' ORDER BY v.id",
		ARRAY_A
	);
	$by_id = array();
	foreach ( $rows as $inv ) {
		$by_id[ (int) $inv['id'] ] = $inv;
	}

	// 1. Nouveaux essais Telegram des envois échoués (Telegram indisponible au passage précédent).
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $at WHERE status = 'failed' AND channel = 'telegram' AND tries < %d ORDER BY id", PS_ALERT_MAX_TRIES ), ARRAY_A ) as $a ) { // phpcs:ignore
		$inv = $by_id[ (int) $a['invoice_id'] ] ?? null;
		$u   = get_userdata( (int) $a['user_id'] );
		if ( ! $inv || ! $u || '' === ps_tg_chat_id( $u->ID ) ) {
			$wpdb->update( $at, array( 'status' => 'cancelled' ), array( 'id' => (int) $a['id'] ) ); // réponse reçue ou compte délié entre-temps
			continue;
		}
		$err = ps_alert_send_telegram( $u, $a['kind'], $inv, ps_calc_invoice_deadline( $inv['sent_date'], $days ), $a['alert_day'] );
		$wpdb->update( $at, '' === $err ? array( 'status' => 'sent', 'tries' => (int) $a['tries'] + 1, 'sent_at' => ps_now(), 'error' => null ) : array( 'tries' => (int) $a['tries'] + 1, 'error' => mb_substr( $err, 0, 250 ) ), array( 'id' => (int) $a['id'] ) );
		++$report['retried'];
		$report[ '' === $err ? 'sent' : 'failed' ] += 1;
	}

	// 2. Alertes du jour.
	foreach ( $rows as $inv ) {
		$deadline = ps_calc_invoice_deadline( $inv['sent_date'], $days );
		$kind     = ps_calc_invoice_alert_kind( $inv['answer'], $deadline, $today, PS_ALERT_BEFORE_DAYS );
		if ( ! $kind ) {
			continue;
		}
		foreach ( ps_alert_recipients( $inv ) as $u ) {
			// Rappel « avant » : une seule fois en tout ; les autres : une fois par jour (clé unique de la table).
			if ( 'before' === $kind && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $at WHERE user_id = %d AND invoice_id = %d AND kind = 'before'", $u->ID, $inv['id'] ) ) ) { // phpcs:ignore
				continue;
			}
			$tg      = '' !== ps_tg_chat_id( $u->ID ) && ps_tg_enabled();
			$inserted = $wpdb->query(
				$wpdb->prepare( "INSERT IGNORE INTO $at (user_id, invoice_id, kind, alert_day, channel, status, tries, created_at) VALUES (%d, %d, %s, %s, %s, 'pending', 0, %s)", $u->ID, $inv['id'], $kind, $today, $tg ? 'telegram' : 'email', ps_now() ) // phpcs:ignore
			);
			if ( ! $inserted ) {
				++$report['skipped']; // déjà envoyé aujourd'hui à cette personne
				continue;
			}
			$id = (int) $wpdb->insert_id;
			if ( $tg ) {
				$err = ps_alert_send_telegram( $u, $kind, $inv, $deadline, $today );
				if ( '' === $err ) {
					$wpdb->update( $at, array( 'status' => 'sent', 'tries' => 1, 'sent_at' => ps_now() ), array( 'id' => $id ) );
					++$report['sent'];
					continue;
				}
				// Telegram indisponible : échec journalisé + e-mail de secours ; nouvel essai au prochain Cron.
				$mail = ps_alert_send_email( $u, $kind, $inv, $deadline, $today );
				$wpdb->update( $at, array( 'status' => 'failed', 'tries' => 1, 'email_sent' => $mail ? 1 : 0, 'error' => mb_substr( $err, 0, 250 ) ), array( 'id' => $id ) );
				error_log( '[protecta-suivi] alerte Telegram échouée (facture ' . (int) $inv['id'] . ') : ' . $err ); // phpcs:ignore
				++$report['failed'];
				$report['emails'] += $mail ? 1 : 0;
			} else {
				$mail = ps_alert_send_email( $u, $kind, $inv, $deadline, $today );
				$wpdb->update( $at, array( 'status' => $mail ? 'sent' : 'skipped', 'tries' => 1, 'email_sent' => $mail ? 1 : 0, 'sent_at' => $mail ? ps_now() : null, 'error' => $mail ? null : 'no-email' ), array( 'id' => $id ) );
				$report[ $mail ? 'emails' : 'skipped' ] += 1;
			}
		}
	}
	update_option( 'ps_alerts_last_run', ps_now(), false );
	return $report;
}
