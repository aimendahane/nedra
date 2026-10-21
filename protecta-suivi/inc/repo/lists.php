<?php
/**
 * Accès aux listes déroulantes (table ps_lists).
 * L'édition (ajout, renommage, masquage, ordre) arrive à l'étape 5 (الإعدادات).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transforme une ligne brute en tableau prêt pour l'API.
 */
function ps_list_row( array $r ): array {
	return array(
		'id'       => (int) $r['id'],
		'listKey'  => $r['list_key'],
		'code'     => $r['code'],
		'label'    => $r['label'],
		'type'     => $r['type'],
		'weight'   => null === $r['weight'] ? null : (int) $r['weight'],
		'color'    => $r['color'],
		'userId'   => null === $r['user_id'] ? null : (int) $r['user_id'],
		'sort'     => (int) $r['sort_order'],
		'hidden'   => (bool) $r['is_hidden'],
		'system'   => (bool) $r['is_system'],
		'aliases'  => $r['aliases'] ? (array) json_decode( $r['aliases'], true ) : array(),
		'meta'     => $r['meta'] ? (array) json_decode( $r['meta'], true ) : (object) array(),
	);
}

/**
 * Toutes les valeurs de toutes les listes (masquées comprises), groupées par liste.
 * Les valeurs masquées restent nécessaires pour afficher les anciens projets.
 */
function ps_lists_all(): array {
	global $wpdb;
	$table = ps_table( 'lists' );
	$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY list_key, sort_order, id", ARRAY_A ); // phpcs:ignore

	$out = array_fill_keys( array_keys( ps_list_labels() ), array() );
	foreach ( $rows as $r ) {
		$out[ $r['list_key'] ][] = ps_list_row( $r );
	}
	return $out;
}

/**
 * Valeurs d'une liste.
 */
function ps_list_values( string $list_key, bool $include_hidden = true ): array {
	global $wpdb;
	$table = ps_table( 'lists' );
	$sql   = "SELECT * FROM $table WHERE list_key = %s" . ( $include_hidden ? '' : ' AND is_hidden = 0' ) . ' ORDER BY sort_order, id';
	return array_map( 'ps_list_row', $wpdb->get_results( $wpdb->prepare( $sql, $list_key ), ARRAY_A ) ); // phpcs:ignore
}

/**
 * Identifiant d'une valeur par son code (ex. ps_list_id_by_code( 'offer_status', 'sent' )).
 */
function ps_list_id_by_code( string $list_key, string $code ): ?int {
	global $wpdb;
	$table = ps_table( 'lists' );
	$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE list_key = %s AND code = %s ORDER BY id LIMIT 1", $list_key, $code ) );
	return $id ? (int) $id : null;
}

/* =========================================================================
 * Administration des listes (الإعدادات) — ajouter, renommer, masquer, réordonner.
 * « Sans toucher aux projets existants » : les projets gardent l'id de la valeur ;
 * renommer change l'affichage partout, masquer retire la valeur des choix de saisie,
 * supprimer n'est possible que pour une valeur jamais utilisée (sinon : masquer).
 * ====================================================================== */

/**
 * Nombre d'utilisations d'une valeur de liste (projets, أصناف, concurrents, comptes).
 */
function ps_list_usage_count( int $id ): int {
	global $wpdb;
	$n    = 0;
	$cols = array( 'responsible_emp_id', 'follower_emp_id', 'intermediary_emp_id', 'place_id', 'category_id', 'urgency_id', 'importance_kind_id', 'importance_size_id', 'followup_status_id', 'source_id', 'loss_reason_id', 'offer_currency_id', 'purchase_currency_id' );
	$n   += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) . ' WHERE ' . implode( ' = %d OR ', $cols ) . ' = %d', array_fill( 0, count( $cols ), $id ) ) ); // phpcs:ignore
	$icol = array( 'item_type_id', 'employee_id', 'offer_status_id', 'not_sent_reason_id', 'availability_id', 'delivery_status_id' );
	$n   += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'items' ) . ' WHERE ' . implode( ' = %d OR ', $icol ) . ' = %d', array_fill( 0, count( $icol ), $id ) ) ); // phpcs:ignore
	$n   += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'project_competitors' ) . ' WHERE FIND_IN_SET(%d, item_type_ids)', $id ) );
	$n   += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'ps_employee_id' AND meta_value = %s", (string) $id ) );
	return $n;
}

/**
 * Vérifie et prépare les champs d'une valeur (ajout ou modification).
 *
 * @return array|WP_Error Colonnes à écrire.
 */
function ps_list_value_input( string $list_key, array $b, ?array $existing ) {
	$errors = array();
	$set    = array();
	if ( array_key_exists( 'label', $b ) || ! $existing ) {
		$label = ps_clean_text( $b['label'] ?? null );
		if ( null === $label ) {
			$errors['label'] = ps__( 'اكتب الاسم' );
		} else {
			foreach ( ps_list_values( $list_key ) as $v ) {
				if ( ( ! $existing || $v['id'] !== (int) $existing['id'] ) && ps_normalize_text( $v['label'] ) === ps_normalize_text( $label ) ) {
					$errors['label'] = ps__( 'الاسم موجود في هذه القائمة' );
				}
			}
			$set['label'] = $label;
		}
	}
	if ( array_key_exists( 'code', $b ) && ! ( $existing && $existing['is_system'] ) ) {
		$code = ps_clean_text( $b['code'] );
		if ( null !== $code ) {
			$code = 'place' === $list_key || 'currency' === $list_key ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ) : sanitize_key( $code );
			foreach ( ps_list_values( $list_key ) as $v ) {
				if ( ( ! $existing || $v['id'] !== (int) $existing['id'] ) && $v['code'] && strtoupper( $v['code'] ) === strtoupper( $code ) ) {
					$errors['code'] = ps__( 'الرمز مستعمل' );
				}
			}
		}
		$set['code'] = $code ?: null;
	}
	if ( array_key_exists( 'color', $b ) ) {
		$color = sanitize_hex_color( (string) $b['color'] );
		$set['color'] = $color ?: null;
	}
	if ( 'followup_status' === $list_key && ( array_key_exists( 'type', $b ) || ! $existing ) ) {
		$type = (string) ( $b['type'] ?? '' );
		if ( ! isset( ps_followup_types()[ $type ] ) ) {
			$errors['type'] = ps__( 'اختر النوع (جارية / مرفوض / مؤكد / مسلّم)' );
		}
		$set['type'] = $type;
	}
	if ( array_key_exists( 'hidden', $b ) ) {
		$hide = ! empty( $b['hidden'] ) ? 1 : 0;
		$meta = $existing && $existing['meta'] ? (array) json_decode( $existing['meta'], true ) : array();
		if ( $existing && $existing['is_system'] && $hide !== (int) $existing['is_hidden'] ) {
			$errors['hidden'] = ! empty( $meta['import_only'] ) ? ps__( '«غير محدد» مخفية دائمًا (للمشاريع المستوردة فقط)' ) : ps__( 'قيمة أساسية في الحسابات: لا يمكن إخفاؤها' );
		}
		$set['is_hidden'] = $hide;
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات' ), array( 'status' => 422, 'errors' => $errors ) );
	}
	return $set;
}

function ps_list_value_raw( int $id ): ?array {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'lists' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $r ?: null;
}
