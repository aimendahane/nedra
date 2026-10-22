<?php
/**
 * Import du backup JSON du prototype (« suivi-backup.json » : { "p": [ projets… ] }).
 *
 * Aperçu puis import : l'aperçu exécute exactement le même import dans une transaction
 * puis l'ANNULE (ROLLBACK) → le rapport montre fidèlement ce qui sera créé.
 * Avant le vrai import : sauvegarde JSON automatique.
 *
 * Conversions (cahier §8.1, §8.3, décisions client n°3 et n°6) :
 *   - urgence : عاجل → U par défaut (VU au choix dans l'aperçu), متوسط → U, عادي → N ;
 *   - حالة المتابعة : جديد / قيد المتابعة → في دراسة, في انتظار الحريف → في انتظار الرد,
 *     مقبول → تم تأكيد الطلبية, مرفوض → رفض العرض, مغلق → تم التسليم (via les « alias » des listes) ;
 *   - employé « موقع العطاءات » : مصدر = موقع العطاءات et المتابع = المسؤول عن المشروع ;
 *     tous les autres projets : مصدر = « غير محدد » ;
 *   - lieux « T – Tripoli » → code T ; valeurs inconnues (lieu, صنف, employé, تصنيف) → ajoutées aux listes ;
 *   - sociétés et concurrents → annuaires (une seule fiche par nom normalisé) ;
 *   - un projet dont le رقم المشروع existe déjà, ou déjà importé (même identifiant du prototype),
 *     est IGNORÉ (jamais d'écrasement) : on peut réimporter le même fichier sans doublon ;
 *   - le JSON d'origine de chaque projet est conservé (legacy_raw).
 * Les fichiers joints du prototype n'étaient pas dans son backup : ils ne sont pas importés.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extrait la liste des projets d'un backup du prototype.
 *
 * @return array|WP_Error
 */
function ps_import_parse( string $json ) {
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'ps_import_format', ps__( 'الملف ليس JSON صالحًا' ), array( 'status' => 422 ) );
	}
	$projects = isset( $data['p'] ) && is_array( $data['p'] ) ? $data['p'] : ( array_is_list( $data ) ? $data : null );
	if ( null === $projects ) {
		return new WP_Error( 'ps_import_format', ps__( 'الملف لا يحتوي على مشاريع النسخة الأولى (المفتاح «p»)' ), array( 'status' => 422 ) );
	}
	return array_values( array_filter( $projects, 'is_array' ) );
}

/**
 * Lance l'import (ou l'aperçu si $dry_run).
 *
 * @param array $options urgentAs (U|VU), currencyId (devise des montants sans devise)
 */
function ps_import_prototype( array $projects, array $options, bool $dry_run, int $user_id ): array {
	global $wpdb;
	$ctx = array(
		'user'     => $user_id,
		'urgent'   => 'VU' === ( $options['urgentAs'] ?? 'U' ) ? 'VU' : 'U',
		// Fichier Excel (1.4.0) : valeurs de الأهمية inconnues ajoutées à la liste (jamais perdues), libellé du journal.
		'excel'    => ! empty( $options['excel'] ),
		'currency' => ps_clean_id( $options['currencyId'] ?? null ) ?: ps_list_default_id( 'currency' ),
		'report'   => array(
			'total'       => count( $projects ),
			'imported'    => 0,
			'skipped'     => array(),
			'created'     => array( 'employee' => array(), 'place' => array(), 'category' => array(), 'item_type' => array(), 'importance' => array() ),
			'companies'   => array( 'created' => 0, 'reused' => 0 ),
			'competitors' => 0,
			'conversions' => array( 'urgency' => array(), 'followup' => array(), 'source' => array(), 'offer' => array() ),
			'warnings'    => array(),
			'dryRun'      => $dry_run,
		),
		'cache'    => array(),
	);

	if ( ! $dry_run ) {
		$backup = ps_backup_write_auto( 'before-import' );
		if ( false === $backup ) {
			$ctx['report']['warnings'][] = ps__( 'تعذّرت النسخة الاحتياطية التلقائية: لم يتم الاستيراد.' );
			return $ctx['report'];
		}
		$ctx['report']['backup'] = basename( $backup );
	}

	// Projets avec un numéro valide (PRJ-AAAA-NNN ou P123) d'abord (pour garder leurs numéros).
	$numbered = fn( $p ) => ps_import_valid_no( $p['no'] ?? '' ) || ps_import_excel_no( $p['no'] ?? '' );
	usort( $projects, fn( $a, $b ) => (int) ! $numbered( $a ) <=> (int) ! $numbered( $b ) );

	$wpdb->query( 'START TRANSACTION' );
	try {
		foreach ( $projects as $i => $p ) {
			ps_import_one( $p, $i, $ctx );
		}
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		ps_lists_by_id( true );
		$ctx['report']['warnings'][] = ps__( 'خطأ أثناء الاستيراد، لم يتم حفظ أي شيء: ' ) . $e->getMessage();
		$ctx['report']['imported']   = 0;
		return $ctx['report'];
	}
	$wpdb->query( $dry_run ? 'ROLLBACK' : 'COMMIT' );
	ps_lists_by_id( true );
	return $ctx['report'];
}

/** Numéro du fichier Excel de l'équipe : « P029 », « P176 »… (1.4.0, conservé tel quel). */
function ps_import_excel_no( $no ): bool {
	return is_string( $no ) && (bool) preg_match( '/^P\d{1,6}$/i', trim( $no ) );
}

function ps_import_valid_no( $no ): bool {
	return is_string( $no ) && (bool) preg_match( '/^PRJ-(\d{4})-(\d{1,6})$/', trim( $no ) );
}

/** Compte une conversion « avant → après » pour le rapport. */
function ps_import_count( array &$ctx, string $kind, string $from, string $to ): void {
	$k                                         = ( '' === $from ? ps__( '(فارغ)' ) : $from ) . ' ← ' . $to;
	$ctx['report']['conversions'][ $kind ][ $k ] = ( $ctx['report']['conversions'][ $kind ][ $k ] ?? 0 ) + 1;
}

/**
 * Trouve (ou crée) une valeur de liste à partir d'un texte du prototype.
 * Correspondance : libellé normalisé, code, ou alias (anciennes valeurs).
 */
function ps_import_list( string $key, $text, array &$ctx, bool $create = true ): ?int {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return null;
	}
	$code = null;
	$name = $text;
	if ( 'place' === $key && preg_match( '/^([A-Za-z]{1,4})\s*[–\-]\s*(.+)$/u', $text, $m ) ) {
		$code = strtoupper( $m[1] );
		$name = trim( $m[2] );
	}
	$ck = $key . '|' . ps_normalize_text( $text );
	if ( isset( $ctx['cache'][ $ck ] ) ) {
		return $ctx['cache'][ $ck ];
	}
	$norm = ps_normalize_text( $name );
	// Comparaison tolérante (1.4.0) : sans espaces et ظ = ض (« مفاتيح و برايز » = « مفاتيح وبرايز », « منضومة » = « منظومة »).
	$loose = static fn( $s ) => strtr( preg_replace( '/\s+/u', '', ps_normalize_text( (string) $s ) ), array( 'ظ' => 'ض' ) );
	$id    = null;
	foreach ( ps_list_values( $key ) as $v ) {
		$aliases = array_map( 'ps_normalize_text', (array) $v['aliases'] );
		if ( ps_normalize_text( $v['label'] ) === $norm || $loose( $v['label'] ) === $loose( $name ) || ( $code && $v['code'] && strtoupper( $v['code'] ) === $code )
			|| ( $v['code'] && strtoupper( $v['code'] ) === strtoupper( $text ) ) || in_array( ps_normalize_text( $text ), $aliases, true ) ) {
			$id = $v['id'];
			break;
		}
	}
	if ( ! $id && $create ) {
		global $wpdb;
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s', $key ) );
		$id  = ps_insert_list_value( $key, array( 'label' => $name, 'code' => $code ), $max + 10 );
		$ctx['report']['created'][ $key ][] = $code ? "$code – $name" : $name;
		ps_lists_by_id( true );
	}
	$ctx['cache'][ $ck ] = $id;
	return $id;
}

/**
 * Importe un projet du prototype.
 */
function ps_import_one( array $p, int $index, array &$ctx ): void {
	global $wpdb;
	$t     = ps_table( 'projects' );
	$no    = trim( (string) ( $p['no'] ?? '' ) );
	$label = $no ?: ( '#' . ( $index + 1 ) );

	// Déjà présent (même رقم المشروع, ou même projet du prototype déjà importé) → ignoré, jamais écrasé.
	$legacy = isset( $p['id'] ) && '' !== (string) $p['id'] ? (string) $p['id'] : null;
	if ( ( $no && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE project_no = %s", $no ) ) )
		|| ( $legacy && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE legacy_id = %s", $legacy ) ) ) ) {
		$ctx['report']['skipped'][] = $label;
		return;
	}

	// Dates.
	$recv = (string) ( $p['recv'] ?? '' );
	$ts   = is_numeric( $p['id'] ?? null ) && (float) $p['id'] > 1e12 ? (int) ( (float) $p['id'] / 1000 ) : null; // id = Date.now() en ms
	if ( ! ps_is_valid_date( $recv ) ) {
		$recv = $ts ? wp_date( 'Y-m-d', $ts ) : ( ! empty( $p['year'] ) ? (int) $p['year'] . '-01-01' : ps_today() );
		$ctx['report']['warnings'][] = sprintf( ps__( '%1$s: تاريخ الاستلام غير موجود ← %2$s' ), $label, $recv );
	}
	$sent = ps_is_valid_date( $p['sent'] ?? '' ) ? $p['sent'] : null;
	if ( $sent && $sent < $recv ) {
		$ctx['report']['warnings'][] = sprintf( ps__( '%s: تاريخ الإرسال قبل تاريخ الاستلام (تم الإبقاء عليه)' ), $label );
	}
	$created = $ts && $ts > strtotime( '2015-01-01' ) && $ts <= time() ? wp_date( 'Y-m-d H:i:s', $ts ) : ps_now();

	// Employés, source (décision n°3).
	$is_tenders = static fn( $v ) => ps_normalize_text( (string) $v ) === ps_normalize_text( 'موقع العطاءات' );
	$emp        = static function ( $v ) use ( &$ctx, $is_tenders ) {
		return ( '' === trim( (string) $v ) || $is_tenders( $v ) ) ? null : ps_import_list( 'employee', $v, $ctx );
	};
	$resp     = $emp( $p['resp'] ?? '' );
	$follower = $emp( $p['emp'] ?? '' );
	if ( $is_tenders( $p['emp'] ?? '' ) || $is_tenders( $p['resp'] ?? '' ) || $is_tenders( $p['mid'] ?? '' ) ) {
		$source   = ps_list_id_by_code( 'source', 'tenders' );
		$follower = $follower ?: $resp;
		ps_import_count( $ctx, 'source', ps__( 'موظف «موقع العطاءات»' ), ps__( 'مصدر «موقع العطاءات»' ) );
	} else {
		$source = ps_list_id_by_code( 'source', 'unspecified' );
		ps_import_count( $ctx, 'source', ps__( 'مشروع قديم' ), ps__( 'غير محدد' ) );
	}

	// Urgence (عاجل → U ou VU selon le choix).
	$urg_txt = trim( (string) ( $p['urg'] ?? '' ) );
	$urgency = null;
	if ( '' !== $urg_txt ) {
		$urgency = ps_normalize_text( $urg_txt ) === ps_normalize_text( 'عاجل' ) ? ps_list_id_by_code( 'urgency', $ctx['urgent'] ) : ps_import_list( 'urgency', $urg_txt, $ctx, false );
		if ( ! $urgency ) {
			$ctx['report']['warnings'][] = sprintf( ps__( '%1$s: درجة استعجال غير معروفة «%2$s» (تركت فارغة)' ), $label, $urg_txt );
		} else {
			ps_import_count( $ctx, 'urgency', $urg_txt, ps_list_code( $urgency ) );
		}
	}

	// حالة المتابعة.
	$fu_txt = trim( (string) ( $p['fu'] ?? '' ) );
	$fu     = '' === $fu_txt ? null : ps_import_list( 'followup_status', $fu_txt, $ctx, false );
	if ( ! $fu ) {
		$fu = ps_list_default_id( 'followup_status' );
		if ( '' !== $fu_txt ) {
			$ctx['report']['warnings'][] = sprintf( ps__( '%1$s: حالة متابعة غير معروفة «%2$s» ← في دراسة' ), $label, $fu_txt );
		}
	}
	ps_import_count( $ctx, 'followup', $fu_txt, ps_list_label( $fu ) );

	// Numérotation : numéro d'origine si libre, sinon nouveau numéro.
	if ( ps_import_valid_no( $no ) ) {
		preg_match( '/^PRJ-(\d{4})-(\d+)$/', $no, $m );
		$year = (int) $m[1];
		$seq  = (int) $m[2];
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE year = %d AND seq = %d", $year, $seq ) ) ) {
			$seq = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(seq) FROM $t WHERE year = %d", $year ) );
			$ctx['report']['warnings'][] = sprintf( ps__( '%1$s: الرقم مستعمل ← %2$s' ), $no, sprintf( 'PRJ-%d-%03d', $year, $seq ) );
			$no = sprintf( 'PRJ-%d-%03d', $year, $seq );
		}
	} elseif ( ps_import_excel_no( $no ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE year = %d AND seq = %d", (int) substr( $recv, 0, 4 ), (int) substr( $no, 1 ) ) ) ) {
		// Numéro « P029 » du fichier Excel gardé tel quel (problème 09) ; seq = son nombre.
		$no   = strtoupper( $no );
		$year = (int) substr( $recv, 0, 4 );
		$seq  = (int) substr( $no, 1 );
	} else {
		if ( ps_import_excel_no( $no ) ) {
			$ctx['report']['warnings'][] = sprintf( ps__( '%s: الرقم مستعمل في نفس السنة ← رقم جديد' ), $label );
		}
		$year = (int) substr( $recv, 0, 4 );
		$seq  = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(seq) FROM $t WHERE year = %d", $year ) );
		$no   = sprintf( 'PRJ-%d-%03d', $year, $seq );
	}

	$offer_no = ps_clean_text( $p['offno'] ?? null );
	if ( $offer_no && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE offer_no = %s", $offer_no ) ) ) {
		$ctx['report']['warnings'][] = sprintf( ps__( '%1$s: رقم العرض %2$s مستعمل ← %3$s' ), $label, $offer_no, $offer_no . '-' . $seq );
		$offer_no .= '-' . $seq;
	}

	$amount = ps_clean_number( $p['amount'] ?? null );
	$amount = is_numeric( $amount ) ? $amount : null;
	$name   = ps_clean_text( $p['name'] ?? null ) ?? '(بدون اسم)';

	$wpdb->insert(
		$t,
		array(
			'project_no'          => $no,
			'year'                => $year,
			'seq'                 => $seq,
			'month'               => (int) substr( $recv, 5, 2 ),
			'offer_no'            => $offer_no,
			'name'                => $name,
			'name_norm'           => ps_normalize_text( $name ),
			'received_date'       => $recv,
			'sent_date'           => $sent,
			'responsible_emp_id'  => $resp,
			'follower_emp_id'     => $follower,
			'intermediary_emp_id' => $emp( $p['mid'] ?? '' ),
			'place_id'            => ps_import_list( 'place', $p['place'] ?? '', $ctx ),
			'category_id'         => ps_import_list( 'category', $p['cls'] ?? '', $ctx ),
			'urgency_id'          => $urgency,
			'importance_kind_id'  => ps_import_list( 'importance', $p['imc'] ?? '', $ctx, $ctx['excel'] ),
			'importance_size_id'  => ps_import_list( 'importance', $p['imv'] ?? '', $ctx, $ctx['excel'] ),
			'followup_status_id'  => $fu,
			'source_id'           => $source,
			'offer_amount'        => $amount,
			'offer_currency_id'   => null === $amount ? null : $ctx['currency'],
			'files_link'          => ps_clean_text( $p['link'] ?? null ),
			'note'                => ps_clean_text( $p['note'] ?? null, true ),
			'created_by'          => $ctx['user'],
			'updated_by'          => $ctx['user'],
			'created_at'          => $created,
			'updated_at'          => $created,
			'legacy_id'           => isset( $p['id'] ) ? (string) $p['id'] : null,
			'legacy_raw'          => wp_json_encode( $p, JSON_UNESCAPED_UNICODE ),
		)
	);
	$pid = (int) $wpdb->insert_id;
	if ( ! $pid ) {
		throw new RuntimeException( "$label: " . $wpdb->last_error );
	}

	// Intervenants : own → المالك, exe → المنفذة, dir → المباشر, mid2 → الوسيط.
	foreach ( array( 'own' => 'owner', 'exe' => 'executor', 'dir' => 'direct', 'mid2' => 'broker' ) as $prefix => $role ) {
		$f = array();
		foreach ( array( 'co', 'pe', 'em', 'ph', 'ad', 'po' ) as $k ) {
			$f[ $k ] = ps_clean_text( $p[ $prefix . '_' . $k ] ?? null );
		}
		if ( ! array_filter( $f ) ) {
			continue;
		}
		$company = null;
		if ( $f['co'] ) {
			$exists  = ps_dir_find_by_name( 'companies', $f['co'] );
			$company = ps_dir_find_or_create( 'companies', $f['co'], array( 'person' => $f['pe'], 'email' => $f['em'], 'phone' => $f['ph'], 'address' => $f['ad'], 'position' => $f['po'] ), $ctx['user'] );
			$ctx['report']['companies'][ $exists ? 'reused' : 'created' ]++;
		}
		$wpdb->insert( ps_table( 'project_parties' ), array( 'project_id' => $pid, 'role' => $role, 'company_id' => $company, 'person' => $f['pe'], 'email' => $f['em'], 'phone' => $f['ph'], 'address' => $f['ad'], 'position' => $f['po'], 'created_at' => $created, 'updated_at' => $created ) );
	}

	// أصناف.
	$offer_map = array( 'قيد الإعداد' => 'preparing', 'مرسل' => 'sent', 'لم يرسل' => 'not_sent', 'مقبول' => 'accepted', 'مرفوض' => 'rejected', 'ملغى' => 'cancelled', 'ملغي' => 'cancelled' );
	$deliv_map = array( 'في الانتظار' => 'waiting', 'جاهز' => 'ready', 'تم التسليم' => 'delivered', 'متاخر' => 'waiting' );
	$avail_map = array( 'نعم' => 'yes', 'لا' => 'no', 'جزئي' => 'partial' );
	$norm_map  = static function ( array $map ) {
		$o = array();
		foreach ( $map as $k => $v ) {
			$o[ ps_normalize_text( $k ) ] = $v;
		}
		return $o;
	};
	$offer_map = $norm_map( $offer_map );
	$deliv_map = $norm_map( $deliv_map );
	$avail_map = $norm_map( $avail_map );
	foreach ( array_values( (array) ( $p['items'] ?? array() ) ) as $k => $it ) {
		if ( ! is_array( $it ) || '' === trim( (string) ( $it['n'] ?? '' ) ) ) {
			continue;
		}
		$st   = $offer_map[ ps_normalize_text( (string) ( $it['st'] ?? '' ) ) ] ?? 'preparing';
		$dl   = $deliv_map[ ps_normalize_text( (string) ( $it['ds'] ?? '' ) ) ] ?? 'waiting';
		$av   = $avail_map[ ps_normalize_text( (string) ( $it['av'] ?? '' ) ) ] ?? null;
		$qty  = ps_clean_number( $it['q'] ?? null );
		ps_import_count( $ctx, 'offer', (string) ( $it['st'] ?? '' ), (string) ps_list_label( ps_list_id_by_code( 'offer_status', $st ) ) );
		$wpdb->insert(
			ps_table( 'items' ),
			array(
				'project_id'           => $pid,
				'sort_order'           => ( $k + 1 ) * 10,
				'item_type_id'         => ps_import_list( 'item_type', $it['n'], $ctx ),
				'quantity'             => is_numeric( $qty ) ? $qty : null,
				'employee_id'          => $emp( $it['emp'] ?? '' ),
				'due_date'             => ps_is_valid_date( $it['due'] ?? '' ) ? $it['due'] : null,
				'offer_status_id'      => ps_list_id_by_code( 'offer_status', $st ),
				'offer_date'           => ps_is_valid_date( $it['od'] ?? '' ) ? $it['od'] : null,
				'offer_invoice_no'     => ps_clean_text( $it['inv'] ?? null ),
				'offer_notes'          => ps_clean_text( $it['on'] ?? null, true ),
				'supplier_invoice_no'  => ps_clean_text( $it['sinv'] ?? null ),
				'availability_id'      => $av ? ps_list_id_by_code( 'availability', $av ) : null,
				'delivery_due_date'    => ps_is_valid_date( $it['dd'] ?? '' ) ? $it['dd'] : null,
				'delivery_actual_date' => ps_is_valid_date( $it['da'] ?? '' ) ? $it['da'] : null,
				'delivery_status_id'   => ps_list_id_by_code( 'delivery_status', $dl ),
				'delivery_notes'       => ps_clean_text( $it['ln'] ?? null, true ),
				'created_at'           => $created,
				'updated_at'           => $created,
			)
		);
		if ( 'not_sent' === $st ) {
			$ctx['report']['warnings'][] = sprintf( ps__( '%s: صنف «لم يُرسل» بدون سبب (يُكمّل من صفحة العروض)' ), $label );
		}
	}

	// منافسون : c = منافس, b = ماركة, a = أصناف (texte), n = ملاحظات.
	foreach ( array_values( (array) ( $p['comp'] ?? array() ) ) as $k => $c ) {
		if ( ! is_array( $c ) || ( '' === trim( (string) ( $c['c'] ?? '' ) ) && '' === trim( (string) ( $c['a'] ?? '' ) ) ) ) {
			continue;
		}
		$types   = array();
		$unknown = array();
		foreach ( preg_split( '/[،,;\/]+/u', (string) ( $c['a'] ?? '' ) ) as $txt ) {
			$txt = trim( $txt );
			if ( '' === $txt ) {
				continue;
			}
			$id = ps_import_list( 'item_type', $txt, $ctx, false );
			$id ? $types[] = $id : $unknown[] = $txt;
		}
		$notes = trim( ( ps_clean_text( $c['n'] ?? null ) ?? '' ) . ( $unknown ? ' [أصناف: ' . implode( '، ', $unknown ) . ']' : '' ) );
		$wpdb->insert(
			ps_table( 'project_competitors' ),
			array(
				'project_id'    => $pid,
				'competitor_id' => '' !== trim( (string) ( $c['c'] ?? '' ) ) ? ps_dir_find_or_create( 'competitors', (string) $c['c'], array(), $ctx['user'] ) : null,
				'brand'         => ps_clean_text( $c['b'] ?? null ),
				'item_type_ids' => $types ? implode( ',', array_unique( $types ) ) : null,
				'notes'         => '' === $notes ? null : $notes,
				'sort_order'    => ( $k + 1 ) * 10,
				'created_at'    => $created,
				'updated_at'    => $created,
			)
		);
		++$ctx['report']['competitors'];
	}

	ps_log( $pid, 'import', 'project', $pid, null, null, $ctx['excel'] ? 'ملف Excel' : 'النسخة الأولى (prototype)', $ctx['user'] );
	++$ctx['report']['imported'];
}
