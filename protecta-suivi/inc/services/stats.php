<?php
/**
 * Faits calculés par projet + indicateurs (KPI).
 *
 * Utilisé par : Recap (étape 4), مهامي, compteurs du menu, e-mail du matin, tests.
 * Aucune règle de calcul ici : tout passe par inc/calculs.php. Ce fichier ne fait que
 * lire la base et appliquer ces règles projet par projet.
 *
 * Choix (voir CLAUDE.md) : un projet dont حالة العرض = ملغى est compté À PART
 * (« ملغى ») et exclu des autres indicateurs (المؤكدة، المرفوضة، قيد الدراسة، المرسلة…).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Faits de chaque projet (non supprimé) correspondant aux filtres.
 *
 * @param array       $filters year, month, followerEmpId, placeId, sourceId, categoryId, ids (liste d'id)
 * @param string|null $today   Date du jour (AAAA-MM-JJ) ; null = aujourd'hui (fuseau WordPress).
 */
function ps_stats_facts( array $filters = array(), ?string $today = null ): array {
	global $wpdb;
	$today = $today ?? ps_today();
	$lists = ps_lists_by_id();
	$where = array( 'p.deleted_at IS NULL' );
	$args  = array();
	if ( ! empty( $filters['year'] ) ) {
		$where[] = 'YEAR(p.received_date) = %d';
		$args[]  = (int) $filters['year'];
	}
	if ( ! empty( $filters['month'] ) ) {
		$where[] = 'MONTH(p.received_date) = %d';
		$args[]  = (int) $filters['month'];
	}
	foreach ( array( 'followerEmpId' => 'follower_emp_id', 'placeId' => 'place_id', 'sourceId' => 'source_id', 'categoryId' => 'category_id' ) as $k => $col ) {
		if ( ! empty( $filters[ $k ] ) ) {
			$where[] = "p.$col = %d";
			$args[]  = (int) $filters[ $k ];
		}
	}
	if ( isset( $filters['ids'] ) ) {
		$ids     = array_map( 'intval', (array) $filters['ids'] ) ?: array( 0 );
		$where[] = 'p.id IN (' . implode( ',', $ids ) . ')';
	}
	$sql  = 'SELECT p.*, pp.company_id, c.name AS company_name FROM ' . ps_table( 'projects' ) . ' p LEFT JOIN ' . ps_table( 'project_parties' ) . " pp ON pp.project_id = p.id AND pp.role = 'owner' LEFT JOIN " . ps_table( 'companies' ) . ' c ON c.id = pp.company_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.received_date, p.id';
	$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	if ( ! $rows ) {
		return array();
	}

	$ids   = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
	$items = array();
	foreach ( $wpdb->get_results( 'SELECT * FROM ' . ps_table( 'items' ) . " WHERE project_id IN ($ids) ORDER BY sort_order, id", ARRAY_A ) as $it ) { // phpcs:ignore
		$items[ $it['project_id'] ][] = $it;
	}
	$last_fu = array();
	foreach ( $wpdb->get_results( 'SELECT project_id, entry_date, next_date FROM ' . ps_table( 'followups' ) . " WHERE project_id IN ($ids) ORDER BY project_id, entry_date DESC, id DESC", ARRAY_A ) as $f ) { // phpcs:ignore
		if ( ! isset( $last_fu[ $f['project_id'] ] ) ) {
			$last_fu[ $f['project_id'] ] = $f;
		}
	}
	$comp = array();
	foreach ( $wpdb->get_results( 'SELECT pc.project_id, pc.competitor_id, pc.brand, pc.item_type_ids, c.name FROM ' . ps_table( 'project_competitors' ) . ' pc LEFT JOIN ' . ps_table( 'competitors' ) . " c ON c.id = pc.competitor_id WHERE pc.project_id IN ($ids)", ARRAY_A ) as $c ) { // phpcs:ignore
		$comp[ $c['project_id'] ][] = $c;
	}

	$code   = fn( $id ) => $id ? ( $lists[ (int) $id ]['code'] ?? null ) : null;
	$weight = fn( $id ) => $id && isset( $lists[ (int) $id ] ) && null !== $lists[ (int) $id ]['weight'] ? (int) $lists[ (int) $id ]['weight'] : null;
	$days   = (int) ps_setting( 'followup_alert_days' );
	$facts  = array();

	foreach ( $rows as $p ) {
		$urgency  = $code( $p['urgency_id'] );
		$fu_type  = $p['followup_status_id'] ? ( $lists[ (int) $p['followup_status_id'] ]['type'] ?? null ) : null;
		$its      = array();
		foreach ( $items[ $p['id'] ] ?? array() as $it ) {
			$oc      = $code( $it['offer_status_id'] ) ?? 'preparing';
			$dc      = $code( $it['delivery_status_id'] );
			$d_late  = ps_calc_is_delivery_late( $oc, $dc, $it['delivery_due_date'], $it['delivery_actual_date'], $today );
			$its[]   = array(
				'id'               => (int) $it['id'],
				'type'             => $it['item_type_id'] ? (int) $it['item_type_id'] : null,
				'employee'         => $it['employee_id'] ? (int) $it['employee_id'] : null,
				'offer_code'       => $oc,
				'due'              => $it['due_date'],
				'late'             => ps_calc_is_offer_late( $oc, $it['due_date'], $urgency, $today ),
				'not_sent_reason'  => $it['not_sent_reason_id'] ? (int) $it['not_sent_reason_id'] : null,
				'delivery_due'     => $it['delivery_due_date'],
				'delivery_actual'  => $it['delivery_actual_date'],
				'delivery_code'    => $dc,
				'delivery_late'    => $d_late,
				'delay'            => 'accepted' === $oc ? ps_calc_delivery_delay_days( $it['delivery_due_date'], $it['delivery_actual_date'], $today ) : 0,
				'delivery_display' => ps_calc_effective_delivery_status( $dc, $d_late ),
			);
		}
		$offer  = ps_calc_project_offer_status( array_column( $its, 'offer_code' ) );
		$cancel = 'cancelled' === $offer;
		$sent   = ! $cancel && ps_calc_is_offer_sent( $offer, $fu_type );
		$lf     = $last_fu[ $p['id'] ] ?? null;

		$facts[] = array(
			'id'               => (int) $p['id'],
			'project_no'       => $p['project_no'],
			'name'             => $p['name'],
			'received_date'    => $p['received_date'],
			'year'             => (int) substr( $p['received_date'], 0, 4 ),
			'month'            => (int) substr( $p['received_date'], 5, 2 ),
			'follower'         => $p['follower_emp_id'] ? (int) $p['follower_emp_id'] : null,
			'responsible'      => $p['responsible_emp_id'] ? (int) $p['responsible_emp_id'] : null,
			'place'            => $p['place_id'] ? (int) $p['place_id'] : null,
			'source'           => $p['source_id'] ? (int) $p['source_id'] : null,
			'source_code'      => $code( $p['source_id'] ),
			'category'         => $p['category_id'] ? (int) $p['category_id'] : null,
			'urgency'          => $p['urgency_id'] ? (int) $p['urgency_id'] : null,
			'urgency_code'     => $urgency,
			'followup_status'  => $p['followup_status_id'] ? (int) $p['followup_status_id'] : null,
			'followup_type'    => $fu_type,
			'offer_status'     => $offer,
			'is_cancelled'     => $cancel,
			'is_sent'          => $sent,
			'is_not_sent'      => ! $cancel && ps_calc_is_offer_not_sent( $offer, $fu_type ),
			'is_confirmed'     => ! $cancel && ps_calc_is_confirmed( $fu_type ),
			'is_rejected'      => ! $cancel && 'rejected' === $fu_type,
			'is_delivered'     => ! $cancel && 'delivered' === $fu_type,
			'is_ongoing'       => ! $cancel && ( 'ongoing' === $fu_type || null === $fu_type ),
			'is_closed'        => ps_calc_is_closed( $fu_type ),
			'company_id'       => $p['company_id'] ? (int) $p['company_id'] : null,
			'company_name'     => $p['company_name'],
			'offer_amount'     => null === $p['offer_amount'] ? null : (float) $p['offer_amount'],
			'offer_currency'   => $p['offer_currency_id'] ? (int) $p['offer_currency_id'] : null,
			'priority'         => ps_calc_priority( $weight( $p['urgency_id'] ), $weight( $p['importance_size_id'] ) ),
			'margin'           => ps_calc_margin( $p['offer_amount'], $p['offer_currency_id'], $p['purchase_amount'], $p['purchase_currency_id'] ),
			'last_followup'    => $lf['entry_date'] ?? null,
			'next_followup'    => $lf['next_date'] ?? null,
			'needs_followup'   => ps_calc_needs_followup( $fu_type, $urgency, $lf['entry_date'] ?? null, $p['updated_at'], $lf['next_date'] ?? null, $today, $days ),
			'competitors'      => $comp[ $p['id'] ] ?? array(),
			'items'            => $its,
		);
	}
	return $facts;
}

/**
 * Indicateurs principaux d'un ensemble de projets (brief §6-7).
 */
function ps_stats_kpis( array $facts ): array {
	$active    = array_values( array_filter( $facts, fn( $f ) => ! $f['is_cancelled'] ) );
	$count     = fn( $key ) => count( array_filter( $active, fn( $f ) => $f[ $key ] ) );
	$confirmed = $count( 'is_confirmed' );
	$sent      = $count( 'is_sent' );
	$not_sent  = $count( 'is_not_sent' );
	$items     = array_merge( ...array_map( fn( $f ) => $f['items'], $facts ) ?: array( array() ) );

	return array(
		'projects'          => count( $active ),
		'cancelled'         => count( $facts ) - count( $active ),
		'confirmed'         => $confirmed,
		'rejected'          => $count( 'is_rejected' ),
		'ongoing'           => $count( 'is_ongoing' ),
		'delivered'         => $count( 'is_delivered' ),
		'sent'              => $sent,
		'notSent'           => $not_sent,
		'successRate'       => ps_calc_success_rate( $confirmed, $sent ),
		'sendRate'          => ps_calc_send_rate( $sent, $not_sent ),
		'amounts'           => ps_calc_amounts_by_currency( array_map( fn( $f ) => array( $f['offer_amount'], $f['offer_currency'] ), $active ) ),
		'confirmedAmounts'  => ps_calc_amounts_by_currency( array_map( fn( $f ) => array( $f['offer_amount'], $f['offer_currency'] ), array_filter( $active, fn( $f ) => $f['is_confirmed'] ) ) ),
		'lateOffers'        => count( array_filter( $items, fn( $i ) => $i['late'] ) ),
		'lateDeliveries'    => count( array_filter( $items, fn( $i ) => $i['delivery_late'] ) ),
		'followupAlerts'    => count( array_filter( $facts, fn( $f ) => $f['needs_followup'] ) ),
	);
}
