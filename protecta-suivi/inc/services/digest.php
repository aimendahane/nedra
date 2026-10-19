<?php
/**
 * E-mail récapitulatif du matin (brief §4.6).
 *
 *   - chaque employé (option « البريد الصباحي » cochée sur son compte) reçoit SES tâches
 *     (mêmes blocs que la page مهامي) ; rien n'est envoyé s'il n'a aucune tâche ;
 *   - la الإدارة reçoit un résumé global (tâches par employé + indicateurs de l'année).
 *   - activation générale dans الإعدادات (« تفعيل البريد الصباحي ») ;
 *   - envoyé une seule fois par jour, déclenché par le Cron Job de cPanel (voir inc/cron.php, README).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Envoie (ou non) les e-mails du jour.
 *
 * @param bool $force Ignorer « déjà envoyé aujourd'hui » et l'activation générale (tests, bouton d'essai).
 * @return array Rapport : [ 'status' => sent|disabled|already, 'sent' => n, 'skipped' => n ]
 */
function ps_digest_run( bool $force = false ): array {
	if ( ! $force && ! ps_setting( 'digest_enabled' ) ) {
		return array( 'status' => 'disabled', 'sent' => 0, 'skipped' => 0 );
	}
	$today = ps_today();
	if ( ! $force && get_option( 'ps_digest_last_run' ) === $today ) {
		return array( 'status' => 'already', 'sent' => 0, 'skipped' => 0 );
	}
	update_option( 'ps_digest_last_run', $today, false );

	$facts = ps_stats_facts();
	$sent  = 0;
	$skip  = 0;
	$users = get_users( array( 'meta_key' => 'ps_daily_email', 'meta_value' => '1' ) );
	foreach ( $users as $u ) {
		if ( get_user_meta( $u->ID, 'ps_disabled', true ) || ! user_can( $u, 'ps_access' ) ) {
			++$skip;
			continue;
		}
		ps_digest_send_to_user( $u, false, $facts ) ? ++$sent : ++$skip;
	}
	return array( 'status' => 'sent', 'sent' => $sent, 'skipped' => $skip );
}

/**
 * Prépare et envoie l'e-mail d'un utilisateur. Renvoie true si un e-mail est parti.
 *
 * @param bool        $force Envoyer même s'il n'y a aucune tâche (bouton « بريد تجريبي »).
 * @param string|null $to    Autre adresse de destination (bouton d'essai) ; défaut : l'e-mail du compte.
 */
function ps_digest_send_to_user( WP_User $u, bool $force = false, ?array $facts = null, ?string $to = null ): bool {
	$to = $to ?? $u->user_email;
	if ( ! is_email( $to ) ) {
		return false;
	}
	$facts = $facts ?? ps_stats_facts();
	$admin = user_can( $u, 'ps_view_recap' );
	$emp   = ps_user_employee_id( (int) $u->ID );
	if ( ! $admin && ! $emp ) {
		return false;
	}
	$tasks = ps_tasks( $facts, $admin ? null : $emp );
	$total = count( $tasks['lateOffers'] ) + count( $tasks['followupsDue'] ) + count( $tasks['deliveries'] ) + count( $tasks['noFollowup'] ) + count( $tasks['assigned'] );
	if ( ! $total && ! $force ) {
		return false;
	}
	$subject = ( $admin ? 'ملخص الفريق' : 'مهامك اليوم' ) . ' — ' . wp_date( 'd/m/Y' ) . ' — متابعة المشاريع';
	$body    = ps_digest_html( $u, $tasks, $admin, $admin ? ps_stats_kpis( array_values( array_filter( $facts, fn( $f ) => (int) current_time( 'Y' ) === $f['year'] ) ) ) : null );
	return true === ps_mail_send( $to, $subject, $body ); // erreur détaillée : ps_mail_last_error()
}

/**
 * Corps HTML (RTL, styles en ligne pour les logiciels de messagerie).
 */
function ps_digest_html( WP_User $u, array $tasks, bool $admin, ?array $kpis ): string {
	$app   = esc_url( home_url( '/' ) );
	$label = fn( $id ) => $id ? esc_html( ps_list_label( $id ) ) : '';
	$row   = static function ( string $text, string $sub, string $hash ) use ( $app ) {
		return '<li style="margin:0 0 8px"><a href="' . $app . $hash . '" style="color:#385B9A;font-weight:bold;text-decoration:none">' . $text . '</a><br><span style="color:#5D6878;font-size:13px">' . $sub . '</span></li>';
	};
	$block = static function ( string $title, array $items, callable $fmt ) {
		if ( ! $items ) {
			return '';
		}
		return '<h3 style="color:#E8571F;margin:20px 0 8px;font-size:16px">' . esc_html( $title ) . ' (' . count( $items ) . ')</h3><ul style="padding-right:18px;margin:0">' . implode( '', array_map( $fmt, array_slice( $items, 0, 30 ) ) ) . '</ul>';
	};
	$who = fn( $r ) => $admin && ! empty( $r['employeeId'] ) ? ' · ' . $label( $r['employeeId'] ) : '';

	$h  = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;color:#1F2733;max-width:640px;margin:auto;border-top:4px solid #E8571F;padding:16px">';
	$h .= '<h2 style="color:#385B9A;margin:0 0 4px">متابعة المشاريع والعروض</h2>';
	$h .= '<p style="margin:0 0 12px">صباح الخير ' . esc_html( $u->display_name ) . '، ' . ( $admin ? 'هذا ملخص الفريق لليوم ' : 'هذه مهامك لليوم ' ) . esc_html( wp_date( 'd/m/Y' ) ) . '.</p>';

	if ( $admin && $kpis ) {
		$h .= '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:8px"><tr>';
		foreach ( array( 'المشاريع' => $kpis['projects'], 'المؤكدة' => $kpis['confirmed'], 'نسبة النجاح' => null === $kpis['successRate'] ? '—' : $kpis['successRate'] . '%', 'عروض متأخرة' => $kpis['lateOffers'], 'تسليمات متأخرة' => $kpis['lateDeliveries'] ) as $k => $v ) {
			$h .= '<td style="border:1px solid #DDE2EC;padding:8px;text-align:center"><div style="color:#5D6878;font-size:12px">' . esc_html( $k ) . '</div><strong style="font-size:18px">' . esc_html( (string) $v ) . '</strong></td>';
		}
		$h .= '</tr></table><p style="color:#5D6878;font-size:12px;margin:0">مؤشرات سنة ' . esc_html( current_time( 'Y' ) ) . '</p>';
	}

	$h .= $block( 'مهام مُسندة', $tasks['assigned'], fn( $r ) => $row( esc_html( $r['title'] ), ( $r['dueDate'] ? 'الأجل ' . esc_html( $r['dueDate'] ) . ( $r['late'] ? ' · متأخرة' : '' ) : 'بدون أجل' ) . ( $r['createdByName'] ? ' · من ' . esc_html( $r['createdByName'] ) : '' ) . $who( $r ), '#/taches' ) );
	$h .= $block( 'عروض متأخرة — لازم قرار', $tasks['lateOffers'], fn( $r ) => $row( esc_html( $r['projectNo'] . ' — ' . ps_list_label( $r['itemTypeId'] ) . ' — ' . $r['projectName'] ), 'الأجل ' . esc_html( $r['dueDate'] ) . ' · متأخر ' . (int) $r['daysLate'] . ' يوم' . $who( $r ), '#/projet?id=' . (int) $r['projectId'] ) );
	$h .= $block( 'متابعات مبرمجة اليوم أو فائتة', $tasks['followupsDue'], fn( $r ) => $row( esc_html( $r['projectNo'] . ' — ' . $r['projectName'] ), 'التاريخ ' . esc_html( $r['nextDate'] ) . $who( $r ), '#/projet?id=' . (int) $r['projectId'] . '&tab=followups' ) );
	$h .= $block( 'تسليمات هذا الأسبوع', $tasks['deliveries'], fn( $r ) => $row( esc_html( $r['projectNo'] . ' — ' . ps_list_label( $r['itemTypeId'] ) ), 'أجل التسليم ' . esc_html( $r['deliveryDueDate'] ) . ( $r['late'] ? ' · متأخر ' . (int) $r['delayDays'] . ' يوم' : '' ) . $who( $r ), '#/livraison' ) );
	$h .= $block( 'مشاريع بدون متابعة منذ أكثر من ' . (int) ps_setting( 'followup_alert_days' ) . ' أيام', $tasks['noFollowup'], fn( $r ) => $row( esc_html( $r['projectNo'] . ' — ' . $r['projectName'] ), $r['lastDate'] ? 'آخر متابعة ' . esc_html( $r['lastDate'] ) : 'لا توجد متابعة' . $who( $r ), '#/projet?id=' . (int) $r['projectId'] . '&tab=followups' ) );

	if ( ! array_sum( array_map( 'count', $tasks ) ) ) {
		$h .= '<p style="color:#2B8A3E">✔ لا توجد مهام متأخرة اليوم.</p>';
	}
	$h .= '<p style="margin-top:24px"><a href="' . $app . '" style="background:#385B9A;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">فتح التطبيق</a></p>';
	$h .= '<p style="color:#868e96;font-size:12px;margin-top:20px">لإيقاف هذا البريد: اطلب من الإدارة إلغاء «البريد الصباحي» في حسابك.</p></div>';
	return $h;
}
