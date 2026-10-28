<?php
/**
 * Version 1.6.0 — brief « Fiche Projet, Offres, Factures et alertes Telegram » (§5 : tests demandés).
 *   - « مشروع؟ = لا » : exclu des offres, des KPI d'offres et de نسبة النجاح ; présent dans Base Projets (filtre) ;
 *   - آخر أجل للرد = date d'envoi + 15 j ; changer N recalcule sans toucher aux dates d'envoi ;
 *   - plusieurs factures pour une offre, chacune son suivi ;
 *   - المتابع d'une offre : changement journalisé avec justification, refusé sans droit ;
 *   - liaison Telegram : code valable, code expiré refusé, déliaison ;
 *   - envois simulés : J-3, jour J, retard une fois par jour, pas de doublon ;
 *   - Telegram indisponible : échec journalisé, e-mail de secours, nouvel essai au Cron suivant ;
 *   - token (wp-config.php) absent de l'export JSON et du HTML ;
 *   - fichier joint dès la création du projet.
 * Telegram est SIMULÉ (pre_http_request) et les e-mails interceptés (pre_wp_mail) : rien ne sort de la machine.
 */

global $wpdb;

// Token défini « dans wp-config.php » (1.6.0) — jamais affiché nulle part.
if ( ! defined( 'PS_TELEGRAM_TOKEN' ) ) {
	define( 'PS_TELEGRAM_TOKEN', '424242:SecretTokenFromWpConfig_xyz0123456789AB' );
}
remove_all_filters( 'pre_http_request' );
remove_all_filters( 'pre_wp_mail' );
$tg = array( 'calls' => array(), 'updates' => array(), 'down' => false );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$tg ) {
		if ( ! preg_match( '#^https://api\.telegram\.org/bot([^/]+)/(\w+)$#', $url, $m ) ) {
			return $pre;
		}
		if ( $tg['down'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ); // Telegram indisponible
		}
		$params        = json_decode( (string) $args['body'], true ) ?: array();
		$tg['calls'][] = array( 'token' => $m[1], 'method' => $m[2], 'params' => $params );
		$ok            = static fn( $r ) => array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'ok' => true, 'result' => $r ) ), 'headers' => array(), 'cookies' => array() );
		if ( 'getMe' === $m[2] ) {
			return $ok( array( 'id' => 1, 'is_bot' => true, 'username' => 'ProtectaAlertsBot' ) );
		}
		if ( 'getUpdates' === $m[2] ) {
			$u             = $tg['updates'];
			$tg['updates'] = array();
			return $ok( $u );
		}
		return $ok( array( 'message_id' => count( $tg['calls'] ) ) );
	},
	10,
	3
);
$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$tg_msgs = function ( $chat = null ) use ( &$tg ) {
	return array_values( array_filter( $tg['calls'], fn( $c ) => 'sendMessage' === $c['method'] && ( null === $chat || (string) $c['params']['chat_id'] === (string) $chat ) ) );
};
$upd = 5000;
$say = function ( $chat, $text ) use ( &$tg, &$upd ) {
	$tg['updates'][] = array( 'update_id' => ++$upd, 'message' => array( 'chat' => array( 'id' => $chat, 'type' => 'private' ), 'text' => $text ) );
};

ps_test_reset_data();
$adm  = ps_test_user( 't16_adm', 'ps_admin' );
$mgr  = ps_test_user( 't16_mgr', 'ps_manager', 'وليد جدير' );   // المسؤول عن المشروع
$fol  = ps_test_user( 't16_fol', 'ps_employee', 'محمد حسين' );  // الموظف المتابع
$emp  = ps_test_user( 't16_emp', 'ps_employee', 'هاشم الخراز' ); // autre employé
$other_mgr = ps_test_user( 't16_mgr2', 'ps_manager', 'بدر ناجي' );
$stranger  = ps_test_user( 't16_x', 'ps_employee', 'غفران دريدي' ); // aucun lien avec les offres testées
foreach ( array( $adm, $mgr, $fol, $emp ) as $u ) {
	wp_update_user( array( 'ID' => $u, 'user_email' => get_userdata( $u )->user_login . '@example.com' ) );
}

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : migration 7 (ajouts seulement)' );

foreach ( array( 'is_project', 'non_project_reason_id', 'non_project_note', 'project_note' ) as $col ) {
	ps_assert( (bool) $wpdb->get_var( "SHOW COLUMNS FROM " . ps_table( 'projects' ) . " LIKE '$col'" ), "ps_projects.$col ajoutée" );
}
foreach ( array( 'responsible_emp_id', 'follower_emp_id' ) as $col ) {
	ps_assert( (bool) $wpdb->get_var( "SHOW COLUMNS FROM " . ps_table( 'items' ) . " LIKE '$col'" ), "ps_items.$col ajoutée (المسؤول / المتابع de l'offre)" );
}
ps_assert( $wpdb->get_var( "SHOW TABLES LIKE '" . ps_table( 'invoices' ) . "'" ) && $wpdb->get_var( "SHOW TABLES LIKE '" . ps_table( 'alerts' ) . "'" ), 'tables ps_invoices et ps_alerts créées' );
ps_assert( 7 <= (int) get_option( 'ps_db_version' ), 'ps_db_version ≥ 7' );
$reasons = array_column( ps_lists_all()['non_project_reason'] ?? array(), 'label' );
ps_assert( array( 'غير مؤكد', 'خارج الاختصاص', 'معلومات غير كافية', 'أخرى' ) === $reasons, 'liste « سبب عدم اعتباره مشروعا » : valeurs par défaut', implode( ', ', $reasons ) );
ps_assert( 15 === (int) ps_setting( 'invoice_reply_days' ) && true === (bool) ps_setting( 'alerts_enabled' ), 'réglages : délai de réponse 15 j, « تفعيل التنبيهات » activé' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : « مشروع؟ » obligatoire, « لا » → سبب obligatoire' );

wp_set_current_user( $adm );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => '' ) ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['is_project'] ), '« مشروع؟ » vide → refusé (422)' );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 0 ) ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['non_project_reason_id'] ), '« لا » sans سبب → refusé (422)' );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 0, 'nonProjectReasonId' => ps_test_lid( 'source', 'direct' ) ) ) );
ps_assert( 422 === $r->get_status(), 'سبب hors de la bonne liste → refusé' );

// Deux projets « نعم » (un مؤكد) et un projet « لا » mais « مؤكد » + offre en retard : il ne doit compter nulle part.
$confirmed = ps_test_lid( 'followup_status', 'تم تأكيد الطلبية' );
$p_yes     = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => 'مشروع نعم 1', 'projectNote' => 'ملاحظة داخلية', 'note' => '' ) ) )->get_data();
$p_yes2    = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => true, 'name' => 'مشروع نعم 2', 'confirmDuplicates' => true ) ) )->get_data();
$r         = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 0, 'nonProjectReasonId' => ps_test_lid( 'non_project_reason', 'خارج الاختصاص' ), 'nonProjectNote' => 'طلب أسعار فقط', 'name' => 'طلب غير مشروع', 'confirmDuplicates' => true, 'items' => array( array( 'dueDate' => '2026-09-01' ) ) ) ) );
ps_assert_same( 201, $r->get_status(), '« لا » + سبب → enregistré', wp_json_encode( $r->get_data() ) );
$p_no = $r->get_data();
ps_assert( false === $p_no['isProject'] && 'طلب أسعار فقط' === $p_no['nonProjectNote'] && true === $p_yes['isProject'] && 'ملاحظة داخلية' === $p_yes['projectNote'] && null === $p_yes['note'], 'API : isProject, سبب, ملاحظة المشروع (distincte de NOTE, facultative)' );
$put = function ( $p, $over ) {
	$body = ps_test_payload( array( 'offerNo' => $p['offerNo'], 'name' => $p['name'], 'confirmDuplicates' => true ) );
	$body['items'] = array_map( fn( $i ) => array( 'id' => $i['id'], 'itemTypeId' => $i['itemTypeId'], 'quantity' => (string) $i['quantity'], 'employeeId' => $i['employeeId'], 'dueDate' => $i['dueDate'] ), $p['items'] );
	return ps_test_json( 'PUT', '/projects/' . $p['id'], array_replace( $body, $over ) );
};
ps_assert_same( 200, $put( $p_yes, array( 'isProject' => 1, 'followupStatusId' => $confirmed, 'sentDate' => '2026-10-02' ) )->get_status(), 'projet نعم → مؤكد' );
ps_assert_same( 200, $put( $p_no, array( 'isProject' => 0, 'nonProjectReasonId' => $p_no['nonProjectReasonId'], 'followupStatusId' => $confirmed, 'sentDate' => '2026-10-02' ) )->get_status(), 'projet لا → مؤكد aussi (ne doit pas compter)' );

$facts = ps_stats_facts( array( 'year' => 2026 ), '2026-10-20' );
$k     = ps_stats_kpis( $facts );
ps_assert( 2 === $k['projects'] && 1 === $k['nonProjects'] && 1 === $k['confirmed'], 'KPI : 2 projets, 1 « غير مشروع » compté à part, 1 مؤكد (pas celui du « لا »)', wp_json_encode( $k ) );
ps_assert( 1 === $k['sent'] && 100.0 === $k['successRate'], 'نسبة النجاح : le « غير مشروع » n\'y est pas (1 ÷ 1)' );
ps_assert( 0 === $k['lateOffers'] || ! in_array( $p_no['id'], array_column( array_filter( $facts, fn( $f ) => array_filter( $f['items'], fn( $i ) => $i['late'] ) ), 'id' ), true ), 'offre en retard du « غير مشروع » ignorée' );
$rec = ps_recap( array( 'year' => 2026 ), '2026-10-20' );
ps_assert( 1 === $rec['kpis']['nonProjects'] && 2 === array_sum( array_column( $rec['byMonth'], 'total' ) ), 'Recap : compteur « غير مشروع » séparé, exclu des répartitions' );
$offers = ps_items_list( array( 'view' => 'offers' ), $adm );
ps_assert( ! in_array( $p_no['id'], array_column( $offers['rows'], 'projectId' ), true ) && in_array( $p_yes['id'], array_column( $offers['rows'], 'projectId' ), true ), 'Suivi Offres : le « غير مشروع » n\'apparaît pas' );
$tasks = ps_tasks( ps_stats_facts( array(), '2026-10-20' ), null, '2026-10-20' );
ps_assert( ! in_array( $p_no['id'], array_column( $tasks['lateOffers'], 'projectId' ), true ), 'مهامي : pas de « لازم قرار » pour le « غير مشروع »' );
$list = ps_test_json( 'GET', '/projects?isProject=0' )->get_data();
ps_assert( 1 === $list['total'] && $p_no['id'] === $list['rows'][0]['id'] && false === $list['rows'][0]['isProject'], 'Base Projets : filtre « غير مشروع » → ce projet seulement' );
ps_assert( 3 === ps_test_json( 'GET', '/projects' )->get_data()['total'] && 2 === ps_test_json( 'GET', '/projects?isProject=1' )->get_data()['total'], 'Base Projets : tous visibles (3), filtre « مشروع » → 2' );
$r = ps_test_json( 'POST', '/items/' . $p_no['items'][0]['id'] . '/invoices', array( 'invoiceNo' => 'F-NO-1', 'sentDate' => '2026-10-01' ) );
ps_assert_same( 422, $r->get_status(), 'pas de facture sur un « غير مشروع »' );
// Repasser à « نعم » efface le سبب (gardé au journal).
ps_assert_same( 200, $put( $p_no, array( 'isProject' => 1, 'followupStatusId' => $confirmed, 'sentDate' => '2026-10-02' ) )->get_status(), '« لا » → « نعم »' );
$row = ps_project_row( $p_no['id'] );
ps_assert( 1 === (int) $row['is_project'] && null === $row['non_project_reason_id'], 'سبب effacé quand le projet redevient « نعم »' );
ps_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'changelog' ) . " WHERE project_id = %d AND field = 'is_project' AND old_value = 'لا' AND new_value = 'نعم'", $p_no['id'] ) ), 'journal : « مشروع؟ » لا → نعم' );
// Projet sans « isProject » (ancienne interface / import) → « نعم » par défaut, rien n'est cassé.
$r = ps_test_json( 'POST', '/projects', array_diff_key( ps_test_payload( array( 'name' => 'بدون حقل مشروع', 'confirmDuplicates' => true ) ), array( 'isProject' => 1 ) ) );
ps_assert( 201 === $r->get_status() && true === $r->get_data()['isProject'], 'champ absent (ancienne interface, import) → « نعم » par défaut' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : coordonnées des sociétés venant de l\'annuaire' );

$co = ps_dir_find_or_create( 'companies', 'شركة الدليل', array( 'person' => 'رمزي', 'phone' => '0911111111' ), $adm );
$r  = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => 'مشروع الدليل', 'confirmDuplicates' => true, 'parties' => array( 'owner' => array( 'companyId' => $co, 'companyName' => 'شركة الدليل', 'person' => 'شخص آخر', 'phone' => '0922222222', 'email' => 'x@example.com' ) ) ) ) );
$pd = $r->get_data();
ps_assert( 201 === $r->get_status() && 'رمزي' === $pd['parties']['owner']['person'] && '0911111111' === $pd['parties']['owner']['phone'], 'الشخص المسؤول / الهاتف : ceux de la fiche société (pas ressaisis par projet)', wp_json_encode( $pd['parties']['owner'], JSON_UNESCAPED_UNICODE ) );
ps_assert( in_array( 'person', $pd['parties']['owner']['fromDirectory'], true ) && in_array( 'phone', $pd['parties']['owner']['fromDirectory'], true ), 'API : champs venant de l\'annuaire signalés (fromDirectory)', wp_json_encode( $pd['parties']['owner']['fromDirectory'] ) );
ps_assert( 'x@example.com' === ps_dir_get( 'companies', $co )['email'] && 'رمزي' === ps_dir_get( 'companies', $co )['person'], 'annuaire : champ vide complété, rien n\'est écrasé' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : المسؤول / المتابع de l\'offre (justification, droits)' );

$p_yes = ps_test_json( 'GET', '/projects/' . $p_yes['id'] )->get_data();
$item  = $p_yes['items'][0];
$new_f = ps_test_lid( 'employee', 'هاشم الخراز' );
$row   = fn() => array_values( array_filter( ps_items_list( array( 'view' => 'offers', 'projectId' => $p_yes['id'] ), $adm )['rows'], fn( $x ) => $x['id'] === $item['id'] ) )[0];
ps_assert( $row()['followerEmpId'] === ps_test_lid( 'employee', 'محمد حسين' ) && $row()['responsibleEmpId'] === ps_test_lid( 'employee', 'وليد جدير' ), 'offre : المسؤول / المتابع pré-remplis depuis le projet' );
wp_set_current_user( $fol ); // موظف du صنف : peut modifier l'offre, pas l'affectation
ps_assert_same( 403, ps_test_json( 'PUT', '/items/' . $item['id'], array( 'followerEmpId' => $new_f, 'reason' => 'x' ) )->get_status(), 'موظف : changer le المتابع → refusé (403)' );
wp_set_current_user( $other_mgr );
ps_assert_same( 403, ps_test_json( 'PUT', '/items/' . $item['id'], array( 'followerEmpId' => $new_f, 'reason' => 'x' ) )->get_status(), 'مسؤول d\'un autre projet → refusé' );
wp_set_current_user( $mgr );
$r = ps_test_json( 'PUT', '/items/' . $item['id'], array( 'followerEmpId' => $new_f ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['reason'] ), 'sans justification → refusé (422)' );
$r = ps_test_json( 'PUT', '/items/' . $item['id'], array( 'followerEmpId' => $new_f, 'reason' => 'محمد في إجازة' ) );
ps_assert( 200 === $r->get_status() && $new_f === $row()['followerEmpId'] && true === $row()['assignOverride'], 'مسؤول المشروع + justification → enregistré au niveau de l\'offre' );
$log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'changelog' ) . " WHERE entity = 'item' AND entity_id = %d AND field = 'follower_emp_id' ORDER BY id DESC LIMIT 1", $item['id'] ), ARRAY_A );
ps_assert( $log && (int) $log['user_id'] === $mgr && 'محمد حسين' === $log['old_value'] && 'هاشم الخراز' === $log['new_value'] && 'محمد في إجازة' === $log['reason'] && $log['created_at'], 'journal : qui, quand, ancienne → nouvelle valeur, justification', wp_json_encode( $log, JSON_UNESCAPED_UNICODE ) );
ps_assert( 'محمد في إجازة' === ( array_values( array_filter( ps_test_json( 'GET', '/projects/' . $p_yes['id'] . '/changelog' )->get_data(), fn( $l ) => 'follower_emp_id' === $l['field'] ) )[0]['reason'] ?? '' ), 'سجل التعديلات (API) : justification renvoyée' );
ps_assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT follower_emp_id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $p_yes['id'] ) ) === ps_test_lid( 'employee', 'محمد حسين' ), 'المتابع du PROJET inchangé' );
wp_set_current_user( $adm );
ps_assert_same( 200, ps_test_json( 'PUT', '/items/' . $item['id'], array( 'responsibleEmpId' => ps_test_lid( 'employee', 'بدر ناجي' ), 'reason' => 'إعادة توزيع' ) )->get_status(), 'الإدارة : changer المسؤول de l\'offre' );
// رقم فاتورة العرض unique.
ps_assert_same( 200, ps_test_json( 'PUT', '/items/' . $item['id'], array( 'offerInvoiceNo' => 'PF-2026-01', 'offerNotes' => 'خصم 5%' ) )->get_status(), 'رقم فاتورة العرض + ملاحظة العرض' );
$item2 = ps_test_json( 'GET', '/projects/' . $p_yes2['id'] )->get_data()['items'][0];
$r     = ps_test_json( 'PUT', '/items/' . $item2['id'], array( 'offerInvoiceNo' => 'PF-2026-01' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['offerInvoiceNo'] ), 'رقم فاتورة العرض déjà pris → refusé (unique)' );
// Problème n°01 : تاريخ تقديم العرض = date d'envoi du PROJET (et non la date du jour) quand le صنف passe à مرسل.
ps_test_json( 'PUT', '/items/' . $item['id'], array( 'offerStatusId' => ps_list_id_by_code( 'offer_status', 'sent' ) ) );
ps_assert_same( '2026-10-02', $row()['offerDate'], 'passage à مرسل : تاريخ تقديم العرض = تاريخ إرسال العرض du projet (n°01)' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : factures (échéance calculée, plusieurs par offre)' );

ps_assert_same( '2026-10-16', ps_calc_invoice_deadline( '2026-10-01', 15 ), 'calcul : 01/10 + 15 j = 16/10' );
ps_assert_same( '2026-03-02', ps_calc_invoice_deadline( '2026-02-15', 15 ), 'calcul : passage de mois (15/02 + 15 = 02/03)' );
ps_assert( false === ps_calc_invoice_late( 'pending', '2026-10-16', '2026-10-16' ) && true === ps_calc_invoice_late( 'pending', '2026-10-16', '2026-10-17' ) && false === ps_calc_invoice_late( 'yes', '2026-10-16', '2026-10-30' ), 'retard : jour J non ; J+1 oui ; réponse reçue jamais' );
ps_assert( 'before' === ps_calc_invoice_alert_kind( 'pending', '2026-10-16', '2026-10-13' ) && null === ps_calc_invoice_alert_kind( 'pending', '2026-10-16', '2026-10-12' ) && 'due' === ps_calc_invoice_alert_kind( 'pending', '2026-10-16', '2026-10-16' ) && 'late' === ps_calc_invoice_alert_kind( 'pending', '2026-10-16', '2026-10-20' ) && null === ps_calc_invoice_alert_kind( 'no', '2026-10-16', '2026-10-20' ), 'type d\'alerte : J-3, jour J, retard ; rien si répondu' );

wp_set_current_user( $stranger ); // ni موظف du صنف, ni متابع, ni مسؤول
ps_assert_same( 403, ps_test_json( 'POST', '/items/' . $item['id'] . '/invoices', array( 'invoiceNo' => 'F-X', 'sentDate' => '2026-10-01' ) )->get_status(), 'facture : refusée à un employé sans lien avec l\'offre' );
wp_set_current_user( $emp ); // المتابع de l'offre (affecté plus haut)
$r = ps_test_json( 'POST', '/items/' . $item['id'] . '/invoices', array( 'invoiceNo' => 'F-2026-001', 'sentDate' => '2026-10-01', 'amount' => '5000', 'note' => 'الدفعة الأولى' ) );
ps_assert( 200 === $r->get_status(), 'le الموظف المتابع de l\'offre ajoute une facture', wp_json_encode( $r->get_data(), JSON_UNESCAPED_UNICODE ) );
$f1 = $r->get_data();
ps_assert( 'pending' === $f1['answer'] && '2026-10-16' === $f1['computed']['deadline'] && $f1['currencyId'] === ps_list_id_by_code( 'currency', 'LYD' ), 'défauts : جواب = في الانتظار, آخر أجل = +15 j, devise LYD' );
wp_set_current_user( $mgr );
$f2 = ps_test_json( 'POST', '/items/' . $item['id'] . '/invoices', array( 'invoiceNo' => 'F-2026-002', 'sentDate' => '2026-10-10', 'amount' => '300', 'currencyId' => ps_list_id_by_code( 'currency', 'USD' ) ) )->get_data();
$r  = ps_test_json( 'POST', '/items/' . $item2['id'] . '/invoices', array( 'invoiceNo' => 'F-2026-001', 'sentDate' => '2026-10-05' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['invoiceNo'] ), 'رقم الفاتورة unique dans tout le système' );
$r = ps_test_json( 'POST', '/items/' . $item2['id'] . '/invoices', array( 'invoiceNo' => 'F-2026-003' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['sentDate'] ), 'تاريخ إرسال الفاتورة obligatoire' );
ps_assert( 422 === ps_test_json( 'POST', '/items/' . $item2['id'] . '/invoices', array( 'invoiceNo' => 'F-2026-004', 'sentDate' => '2026-10-05', 'answer' => 'maybe' ) )->get_status(), 'جواب الحريف : liste fermée' );
$list = ps_test_json( 'GET', '/invoices?status=all&itemId=' . $item['id'] )->get_data();
ps_assert( 2 === $list['total'], 'deux factures sur la même offre' );
ps_assert_same( 200, ps_test_json( 'PUT', '/invoices/' . $f1['id'], array( 'answer' => 'yes' ) )->get_status(), 'facture 1 : جواب = نعم' );
$one = fn( $id ) => ps_invoices_list( array( 'id' => $id ), $adm, '2026-10-30' )['rows'][0];
ps_assert( 'yes' === $one( $f1['id'] )['answer'] && false === $one( $f1['id'] )['computed']['late'] && 'pending' === $one( $f2['id'] )['answer'] && true === $one( $f2['id'] )['computed']['late'] && 5 === $one( $f2['id'] )['computed']['daysLate'], 'chaque facture a son propre suivi (1 répondue, 2 en retard de 5 j au 30/10)' );
ps_assert( null !== $one( $f1['id'] )['answerDate'], 'date de la réponse retenue' );

// 1.11.0 : آخر أجل saisi (proposé +N) → changer N ne touche pas aux factures enregistrées ; une ANCIENNE facture
// (sans échéance saisie) garde le calcul d'avant (date d'envoi + N).
$settings = get_option( 'ps_settings' );
$settings['invoice_reply_days'] = 20;
update_option( 'ps_settings', $settings );
ps_assert( '2026-10-25' === $one( $f2['id'] )['computed']['deadline'] && '2026-10-10' === $wpdb->get_var( $wpdb->prepare( 'SELECT sent_date FROM ' . ps_table( 'invoices' ) . ' WHERE id = %d', $f2['id'] ) ), 'N = 20 : échéance saisie inchangée (25/10), date d\'envoi inchangée (10/10)' );
$wpdb->update( ps_table( 'invoices' ), array( 'reply_deadline' => null ), array( 'id' => $f2['id'] ) );
ps_assert_same( '2026-10-30', $one( $f2['id'] )['computed']['deadline'], 'ancienne facture (sans échéance saisie) : calculée = envoi + N (30/10)' );
$wpdb->update( ps_table( 'invoices' ), array( 'reply_deadline' => '2026-10-25' ), array( 'id' => $f2['id'] ) );
$settings['invoice_reply_days'] = 15;
update_option( 'ps_settings', $settings );

// Recap : factures en attente, en retard, taux نعم / لا, montants par devise.
$st = ps_invoice_stats( array( $p_yes['id'] ), '2026-10-30' );
ps_assert( 2 === $st['total'] && 1 === $st['pending'] && 1 === $st['late'] && 1 === $st['yes'] && 100.0 === $st['rates']['yes'] && 0.0 === $st['rates']['no'], 'Recap factures : 1 en attente, 1 en retard, taux نعم 100 %', wp_json_encode( $st ) );
ps_assert( 5000.0 === $st['amounts'][ ps_list_id_by_code( 'currency', 'LYD' ) ] && 300.0 === $st['amounts'][ ps_list_id_by_code( 'currency', 'USD' ) ], 'montants séparés par devise (jamais additionnés)' );
// Fichier joint de la facture.
$tmp = wp_tempnam( 'inv' );
file_put_contents( $tmp, "%PDF-1.4\n%%EOF" );
$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $p_yes['id'] . '/files' );
$req->set_param( 'invoiceId', $f2['id'] );
$req->set_file_params( array( 'file' => array( 'name' => 'facture.pdf', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ) ) );
$fr = rest_get_server()->dispatch( $req );
ps_assert( 200 === $fr->get_status() && $f2['id'] === $fr->get_data()['invoiceId'] && null === $fr->get_data()['itemId'], 'fichier joint à la facture (facture de l\'offre entière : pas de صنف)' );
ps_assert( 1 === count( ps_test_json( 'GET', '/invoices/' . $f2['id'] )->get_data()['files'] ), 'fiche facture : son fichier' );
wp_set_current_user( $emp );
ps_assert_same( 403, ps_test_json( 'DELETE', '/invoices/' . $f2['id'] )->get_status(), 'retirer une facture : refusé au موظف (même المتابع)' );
wp_set_current_user( $adm );
ps_test_json( 'PUT', '/invoices/' . $f2['id'], array( 'answer' => 'pending' ) );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : liaison Telegram (code court 10 min, déliaison)' );

$opts = get_option( 'ps_settings' );
$opts['telegram_enabled'] = true;
update_option( 'ps_settings', $opts );
delete_option( 'ps_telegram_bot_for' );
ps_assert( 'config' === ps_tg_token_source() && 'ProtectaAlertsBot' === ps_tg_bot() && ps_tg_enabled(), 'token lu dans wp-config.php (PS_TELEGRAM_TOKEN), nom du bot demandé à Telegram' );
wp_set_current_user( $emp );
$link = ps_test_json( 'POST', '/me/telegram/link' )->get_data();
ps_assert( (bool) preg_match( '/^[A-Z2-9]{8}$/', $link['code'] ) && 10 === $link['minutes'] && str_ends_with( $link['url'], '?start=' . $link['code'] ), 'code de liaison : 8 caractères, valable 10 minutes', wp_json_encode( $link ) );
$say( 3001, '/start ' . strtolower( $link['code'] ) ); // casse indifférente
ps_assert( true === ps_test_json( 'POST', '/me/telegram/check' )->get_data()['linked'] && '3001' === ps_tg_chat_id( $emp ), 'code valable → compte lié' );
wp_set_current_user( $mgr );
$link = ps_test_json( 'POST', '/me/telegram/link' )->get_data();
update_user_meta( $mgr, 'ps_tg_code', $link['code'] . '|' . ( time() - 1 ) ); // 10 minutes passées
$say( 3002, '/start ' . $link['code'] );
ps_tg_poll();
ps_assert( '' === ps_tg_chat_id( $mgr ) && str_contains( (string) end( $tg['calls'] )['params']['text'], '10' ), 'code expiré → refusé (message « صالح 10 دقائق »)' );
$link = ps_test_json( 'POST', '/me/telegram/link' )->get_data();
$say( 3002, '/start ' . $link['code'] );
ps_tg_poll();
ps_assert_same( '3002', ps_tg_chat_id( $mgr ), 'nouveau code → lié' );
$users = ps_test_json( 'GET', '/users' );
wp_set_current_user( $adm );
$users = ps_test_json( 'GET', '/users' )->get_data()['users'];
$by    = array_column( $users, 'telegram', 'id' );
ps_assert( true === $by[ $emp ] && false === $by[ $fol ], 'page des utilisateurs : « مربوط بتيليغرام / غير مربوط »' );
wp_set_current_user( $fol );
ps_assert( false === ps_test_json( 'GET', '/me/telegram' )->get_data()['linked'], 'statut dans les réglages de l\'utilisateur : غير مربوط' );
wp_set_current_user( $mgr );
ps_assert( false === ps_test_json( 'POST', '/me/telegram/unlink' )->get_data()['linked'] && '' === ps_tg_chat_id( $mgr ), 'déliaison à tout moment' );
$link = ps_test_json( 'POST', '/me/telegram/link' )->get_data();
$say( 3002, '/start ' . $link['code'] );
ps_tg_poll();

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : alertes des factures (J-3, jour J, retard, sans doublon)' );

// Facture 2 : envoyée le 10/10 → آخر أجل 25/10. 1.11.0 : la facture suit l'offre ENTIÈRE → destinataire = le
// المتابع du PROJET ; on le règle sur « هاشم الخراز » ($emp, lié à Telegram 3001).
$p_fol = $wpdb->get_var( $wpdb->prepare( 'SELECT follower_emp_id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $p_yes['id'] ) );
$wpdb->update( ps_table( 'projects' ), array( 'follower_emp_id' => ps_test_lid( 'employee', 'هاشم الخراز' ) ), array( 'id' => $p_yes['id'] ) );
$tg['calls'] = array();
$mails       = array();
$run         = fn( $day ) => ps_alerts_run( $day );
$a           = $run( '2026-10-21' );
ps_assert( 0 === $a['sent'] + $a['emails'], '21/10 (J-4) : rien' );
$a = $run( '2026-10-22' );
ps_assert( 1 === $a['sent'] && 1 === count( $tg_msgs( 3001 ) ), '22/10 (J-3) : rappel envoyé au المتابع sur Telegram' );
$msg = $tg_msgs( 3001 )[0]['params']['text'];
ps_assert( str_contains( $msg, 'F-2026-002' ) && str_contains( $msg, $p_yes['projectNo'] ) && str_contains( $msg, '25/10/2026' ) && str_contains( $msg, '#/factures/' . $f2['id'] ), 'message : n° facture, n° projet, date limite, lien #/factures/ID', $msg );
ps_assert( ! str_contains( $msg, 'شركة' ) && ! str_contains( $msg, '300' ) && ! str_contains( $msg, $p_yes['name'] ), 'message : ni client, ni montant, ni nom du projet' );
$a = $run( '2026-10-22' );
ps_assert( 0 === $a['sent'] && 1 === count( $tg_msgs( 3001 ) ), 'Cron relancé le même jour : aucun doublon' );
$run( '2026-10-23' );
ps_assert( 1 === count( $tg_msgs( 3001 ) ), '23/10 (J-2) : le rappel n\'est pas répété' );
$run( '2026-10-25' );
ps_assert( 2 === count( $tg_msgs( 3001 ) ) && str_contains( $tg_msgs( 3001 )[1]['params']['text'], '📅' ), '25/10 : alerte « jour de l\'échéance »' );
$run( '2026-10-26' );
$run( '2026-10-26' );
$run( '2026-10-27' );
ps_assert( 2 === count( $tg_msgs( 3001 ) ), '1.11.0 : après آخر أجل, pas de rappel chaque jour (26/10, 27/10 : rien)' );
ps_assert_same( 'before,due', implode( ',', $wpdb->get_col( $wpdb->prepare( 'SELECT kind FROM ' . ps_table( 'alerts' ) . ' WHERE invoice_id = %d AND user_id = %d ORDER BY alert_day', $f2['id'], $emp ) ) ), 'envois enregistrés dans ps_alerts (un par type et par jour)' );
// Réponse enregistrée → plus d'alerte.
ps_test_json( 'PUT', '/invoices/' . $f2['id'], array( 'answer' => 'no' ) );
$run( '2026-10-28' );
ps_assert( 2 === count( $tg_msgs( 3001 ) ), 'réponse « لا » enregistrée → plus d\'alerte' );
ps_test_json( 'PUT', '/invoices/' . $f2['id'], array( 'answer' => 'pending' ) );
// Interrupteur global.
$opts                   = get_option( 'ps_settings' );
$opts['alerts_enabled'] = false;
update_option( 'ps_settings', $opts );
ps_assert( 'off' === $run( '2026-10-29' )['status'] && 2 === count( $tg_msgs( 3001 ) ), '« تفعيل التنبيهات » désactivé → rien n\'est envoyé' );
$opts['alerts_enabled'] = true;
update_option( 'ps_settings', $opts );
// Destinataire de secours : المسؤول du projet quand le المتابع n'a pas de compte ; sans Telegram → e-mail.
$wpdb->insert( ps_table( 'lists' ), array( 'list_key' => 'employee', 'label' => 'موظف بلا حساب', 'sort_order' => 999, 'created_at' => ps_now(), 'updated_at' => ps_now() ) );
$p_resp = $wpdb->get_var( $wpdb->prepare( 'SELECT responsible_emp_id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $p_yes['id'] ) );
$wpdb->update( ps_table( 'projects' ), array( 'follower_emp_id' => (int) $wpdb->insert_id, 'responsible_emp_id' => ps_test_lid( 'employee', 'بدر ناجي' ) ), array( 'id' => $p_yes['id'] ) );
wp_update_user( array( 'ID' => $other_mgr, 'user_email' => 't16_mgr2@example.com' ) ); // بدر ناجي, non lié à Telegram
$mails = array();
$run( '2026-10-30' );
ps_assert( 1 === count( array_filter( $mails, fn( $m ) => 't16_mgr2@example.com' === $m['to'] && str_contains( $m['message'], 'F-2026-002' ) ) ), 'المتابع sans compte → المسؤول (du projet) prévenu, par e-mail faute de Telegram' );
$wpdb->update( ps_table( 'projects' ), array( 'follower_emp_id' => ps_test_lid( 'employee', 'هاشم الخراز' ), 'responsible_emp_id' => $p_resp ), array( 'id' => $p_yes['id'] ) );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : Telegram indisponible (échec journalisé, e-mail de secours, nouvel essai)' );

$tg['down'] = true;
$mails      = array();
$before     = count( $tg_msgs( 3001 ) );
// 1.11.0 : rappel « retard » 15 jours après آخر أجل (25/10 + 15 = 09/11).
ps_assert( 0 === $run( '2026-11-08' )['sent'] + $run( '2026-11-08' )['failed'], '08/11 : pas encore de rappel (14 jours après آخر أجل)' );
$a          = $run( '2026-11-09' );
$fail       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'alerts' ) . " WHERE invoice_id = %d AND user_id = %d AND alert_day = '2026-11-09'", $f2['id'], $emp ), ARRAY_A );
ps_assert( 1 === $a['failed'] && 'failed' === $fail['status'] && str_contains( (string) $fail['error'], 'timed out' ), 'Telegram indisponible : échec journalisé (status failed + erreur)', wp_json_encode( $fail ) );
ps_assert( 1 === (int) $fail['email_sent'] && 1 === count( array_filter( $mails, fn( $m ) => 't16_emp@example.com' === $m['to'] && str_contains( $m['message'], 'F-2026-002' ) ) ), 'e-mail de secours envoyé à l\'utilisateur' );
$tg['down'] = false;
$a          = $run( '2026-11-09' ); // Cron suivant
$fail       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'alerts' ) . ' WHERE id = %d', $fail['id'] ), ARRAY_A );
ps_assert( 1 === $a['retried'] && 'sent' === $fail['status'] && 2 === (int) $fail['tries'] && $before + 1 === count( $tg_msgs( 3001 ) ), 'Cron suivant : nouvel essai réussi, rien de perdu (et pas de doublon)' );
ps_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'invoices' ) . ' WHERE id = %d', $f2['id'] ) ), 'aucune donnée perdue (facture intacte)' );
ps_assert( 'late' === $fail['kind'], '09/11 = 15 jours après آخر أجل : rappel « retard » (réglage تذكير كل 15 يوم)' );
$wpdb->update( ps_table( 'projects' ), array( 'follower_emp_id' => $p_fol ), array( 'id' => $p_yes['id'] ) );
ps_assert( str_contains( ps_cron_run(), 'alerts=ok' ), 'Cron : les alertes font partie de la tâche du matin' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : le token n\'apparaît nulle part' );

wp_set_current_user( $adm );
$secret = 'SecretTokenFromWpConfig';
ps_assert( ! str_contains( wp_json_encode( ps_backup_build() ), $secret ), 'absent de l\'export JSON (sauvegarde)' );
ps_assert( ! str_contains( wp_json_encode( ps_test_json( 'GET', '/settings' )->get_data() ), $secret ) && 'config' === ps_test_json( 'GET', '/settings' )->get_data()['telegramTokenSource'], 'absent de l\'API des réglages (seule la source « config » est indiquée)' );
wp_set_current_user( $adm );
wp_enqueue_scripts();
ob_start();
wp_print_scripts();
wp_print_footer_scripts();
include PS_DIR . '/templates/app-shell.php';
$page = ob_get_clean();
ps_assert( strlen( $page ) > 1000 && str_contains( $page, 'PS.config' ) && ! str_contains( $page, $secret ) && ! str_contains( $page, '424242' ), 'absent du HTML de la page (configuration JS comprise)' );
ps_assert( ! str_contains( wp_json_encode( $tg['calls'] ), '"' . $secret ), 'jamais dans les paramètres envoyés' );
$r = ps_test_json( 'PUT', '/settings', array( 'telegram_token' => '123456:AbcdefghijklmnopqrstuvwxyzABCDEFGH' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['telegram_token'] ), 'avec wp-config.php, impossible d\'enregistrer un autre token depuis l\'interface' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : fichier joint dès la création du projet' );

wp_set_current_user( $fol );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => 'مشروع مع ملف', 'confirmDuplicates' => true ) ) );
$np = $r->get_data();
$tmp = wp_tempnam( 'new' );
file_put_contents( $tmp, "%PDF-1.4\n%%EOF" );
$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $np['id'] . '/files' );
$req->set_param( 'displayName', 'كراس الشروط' );
$req->set_file_params( array( 'file' => array( 'name' => 'cahier.pdf', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ) ) );
$fr = rest_get_server()->dispatch( $req );
ps_assert( 201 === $r->get_status() && 200 === $fr->get_status() && 'كراس الشروط' === ps_test_json( 'GET', '/projects/' . $np['id'] . '/files' )->get_data()[0]['name'], 'le créateur (موظف) joint un fichier juste après la création' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.6.0 : sauvegarde / restauration avec factures' );

wp_set_current_user( $adm );
$bk = ps_backup_build();
ps_assert( isset( $bk['tables']['invoices'], $bk['tables']['alerts'] ) && 2 <= count( $bk['tables']['invoices'] ), 'la sauvegarde JSON contient les factures et les alertes' );
wp_set_current_user( 0 );
