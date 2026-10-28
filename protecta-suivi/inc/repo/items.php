<?php
/**
 * أصناف : mise à jour de l'offre (Suivi Offres) et de la livraison (Suivi Livraison).
 *
 * Qui peut modifier un صنف :
 *   - le موظف responsable de ce صنف (capacité ps_edit_own_items) ;
 *   - toute personne qui peut modifier le projet (الإدارة، مسؤول المشروع، créateur pendant 24 h).
 *
 * Règles (brief §5, décision n°5) :
 *   - « لم يُرسل » exige « سبب عدم الإرسال » (+ note libre optionnelle) ;
 *   - passer à مرسل / مقبول / مرفوض exige que le projet ait une « تاريخ إرسال العرض » :
 *     si elle est vide, l'interface propose la date du jour (modifiable) et l'envoie
 *     dans « projectSentDate » ; la règle الإرسال ≥ الاستلام reste valable ;
 *   - passer à مرسل sans تاريخ تقديم العرض → date du jour ;
 *   - « متأخر » (livraison) est calculé, jamais choisi à la main.
 *
 * 1.6.0 — « المسؤول عن المشروع » et « الموظف المتابع » de l'OFFRE (= ce صنف) : vides = ceux du projet
 * (pré-remplis), modifiables seulement par مسؤول المشروع et la الإدارة, avec une justification OBLIGATOIRE
 * enregistrée au journal (qui, quand, ancienne → nouvelle valeur, justification). « رقم فاتورة العرض » unique.
 */

defined( 'ABSPATH' ) || exit;

/** Champs modifiables : clé API => [ colonne, type, liste ]. */
function ps_item_update_fields(): array {
	return array(
		'offerStatusId'      => array( 'offer_status_id', 'list', 'offer_status' ),
		'offerDate'          => array( 'offer_date', 'date', null ),
		'notSentReasonId'    => array( 'not_sent_reason_id', 'list', 'not_sent_reason' ),
		'notSentNote'        => array( 'not_sent_note', 'textarea', null ),
		'offerInvoiceNo'     => array( 'offer_invoice_no', 'text', null ),
		'offerNotes'         => array( 'offer_notes', 'textarea', null ),
		'supplierInvoiceNo'  => array( 'supplier_invoice_no', 'text', null ),
		'availabilityId'     => array( 'availability_id', 'list', 'availability' ),
		'deliveryDueDate'    => array( 'delivery_due_date', 'date', null ),
		'deliveryActualDate' => array( 'delivery_actual_date', 'date', null ),
		'deliveryStatusId'   => array( 'delivery_status_id', 'list', 'delivery_status' ),
		'deliveryNotes'      => array( 'delivery_notes', 'textarea', null ),
	);
}

function ps_item_row( int $id ): ?array {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'items' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $r ?: null;
}

/**
 * L'utilisateur peut-il modifier ce صنف ?
 */
function ps_item_can_edit( array $item, array $project, int $user_id ): bool {
	if ( ! $user_id || ! empty( $project['deleted_at'] ) ) {
		return false;
	}
	// الإدارة et le مسؤول : tous les أصناف. Le créateur (fenêtre de 24 h) : seulement les siens (1.4.0).
	if ( in_array( ps_project_edit_reason( $project, $user_id ), array( 'admin', 'responsible' ), true )
		|| ( 'creator' === ps_project_edit_reason( $project, $user_id ) && user_can( $user_id, 'ps_edit_own_projects' ) ) ) {
		return true;
	}
	$emp = ps_user_employee_id( $user_id );
	return $emp && user_can( $user_id, 'ps_edit_own_items' ) && (int) $item['employee_id'] === $emp;
}

/**
 * Peut changer المسؤول / المتابع d'une offre : الإدارة ou مسؤول المشروع du projet (1.6.0).
 */
function ps_item_can_assign( array $project, int $user_id ): bool {
	return in_array( ps_project_edit_reason( $project, $user_id ), array( 'admin', 'responsible' ), true );
}

/** المسؤول / المتابع effectifs d'une offre : la valeur de l'offre, sinon celle du projet. */
function ps_item_effective( array $item, array $project, string $col ): ?int {
	$v = ! empty( $item[ $col ] ) ? $item[ $col ] : ( $project[ $col ] ?? null );
	return $v ? (int) $v : null;
}

/**
 * Valide puis enregistre une modification partielle d'un صنف.
 *
 * @param array $body Clés de ps_item_update_fields() présentes = à modifier ; + projectSentDate.
 * @return true|WP_Error
 */
function ps_item_update( array $item, array $project, array $body, int $user_id ) {
	global $wpdb;
	$lists  = ps_lists_by_id();
	$errors = array();
	$set    = array();

	foreach ( ps_item_update_fields() as $key => list( $col, $type, $list_key ) ) {
		if ( ! array_key_exists( $key, $body ) ) {
			continue;
		}
		$v = $body[ $key ];
		if ( 'list' === $type ) {
			$v = ps_clean_id( $v );
			if ( $v ) {
				$row = $lists[ $v ] ?? null;
				if ( ! $row || $row['list_key'] !== $list_key ) {
					$errors[ $key ] = ps__( 'قيمة غير موجودة في القائمة' );
				} elseif ( $row['is_hidden'] && (int) $item[ $col ] !== $v ) {
					$errors[ $key ] = ps__( 'هذه القيمة لم تعد متاحة' );
				} elseif ( 'delivery_status' === $list_key && 'late' === $row['code'] ) {
					$errors[ $key ] = ps__( '«متأخر» تُحسب تلقائيًا ولا تُختار' );
				}
			} elseif ( 'offer_status_id' === $col ) {
				$errors[ $key ] = ps__( 'اختر حالة العرض' );
			}
		} elseif ( 'date' === $type ) {
			$v = ps_clean_text( $v );
			if ( null !== $v && ! ps_is_valid_date( $v ) ) {
				$errors[ $key ] = ps__( 'تاريخ غير صحيح' );
			}
		} else {
			$v = ps_clean_text( $v, 'textarea' === $type );
		}
		$set[ $col ] = $v;
	}

	// 1.6.0 : المسؤول / المتابع de l'offre (justification obligatoire, droits مسؤول + الإدارة).
	$assign = array();
	foreach ( array( 'responsibleEmpId' => 'responsible_emp_id', 'followerEmpId' => 'follower_emp_id' ) as $key => $col ) {
		if ( ! array_key_exists( $key, $body ) ) {
			continue;
		}
		$v = ps_clean_id( $body[ $key ] );
		if ( $v === ps_item_effective( $item, $project, $col ) ) {
			continue; // aucun changement
		}
		if ( ! ps_item_can_assign( $project, $user_id ) ) {
			return new WP_Error( 'ps_forbidden', ps__( 'تغيير المسؤول أو المتابع للعرض: لمسؤول المشروع والإدارة فقط.' ), array( 'status' => 403 ) );
		}
		$row = $v ? ( $lists[ $v ] ?? null ) : null;
		if ( ! $v ) {
			$errors[ $key ] = ps__( 'اختر الموظف' );
		} elseif ( ! $row || 'employee' !== $row['list_key'] ) {
			$errors[ $key ] = ps__( 'قيمة غير موجودة في القائمة' );
		} elseif ( $row['is_hidden'] ) {
			$errors[ $key ] = ps__( 'هذه القيمة لم تعد متاحة' );
		} else {
			$assign[ $col ] = $v;
		}
	}
	$reason = ps_clean_text( $body['reason'] ?? null, true );
	if ( $assign && null === $reason ) {
		$errors['reason'] = ps__( 'اكتب سبب التغيير (إجباري)' );
	}

	// « رقم فاتورة العرض » unique dans tout le système.
	if ( ! empty( $set['offer_invoice_no'] ) ) {
		$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT p.project_no FROM ' . ps_table( 'items' ) . ' i JOIN ' . ps_table( 'projects' ) . ' p ON p.id = i.project_id WHERE i.offer_invoice_no = %s AND i.id <> %d LIMIT 1', $set['offer_invoice_no'], (int) $item['id'] ) );
		if ( $dup ) {
			$errors['offerInvoiceNo'] = ps__( 'رقم فاتورة العرض مستعمل في المشروع ' ) . $dup;
		}
	}

	$new        = array_merge( $item, $set );
	$offer_code = $new['offer_status_id'] ? ( $lists[ (int) $new['offer_status_id'] ]['code'] ?? null ) : null;
	$changed_to = isset( $set['offer_status_id'] ) && (int) $set['offer_status_id'] !== (int) $item['offer_status_id'] ? $offer_code : null;

	// « لم يُرسل » → سبب عدم الإرسال obligatoire.
	if ( 'not_sent' === $offer_code && ! $new['not_sent_reason_id'] && ! isset( $errors['notSentReasonId'] ) ) {
		$errors['notSentReasonId'] = ps__( 'اختر سبب عدم الإرسال' );
	}

	// Offre envoyée → تاريخ إرسال العرض du projet obligatoire (décision n°5).
	$project_sent = null;
	if ( in_array( $offer_code, array( 'sent', 'accepted', 'rejected' ), true ) && empty( $project['sent_date'] ) ) {
		$project_sent = ps_clean_text( $body['projectSentDate'] ?? null );
		if ( null === $project_sent || ! ps_is_valid_date( $project_sent ) ) {
			$errors['projectSentDate'] = ps__( 'تاريخ إرسال العرض إجباري عند إرسال العرض' );
		} elseif ( ! empty( ps_validation_rules()['sent_after_received'] ) && $project_sent < $project['received_date'] ) {
			$errors['projectSentDate'] = ps__( 'تاريخ الإرسال لازم يكون بعد تاريخ الاستلام أو نفس اليوم' );
		}
	}

	if ( $errors ) {
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات المعلّمة' ), array( 'status' => 422, 'errors' => $errors ) );
	}

	if ( 'sent' === $changed_to && empty( $new['offer_date'] ) ) {
		// تاريخ تقديم العرض par défaut : la date d'envoi du projet si elle existe (1.6.0, problème n°01), sinon aujourd'hui.
		$set['offer_date'] = ! empty( $project['sent_date'] ) ? $project['sent_date'] : ( $project_sent ? $project_sent : ps_today() );
	}

	$now = ps_now();
	$wpdb->query( 'START TRANSACTION' );
	$diff = array();
	foreach ( $set as $col => $v ) {
		if ( (string) $item[ $col ] !== (string) $v ) {
			$diff[ $col ] = $v;
			ps_log( (int) $project['id'], 'update', 'item', (int) $item['id'], $col, ps_field_display( $col, $item[ $col ] ), ps_field_display( $col, $v ), $user_id );
		}
	}
	foreach ( $assign as $col => $v ) {
		$diff[ $col ] = $v;
		ps_log( (int) $project['id'], 'update', 'item', (int) $item['id'], $col, ps_list_label( ps_item_effective( $item, $project, $col ) ), ps_list_label( $v ), $user_id, $reason );
	}
	if ( $diff ) {
		$wpdb->update( ps_table( 'items' ), array_merge( $diff, array( 'updated_at' => $now ) ), array( 'id' => (int) $item['id'] ) );
	}
	if ( $project_sent ) {
		$wpdb->update( ps_table( 'projects' ), array( 'sent_date' => $project_sent, 'updated_by' => $user_id, 'updated_at' => $now ), array( 'id' => (int) $project['id'] ) );
		ps_log( (int) $project['id'], 'update', 'project', (int) $project['id'], 'sent_date', null, $project_sent, $user_id );
	} elseif ( $diff ) {
		$wpdb->update( ps_table( 'projects' ), array( 'updated_by' => $user_id, 'updated_at' => $now ), array( 'id' => (int) $project['id'] ) );
	}
	if ( $wpdb->last_error ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ps_save_failed', ps__( 'تعذّر الحفظ' ), array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' );
	return true;
}

/**
 * Lignes de Suivi Offres (view=offers) ou Suivi Livraison (view=delivery, أصناف مقبولة seulement),
 * avec les valeurs calculées (retards, couleurs, تأخير) et le droit de modification.
 *
 * @param array $args view, q, employeeId ('me' ou id), offerStatus (code), late (1), deliveryLate (1),
 *                    projectId, year, sort (due|project), page, perPage.
 */
function ps_items_list( array $args, int $user_id, ?string $today = null ): array {
	global $wpdb;
	$today = $today ?? ps_today();
	$lists = ps_lists_by_id();
	$code  = fn( $id ) => $id ? ( $lists[ (int) $id ]['code'] ?? null ) : null;
	$rows  = $wpdb->get_results(
		'SELECT i.*, p.project_no, p.legacy_id, p.name AS project_name, p.received_date, p.sent_date, p.urgency_id, p.responsible_emp_id AS p_responsible_emp_id, p.follower_emp_id AS p_follower_emp_id, p.intermediary_emp_id, p.created_by, p.created_at AS project_created_at, p.deleted_at, c.name AS company_name
		 FROM ' . ps_table( 'items' ) . ' i JOIN ' . ps_table( 'projects' ) . ' p ON p.id = i.project_id AND p.deleted_at IS NULL AND p.is_project = 1
		 LEFT JOIN ' . ps_table( 'project_parties' ) . " pp ON pp.project_id = p.id AND pp.role = 'owner'
		 LEFT JOIN " . ps_table( 'companies' ) . ' c ON c.id = pp.company_id
		 ORDER BY i.due_date IS NULL, i.due_date, i.id',
		ARRAY_A
	);

	// 1.6.0 : factures et fichiers de chaque offre (compteurs).
	$inv_count = array();
	// 1.11.0 : une facture suit l'offre ENTIÈRE → compteurs par projet (chaque صنف de l'offre affiche les mêmes).
	foreach ( $wpdb->get_results( 'SELECT project_id, answer, sent_date, reply_deadline FROM ' . ps_table( 'invoices' ) . ' WHERE deleted_at IS NULL', ARRAY_A ) as $v ) {
		$k                = (int) $v['project_id'];
		$inv_count[ $k ]  = $inv_count[ $k ] ?? array( 'total' => 0, 'pending' => 0, 'late' => 0 );
		++$inv_count[ $k ]['total'];
		if ( 'pending' === $v['answer'] ) {
			++$inv_count[ $k ]['pending'];
			$inv_count[ $k ]['late'] += ps_calc_invoice_late( 'pending', ps_invoice_deadline( $v ), $today ) ? 1 : 0;
		}
	}
	$file_count = array();
	foreach ( $wpdb->get_results( 'SELECT item_id, COUNT(*) AS n FROM ' . ps_table( 'files' ) . ' WHERE deleted_at IS NULL AND item_id IS NOT NULL AND invoice_id IS NULL GROUP BY item_id', ARRAY_A ) as $f ) {
		$file_count[ (int) $f['item_id'] ] = (int) $f['n'];
	}

	$delivery = 'delivery' === ( $args['view'] ?? 'offers' );
	$me       = ps_user_employee_id( $user_id );
	$emp      = $args['employeeId'] ?? '';
	if ( 'me' === $emp ) {
		$emp = $me ?: -1;
	}
	$q      = ps_normalize_text( (string) ( $args['q'] ?? '' ) );
	$out    = array();
	$facets = array( 'all' => 0, 'late' => 0 );

	foreach ( $rows as $r ) {
		$oc = $code( $r['offer_status_id'] ) ?? 'preparing';
		if ( $delivery && 'accepted' !== $oc ) {
			continue;
		}
		if ( ! empty( $args['projectId'] ) && (int) $args['projectId'] !== (int) $r['project_id'] ) {
			continue;
		}
		if ( '' !== (string) $emp && (int) $emp !== (int) $r['employee_id'] ) {
			continue;
		}
		if ( ! empty( $args['year'] ) && (int) $args['year'] !== (int) substr( $r['received_date'], 0, 4 ) ) {
			continue;
		}
		if ( ! empty( $args['notSentReasonId'] ) && ( 'not_sent' !== $oc || (int) $args['notSentReasonId'] !== (int) $r['not_sent_reason_id'] ) ) {
			continue;
		}
		if ( ! empty( $args['itemTypeId'] ) && (int) $args['itemTypeId'] !== (int) $r['item_type_id'] ) {
			continue;
		}
		$urg     = $code( $r['urgency_id'] );
		$late    = ps_calc_is_offer_late( $oc, $r['due_date'], $urg, $today );
		$dc      = $code( $r['delivery_status_id'] );
		$d_late  = ps_calc_is_delivery_late( $oc, $dc, $r['delivery_due_date'], $r['delivery_actual_date'], $today );
		if ( ! empty( $args['deliveryLate'] ) && ! $d_late ) {
			continue;
		}
		if ( ! empty( $args['deliveryStatus'] ) && $args['deliveryStatus'] !== ps_calc_effective_delivery_status( $dc, $d_late ) ) {
			continue;
		}
		if ( '' !== $q ) {
			$hay = ps_normalize_text( $r['project_no'] . ' ' . ps_project_old_no( $r ) . ' ' . $r['project_name'] . ' ' . $r['company_name'] . ' ' . ( $lists[ (int) $r['item_type_id'] ]['label'] ?? '' ) . ' ' . $r['offer_invoice_no'] . ' ' . $r['supplier_invoice_no'] );
			if ( ! str_contains( $hay, $q ) ) {
				continue;
			}
		}
		// 1.7.0 : compteurs par statut (filtres rapides) avant le filtre de statut.
		++$facets['all'];
		$facets[ $oc ] = ( $facets[ $oc ] ?? 0 ) + 1;
		$facets['late'] += $late ? 1 : 0;
		if ( ! empty( $args['offerStatus'] ) && $args['offerStatus'] !== $oc ) {
			continue;
		}
		if ( ! empty( $args['late'] ) && ! $late ) {
			continue;
		}
		$project = array(
			'id'                 => (int) $r['project_id'],
			'responsible_emp_id' => $r['p_responsible_emp_id'],
			'follower_emp_id'    => $r['p_follower_emp_id'],
			'intermediary_emp_id'=> $r['intermediary_emp_id'],
			'created_by'         => $r['created_by'],
			'created_at'         => $r['project_created_at'],
			'deleted_at'         => $r['deleted_at'],
		);
		$out[] = array(
			'id'                 => (int) $r['id'],
			'projectId'          => (int) $r['project_id'],
			'projectNo'          => $r['project_no'],
			'projectName'        => $r['project_name'],
			'companyName'        => $r['company_name'],
			'projectSentDate'    => $r['sent_date'],
			'receivedDate'       => $r['received_date'],
			'urgencyId'          => $r['urgency_id'] ? (int) $r['urgency_id'] : null,
			'itemTypeId'         => $r['item_type_id'] ? (int) $r['item_type_id'] : null,
			'quantity'           => null === $r['quantity'] ? null : (float) $r['quantity'],
			'employeeId'         => $r['employee_id'] ? (int) $r['employee_id'] : null,
			'dueDate'            => $r['due_date'],
			'offerStatusId'      => $r['offer_status_id'] ? (int) $r['offer_status_id'] : null,
			'offerStatusCode'    => $oc,
			'offerDate'          => $r['offer_date'],
			'notSentReasonId'    => $r['not_sent_reason_id'] ? (int) $r['not_sent_reason_id'] : null,
			'notSentNote'        => $r['not_sent_note'],
			'offerInvoiceNo'     => $r['offer_invoice_no'],
			'offerNotes'         => $r['offer_notes'],
			'supplierInvoiceNo'  => $r['supplier_invoice_no'],
			'availabilityId'     => $r['availability_id'] ? (int) $r['availability_id'] : null,
			'deliveryDueDate'    => $r['delivery_due_date'],
			'deliveryActualDate' => $r['delivery_actual_date'],
			'deliveryStatusId'   => $r['delivery_status_id'] ? (int) $r['delivery_status_id'] : null,
			'deliveryNotes'      => $r['delivery_notes'],
			// 1.6.0 : المسؤول / المتابع de l'offre (valeur effective) + indication « modifié au niveau de l'offre ».
			'responsibleEmpId'   => ps_item_effective( $r, $project, 'responsible_emp_id' ),
			'followerEmpId'      => ps_item_effective( $r, $project, 'follower_emp_id' ),
			'assignOverride'     => ! empty( $r['responsible_emp_id'] ) || ! empty( $r['follower_emp_id'] ),
			'canAssign'          => ps_item_can_assign( $project, $user_id ),
			'invoices'           => $inv_count[ (int) $r['project_id'] ] ?? array( 'total' => 0, 'pending' => 0, 'late' => 0 ),
			'filesCount'         => $file_count[ (int) $r['id'] ] ?? 0,
			'computed'           => array(
				'late'            => $late,
				'rowColor'        => ps_calc_offer_row_color( $oc, $r['due_date'], $urg, $today ),
				'deliveryLate'    => $d_late,
				'delayDays'       => ps_calc_delivery_delay_days( $r['delivery_due_date'], $r['delivery_actual_date'], $today ),
				'deliveryDisplay' => ps_calc_effective_delivery_status( $dc, $d_late ),
			),
			'canEdit'            => ps_item_can_edit( $r, $project, $user_id ),
			'mine'               => $me && (int) $r['employee_id'] === $me,
		);
	}

	$total    = count( $out );
	$per_page = max( 1, min( 500, (int) ( $args['perPage'] ?? 100 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	return array(
		'total'        => $total,
		'page'         => $page,
		'perPage'      => $per_page,
		'needDecision' => count( array_filter( $out, fn( $o ) => $o['computed']['late'] ) ),
		'facets'       => $facets, // 1.7.0 : all, late, et un compteur par code de statut d'offre
		'rows'         => array_slice( $out, ( $page - 1 ) * $per_page, $per_page ),
	);
}
