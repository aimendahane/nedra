<?php
/**
 * Recap (brief §7) : indicateurs + comparaison avec la même période de l'année précédente,
 * et données de chaque graphique / tableau.
 *
 * La période d'un projet = mois / année de « تاريخ استلام الطلب ».
 * Filtres : year (obligatoire), month, followerEmpId, placeId, sourceId.
 * Les projets « ملغى » sont comptés à part et exclus des répartitions (voir CLAUDE.md).
 * Tous les calculs viennent de inc/calculs.php (via ps_stats_facts / ps_stats_kpis).
 */

defined( 'ABSPATH' ) || exit;

/** Nombre maximal de parts dans l'anneau « حسب التصنيف » (au-delà : « أخرى »). */
const PS_RECAP_MAX_SLICES = 8;

/**
 * Évolution d'un indicateur par rapport à l'année précédente.
 *   - nombres : variation en % ((N − N-1) ÷ N-1 × 100, 1 décimale), null si N-1 = 0 ;
 *   - taux (en %) : écart en points (N − N-1).
 */
function ps_recap_delta( $current, $previous, bool $is_rate = false ): array {
	if ( null === $current || null === $previous ) {
		return array( 'prev' => $previous, 'diff' => null, 'pct' => null, 'dir' => null );
	}
	$diff = round( (float) $current - (float) $previous, 1 );
	$pct  = $is_rate ? null : ( 0.0 === (float) $previous ? null : round( ( $current - $previous ) / $previous * 100, 1 ) );
	return array(
		'prev' => $previous,
		'diff' => $diff,
		'pct'  => $pct,
		'dir'  => $diff > 0 ? 'up' : ( $diff < 0 ? 'down' : 'same' ),
	);
}

/**
 * Données complètes du Recap.
 */
function ps_recap( array $filters, ?string $today = null ): array {
	$year  = (int) ( $filters['year'] ?? 0 ) ?: (int) current_time( 'Y' );
	$base  = array_intersect_key( $filters, array_flip( array( 'month', 'followerEmpId', 'placeId', 'sourceId' ) ) );
	$facts = ps_stats_facts( array_merge( $base, array( 'year' => $year ) ), $today );
	$prevf = ps_stats_facts( array_merge( $base, array( 'year' => $year - 1 ) ), $today );
	$k     = ps_stats_kpis( $facts );
	$p     = ps_stats_kpis( $prevf );
	$lists = ps_lists_by_id();
	// Libellés dans la langue de l'interface (valeurs par défaut traduites, valeurs saisies inchangées).
	$label = fn( $id ) => $id ? ps__( $lists[ (int) $id ]['label'] ?? '—' ) : ps__( 'غير محدد' );

	// Comparaison N-1 de chaque indicateur.
	$compare = array();
	foreach ( array( 'projects', 'cancelled', 'nonProjects', 'pendingProjects', 'confirmed', 'rejected', 'ongoing', 'sent', 'notSent', 'lateOffers', 'lateDeliveries' ) as $key ) {
		$compare[ $key ] = ps_recap_delta( $k[ $key ], $p[ $key ] );
	}
	$compare['successRate'] = ps_recap_delta( $k['successRate'], $p['successRate'], true );
	$compare['sendRate']    = ps_recap_delta( $k['sendRate'], $p['sendRate'], true );
	foreach ( array( 'amounts', 'confirmedAmounts' ) as $key ) {
		$compare[ $key ] = array();
		foreach ( array_unique( array_merge( array_keys( $k[ $key ] ), array_keys( $p[ $key ] ) ) ) as $cur ) {
			$compare[ $key ][ $cur ] = ps_recap_delta( $k[ $key ][ $cur ] ?? 0.0, $p[ $key ][ $cur ] ?? 0.0 );
		}
	}

	$active = array_values( array_filter( $facts, fn( $f ) => ! $f['is_cancelled'] && empty( $f['is_non_project'] ) ) );
	$total  = count( $active );
	$pct    = fn( $n ) => $total ? round( $n / $total * 100, 1 ) : 0.0;

	// حسب الشهر : barres empilées par type (جارية / مؤكدة / مرفوضة).
	$months = array();
	for ( $m = 1; $m <= 12; $m++ ) {
		$months[ $m ] = array( 'month' => $m, 'ongoing' => 0, 'confirmed' => 0, 'rejected' => 0, 'total' => 0 );
	}
	foreach ( $active as $f ) {
		$type = $f['is_confirmed'] ? 'confirmed' : ( $f['is_rejected'] ? 'rejected' : 'ongoing' );
		++$months[ $f['month'] ][ $type ];
		++$months[ $f['month'] ]['total'];
	}

	// Comptage générique par clé.
	$count_by = static function ( array $rows, callable $key ) {
		$out = array();
		foreach ( $rows as $r ) {
			foreach ( (array) $key( $r ) as $k ) {
				$out[ $k ] = ( $out[ $k ] ?? 0 ) + 1;
			}
		}
		arsort( $out );
		return $out;
	};

	// حسب التصنيف : anneau, 8 parts au plus + « أخرى ».
	$by_cat   = $count_by( $active, fn( $f ) => (int) $f['category'] );
	$category = array();
	$i        = 0;
	$other    = 0;
	foreach ( $by_cat as $id => $n ) {
		if ( ++$i < PS_RECAP_MAX_SLICES || count( $by_cat ) <= PS_RECAP_MAX_SLICES ) {
			$category[] = array( 'id' => $id ?: null, 'label' => $label( $id ), 'count' => $n, 'pct' => $pct( $n ) );
		} else {
			$other += $n;
		}
	}
	if ( $other ) {
		$category[] = array( 'id' => null, 'label' => ps__( 'أخرى' ), 'count' => $other, 'pct' => $pct( $other ), 'other' => true );
	}

	// حسب حالة المتابعة : toutes les حالات de la liste (ordre des réglages), avec couleur.
	$by_fs  = $count_by( $active, fn( $f ) => (int) $f['followup_status'] );
	$status = array();
	foreach ( ps_list_values( 'followup_status' ) as $v ) {
		$n = $by_fs[ $v['id'] ] ?? 0;
		if ( $n || ! $v['hidden'] ) {
			$status[] = array( 'id' => $v['id'], 'label' => ps__( $v['label'] ), 'color' => $v['color'], 'type' => $v['type'], 'count' => $n, 'pct' => $pct( $n ) );
		}
	}

	// حسب درجة الاستعجال : VU / U / N / L.
	$by_urg  = $count_by( $active, fn( $f ) => (int) $f['urgency'] );
	$urgency = array();
	foreach ( ps_list_values( 'urgency' ) as $v ) {
		$n         = $by_urg[ $v['id'] ] ?? 0;
		$urgency[] = array( 'id' => $v['id'], 'code' => $v['code'], 'label' => ps__( $v['label'] ), 'color' => $v['color'], 'count' => $n, 'pct' => $pct( $n ) );
	}

	// حسب الموظف المتابع : عدد، مؤكدة، مرفوضة، مسلّمة، لم تُرسل، نسبة النجاح.
	$emps = array();
	foreach ( $active as $f ) {
		$e = (int) $f['follower'];
		if ( ! isset( $emps[ $e ] ) ) {
			$emps[ $e ] = array( 'id' => $e ?: null, 'label' => $label( $e ), 'count' => 0, 'confirmed' => 0, 'rejected' => 0, 'delivered' => 0, 'notSent' => 0, 'sent' => 0 );
		}
		++$emps[ $e ]['count'];
		$emps[ $e ]['confirmed'] += $f['is_confirmed'] ? 1 : 0;
		$emps[ $e ]['rejected']  += $f['is_rejected'] ? 1 : 0;
		$emps[ $e ]['delivered'] += $f['is_delivered'] ? 1 : 0;
		$emps[ $e ]['notSent']   += $f['is_not_sent'] ? 1 : 0;
		$emps[ $e ]['sent']      += $f['is_sent'] ? 1 : 0;
	}
	foreach ( $emps as &$e ) {
		$e['successRate'] = ps_calc_success_rate( $e['confirmed'], $e['sent'] );
	}
	unset( $e );
	usort( $emps, fn( $a, $b ) => $b['count'] <=> $a['count'] );

	// حسب الصنف المطلوب : un projet compte UNE fois par صنف.
	$by_type = $count_by( $active, fn( $f ) => array_unique( array_filter( array_column( $f['items'], 'type' ) ) ) );
	$types   = array();
	foreach ( $by_type as $id => $n ) {
		$types[] = array( 'id' => $id, 'label' => $label( $id ), 'count' => $n );
	}

	// حسب المكان : code + nom complet.
	$by_place = $count_by( $active, fn( $f ) => (int) $f['place'] );
	$places   = array();
	foreach ( $by_place as $id => $n ) {
		$places[] = array( 'id' => $id ?: null, 'code' => $id ? ( $lists[ $id ]['code'] ?: $lists[ $id ]['label'] ) : '—', 'label' => $label( $id ), 'count' => $n );
	}

	// حسب مصدر الطلب : « غير محدد » à part et sans نسبة النجاح (décision n°3).
	$src = array();
	foreach ( $active as $f ) {
		$s = (int) $f['source'];
		if ( ! isset( $src[ $s ] ) ) {
			$src[ $s ] = array( 'id' => $s ?: null, 'label' => $label( $s ), 'unspecified' => 'unspecified' === $f['source_code'] || ! $s, 'count' => 0, 'confirmed' => 0, 'sent' => 0 );
		}
		++$src[ $s ]['count'];
		$src[ $s ]['confirmed'] += $f['is_confirmed'] ? 1 : 0;
		$src[ $s ]['sent']      += $f['is_sent'] ? 1 : 0;
	}
	foreach ( $src as &$s ) {
		$s['successRate'] = $s['unspecified'] ? null : ps_calc_success_rate( $s['confirmed'], $s['sent'] );
	}
	unset( $s );
	usort( $src, fn( $a, $b ) => ( $a['unspecified'] <=> $b['unspecified'] ) ?: ( $b['count'] <=> $a['count'] ) );

	// أهم 10 شركات (annuaire) : projets, مؤكدة, montants confirmés par devise.
	$comp = array();
	foreach ( $active as $f ) {
		if ( ! $f['company_id'] ) {
			continue;
		}
		$c = $f['company_id'];
		if ( ! isset( $comp[ $c ] ) ) {
			$comp[ $c ] = array( 'id' => $c, 'name' => $f['company_name'], 'count' => 0, 'confirmed' => 0, 'rows' => array() );
		}
		++$comp[ $c ]['count'];
		if ( $f['is_confirmed'] ) {
			++$comp[ $c ]['confirmed'];
			$comp[ $c ]['rows'][] = array( $f['offer_amount'], $f['offer_currency'] );
		}
	}
	foreach ( $comp as &$c ) {
		$c['confirmedAmounts'] = ps_calc_amounts_by_currency( $c['rows'] );
		unset( $c['rows'] );
	}
	unset( $c );
	usort( $comp, fn( $a, $b ) => ( $b['count'] <=> $a['count'] ) ?: ( $b['confirmed'] <=> $a['confirmed'] ) );

	// المنافسون الأكثر حضورا : عدد المشاريع، الماركات، الأصناف.
	$rivals = array();
	foreach ( $active as $f ) {
		foreach ( $f['competitors'] as $c ) {
			$id = (int) $c['competitor_id'];
			if ( ! $id ) {
				continue;
			}
			if ( ! isset( $rivals[ $id ] ) ) {
				$rivals[ $id ] = array( 'id' => $id, 'name' => $c['name'], 'projects' => array(), 'brands' => array(), 'types' => array() );
			}
			$rivals[ $id ]['projects'][ $f['id'] ] = true;
			if ( $c['brand'] ) {
				$rivals[ $id ]['brands'][ $c['brand'] ] = true;
			}
			foreach ( array_filter( explode( ',', (string) $c['item_type_ids'] ) ) as $t ) {
				$rivals[ $id ]['types'][ $label( (int) $t ) ] = true;
			}
		}
	}
	$rivals = array_map(
		fn( $r ) => array( 'id' => $r['id'], 'name' => $r['name'], 'count' => count( $r['projects'] ), 'brands' => array_keys( $r['brands'] ), 'itemTypes' => array_keys( $r['types'] ) ),
		array_values( $rivals )
	);
	usort( $rivals, fn( $a, $b ) => $b['count'] <=> $a['count'] );

	// أسباب عدم الإرسال (par صنف لم يُرسل) + لم تُرسل حسب الصنف.
	$ns_items = array();
	foreach ( $active as $f ) {
		foreach ( $f['items'] as $it ) {
			if ( 'not_sent' === $it['offer_code'] ) {
				$ns_items[] = $it;
			}
		}
	}
	$ns_total = count( $ns_items );
	$reasons  = array();
	foreach ( $count_by( $ns_items, fn( $it ) => (int) $it['not_sent_reason'] ) as $id => $n ) {
		$reasons[] = array( 'id' => $id ?: null, 'label' => $id ? $label( $id ) : ps__( 'بدون سبب' ), 'count' => $n, 'pct' => $ns_total ? round( $n / $ns_total * 100, 1 ) : 0.0 );
	}
	$ns_types = array();
	foreach ( $count_by( $ns_items, fn( $it ) => (int) $it['type'] ) as $id => $n ) {
		$ns_types[] = array( 'id' => $id, 'label' => $label( $id ), 'count' => $n );
	}

	return array(
		'filters'          => array_merge( $base, array( 'year' => $year ) ),
		'previousYear'     => $year - 1,
		'kpis'             => $k,
		'compare'          => $compare,
		'byMonth'          => array_values( $months ),
		'byCategory'       => $category,
		'byFollowupStatus' => $status,
		'byUrgency'        => $urgency,
		'byEmployee'       => $emps,
		'byItemType'       => $types,
		'byPlace'          => $places,
		'bySource'         => $src,
		'topCompanies'     => array_slice( $comp, 0, 10 ),
		'competitors'      => array_slice( $rivals, 0, 15 ),
		'notSentReasons'   => $reasons,
		'notSentByType'    => $ns_types,
		'notSentItems'     => $ns_total,
		'years'            => ps_recap_years(),
		'typeColors'       => ps_recap_type_colors(),
		// 1.6.0 : factures des projets de la période (en attente, en retard, taux نعم / لا, montants par devise).
		'invoices'         => ps_invoice_stats( array_column( array_filter( $facts, fn( $f ) => empty( $f['is_non_project'] ) ), 'id' ), $today ),
	);
}

/**
 * Années disponibles dans le filtre (de la plus ancienne réception à l'année en cours).
 */
function ps_recap_years(): array {
	global $wpdb;
	$min = (int) $wpdb->get_var( 'SELECT MIN(YEAR(received_date)) FROM ' . ps_table( 'projects' ) . ' WHERE deleted_at IS NULL' );
	$max = max( (int) current_time( 'Y' ), (int) $wpdb->get_var( 'SELECT MAX(YEAR(received_date)) FROM ' . ps_table( 'projects' ) . ' WHERE deleted_at IS NULL' ) );
	$min = $min ?: $max;
	return range( $max, $min );
}

/**
 * Couleurs des types (barres empilées par mois), reprises des réglages :
 * couleur de la première حالة de chaque type dans la liste « حالة المتابعة ».
 */
function ps_recap_type_colors(): array {
	$out = array( 'ongoing' => '#1c7ed6', 'confirmed' => '#2b8a3e', 'rejected' => '#e03131' );
	$seen = array();
	foreach ( ps_list_values( 'followup_status' ) as $v ) {
		$t = 'delivered' === $v['type'] ? 'confirmed' : $v['type'];
		if ( $t && $v['color'] && ! isset( $seen[ $t ] ) && 'confirmed' !== $t ) {
			$out[ $t ]  = $v['color'];
			$seen[ $t ] = true;
		}
	}
	// مؤكدة : couleur de « تم التسليم » (vert foncé), plus lisible que le vert clair de « تم تأكيد الطلبية ».
	foreach ( ps_list_values( 'followup_status' ) as $v ) {
		if ( 'delivered' === $v['type'] && $v['color'] ) {
			$out['confirmed'] = $v['color'];
		}
	}
	return $out;
}
