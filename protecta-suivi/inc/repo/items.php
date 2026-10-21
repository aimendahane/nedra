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
	if ( ps_project_edit_reason( $project, $user_id ) ) {
		return true;
	}
	$emp = ps_user_employee_id( $user_id );
	return $emp && user_can( $user_id, 'ps_edit_own_items' ) && (int) $item['employee_id'] === $emp;
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
		$set['offer_date'] = ps_today(); // تاريخ تقديم العرض par défaut
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
		'SELECT i.*, p.project_no, p.name AS project_name, p.received_date, p.sent_date, p.urgency_id, p.responsible_emp_id, p.follower_emp_id, p.intermediary_emp_id, p.created_by, p.created_at AS project_created_at, p.deleted_at, c.name AS company_name
		 FROM ' . ps_table( 'items' ) . ' i JOIN ' . ps_table( 'projects' ) . ' p ON p.id = i.project_id AND p.deleted_at IS NULL
		 LEFT JOIN ' . ps_table( 'project_parties' ) . " pp ON pp.project_id = p.id AND pp.role = 'owner'
		 LEFT JOIN " . ps_table( 'companies' ) . ' c ON c.id = pp.company_id
		 ORDER BY i.due_date IS NULL, i.due_date, i.id',
		ARRAY_A
	);

	$delivery = 'delivery' === ( $args['view'] ?? 'offers' );
	$me       = ps_user_employee_id( $user_id );
	$emp      = $args['employeeId'] ?? '';
	if ( 'me' === $emp ) {
		$emp = $me ?: -1;
	}
	$q   = ps_normalize_text( (string) ( $args['q'] ?? '' ) );
	$out = array();

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
		if ( ! empty( $args['offerStatus'] ) && $args['offerStatus'] !== $oc ) {
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
		if ( ! empty( $args['late'] ) && ! $late ) {
			continue;
		}
		if ( ! empty( $args['deliveryLate'] ) && ! $d_late ) {
			continue;
		}
		if ( ! empty( $args['deliveryStatus'] ) && $args['deliveryStatus'] !== ps_calc_effective_delivery_status( $dc, $d_late ) ) {
			continue;
		}
		if ( '' !== $q ) {
			$hay = ps_normalize_text( $r['project_no'] . ' ' . $r['project_name'] . ' ' . $r['company_name'] . ' ' . ( $lists[ (int) $r['item_type_id'] ]['label'] ?? '' ) . ' ' . $r['offer_invoice_no'] . ' ' . $r['supplier_invoice_no'] );
			if ( ! str_contains( $hay, $q ) ) {
				continue;
			}
		}
		$project = array(
			'id'                 => (int) $r['project_id'],
			'responsible_emp_id' => $r['responsible_emp_id'],
			'follower_emp_id'    => $r['follower_emp_id'],
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
		'rows'         => array_slice( $out, ( $page - 1 ) * $per_page, $per_page ),
	);
}
