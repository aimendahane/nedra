<?php
/**
 * Factures (version 1.6.0) — table ps_invoices.
 *
 * Une facture est liée à une OFFRE (= un صنف de Suivi Offres). Plusieurs factures par offre sont possibles
 * (paiements en tranches) ; chacune a son propre suivi.
 *
 * Champs : رقم الفاتورة (unique dans le système), تاريخ إرسال الفاتورة (obligatoire), جواب الحريف
 * (pending = في الانتظار par défaut / yes = نعم / no = لا), مبلغ + عملة (facultatifs, jamais additionnés entre
 * devises), ملاحظة الفاتورة, fichiers joints (ps_files.invoice_id).
 *
 * « آخر أجل للرد » n'est PAS stocké : calculé (inc/calculs.php) = date d'envoi + réglage invoice_reply_days.
 *
 * Droits :
 *   - voir : tout compte de l'application (comme les projets, décision n°1) ;
 *   - ajouter / modifier : ceux qui peuvent modifier l'offre (موظف du صنف, مسؤول المشروع, الإدارة, créateur 24 h)
 *     + le الموظف المتابع et l'المسؤول de l'offre ;
 *   - retirer (corbeille, jamais effacée) : مسؤول المشروع et الإدارة.
 */

defined( 'ABSPATH' ) || exit;

/** Réponses possibles du client (liste fermée). */
function ps_invoice_answers(): array {
	return array(
		'pending' => ps__( 'في الانتظار' ),
		'yes'     => ps__( 'نعم' ),
		'no'      => ps__( 'لا' ),
	);
}

function ps_invoice_row( int $id ): ?array {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'invoices' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $r ?: null;
}

/** Délai de réponse en jours (réglage). */
function ps_invoice_reply_days(): int {
	return max( 0, (int) ps_setting( 'invoice_reply_days' ) );
}

/**
 * Peut ajouter / modifier les factures de cette offre.
 */
function ps_invoice_can_edit( array $item, array $project, int $user_id ): bool {
	if ( ps_item_can_edit( $item, $project, $user_id ) ) {
		return true;
	}
	// + le الموظف المتابع et l'المسؤول de l'OFFRE (1.6.0, valeurs effectives).
	$emp = ps_user_employee_id( $user_id );
	return $emp && ! empty( $project ) && empty( $project['deleted_at'] )
		&& in_array( $emp, array( ps_item_effective( $item, $project, 'follower_emp_id' ), ps_item_effective( $item, $project, 'responsible_emp_id' ) ), true );
}

/** Retirer une facture (corbeille) : مسؤول المشروع et الإدارة. */
function ps_invoice_can_delete( array $project, int $user_id ): bool {
	return in_array( ps_project_edit_reason( $project, $user_id ), array( 'admin', 'responsible' ), true );
}

/**
 * Vérifie et nettoie les champs envoyés.
 *
 * @param array      $b        Corps JSON (invoiceNo, sentDate, answer, amount, currencyId, note).
 * @param array|null $existing Facture existante (modification) : seules les clés présentes sont changées.
 * @return array|WP_Error Colonnes à enregistrer.
 */
function ps_invoice_input( array $b, ?array $existing = null ) {
	global $wpdb;
	$errors = array();
	$set    = array();
	$has    = fn( $k ) => null === $existing || array_key_exists( $k, $b );

	if ( $has( 'invoiceNo' ) ) {
		$no = ps_clean_text( $b['invoiceNo'] ?? null );
		if ( null === $no ) {
			$errors['invoiceNo'] = ps__( 'خانة إجبارية: ' ) . ps__( 'رقم الفاتورة' );
		} elseif ( mb_strlen( $no ) > 100 ) {
			$errors['invoiceNo'] = ps__( 'رقم الفاتورة طويل جدًا' );
		} else {
			$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'invoices' ) . ' WHERE invoice_no = %s AND id <> %d', $no, (int) ( $existing['id'] ?? 0 ) ) );
			if ( $dup ) {
				$errors['invoiceNo'] = ps__( 'رقم الفاتورة مستعمل في فاتورة أخرى' );
			}
			$set['invoice_no'] = $no;
		}
	}
	if ( $has( 'sentDate' ) ) {
		$d = ps_clean_text( $b['sentDate'] ?? null );
		if ( null === $d ) {
			$errors['sentDate'] = ps__( 'خانة إجبارية: ' ) . ps__( 'تاريخ إرسال الفاتورة' );
		} elseif ( ! ps_is_valid_date( $d ) ) {
			$errors['sentDate'] = ps__( 'تاريخ غير صحيح' );
		} else {
			$set['sent_date'] = $d;
		}
	}
	if ( $has( 'answer' ) ) {
		$a = (string) ( $b['answer'] ?? 'pending' );
		$a = '' === $a ? 'pending' : $a;
		if ( ! isset( ps_invoice_answers()[ $a ] ) ) {
			$errors['answer'] = ps__( 'قيمة غير موجودة في القائمة' );
		} else {
			$set['answer'] = $a;
		}
	}
	if ( $has( 'amount' ) ) {
		$v = ps_clean_number( $b['amount'] ?? null );
		if ( null !== $v && ( ! is_numeric( $v ) || (float) $v < 0 ) ) {
			$errors['amount'] = ps__( 'مبلغ غير صحيح' );
		} else {
			$set['amount'] = $v;
		}
	}
	if ( $has( 'currencyId' ) || isset( $set['amount'] ) ) {
		$c = ps_clean_id( $b['currencyId'] ?? ( $existing['currency_id'] ?? null ) );
		if ( $c && 'currency' !== ps_list_key_of( $c ) ) {
			$errors['currencyId'] = ps__( 'قيمة غير موجودة في القائمة' );
		}
		$amount = array_key_exists( 'amount', $set ) ? $set['amount'] : ( $existing['amount'] ?? null );
		// Montant sans devise → devise par défaut (LYD), comme pour les projets.
		$set['currency_id'] = $c ?: ( null !== $amount ? ps_list_default_id( 'currency' ) : null );
	}
	if ( $has( 'note' ) ) {
		$set['note'] = ps_clean_text( $b['note'] ?? null, true );
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات المعلّمة' ), array( 'status' => 422, 'errors' => $errors ) );
	}
	return $set;
}

/**
 * Crée une facture pour une offre. @return int|WP_Error
 */
function ps_invoice_create( array $item, array $b, int $user_id ) {
	global $wpdb;
	$set = ps_invoice_input( $b );
	if ( is_wp_error( $set ) ) {
		return $set;
	}
	$now = ps_now();
	if ( 'pending' !== $set['answer'] ) {
		$set['answer_date'] = ps_today();
	}
	$wpdb->last_error = '';
	$ok = $wpdb->insert(
		ps_table( 'invoices' ),
		$set + array(
			'project_id' => (int) $item['project_id'],
			'item_id'    => (int) $item['id'],
			'created_by' => $user_id,
			'updated_by' => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	if ( ! $ok ) {
		// Deux enregistrements simultanés du même numéro : l'index unique tranche.
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات المعلّمة' ), array( 'status' => 422, 'errors' => array( 'invoiceNo' => ps__( 'رقم الفاتورة مستعمل في فاتورة أخرى' ) ) ) );
	}
	$id = (int) $wpdb->insert_id;
	ps_log( (int) $item['project_id'], 'create', 'invoice', $id, null, null, $set['invoice_no'], $user_id );
	return $id;
}

/**
 * Modifie une facture (seules les clés envoyées). Chaque changement est journalisé.
 *
 * @return true|WP_Error
 */
function ps_invoice_update( array $inv, array $b, int $user_id ) {
	global $wpdb;
	$set = ps_invoice_input( $b, $inv );
	if ( is_wp_error( $set ) ) {
		return $set;
	}
	if ( isset( $set['answer'] ) && $set['answer'] !== $inv['answer'] ) {
		$set['answer_date'] = 'pending' === $set['answer'] ? null : ps_today();
	}
	$diff = array();
	foreach ( $set as $col => $v ) {
		if ( (string) $inv[ $col ] === (string) $v || ( 'amount' === $col && null !== $v && null !== $inv[ $col ] && abs( (float) $v - (float) $inv[ $col ] ) < 0.0005 ) ) {
			continue;
		}
		$diff[ $col ] = $v;
		if ( 'answer_date' !== $col ) {
			$disp = static function ( $c, $x ) {
				if ( 'answer' === $c ) {
					return ps_invoice_answers()[ $x ] ?? $x;
				}
				return ps_field_display( $c, $x );
			};
			ps_log( (int) $inv['project_id'], 'update', 'invoice', (int) $inv['id'], 'sent_date' === $col ? 'sent_date_invoice' : $col, $disp( $col, $inv[ $col ] ), $disp( $col, $v ), $user_id );
		}
	}
	if ( $diff ) {
		$wpdb->update( ps_table( 'invoices' ), $diff + array( 'updated_by' => $user_id, 'updated_at' => ps_now() ), array( 'id' => (int) $inv['id'] ) );
	}
	return true;
}

function ps_invoice_soft_delete( array $inv, int $user_id ): void {
	global $wpdb;
	$wpdb->update( ps_table( 'invoices' ), array( 'deleted_at' => ps_now(), 'updated_by' => $user_id ), array( 'id' => (int) $inv['id'] ) );
	ps_log( (int) $inv['project_id'], 'delete', 'invoice', (int) $inv['id'], null, $inv['invoice_no'], null, $user_id );
}

/**
 * Factures (non retirées) avec les données de l'offre et du projet.
 *
 * @param array $args id, itemId, projectId, status (pending|late|answered|yes|no|all), q, employeeId (المتابع,
 *                    'me' = moi), year, month (date d'envoi de la facture), page, perPage.
 */
function ps_invoices_list( array $args, int $user_id, ?string $today = null ): array {
	global $wpdb;
	$today = $today ?? ps_today();
	$days  = ps_invoice_reply_days();
	$where = array( 'v.deleted_at IS NULL', 'p.deleted_at IS NULL' );
	$q_args = array();
	foreach ( array( 'id' => 'v.id', 'itemId' => 'v.item_id', 'projectId' => 'v.project_id' ) as $k => $col ) {
		if ( ! empty( $args[ $k ] ) ) {
			$where[]  = "$col = %d";
			$q_args[] = (int) $args[ $k ];
		}
	}
	$sql  = 'SELECT v.*, i.item_type_id, i.employee_id, i.offer_invoice_no, i.responsible_emp_id AS i_responsible, i.follower_emp_id AS i_follower,
			p.project_no, p.name AS project_name, p.legacy_id, p.responsible_emp_id, p.follower_emp_id, p.created_by AS p_created_by, p.created_at AS p_created_at, p.deleted_at AS p_deleted_at
		FROM ' . ps_table( 'invoices' ) . ' v
		JOIN ' . ps_table( 'items' ) . ' i ON i.id = v.item_id
		JOIN ' . ps_table( 'projects' ) . ' p ON p.id = v.project_id
		WHERE ' . implode( ' AND ', $where ) . ' ORDER BY v.sent_date, v.id';
	$rows = $q_args ? $wpdb->get_results( $wpdb->prepare( $sql, $q_args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

	$me     = ps_user_employee_id( $user_id );
	$emp    = $args['employeeId'] ?? '';
	$emp    = 'me' === $emp ? ( $me ?: -1 ) : $emp;
	$status = (string) ( $args['status'] ?? 'all' );
	$q      = ps_normalize_text( (string) ( $args['q'] ?? '' ) );
	$out    = array();
	// 1.6.1 : nombre de fichiers de chaque facture (colonne 📎).
	$files = array();
	if ( $rows ) {
		$ids = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
		foreach ( $wpdb->get_results( 'SELECT invoice_id, COUNT(*) AS n FROM ' . ps_table( 'files' ) . " WHERE deleted_at IS NULL AND invoice_id IN ($ids) GROUP BY invoice_id", ARRAY_A ) as $f ) { // phpcs:ignore
			$files[ (int) $f['invoice_id'] ] = (int) $f['n'];
		}
	}
	foreach ( $rows as $r ) {
		$api               = ps_invoice_to_api( $r, $user_id, $today, $days );
		$api['filesCount'] = $files[ (int) $r['id'] ] ?? 0;
		if ( '' !== (string) $emp && (int) $emp !== (int) $api['followerEmpId'] ) {
			continue;
		}
		if ( ! empty( $args['year'] ) && (int) $args['year'] !== (int) substr( $r['sent_date'], 0, 4 ) ) {
			continue;
		}
		if ( ! empty( $args['month'] ) && (int) $args['month'] !== (int) substr( $r['sent_date'], 5, 2 ) ) {
			continue;
		}
		$keep = match ( $status ) {
			'pending'  => 'pending' === $r['answer'],
			'late'     => $api['computed']['late'],
			'answered' => 'pending' !== $r['answer'],
			'yes', 'no' => $status === $r['answer'],
			default    => true,
		};
		if ( ! $keep ) {
			continue;
		}
		if ( '' !== $q && ! str_contains( ps_normalize_text( $r['invoice_no'] . ' ' . $r['project_no'] . ' ' . $r['project_name'] . ' ' . ps_project_old_no( $r ) . ' ' . $r['offer_invoice_no'] ), $q ) ) {
			continue;
		}
		$out[] = $api;
	}
	$total    = count( $out );
	$per_page = max( 1, min( 500, (int) ( $args['perPage'] ?? 200 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	return array(
		'total'     => $total,
		'replyDays' => $days,
		'counts'    => array(
			'pending' => count( array_filter( $out, fn( $o ) => 'pending' === $o['answer'] ) ),
			'late'    => count( array_filter( $out, fn( $o ) => $o['computed']['late'] ) ),
		),
		'rows'      => array_slice( $out, ( $page - 1 ) * $per_page, $per_page ),
	);
}

/**
 * Une facture au format de l'API (avec l'échéance et le retard calculés).
 */
function ps_invoice_to_api( array $r, int $user_id, ?string $today = null, ?int $days = null ): array {
	$today    = $today ?? ps_today();
	$days     = $days ?? ps_invoice_reply_days();
	$deadline = ps_calc_invoice_deadline( $r['sent_date'], $days );
	$project  = array(
		'id'                 => (int) $r['project_id'],
		'responsible_emp_id' => $r['responsible_emp_id'],
		'follower_emp_id'    => $r['follower_emp_id'],
		'created_by'         => $r['p_created_by'],
		'created_at'         => $r['p_created_at'],
		'deleted_at'         => $r['p_deleted_at'],
	);
	$item     = array( 'id' => (int) $r['item_id'], 'project_id' => (int) $r['project_id'], 'employee_id' => $r['employee_id'], 'responsible_emp_id' => $r['i_responsible'], 'follower_emp_id' => $r['i_follower'] );
	$u        = fn( $id ) => $id && ( $x = get_userdata( (int) $id ) ) ? $x->display_name : null;
	return array(
		'id'               => (int) $r['id'],
		'projectId'        => (int) $r['project_id'],
		'projectNo'        => $r['project_no'],
		'projectName'      => $r['project_name'],
		'oldNo'            => ps_project_old_no( $r ),
		'itemId'           => (int) $r['item_id'],
		'itemTypeId'       => $r['item_type_id'] ? (int) $r['item_type_id'] : null,
		'offerInvoiceNo'   => $r['offer_invoice_no'],
		'invoiceNo'        => $r['invoice_no'],
		'sentDate'         => $r['sent_date'],
		'answer'           => $r['answer'],
		'answerDate'       => $r['answer_date'],
		'amount'           => null === $r['amount'] ? null : (float) $r['amount'],
		'currencyId'       => $r['currency_id'] ? (int) $r['currency_id'] : null,
		'note'             => $r['note'],
		'followerEmpId'    => ps_item_effective( $item, $project, 'follower_emp_id' ),
		'responsibleEmpId' => ps_item_effective( $item, $project, 'responsible_emp_id' ),
		'createdBy'        => $u( $r['created_by'] ),
		'createdAt'        => $r['created_at'],
		'updatedAt'        => $r['updated_at'],
		'computed'         => array(
			'deadline' => $deadline,
			'late'     => ps_calc_invoice_late( $r['answer'], $deadline, $today ),
			'daysLate' => ps_calc_invoice_days_late( $r['answer'], $deadline, $today ),
			'daysLeft' => 'pending' === $r['answer'] ? ps_calc_days_between( $today, $deadline ) : null,
		),
		'canEdit'          => ps_invoice_can_edit( $item, $project, $user_id ),
		'canDelete'        => ps_invoice_can_delete( $project, $user_id ),
	);
}

/**
 * Indicateurs des factures pour le Recap (factures des projets retenus par les filtres du Recap).
 *
 * @param int[] $project_ids Projets de la période (faits du Recap).
 */
function ps_invoice_stats( array $project_ids, ?string $today = null ): array {
	global $wpdb;
	$today = $today ?? ps_today();
	$days  = ps_invoice_reply_days();
	$out   = array( 'total' => 0, 'pending' => 0, 'late' => 0, 'yes' => 0, 'no' => 0, 'rates' => ps_calc_invoice_answer_rates( 0, 0 ), 'amounts' => array(), 'yesAmounts' => array(), 'pendingAmounts' => array() );
	if ( ! $project_ids ) {
		return $out;
	}
	$ids  = implode( ',', array_map( 'intval', $project_ids ) );
	$rows = $wpdb->get_results( 'SELECT answer, sent_date, amount, currency_id FROM ' . ps_table( 'invoices' ) . " WHERE deleted_at IS NULL AND project_id IN ($ids)", ARRAY_A ); // phpcs:ignore
	foreach ( $rows as $r ) {
		++$out['total'];
		++$out[ $r['answer'] ];
		if ( ps_calc_invoice_late( $r['answer'], ps_calc_invoice_deadline( $r['sent_date'], $days ), $today ) ) {
			++$out['late'];
		}
	}
	$out['rates']          = ps_calc_invoice_answer_rates( $out['yes'], $out['no'] );
	$amount                = fn( $filter ) => ps_calc_amounts_by_currency( array_map( fn( $r ) => array( $r['amount'], $r['currency_id'] ), array_filter( $rows, $filter ) ) );
	$out['amounts']        = $amount( fn( $r ) => true );
	$out['yesAmounts']     = $amount( fn( $r ) => 'yes' === $r['answer'] );
	$out['pendingAmounts'] = $amount( fn( $r ) => 'pending' === $r['answer'] );
	return $out;
}
