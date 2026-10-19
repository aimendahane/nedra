<?php
/**
 * Étape 2 — Fiche Projet, Base Projets, annuaire, سجل المتابعة, fichiers, journal.
 */

global $wpdb;
ps_test_reset_data();

/** Appel REST avec un corps JSON (comme le navigateur). */
function ps_test_json( string $method, string $route, array $body = array() ): WP_REST_Response {
	$parts = explode( '?', $route, 2 );
	$req   = new WP_REST_Request( $method, '/' . PS_REST_NS . $parts[0] );
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
		$req->set_query_params( $query );
	}
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $req );
}

function ps_test_lid( string $key, string $label_or_code ): int {
	foreach ( ps_lists_all()[ $key ] as $v ) {
		if ( $v['code'] === $label_or_code || $v['label'] === $label_or_code ) {
			return $v['id'];
		}
	}
	throw new RuntimeException( "valeur introuvable $key/$label_or_code" );
}

function ps_test_user( string $login, string $role, ?string $employee = null ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => $login ) );
	if ( $employee ) {
		update_user_meta( $id, 'ps_employee_id', ps_test_lid( 'employee', $employee ) );
	}
	return $id;
}

/** Projet valide minimal (tous les champs obligatoires par défaut). */
function ps_test_payload( array $over = array() ): array {
	$base = array(
		'offerNo'          => 'OFR-T-' . wp_generate_password( 6, false ),
		'name'             => 'إنارة مدرسة الأمل',
		'receivedDate'     => '2026-10-01',
		'responsibleEmpId' => ps_test_lid( 'employee', 'وليد جدير' ),
		'followerEmpId'    => ps_test_lid( 'employee', 'محمد حسين' ),
		'placeId'          => ps_test_lid( 'place', 'T' ),
		'categoryId'       => ps_test_lid( 'category', 'إنارة' ),
		'urgencyId'        => ps_test_lid( 'urgency', 'U' ),
		'sourceId'         => ps_test_lid( 'source', 'direct' ),
		'parties'          => array(
			'owner'    => array( 'companyName' => 'شركة النور', 'person' => 'سالم', 'phone' => '+218 91 234 5678', 'email' => 'salem@example.com' ),
			'executor' => array( 'companyName' => 'شركة البناء الحديث' ),
			'direct'   => array( 'person' => 'خالد', 'phone' => '0921112233' ),
		),
		'items'            => array(
			array( 'itemTypeId' => ps_test_lid( 'item_type', 'إنارة' ), 'quantity' => '12', 'employeeId' => ps_test_lid( 'employee', 'محمد حسين' ), 'dueDate' => '2026-10-15' ),
		),
		'competitors'      => array(
			array( 'competitorName' => 'شركة المنافس', 'brand' => 'Philips', 'itemTypeIds' => array( ps_test_lid( 'item_type', 'إنارة' ) ) ),
		),
	);
	return array_replace_recursive( $base, $over );
}

$emp_a = ps_test_user( 't2_emp_a', 'ps_employee', 'محمد حسين' );
$emp_b = ps_test_user( 't2_emp_b', 'ps_employee', 'هاشم الخراز' );
$mgr   = ps_test_user( 't2_mgr', 'ps_manager', 'وليد جدير' );
$mgr2  = ps_test_user( 't2_mgr2', 'ps_manager', 'بدر ناجي' );
$adm   = ps_test_user( 't2_adm', 'ps_admin' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Calculs de l\'étape 2 (inc/calculs.php)' );

$cases = array(
	array( array(), 'preparing', 'aucun صنف → قيد الإعداد' ),
	array( array( 'cancelled', 'cancelled' ), 'cancelled', 'tous ملغى → ملغى' ),
	array( array( 'accepted', 'rejected', 'preparing' ), 'accepted', 'un مقبول → مقبول' ),
	array( array( 'rejected', 'rejected', 'cancelled' ), 'rejected', 'tous مرفوض (ملغى ignorés) → مرفوض' ),
	array( array( 'sent', 'rejected' ), 'sent', 'un مرسل (pas de مقبول) → مرسل' ),
	array( array( 'not_sent', 'not_sent', 'cancelled' ), 'not_sent', 'tous لم يُرسل (ملغى ignorés) → لم يُرسل' ),
	array( array( 'not_sent', 'preparing' ), 'preparing', 'لم يُرسل + قيد الإعداد → قيد الإعداد' ),
	array( array( 'rejected', 'not_sent' ), 'preparing', 'مرفوض + لم يُرسل → قيد الإعداد (règle « sinon » du brief)' ),
);
foreach ( $cases as list( $codes, $want, $label ) ) {
	ps_assert_same( $want, ps_calc_project_offer_status( $codes ), 'حالة العرض : ' . $label );
}
$prio = array( array( 4, 3, 'P1' ), array( 4, 2, 'P1' ), array( 3, 3, 'P1' ), array( 4, 1, 'P2' ), array( 3, 2, 'P2' ), array( 2, 2, 'P2' ), array( 3, 1, 'P2' ), array( 2, 1, 'P3' ), array( 1, 1, 'P3' ), array( 1, 3, 'P2' ), array( 2, null, 'P3' ), array( 4, null, 'P2' ) );
foreach ( $prio as list( $u, $s, $want ) ) {
	ps_assert_same( $want, ps_calc_priority( $u, $s ), "الأولوية : استعجال $u + حجم " . ( null === $s ? 'vide(=1)' : $s ) . " → $want" );
}
ps_assert_same( null, ps_calc_priority( null, 3 ), 'الأولوية : sans urgence → aucune' );
ps_assert_same( array( 'amount' => 2500.0, 'percent' => 25.0 ), ps_calc_margin( '10000', 1, '7500', 1 ), 'هامش : 10000 − 7500 = 2500 (25.0 %)' );
ps_assert_same( array( 'amount' => -500.0, 'percent' => -12.5 ), ps_calc_margin( 4000, 2, 4500, 2 ), 'هامش négatif : 4000 − 4500 = −500 (−12.5 %)' );
ps_assert_same( null, ps_calc_margin( 1000, 1, 800, 2 ), 'هامش : devises différentes → non calculé' );
ps_assert_same( null, ps_calc_margin( 1000, 1, null, 1 ), 'هامش : montant d\'achat absent → non calculé' );
ps_assert_same( array( 'amount' => -50.0, 'percent' => null ), ps_calc_margin( 0, 1, 50, 1 ), 'هامش : مبلغ العرض = 0 → pas de pourcentage' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Normalisation arabe et similarité' );

ps_assert_same( ps_normalize_text( 'شَرِكة الإنارة' ), ps_normalize_text( 'شركه الانارة' ), 'ة/ه, إ/ا, voyelles : « شَرِكة الإنارة » = « شركه الانارة »' );
ps_assert_same( 'abc 123', ps_normalize_text( '  ABC — ١٢٣ ' ), 'majuscules, ponctuation et chiffres arabes normalisés' );
ps_assert( ps_text_similarity( 'إنارة مدرسة الأمل', 'انارة مدرسه الامل' ) === 1.0, 'similarité : orthographes différentes = 100 %' );
ps_assert( ps_text_similarity( 'إنارة مدرسة الأمل', 'مشروع إنارة مدرسة الأمل' ) >= 0.75, 'similarité : nom contenu dans un autre ≥ 75 %' );
ps_assert( ps_text_similarity( 'إنارة مدرسة الأمل', 'كاميرات مستشفى الهدى' ) < 0.5, 'similarité : projets différents < 50 %' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Création (Fiche Projet) et numérotation' );

wp_set_current_user( $emp_a );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'offerNo' => 'OFR-2026-001' ) ) );
ps_assert_same( 201, $r->get_status(), 'موظف : création d\'un projet valide (201)' );
$p1 = $r->get_data();
ps_assert_same( 'PRJ-2026-001', $p1['projectNo'], 'رقم المشروع automatique PRJ-2026-001' );
ps_assert_same( 'في دراسة', ps_list_label( $p1['followupStatusId'] ), 'حالة المتابعة par défaut : في دراسة' );
ps_assert_same( 'preparing', $p1['items'][0]['offerStatusCode'], 'صنف créé en « قيد الإعداد »' );
ps_assert_same( 'preparing', $p1['computed']['offerStatus'], 'حالة العرض calculée : قيد الإعداد' );
ps_assert_same( 'P2', $p1['computed']['priority'], 'الأولوية calculée : U(3) + حجم vide(1) = 4 → P2' );
ps_assert( $p1['parties']['owner']['companyId'] > 0 && 'شركة النور' === $p1['parties']['owner']['companyName'], 'المالك : société créée dans l\'annuaire et liée' );
ps_assert( $p1['competitors'][0]['competitorId'] > 0 && 'Philips' === $p1['competitors'][0]['brand'], 'منافس créé dans l\'annuaire avec sa ماركة et ses أصناف' );
ps_assert( true === $p1['permissions']['canEdit'] && 'creator' === $p1['permissions']['editReason'], 'créateur : peut modifier (fenêtre 24 h)' );

$r2 = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'offerNo' => 'OFR-2026-002', 'name' => 'كاميرات مستشفى الهدى' ) ) );
ps_assert_same( 'PRJ-2026-002', $r2->get_data()['projectNo'] ?? null, 'numéro suivant : PRJ-2026-002' );
$r3 = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'offerNo' => 'OFR-2025-001', 'name' => 'منظومة إطفاء مصنع', 'receivedDate' => '2025-12-20' ) ) );
ps_assert_same( 'PRJ-2025-001', $r3->get_data()['projectNo'] ?? null, 'numérotation par année de réception : PRJ-2025-001' );
$p3 = $r3->get_data();

/* ------------------------------------------------------------------ */
ps_test_section( 'Validation côté serveur (§11 + brief)' );

$bad = ps_test_payload();
unset( $bad['name'], $bad['sourceId'] );
$bad['parties']['owner']['phone'] = '';
$bad['items'][0]['dueDate']       = '';
$r = ps_test_json( 'POST', '/projects', $bad );
$e = $r->get_data()['data']['errors'] ?? array();
ps_assert_same( 422, $r->get_status(), 'champs obligatoires manquants → refus (422)' );
ps_assert( isset( $e['name'], $e['source_id'], $e['parties.owner.phone'], $e['items.0.due_date'] ), 'erreurs indiquées champ par champ (اسم المشروع، مصدر الطلب، هاتف المالك، الأجل)', array_keys( $e ) );

$no_items          = ps_test_payload();
$no_items['items'] = array();
$r                 = ps_test_json( 'POST', '/projects', $no_items );
ps_assert( isset( $r->get_data()['data']['errors']['items'] ), 'aucun صنف → refus (صنف واحد على الأقل)' );

$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'sentDate' => '2026-09-30' ) ) );
ps_assert( isset( $r->get_data()['data']['errors']['sent_date'] ), 'تاريخ الإرسال < تاريخ الاستلام → refus' );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'sentDate' => '2026-10-01', 'name' => 'مشروع يوم الإرسال', 'parties' => array( 'owner' => array( 'companyName' => 'شركة أخرى' ) ) ) ) );
ps_assert_same( 201, $r->get_status(), 'تاريخ الإرسال = تاريخ الاستلام → accepté' );

$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'parties' => array( 'owner' => array( 'email' => 'pas-un-email', 'phone' => '09-ABC' ) ) ) ) );
$e = $r->get_data()['data']['errors'] ?? array();
ps_assert( isset( $e['parties.owner.email'], $e['parties.owner.phone'] ), 'e-mail invalide et téléphone non numérique → refus' );
ps_assert( ps_is_valid_phone( '+218 91-234.5678' ) && ! ps_is_valid_phone( '12ab' ) && ! ps_is_valid_phone( '123' ), 'téléphone : chiffres (+, espaces, tirets tolérés), 6 à 15 chiffres' );

$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'offerNo' => 'OFR-2026-001' ) ) );
ps_assert( str_contains( $r->get_data()['data']['errors']['offer_no'] ?? '', 'PRJ-2026-001' ), 'رقم العرض déjà utilisé → refus (indique le projet)' );

$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'sourceId' => ps_test_lid( 'source', 'unspecified' ) ) ) );
ps_assert( isset( $r->get_data()['data']['errors']['source_id'] ), '« غير محدد » refusé à la saisie (réservé à l\'import)' );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'placeId' => ps_test_lid( 'urgency', 'U' ) ) ) );
ps_assert( isset( $r->get_data()['data']['errors']['place_id'] ), 'valeur d\'une autre liste → refus' );

$wpdb->update( ps_table( 'lists' ), array( 'is_hidden' => 1 ), array( 'id' => ps_test_lid( 'category', 'إنارة' ) ) );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'name' => 'مشروع جديد بقيمة مخفية' ) ) );
ps_assert( isset( $r->get_data()['data']['errors']['category_id'] ), 'valeur masquée refusée pour un nouveau projet' );
wp_set_current_user( $adm );
$keep = ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $p1, array( 'note' => 'تعديل بسيط' ) ) );
ps_assert_same( 200, $keep->get_status(), 'valeur masquée conservée sur un projet existant (pas d\'erreur)' );
$wpdb->update( ps_table( 'lists' ), array( 'is_hidden' => 0 ), array( 'id' => ps_test_lid( 'category', 'إنارة' ) ) );
ps_lists_by_id( true );

ps_update_settings( array( 'validation' => array( 'required' => array_values( array_diff( ps_default_required_fields(), array( 'offer_no' ) ) ) ) ) );
wp_set_current_user( $emp_a );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'offerNo' => '', 'name' => 'بدون رقم عرض', 'parties' => array( 'owner' => array( 'companyName' => 'شركة ثالثة' ) ) ) ) );
ps_assert_same( 201, $r->get_status(), 'règles modifiables : رقم العرض rendu facultatif dans les réglages → accepté' );
ps_update_settings( array( 'validation' => array( 'required' => ps_default_required_fields() ) ) );

/* ------------------------------------------------------------------ */
ps_test_section( 'Annuaire des sociétés : une seule fiche par société' );

$count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'companies' ) );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'name' => 'لوحات كهربائية للمخزن', 'receivedDate' => '2026-03-01', 'parties' => array( 'owner' => array( 'companyName' => 'شركه النور', 'person' => 'سالم', 'phone' => '0911111111', 'address' => 'طرابلس - قرجي' ) ) ) ) );
$pp = $r->get_data();
ps_assert_same( $p1['parties']['owner']['companyId'], $pp['parties']['owner']['companyId'] ?? null, '« شركه النور » saisi à la main → même fiche que « شركة النور »' );
ps_assert_same( $count_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'companies' ) ), 'aucune fiche en double créée (المالك et المنفذة déjà dans l\'annuaire)' );
$c = ps_dir_get( 'companies', $p1['parties']['owner']['companyId'] );
ps_assert( 'طرابلس - قرجي' === $c['address'] && '+218 91 234 5678' === $c['phone'], 'coordonnées vides de la fiche complétées, existantes conservées' );
$s = ps_test_json( 'GET', '/companies/search?q=' . rawurlencode( 'النور' ) );
$req = new WP_REST_Request( 'GET', '/' . PS_REST_NS . '/companies/search' );
$req->set_param( 'q', 'نور' );
$found = rest_get_server()->dispatch( $req )->get_data();
ps_assert( count( $found ) >= 1 && 'شركة النور' === $found[0]['name'], 'autocomplétion : « نور » trouve « شركة النور »' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Détection de doublons (avertir sans bloquer)' );

$dup = ps_test_payload( array( 'offerNo' => 'OFR-DUP-1', 'name' => 'انارة مدرسه الامل', 'receivedDate' => '2026-11-20', 'parties' => array( 'owner' => array( 'companyId' => $p1['parties']['owner']['companyId'], 'companyName' => 'شركة النور' ) ) ) );
$r   = ps_test_json( 'POST', '/projects', $dup );
ps_assert_same( 409, $r->get_status(), 'même société + nom proche à 50 jours → avertissement (409)' );
ps_assert_same( 'PRJ-2026-001', $r->get_data()['data']['duplicates'][0]['projectNo'] ?? null, 'le projet ressemblant est indiqué (PRJ-2026-001)' );
$r = ps_test_json( 'POST', '/projects', array_merge( $dup, array( 'confirmDuplicates' => true ) ) );
ps_assert_same( 201, $r->get_status(), '« حفظ رغم ذلك » → enregistré' );
$far = ps_test_payload( array( 'offerNo' => 'OFR-DUP-2', 'name' => 'إنارة مدرسة الأمل', 'receivedDate' => '2027-03-15', 'parties' => array( 'owner' => array( 'companyName' => 'شركة النور' ) ) ) );
ps_assert_same( 201, ps_test_json( 'POST', '/projects', $far )->get_status(), 'même nom mais à plus de 60 jours → pas d\'avertissement' );
$other = ps_test_payload( array( 'offerNo' => 'OFR-DUP-3', 'name' => 'إنارة مدرسة الأمل', 'parties' => array( 'owner' => array( 'companyName' => 'شركة مختلفة تمامًا' ) ) ) );
ps_assert_same( 201, ps_test_json( 'POST', '/projects', $other )->get_status(), 'même nom, autre société → pas d\'avertissement' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Droits de modification (vérifiés par le serveur)' );

// confirmDuplicates : le nouveau nom ressemble au doublon volontaire créé plus haut.
$upd = array_merge( $p1, array( 'name' => 'إنارة مدرسة الأمل - المرحلة 2', 'confirmDuplicates' => true ) );
wp_set_current_user( $emp_b );
ps_assert_same( 403, ps_test_json( 'PUT', '/projects/' . $p1['id'], $upd )->get_status(), 'موظف (autre que le créateur) : modification refusée (403)' );
wp_set_current_user( $mgr2 );
ps_assert_same( 403, ps_test_json( 'PUT', '/projects/' . $p1['id'], $upd )->get_status(), 'مسؤول مشروع d\'un AUTRE projet : refusé (403)' );
wp_set_current_user( $mgr );
$r = ps_test_json( 'PUT', '/projects/' . $p1['id'], $upd );
ps_assert( 200 === $r->get_status() && 'responsible' === $r->get_data()['permissions']['editReason'], 'مسؤول مشروع de CE projet (المسؤول عن المشروع) : modification acceptée' );
wp_set_current_user( $emp_a );
ps_assert_same( 200, ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $upd, array( 'note' => 'تصحيح خطأ' ) ) )->get_status(), 'créateur dans les 24 h : modification acceptée' );
$wpdb->update( ps_table( 'projects' ), array( 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'id' => $p1['id'] ) );
$r = ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $upd, array( 'note' => 'بعد 25 ساعة' ) ) );
ps_assert_same( 403, $r->get_status(), 'créateur après 25 h : modification refusée (403)' );
ps_assert( false === ps_test_json( 'GET', '/projects/' . $p1['id'] )->get_data()['permissions']['canEdit'], 'créateur après 25 h : fiche en lecture seule' );
wp_set_current_user( $adm );
ps_assert_same( 200, ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $upd, array( 'note' => 'الإدارة' ) ) )->get_status(), 'الإدارة : modification acceptée' );
wp_set_current_user( $emp_b );
ps_assert_same( 200, ps_test_json( 'GET', '/projects/' . $p1['id'] )->get_status(), 'موظف : lecture de tous les projets autorisée' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Numéro fixe, أصناف, journal des modifications' );

wp_set_current_user( $adm );
$cur = ps_test_json( 'GET', '/projects/' . $p1['id'] )->get_data();
$r   = ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $cur, array( 'receivedDate' => '2025-10-01', 'sentDate' => '' ) ) );
ps_assert( 'PRJ-2026-001' === $r->get_data()['projectNo'] && 2025 === $r->get_data()['receivedYear'], 'date de réception corrigée → رقم المشروع inchangé, السنة suit la date' );
$cur = $r->get_data();
$cur['items'][] = array( 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => 2, 'employeeId' => ps_test_lid( 'employee', 'هاشم الخراز' ), 'dueDate' => '2026-10-20' );
$cur   = ps_test_json( 'PUT', '/projects/' . $p1['id'], $cur )->get_data();
ps_assert_same( 2, count( $cur['items'] ), 'ajout d\'un 2e صنف' );
$wpdb->update( ps_table( 'items' ), array( 'offer_status_id' => ps_list_id_by_code( 'offer_status', 'sent' ) ), array( 'id' => $cur['items'][0]['id'] ) );
$cur = ps_test_json( 'GET', '/projects/' . $p1['id'] )->get_data();
$try = $cur;
array_shift( $try['items'] );
$r = ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $try, array( 'sentDate' => '2026-10-05' ) ) );
ps_assert( isset( $r->get_data()['data']['errors']['items'] ), 'صنف déjà envoyé : retrait refusé (passer en ملغى)' );
$r = ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $cur, array( 'sentDate' => '' ) ) );
ps_assert( isset( $r->get_data()['data']['errors']['sent_date'] ), 'un صنف مرسل → تاريخ إرسال العرض obligatoire' );
$try = $cur;
array_pop( $try['items'] );
$r = ps_test_json( 'PUT', '/projects/' . $p1['id'], array_merge( $try, array( 'sentDate' => '2026-10-05', 'offerAmount' => '15000', 'purchaseAmount' => '11000.5', 'purchaseCurrencyId' => ps_test_lid( 'currency', 'LYD' ) ) ) );
ps_assert( 200 === $r->get_status() && 1 === count( $r->get_data()['items'] ), 'صنف encore قيد الإعداد : retrait accepté (montant sans devise → LYD par défaut)', $r->get_data() );
ps_assert_same( array( 'amount' => 3999.5, 'percent' => 26.7 ), $r->get_data()['computed']['margin'], 'هامش calculé : 15000 − 11000.5 = 3999.5 LYD (26.7 %)' );
ps_assert_same( 'sent', $r->get_data()['computed']['offerStatus'], 'حالة العرض recalculée : مرسل' );

$log    = ps_changelog_for_project( $p1['id'] );
$fields = array_column( $log, 'field' );
ps_assert( in_array( 'name', $fields, true ) && in_array( 'received_date', $fields, true ) && in_array( 'offer_amount', $fields, true ), 'journal : champs modifiés enregistrés' );
$name_log = array_values( array_filter( $log, fn( $l ) => 'name' === $l['field'] ) );
ps_assert( 'إنارة مدرسة الأمل' === end( $name_log )['old'] && 't2_mgr' === end( $name_log )['user'], 'journal : ancienne valeur + auteur (qui, quoi, quand)' );
ps_assert( in_array( 'delete', array_column( array_filter( $log, fn( $l ) => 'item' === $l['entity'] ), 'action' ), true ), 'journal : retrait d\'un صنف enregistré' );

/* ------------------------------------------------------------------ */
ps_test_section( 'سجل المتابعة' );

wp_set_current_user( $emp_a ); // intervient (المتابع + créateur)
$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $p1['id'] . '/followups' );
$req->set_param( 'date', '2026-10-03' );
$req->set_param( 'text', 'اتصال بالحريف لتأكيد الكمية' );
$req->set_param( 'nextDate', '2026-10-10' );
$r = rest_get_server()->dispatch( $req );
ps_assert( 200 === $r->get_status() && 't2_emp_a' === $r->get_data()['author'], 'ajout par un intervenant : date, auteur, texte, prochaine date' );
$fid = $r->get_data()['id'] ?? 0;
$req->set_param( 'nextDate', '2026-10-01' );
ps_assert_same( 422, rest_get_server()->dispatch( $req )->get_status(), 'prochaine date avant la date du suivi → refus' );
wp_set_current_user( $emp_b );
$req2 = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $p3['id'] . '/followups' );
$req2->set_param( 'text', 'x' );
ps_assert_same( 403, rest_get_server()->dispatch( $req2 )->get_status(), 'موظف qui n\'intervient pas sur le projet : refusé (403)' );
$put = new WP_REST_Request( 'PUT', '/' . PS_REST_NS . '/followups/' . $fid );
$put->set_param( 'date', '2026-10-03' );
$put->set_param( 'text', 'تعديل' );
ps_assert_same( 403, rest_get_server()->dispatch( $put )->get_status(), 'modifier le suivi d\'un autre : refusé' );
wp_set_current_user( $emp_a );
ps_assert_same( 200, rest_get_server()->dispatch( $put )->get_status(), 'l\'auteur modifie son suivi' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Fichiers joints (stockage protégé)' );

$tmp = wp_tempnam( 'ps' );
file_put_contents( $tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF" );
$id = ps_file_store( $p1['id'], array( 'name' => 'عرض السعر.pdf', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ), $emp_a );
ps_assert( is_int( $id ) && $id > 0, 'PDF accepté' );
$f = ps_file_get( (int) $id );
ps_assert( str_starts_with( $f['stored_name'], current_time( 'Y/m' ) . '/' ) && ! str_contains( $f['stored_name'], 'عرض' ) && preg_match( '/[0-9a-f]{32}\.pdf$/', $f['stored_name'] ), 'nom de stockage aléatoire (nom réel jamais dans le chemin)' );
ps_assert( file_exists( ps_storage_dir( 'files/' . $f['stored_name'] ) ), 'fichier écrit dans uploads/protecta-suivi/files (dossier protégé)' );
file_put_contents( $tmp, '<?php echo 1;' );
$bad = ps_file_store( $p1['id'], array( 'name' => 'piege.pdf', 'tmp_name' => $tmp, 'size' => 13, 'error' => 0 ), $emp_a );
ps_assert( is_wp_error( $bad ) && 'ps_upload_type' === $bad->get_error_code(), 'faux PDF (contenu PHP) refusé' );
$bad = ps_file_store( $p1['id'], array( 'name' => 'script.php', 'tmp_name' => $tmp, 'size' => 13, 'error' => 0 ), $emp_a );
ps_assert( is_wp_error( $bad ), 'extension .php refusée' );
wp_set_current_user( $emp_b );
ps_assert_same( 403, rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/' . PS_REST_NS . '/files/' . $id ) )->get_status(), 'retrait du fichier d\'un autre : refusé' );
wp_set_current_user( $emp_a );
ps_assert_same( 200, rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/' . PS_REST_NS . '/files/' . $id ) )->get_status(), 'l\'envoyeur retire son fichier (corbeille)' );
ps_assert( file_exists( ps_storage_dir( 'files/' . $f['stored_name'] ) ), 'fichier retiré : conservé sur le disque (aucune perte)' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Base Projets : recherche, filtres, tri' );

wp_set_current_user( $emp_b );
$list = fn( array $args ) => ps_test_json( 'GET', '/projects?' . http_build_query( $args ) )->get_data();
$all  = $list( array() );
ps_assert( $all['total'] >= 8, 'liste : tous les projets visibles par un موظف', $all['total'] );
ps_assert_same( 'PRJ-2025-001', $list( array( 'q' => 'مصنع' ) )['rows'][0]['projectNo'] ?? null, 'recherche par mot du nom' );
ps_assert( $list( array( 'q' => 'انارة مدرسه' ) )['total'] >= 3, 'recherche tolérante à l\'orthographe arabe' );
ps_assert( $list( array( 'q' => '0921112233' ) )['total'] >= 1, 'recherche par téléphone du الشخص المباشر' );
ps_assert_same( 1, $list( array( 'year' => 2025, 'q' => 'مصنع' ) )['total'], 'filtre السنة (année de réception)' );
ps_assert( $list( array( 'offerStatus' => 'sent' ) )['total'] >= 1 && 'sent' === $list( array( 'offerStatus' => 'sent' ) )['rows'][0]['offerStatus'], 'filtre حالة العرض (valeur calculée)' );
$rows = $list( array( 'sort' => 'receivedDate', 'dir' => 'asc', 'perPage' => 100 ) )['rows'];
ps_assert( $rows[0]['receivedDate'] <= end( $rows )['receivedDate'], 'tri croissant par تاريخ الاستلام' );
ps_assert_same( 2, $list( array( 'perPage' => 2 ) )['perPage'] === 2 ? count( $list( array( 'perPage' => 2 ) )['rows'] ) : -1, 'pagination' );
ps_assert_same( 403, ps_test_json( 'GET', '/projects?trash=1' )->get_status(), 'corbeille : réservée à la الإدارة' );

/* ------------------------------------------------------------------ */
ps_test_section( 'مصدر غير محدد : filtre et modification groupée (décision n°3)' );

$unspec = ps_test_lid( 'source', 'unspecified' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . ps_table( 'projects' ) . ' SET source_id = %d WHERE id IN (%d, %d)', $unspec, $p3['id'], $pp['id'] ) );
ps_assert_same( 2, $list( array( 'sourceId' => 'unspecified' ) )['total'], 'filtre « مصدر غير محدد » : 2 projets' );
$bulk = array( 'ids' => array( $p3['id'], $pp['id'] ), 'sourceId' => ps_test_lid( 'source', 'email' ) );
ps_assert_same( 403, ps_test_json( 'POST', '/projects/bulk-source', $bulk )->get_status(), 'modification groupée : refusée au موظف' );
wp_set_current_user( $adm );
ps_assert_same( 422, ps_test_json( 'POST', '/projects/bulk-source', array( 'ids' => $bulk['ids'], 'sourceId' => $unspec ) )->get_status(), 'modification groupée vers « غير محدد » : refusée' );
$r = ps_test_json( 'POST', '/projects/bulk-source', $bulk );
ps_assert_same( 2, $r->get_data()['updated'] ?? null, 'الإدارة : 2 projets passent à « إيميل » en une fois' );
ps_assert_same( 0, $list( array( 'sourceId' => 'unspecified' ) )['total'], 'plus aucun projet « غير محدد »' );
$src_log = array_values( array_filter( ps_changelog_for_project( $p3['id'] ), fn( $l ) => 'source_id' === $l['field'] ) );
ps_assert( $src_log && 'غير محدد' === $src_log[0]['old'] && 'إيميل' === $src_log[0]['new'], 'modification groupée inscrite au journal de chaque projet, en texte lisible (غير محدد → إيميل)' );

/* ------------------------------------------------------------------ */
ps_test_section( 'Corbeille et ajout rapide de valeurs' );

wp_set_current_user( $emp_a );
ps_assert_same( 403, rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/' . PS_REST_NS . '/projects/' . $p3['id'] ) )->get_status(), 'suppression refusée au موظف (passer en ملغى)' );
wp_set_current_user( $adm );
rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/' . PS_REST_NS . '/projects/' . $p3['id'] ) );
ps_assert( null !== ps_project_row( $p3['id'] )['deleted_at'] && 0 === $list( array( 'q' => 'مصنع' ) )['total'], 'الإدارة : projet mis à la corbeille (masqué de la liste, pas effacé)' );
ps_assert_same( 1, $list( array( 'trash' => 1 ) )['total'], 'visible dans la corbeille' );
rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $p3['id'] . '/restore' ) );
ps_assert( null === ps_project_row( $p3['id'] )['deleted_at'], 'restauration' );

wp_set_current_user( $emp_a );
$qa  = fn( $label, $code = '' ) => ps_test_json( 'POST', '/lists/place/quick-add', array( 'label' => $label, 'code' => $code ) )->get_data();
$new = $qa( 'Zliten', 'ZL' );
ps_assert( false === $new['existed'] && 'ZL' === $new['value']['code'], '« + إضافة مكان جديد » : Zliten (ZL) ajouté' );
ps_assert( true === $qa( 'zliten' )['existed'] && true === $qa( 'Autre', 'zl' )['existed'], 'pas de doublon (même nom ou même code)' );
ps_assert_same( 404, ps_test_json( 'POST', '/lists/urgency/quick-add', array( 'label' => 'X' ) )->get_status(), 'ajout rapide impossible sur les listes à logique (urgence…)' );
wp_set_current_user( 0 );
