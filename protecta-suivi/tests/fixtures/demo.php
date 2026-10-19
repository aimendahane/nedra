<?php
/**
 * Jeu de démonstration : 30 projets (24 en 2026, 6 en 2025), « aujourd'hui » = 2026-10-19.
 * Résultats attendus calculés à la main : tests/fixtures/demo-expected.md.
 *
 * Contenu : plusieurs devises, retards d'offre et de livraison, urgence L, لم يُرسل avec motifs,
 * ملغى, tous les statuts de suivi, doublons de sociétés, sources variées (dont موقع العطاءات), concurrents.
 *
 *   ps_demo_seed()  → insère les données (base de TEST ou site de démonstration uniquement).
 */

const PS_DEMO_TODAY = '2026-10-19';

/**
 * Données. Employés : M = محمد حسين, W = وفاء حامدي, G = غفران دريدي, J = وليد جدير, A = أميرة السعيداني.
 * Item : [ صنف, statut, أجل, employé, extra ] ; extra : reason (لم يُرسل), delivery [demandée, réelle, code].
 */
function ps_demo_projects(): array {
	$P = static function ( $code, $date, $urg, $size, $fu, $src, $place, $cat, $company, $amount, $cur, $purchase, $pcur, $follower, $items, $followups = array(), $updated = null, $competitors = array() ) {
		return compact( 'code', 'date', 'urg', 'size', 'fu', 'src', 'place', 'cat', 'company', 'amount', 'cur', 'purchase', 'pcur', 'follower', 'items', 'followups', 'updated', 'competitors' );
	};
	return array(
		$P( 'D01', '2026-01-10', 'U', 'عالية', 'تم تأكيد الطلبية', 'direct', 'T', 'إنارة', 'شركة النور', 10000, 'LYD', 7500, 'LYD', 'J',
			array( array( 'RELLI', 'accepted', '2026-01-20', 'J', array( 'delivery' => array( '2026-02-15', '2026-02-10', 'delivered' ) ) ) ),
			array( array( '2026-10-15', null ) ), null, array( array( 'المنافس الأول', 'Philips', array( 'RELLI' ) ) ) ),
		$P( 'D02', '2026-01-25', 'N', 'متوسطة', 'رفض العرض', 'tenders', 'BG', 'شبكات', 'مؤسسة الأفق', 5000, 'USD', 4000, 'LYD', 'A',
			array( array( 'CABLE', 'rejected', '2026-02-05', 'J' ) ), array(), null, array( array( 'المنافس الأول', 'Nexans', array( 'CABLE' ) ), array( 'شركة الكابلات', 'Elsewedy', array( 'CABLE' ) ) ) ),
		$P( 'D03', '2026-02-03', 'VU', null, 'في دراسة', 'email', 'M', 'كهرباء', 'مستشفى الهدى', null, null, null, null, 'M',
			array( array( 'UPS', 'preparing', '2026-02-10', 'M' ) ) ),
		$P( 'D04', '2026-02-14', 'L', 'ضعيفة', 'في دراسة', 'phone', 'T', 'تجهيزات', 'شركة البناء الحديث', null, null, null, null, 'J',
			array( array( 'Rack', 'preparing', '2026-03-01', 'J' ) ) ),
		$P( 'D05', '2026-03-02', 'U', 'عالية', 'تم التسليم', 'direct', 'BG', 'إنارة', 'مؤسسة الأفق للتجارة', 20000, 'EUR', 15000, 'EUR', 'A',
			array( array( 'إنارة', 'accepted', '2026-03-10', 'J', array( 'delivery' => array( '2026-04-01', '2026-04-06', 'delivered' ) ) ) ), array(), null, array( array( 'المنافس الأول', 'Philips', array( 'إنارة' ) ) ) ),
		$P( 'D06', '2026-03-15', 'N', 'متوسطة', 'في انتظار الدفع', 'broker', 'S', 'أنظمة أمان وإنذار', 'شركة الأمان', 8000, 'LYD', 8500, 'LYD', 'M',
			array( array( 'حساسات', 'accepted', '2026-03-25', 'M', array( 'delivery' => array( '2026-09-30', null, 'waiting' ) ) ) ),
			array( array( '2026-10-01', null ), array( '2026-10-10', '2026-10-17' ) ) ),
		$P( 'D07', '2026-04-05', 'U', null, 'في انتظار الرد', 'tenders', 'J', 'شبكات', 'جامعة طرابلس', 3000, 'TND', null, null, 'J',
			array( array( 'CABLE', 'sent', '2026-04-15', 'J' ), array( 'Rack', 'not_sent', '2026-04-15', 'J', array( 'reason' => 'السعر غير منافس' ) ) ) ),
		$P( 'D08', '2026-04-20', 'N', 'ضعيفة', 'في دراسة', 'fair', 'D', 'كهرباء', 'شركة الأمان', null, null, null, null, 'J',
			array( array( 'UPS', 'not_sent', '2026-05-01', 'J', array( 'reason' => 'المنتج غير متوفر' ) ) ) ),
		$P( 'D09', '2026-05-08', 'U', 'متوسطة', 'في دراسة', 'email', 'T', 'شبكات', 'مستشفى الهدى', null, null, null, null, 'A',
			array( array( 'Patch cord', 'not_sent', '2026-05-15', 'J', array( 'reason' => 'الأجل قصير' ) ), array( 'Smart home', 'not_sent', '2026-05-15', 'J', array( 'reason' => 'السعر غير منافس' ) ) ) ),
		$P( 'D10', '2026-05-20', 'VU', 'عالية', 'تم تأكيد الطلبية', 'direct', 'T', 'أنظمة أمان وإنذار', 'شركة النور', 50000, 'LYD', 41000, 'LYD', 'J',
			array( array( 'منظومة إطفاء', 'accepted', '2026-05-30', 'W', array( 'delivery' => array( '2026-10-25', null, 'ready' ) ) ) ), array(), null, array( array( 'شركة الحماية', 'Inim', array( 'منظومة إطفاء' ) ) ) ),
		$P( 'D11', '2026-06-02', 'N', null, 'في دراسة', 'phone', 'K', 'إنارة', 'بلدية الكفرة', null, null, null, null, 'J',
			array( array( 'إنارة', 'cancelled', '2026-06-10', 'J' ) ) ),
		$P( 'D12', '2026-06-15', 'U', 'متوسطة', 'في انتظار معلومات', 'direct', 'M', 'كهرباء', 'شركة البناء الحديث', null, null, null, null, 'J',
			array( array( 'CABLE', 'preparing', '2026-10-25', 'J' ) ), array( array( '2026-10-14', '2026-10-25' ) ) ),
		$P( 'D13', '2026-07-01', 'N', 'عالية', 'رفض العرض', 'tenders', 'LY', 'تجهيزات', 'وزارة الصحة', null, null, null, null, 'J',
			array( array( 'Rack', 'sent', '2026-07-10', 'J' ) ), array(), null, array( array( 'شركة الكابلات', 'Legrand', array( 'Rack' ) ) ) ),
		$P( 'D14', '2026-07-10', 'U', null, 'تم تأكيد الطلبية', 'direct', 'T', 'كهرباء', 'شركة الأمان', null, null, null, null, 'J',
			array( array( 'UPS', 'preparing', '2026-07-20', 'W' ) ) ),
		$P( 'D15', '2026-07-22', 'VU', 'ضعيفة', 'في دراسة', 'email', 'BG', 'أنظمة أمان وإنذار', 'جامعة طرابلس', null, null, null, null, 'M',
			array( array( 'حساسات', 'preparing', '2026-10-19', 'J' ) ), array( array( '2026-10-11', '2026-10-19' ) ) ),
		$P( 'D16', '2026-08-03', 'U', 'عالية', 'في انتظار التوريد', 'broker', 'T', 'كهرباء', 'وزارة الصحة', 12000, 'USD', 9000, 'USD', 'J',
			array( array( 'كابلات', 'accepted', '2026-08-10', 'M', array( 'delivery' => array( '2026-09-01', '2026-09-11', 'ready' ) ) ) ) ),
		$P( 'D17', '2026-08-18', 'N', 'متوسطة', 'في دراسة', 'phone', 'S', 'إنارة', 'بلدية الكفرة', null, null, null, null, 'J',
			array( array( 'إنارة', 'preparing', '2026-08-30', 'M' ), array( 'UPS', 'cancelled', '2026-08-30', 'J' ) ) ),
		$P( 'D18', '2026-09-01', 'L', 'عالية', 'في دراسة', 'direct', 'T', 'إنارة', 'شركة النور', null, null, null, null, 'J',
			array( array( 'RELLI', 'preparing', '2026-09-10', 'J' ) ) ),
		$P( 'D19', '2026-09-10', 'U', 'متوسطة', 'في انتظار الرد', 'email', 'M', 'شبكات', 'مستشفى الهدى', 7000, 'LYD', null, null, 'W',
			array( array( 'Patch cord', 'sent', '2026-09-20', 'J' ), array( 'RELLI', 'accepted', '2026-09-20', 'J', array( 'delivery' => array( '2026-11-15', null, 'waiting' ) ) ) ),
			array( array( '2026-10-18', '2026-10-18' ) ) ),
		$P( 'D20', '2026-09-25', 'N', null, 'في دراسة', 'tenders', 'T', 'تجهيزات', 'شركة البناء الحديث', null, null, null, null, 'J',
			array( array( 'Rack', 'preparing', '2026-10-05', 'G' ) ) ),
		$P( 'D21', '2026-10-01', 'VU', 'عالية', 'في دراسة', 'direct', 'T', 'كهرباء', 'شركه النور', null, null, null, null, 'J',
			array( array( 'UPS', 'preparing', '2026-10-10', 'M' ) ), array( array( '2026-10-12', null ) ) ),
		$P( 'D22', '2026-10-05', 'U', null, 'في انتظار معلومات', 'other', 'BG', 'إنارة', 'جامعة طرابلس', null, null, null, null, 'J',
			array( array( 'CABLE', 'preparing', '2026-10-30', 'J' ) ), array(), '2026-10-06' ),
		$P( 'D23', '2026-10-12', 'N', 'ضعيفة', 'في دراسة', 'fair', 'T', 'إنارة', 'وزارة الصحة', null, null, null, null, 'J',
			array( array( 'إنارة', 'preparing', '2026-10-18', 'W' ) ), array(), '2026-10-13' ),
		$P( 'D24', '2026-10-16', 'U', 'متوسطة', 'تم تسليم جزء', 'direct', 'M', 'أنظمة أمان وإنذار', 'شركة الأمان', 4000, 'EUR', null, null, 'J',
			array( array( 'حساسات', 'accepted', '2026-10-17', 'G', array( 'delivery' => array( '2026-10-18', null, 'waiting' ) ) ) ),
			array( array( '2026-10-19', '2026-10-26' ) ) ),

		$P( 'E01', '2025-01-15', 'U', null, 'تم تأكيد الطلبية', 'direct', 'T', 'إنارة', 'شركة النور', 9000, 'LYD', null, null, 'J',
			array( array( 'RELLI', 'accepted', '2025-01-25', 'J', array( 'delivery' => array( '2025-02-20', '2025-02-18', 'delivered' ) ) ) ), array( array( '2025-03-01', null ) ) ),
		$P( 'E02', '2025-02-10', 'N', null, 'رفض العرض', 'tenders', 'BG', 'شبكات', 'مؤسسة الأفق', null, null, null, null, 'J',
			array( array( 'CABLE', 'rejected', '2025-02-20', 'J' ) ) ),
		$P( 'E03', '2025-03-05', 'N', null, 'في دراسة', 'email', 'T', 'كهرباء', 'مستشفى الهدى', null, null, null, null, 'J',
			array( array( 'UPS', 'not_sent', '2025-03-15', 'J', array( 'reason' => 'المنتج غير متوفر' ) ) ) ),
		$P( 'E04', '2025-04-20', 'VU', null, 'تم التسليم', 'direct', 'T', 'إنارة', 'وزارة الصحة', 15000, 'USD', null, null, 'J',
			array( array( 'إنارة', 'accepted', '2025-04-28', 'J', array( 'delivery' => array( '2025-05-10', '2025-05-08', 'delivered' ) ) ) ) ),
		$P( 'E05', '2025-06-11', 'U', null, 'في انتظار الرد', 'phone', 'M', 'تجهيزات', 'شركة الأمان', null, null, null, null, 'J',
			array( array( 'Rack', 'sent', '2025-06-20', 'J' ) ) ),
		$P( 'E06', '2025-11-03', 'N', null, 'في دراسة', 'direct', 'T', 'تجهيزات', 'شركة البناء الحديث', null, null, null, null, 'J',
			array( array( 'Rack', 'preparing', '2025-11-20', 'J' ) ) ),
	);
}

/**
 * Insère le jeu de démonstration. Renvoie [ code => id du projet ].
 * Les dates de création/modification sont fixées (lendemain de la réception, 10:00) pour que
 * les alertes de suivi soient reproductibles.
 */
function ps_demo_seed( int $user_id = 0 ): array {
	global $wpdb;
	$emp_names = array( 'M' => 'محمد حسين', 'W' => 'وفاء حامدي', 'G' => 'غفران دريدي', 'J' => 'وليد جدير', 'A' => 'أميرة السعيداني' );
	$find      = static function ( string $key, string $label_or_code ) use ( $wpdb ): int {
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s AND (code = %s OR label = %s) ORDER BY id LIMIT 1', $key, $label_or_code, $label_or_code ) );
		if ( ! $id ) {
			throw new RuntimeException( "demo: valeur introuvable $key/$label_or_code" );
		}
		return (int) $id;
	};
	$sizes = array( 'عالية' => 'high', 'متوسطة' => 'medium', 'ضعيفة' => 'low' );
	$ids   = array();

	foreach ( ps_demo_projects() as $d ) {
		$year     = (int) substr( $d['date'], 0, 4 );
		$seq      = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(seq) FROM ' . ps_table( 'projects' ) . ' WHERE year = %d', $year ) );
		$created  = gmdate( 'Y-m-d', strtotime( $d['date'] . ' +1 day' ) ) . ' 10:00:00';
		$updated  = $d['updated'] ? $d['updated'] . ' 10:00:00' : $created;
		$has_sent = (bool) array_filter( $d['items'], fn( $i ) => in_array( $i[1], array( 'sent', 'accepted', 'rejected' ), true ) );
		$wpdb->insert(
			ps_table( 'projects' ),
			array(
				'project_no'           => sprintf( 'PRJ-%d-%03d', $year, $seq ),
				'year'                 => $year,
				'seq'                  => $seq,
				'month'                => (int) substr( $d['date'], 5, 2 ),
				'offer_no'             => 'OFR-DEMO-' . $d['code'],
				'name'                 => 'مشروع ' . $d['code'] . ' — ' . $d['cat'] . ' ' . $d['company'],
				'name_norm'            => ps_normalize_text( 'مشروع ' . $d['code'] . ' ' . $d['cat'] . ' ' . $d['company'] ),
				'received_date'        => $d['date'],
				'sent_date'            => $has_sent ? $d['date'] : null,
				'responsible_emp_id'   => $find( 'employee', $emp_names['J'] ),
				'follower_emp_id'      => $find( 'employee', $emp_names[ $d['follower'] ] ),
				'place_id'             => $find( 'place', $d['place'] ),
				'category_id'          => $find( 'category', $d['cat'] ),
				'urgency_id'           => $find( 'urgency', $d['urg'] ),
				'importance_size_id'   => $d['size'] ? $find( 'importance', $sizes[ $d['size'] ] ) : null,
				'followup_status_id'   => $find( 'followup_status', $d['fu'] ),
				'source_id'            => $find( 'source', $d['src'] ),
				'offer_amount'         => $d['amount'],
				'offer_currency_id'    => $d['cur'] ? $find( 'currency', $d['cur'] ) : null,
				'purchase_amount'      => $d['purchase'],
				'purchase_currency_id' => $d['pcur'] ? $find( 'currency', $d['pcur'] ) : null,
				'note'                 => 'بيانات تجريبية',
				'created_by'           => $user_id ?: null,
				'updated_by'           => $user_id ?: null,
				'created_at'           => $created,
				'updated_at'           => $updated,
				'legacy_id'            => 'demo-' . $d['code'],
			)
		);
		$pid               = (int) $wpdb->insert_id;
		$ids[ $d['code'] ] = $pid;

		$company = ps_dir_find_or_create( 'companies', $d['company'], array( 'phone' => '0910000000' ), $user_id );
		foreach ( array( 'owner' => array( $company, 'المسؤول ' . $d['code'], '0910000' . substr( $d['code'], 1 ) ), 'direct' => array( null, 'الشخص ' . $d['code'], '0920000' . substr( $d['code'], 1 ) ), 'executor' => array( $company, null, null ) ) as $role => list( $cid, $person, $phone ) ) {
			$wpdb->insert( ps_table( 'project_parties' ), array( 'project_id' => $pid, 'role' => $role, 'company_id' => $cid, 'person' => $person, 'phone' => $phone, 'created_at' => $created, 'updated_at' => $created ) );
		}

		foreach ( $d['items'] as $k => $it ) {
			list( $type, $status, $due, $emp ) = $it;
			$extra = $it[4] ?? array();
			$dl    = $extra['delivery'] ?? array( null, null, 'waiting' );
			$wpdb->insert(
				ps_table( 'items' ),
				array(
					'project_id'           => $pid,
					'sort_order'           => ( $k + 1 ) * 10,
					'item_type_id'         => $find( 'item_type', $type ),
					'quantity'             => 1,
					'employee_id'          => $find( 'employee', $emp_names[ $emp ] ),
					'due_date'             => $due,
					'offer_status_id'      => $find( 'offer_status', $status ),
					'offer_date'           => in_array( $status, array( 'sent', 'accepted', 'rejected' ), true ) ? $due : null,
					'not_sent_reason_id'   => isset( $extra['reason'] ) ? $find( 'not_sent_reason', $extra['reason'] ) : null,
					'offer_invoice_no'     => 'accepted' === $status ? 'FA-' . $d['code'] : null,
					'delivery_due_date'    => $dl[0],
					'delivery_actual_date' => $dl[1],
					'delivery_status_id'   => $find( 'delivery_status', $dl[2] ),
					'created_at'           => $created,
					'updated_at'           => $created,
				)
			);
		}

		foreach ( $d['followups'] as list( $date, $next ) ) {
			$wpdb->insert( ps_table( 'followups' ), array( 'project_id' => $pid, 'entry_date' => $date, 'author_id' => $user_id ?: null, 'text' => 'متابعة تجريبية', 'next_date' => $next, 'created_at' => $date . ' 09:00:00', 'updated_at' => $date . ' 09:00:00' ) );
		}

		foreach ( $d['competitors'] as $k => list( $name, $brand, $types ) ) {
			$wpdb->insert(
				ps_table( 'project_competitors' ),
				array(
					'project_id'    => $pid,
					'competitor_id' => ps_dir_find_or_create( 'competitors', $name, array(), $user_id ),
					'brand'         => $brand,
					'item_type_ids' => implode( ',', array_map( fn( $t ) => $find( 'item_type', $t ), $types ) ),
					'sort_order'    => ( $k + 1 ) * 10,
					'created_at'    => $created,
					'updated_at'    => $created,
				)
			);
		}
	}
	ps_lists_by_id( true );
	return $ids;
}
