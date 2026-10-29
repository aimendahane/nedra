<?php
/**
 * Projets : lecture, enregistrement (avec أطراف، أصناف، منافسون), numérotation,
 * journal des modifications, liste filtrée (Base Projets), corbeille.
 *
 * Numérotation (cahier §3.1) :
 *   - رقم المشروع = PRJ-<année>-<numéro sur 3 chiffres>, attribué à la création, JAMAIS modifié ;
 *   - l'année de numérotation est celle de تاريخ استلام الطلب au moment de la création
 *     (colonnes year + seq, uniques ensemble) ;
 *   - la colonne month suit تاريخ استلام الطلب ; pour l'affichage et le Recap, l'année et le
 *     mois sont toujours ceux de تاريخ استلام الطلب (si la date est corrigée plus tard,
 *     le numéro reste le même).
 *
 * Format « interne » d'un projet à enregistrer (produit par ps_project_input()) :
 *   [ 'fields' => [ colonnes de ps_projects ], 'parties' => [ rôle => [...] ],
 *     'items' => [ [...], ... ], 'competitors' => [ [...], ... ] ]
 */

defined( 'ABSPATH' ) || exit;

/** Colonnes modifiables d'un projet. */
const PS_PROJECT_FIELDS = array(
	'offer_no', 'name', 'received_date', 'sent_date', 'responsible_emp_id', 'follower_emp_id',
	'intermediary_emp_id', 'place_id', 'category_id', 'urgency_id', 'importance_kind_id',
	'importance_size_id', 'followup_status_id', 'source_id', 'loss_reason_id', 'offer_amount',
	'offer_currency_id', 'purchase_amount', 'purchase_currency_id', 'files_link', 'note',
	// 1.6.0 : « مشروع؟ » (1 = نعم, 0 = لا) + سبب + ملاحظة ; « ملاحظة المشروع » distincte de NOTE / نص الطلب.
	'is_project', 'non_project_reason_id', 'non_project_note', 'project_note',
);

/** Champs texte sur plusieurs lignes. */
const PS_PROJECT_MULTILINE = array( 'note', 'non_project_note', 'project_note' );

/** Coordonnées d'un intervenant (الطرف). */
const PS_PARTY_FIELDS = array( 'person', 'email', 'phone', 'address', 'position', 'rank_title', 'margin', 'location' );

/** Colonnes « structure » d'un صنف modifiables depuis Fiche Projet. */
const PS_ITEM_FIELDS = array( 'item_type_id', 'quantity', 'employee_id', 'due_date' );

/* =========================================================================
 * Outils
 * ====================================================================== */

/** snake_case → camelCase (rank_title → rank). */
function ps_camel( string $snake ): string {
	if ( 'rank_title' === $snake ) {
		return 'rank';
	}
	return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $snake ) ) ) );
}

/** Nettoie un texte reçu (null si vide). */
function ps_clean_text( $v, bool $multiline = false ): ?string {
	if ( null === $v || is_array( $v ) ) {
		return null;
	}
	$v = $multiline ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
	return '' === trim( $v ) ? null : trim( $v );
}

/** Oui / non : 1, 0, ou '' si rien n'est choisi. */
function ps_clean_bool( $v ) {
	if ( true === $v || 1 === $v || in_array( (string) $v, array( '1', 'true', 'yes', 'نعم' ), true ) ) {
		return 1;
	}
	if ( false === $v || 0 === $v || in_array( (string) $v, array( '0', 'false', 'no', 'لا' ), true ) ) {
		return 0;
	}
	return '';
}

/*
 * 1.9.0 — « مشروع؟ » a trois états (colonne is_project, aucune migration : tinyint) :
 *   1 = نعم (c'est une offre), 0 = لا (غير مشروع, سبب obligatoire), 2 = لم يُحدد بعد (demande enregistrée, décision
 *   à prendre plus tard). Seul l'état 1 compte dans Suivi Offres / Livraison, les KPI, les retards et les factures.
 */
const PS_PROJECT_NO      = 0;
const PS_PROJECT_YES     = 1;
const PS_PROJECT_PENDING = 2;

/** Nettoie « مشروع؟ » : 1, 0, 2 (« لم يُحدد بعد ») ou '' (invalide / vide). */
function ps_clean_project_state( $v ) {
	if ( in_array( (string) $v, array( '2', 'pending', 'later' ), true ) ) {
		return PS_PROJECT_PENDING;
	}
	return ps_clean_bool( $v );
}

/** Code lisible de l'état « مشروع؟ » : yes | no | pending. */
function ps_project_state_code( $v ): string {
	$v = (int) ( $v ?? 1 );
	return PS_PROJECT_PENDING === $v ? 'pending' : ( PS_PROJECT_NO === $v ? 'no' : 'yes' );
}

/** Valeur API historique « isProject » : true (نعم), false (لا), null (لم يُحدد بعد). */
function ps_project_state_api( $v ): ?bool {
	$c = ps_project_state_code( $v );
	return 'pending' === $c ? null : 'yes' === $c;
}

/** Nettoie un identifiant (null si vide ou 0). */
function ps_clean_id( $v ): ?int {
	return is_numeric( $v ) && (int) $v > 0 ? (int) $v : null;
}

/** Nettoie un nombre (« 12 500,5 » → « 12500.5 »), null si vide ; texte si invalide (pour l'erreur). */
function ps_clean_number( $v ) {
	if ( null === $v || '' === $v ) {
		return null;
	}
	$s = str_replace( array( ' ', ',' ), array( '', '.' ), (string) $v );
	return is_numeric( $s ) ? $s : (string) $v;
}

/**
 * Toutes les valeurs de listes indexées par id (cache de la requête en cours).
 */
function ps_lists_by_id( bool $refresh = false ): array {
	static $cache = null;
	if ( null === $cache || $refresh ) {
		global $wpdb;
		$cache = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . ps_table( 'lists' ), ARRAY_A ) as $r ) {
			$cache[ (int) $r['id'] ] = $r;
		}
	}
	return $cache;
}

/** Libellé d'une valeur de liste (pour le journal). Lieux et urgences : « CODE – libellé ». */
function ps_list_label( $id ): ?string {
	if ( ! $id ) {
		return null;
	}
	$r = ps_lists_by_id()[ (int) $id ] ?? null;
	if ( ! $r ) {
		return '#' . $id;
	}
	return ( 'place' === $r['list_key'] && $r['code'] ) ? $r['code'] . ' – ' . $r['label'] : $r['label'];
}

/** Libellé de liste dans la langue de l'interface (valeurs par défaut traduites ; e-mails, exports). */
function ps_list_label_t( $id ): string {
	return ps__( (string) ps_list_label( $id ) );
}

/** Code d'une valeur de liste. */
function ps_list_code( $id ): ?string {
	return $id ? ( ps_lists_by_id()[ (int) $id ]['code'] ?? null ) : null;
}

/** Valeur par défaut d'une liste (meta.default), sinon la première visible. */
function ps_list_default_id( string $list_key ): ?int {
	$first = null;
	foreach ( ps_lists_by_id() as $id => $r ) {
		if ( $r['list_key'] !== $list_key || $r['is_hidden'] ) {
			continue;
		}
		$meta = $r['meta'] ? json_decode( $r['meta'], true ) : array();
		if ( ! empty( $meta['default'] ) ) {
			return $id;
		}
		$first = $first ?? $id;
	}
	return $first;
}

/* =========================================================================
 * Entrée : JSON de l'interface → format interne
 * ====================================================================== */

function ps_project_input( array $body ): array {
	$fields = array();
	foreach ( PS_PROJECT_FIELDS as $f ) {
		$v = $body[ ps_camel( $f ) ] ?? null;
		if ( str_ends_with( $f, '_id' ) ) {
			$fields[ $f ] = ps_clean_id( $v );
		} elseif ( in_array( $f, array( 'offer_amount', 'purchase_amount' ), true ) ) {
			$fields[ $f ] = ps_clean_number( $v );
		} elseif ( 'files_link' === $f ) {
			$fields[ $f ] = null === ps_clean_text( $v ) ? null : esc_url_raw( trim( (string) $v ) );
		} elseif ( 'is_project' === $f ) {
			// Absent (ancienne interface, import) → null = inchangé (nouveau projet : نعم). Présent mais vide → '' (erreur).
			$fields[ $f ] = array_key_exists( 'isProject', $body ) ? ps_clean_project_state( $v ) : null;
		} else {
			$fields[ $f ] = ps_clean_text( $v, in_array( $f, PS_PROJECT_MULTILINE, true ) );
		}
	}

	// Montant saisi sans devise → devise par défaut (LYD).
	foreach ( array( 'offer_amount' => 'offer_currency_id', 'purchase_amount' => 'purchase_currency_id' ) as $amount => $cur ) {
		if ( null !== $fields[ $amount ] && ! $fields[ $cur ] ) {
			$fields[ $cur ] = ps_list_default_id( 'currency' );
		}
	}

	$parties = array();
	foreach ( array_keys( ps_party_roles() ) as $role ) {
		$p                = (array) ( $body['parties'][ $role ] ?? array() );
		$parties[ $role ] = array(
			'company_id'   => ps_clean_id( $p['companyId'] ?? null ),
			'company_name' => ps_clean_text( $p['companyName'] ?? null ),
		);
		foreach ( PS_PARTY_FIELDS as $f ) {
			$parties[ $role ][ $f ] = ps_clean_text( $p[ ps_camel( $f ) ] ?? null );
		}
	}

	$items = array();
	foreach ( array_values( (array) ( $body['items'] ?? array() ) ) as $it ) {
		$it      = (array) $it;
		$items[] = array(
			'id'           => ps_clean_id( $it['id'] ?? null ),
			'item_type_id' => ps_clean_id( $it['itemTypeId'] ?? null ),
			'quantity'     => ps_clean_number( $it['quantity'] ?? null ),
			'employee_id'  => ps_clean_id( $it['employeeId'] ?? null ),
			'due_date'     => ps_clean_text( $it['dueDate'] ?? null ),
		);
	}

	$competitors = array();
	foreach ( array_values( (array) ( $body['competitors'] ?? array() ) ) as $c ) {
		$c = (array) $c;
		// Une ligne totalement vide est ignorée.
		if ( empty( $c['competitorId'] ) && null === ps_clean_text( $c['competitorName'] ?? null ) && null === ps_clean_text( $c['brand'] ?? null ) && empty( $c['itemTypeIds'] ) && null === ps_clean_text( $c['notes'] ?? null ) ) {
			continue;
		}
		$competitors[] = array(
			'id'              => ps_clean_id( $c['id'] ?? null ),
			'competitor_id'   => ps_clean_id( $c['competitorId'] ?? null ),
			'competitor_name' => ps_clean_text( $c['competitorName'] ?? null ),
			'brand'           => ps_clean_text( $c['brand'] ?? null ),
			'item_type_ids'   => array_values( array_unique( array_filter( array_map( 'ps_clean_id', (array) ( $c['itemTypeIds'] ?? array() ) ) ) ) ),
			'notes'           => ps_clean_text( $c['notes'] ?? null, true ),
		);
	}

	return array( 'fields' => $fields, 'parties' => ps_parties_from_directory( $parties ), 'items' => $items, 'competitors' => $competitors );
}

/**
 * 1.6.0 (« الشخص المباشر / الشخص المسؤول / الهاتف viennent de l'annuaire ») : pour une société choisie dans l'annuaire,
 * les coordonnées de la FICHE priment ; une coordonnée vide dans la fiche est prise du projet (et complète la fiche
 * à l'enregistrement). Rien n'est perdu : la fiche n'est jamais écrasée (modification réservée à l'annuaire).
 */
function ps_parties_from_directory( array $parties ): array {
	$map = array( 'person' => 'person', 'email' => 'email', 'phone' => 'phone', 'address' => 'address', 'position' => 'position', 'rank_title' => 'rank_title', 'location' => 'website' );
	foreach ( $parties as $role => &$p ) {
		$c = ! empty( $p['company_id'] ) ? ps_dir_get( 'companies', (int) $p['company_id'] ) : null;
		if ( ! $c ) {
			continue;
		}
		if ( ! empty( $c['merged_into_id'] ) ) {
			$c = ps_dir_get( 'companies', ps_dir_resolve( 'companies', (int) $c['id'] ) ) ?: $c;
		}
		foreach ( $map as $col => $ccol ) {
			if ( null !== $c[ $ccol ] && '' !== trim( (string) $c[ $ccol ] ) ) {
				$p[ $col ] = trim( (string) $c[ $ccol ] );
			}
		}
	}
	unset( $p );
	return $parties;
}

/* =========================================================================
 * Lecture
 * ====================================================================== */

function ps_project_row( int $id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $row ?: null;
}

/**
 * Projet complet (format interne) : fields, parties, items, competitors.
 */
function ps_project_load( int $id ): ?array {
	global $wpdb;
	$row = ps_project_row( $id );
	if ( ! $row ) {
		return null;
	}

	$parties = array();
	$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT pp.*, c.name AS company_name FROM ' . ps_table( 'project_parties' ) . ' pp LEFT JOIN ' . ps_table( 'companies' ) . ' c ON c.id = pp.company_id WHERE pp.project_id = %d ORDER BY pp.id', $id ), ARRAY_A );
	foreach ( $rows as $r ) {
		$parties[ $r['role'] ] = $r;
	}

	$lt    = ps_table( 'lists' );
	$items = $wpdb->get_results(
		$wpdb->prepare( 'SELECT i.*, os.code AS offer_status_code, ds.code AS delivery_status_code FROM ' . ps_table( 'items' ) . " i LEFT JOIN $lt os ON os.id = i.offer_status_id LEFT JOIN $lt ds ON ds.id = i.delivery_status_id WHERE i.project_id = %d ORDER BY i.sort_order, i.id", $id ),
		ARRAY_A
	);

	$competitors = $wpdb->get_results(
		$wpdb->prepare( 'SELECT pc.*, c.name AS competitor_name FROM ' . ps_table( 'project_competitors' ) . ' pc LEFT JOIN ' . ps_table( 'competitors' ) . ' c ON c.id = pc.competitor_id WHERE pc.project_id = %d ORDER BY pc.sort_order, pc.id', $id ),
		ARRAY_A
	);

	return array( 'fields' => $row, 'parties' => $parties, 'items' => $items, 'competitors' => $competitors );
}

/* =========================================================================
 * Enregistrement
 * ====================================================================== */

/**
 * Crée ($existing = null) ou modifie un projet. Tout se fait dans une transaction :
 * en cas d'erreur, rien n'est enregistré.
 *
 * La validation (ps_validate_project) et les droits doivent être vérifiés AVANT.
 *
 * @return int|WP_Error Id du projet.
 */
function ps_project_save( array $in, ?array $existing, int $user_id ) {
	global $wpdb;
	$now = ps_now();
	$f   = $in['fields'];

	// Valeurs par défaut.
	if ( ! $f['followup_status_id'] ) {
		$f['followup_status_id'] = $existing['fields']['followup_status_id'] ?? ps_list_default_id( 'followup_status' );
	}
	// « مشروع؟ » : absent → inchangé (nouveau projet : نعم) ; « نعم » efface le سبب (gardé au journal).
	if ( null === $f['is_project'] || '' === $f['is_project'] ) {
		$f['is_project'] = (int) ( $existing['fields']['is_project'] ?? 1 );
		if ( $existing ) {
			$f['non_project_reason_id'] = $f['non_project_reason_id'] ?? $existing['fields']['non_project_reason_id'];
			$f['non_project_note']      = $f['non_project_note'] ?? $existing['fields']['non_project_note'];
		}
	}
	if ( PS_PROJECT_NO !== (int) $f['is_project'] ) { // نعم ou لم يُحدد : pas de سبب
		$f['non_project_reason_id'] = null;
		$f['non_project_note']      = null;
	}
	$f['offer_no']  = null === $f['offer_no'] ? null : trim( $f['offer_no'] );
	$f['name_norm'] = ps_normalize_text( $f['name'] );
	$f['month']     = (int) substr( (string) $f['received_date'], 5, 2 );

	$wpdb->query( 'START TRANSACTION' );
	try {
		if ( ! $existing ) {
			$project_id = ps_project_insert( $f, $user_id, $now );
			if ( is_wp_error( $project_id ) ) {
				throw new RuntimeException( $project_id->get_error_message() );
			}
			ps_log( $project_id, 'create', 'project', $project_id, null, null, $f['name'], $user_id );
		} else {
			$project_id = (int) $existing['fields']['id'];
			ps_project_update_fields( $project_id, $existing['fields'], $f, $user_id, $now );
		}

		ps_project_save_parties( $project_id, $in['parties'], $existing['parties'] ?? array(), $user_id, $now, (bool) $existing );
		ps_project_save_items( $project_id, $in['items'], $existing['items'] ?? array(), $user_id, $now, (bool) $existing );
		ps_project_save_competitors( $project_id, $in['competitors'], $existing['competitors'] ?? array(), $user_id, $now, (bool) $existing );

		if ( $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error );
		}
		$wpdb->query( 'COMMIT' );
		return $project_id;
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[protecta-suivi] save error: ' . $e->getMessage() ); // phpcs:ignore
		return new WP_Error( 'ps_save_failed', ps__( 'تعذّر حفظ المشروع: ' ) . $e->getMessage(), array( 'status' => 500 ) );
	}
}

/**
 * Insertion avec numérotation automatique PRJ-AAAA-NNN (nouvel essai si deux
 * enregistrements simultanés obtiennent le même numéro).
 *
 * @return int|WP_Error
 */
function ps_project_insert( array $f, int $user_id, string $now ) {
	global $wpdb;
	$t    = ps_table( 'projects' );
	$year = (int) substr( (string) $f['received_date'], 0, 4 );

	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$seq = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(seq) FROM $t WHERE year = %d", $year ) );
		$row = array_intersect_key( $f, array_flip( array_merge( PS_PROJECT_FIELDS, array( 'name_norm', 'month' ) ) ) );
		$row = array_merge(
			$row,
			array(
				'project_no' => sprintf( 'PRJ-%d-%03d', $year, $seq ),
				'year'       => $year,
				'seq'        => $seq,
				'created_by' => $user_id,
				'updated_by' => $user_id,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->last_error = '';
		if ( $wpdb->insert( $t, $row ) ) {
			return (int) $wpdb->insert_id;
		}
		if ( str_contains( $wpdb->last_error, 'offer_no' ) ) {
			return new WP_Error( 'ps_offer_no', ps__( 'رقم العرض مستعمل' ) );
		}
		if ( ! str_contains( $wpdb->last_error, 'Duplicate' ) ) {
			return new WP_Error( 'ps_db', $wpdb->last_error );
		}
	}
	return new WP_Error( 'ps_numbering', ps__( 'تعذّر توليد رقم المشروع، حاول مرة أخرى' ) );
}

/**
 * Texte lisible d'une valeur de champ pour le journal.
 */
function ps_field_display( string $field, $value ): ?string {
	if ( null === $value || '' === $value ) {
		return null;
	}
	if ( 'is_project' === $field ) {
		return array( 'yes' => 'نعم', 'no' => 'لا', 'pending' => 'لم يُحدد بعد' )[ ps_project_state_code( $value ) ];
	}
	if ( str_ends_with( $field, '_id' ) ) {
		return ps_list_label( $value );
	}
	if ( in_array( $field, array( 'offer_amount', 'purchase_amount', 'quantity' ), true ) ) {
		return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' );
	}
	return (string) $value;
}

/**
 * Met à jour les colonnes modifiées et journalise chaque changement (ancien → nouveau).
 */
function ps_project_update_fields( int $id, array $old, array $f, int $user_id, string $now ): void {
	global $wpdb;
	$set = array();
	foreach ( array_merge( PS_PROJECT_FIELDS, array( 'name_norm', 'month' ) ) as $col ) {
		$a = $old[ $col ] ?? null;
		$b = $f[ $col ] ?? null;
		$same = in_array( $col, array( 'offer_amount', 'purchase_amount' ), true )
			? ( ( null === $a && null === $b ) || ( null !== $a && null !== $b && abs( (float) $a - (float) $b ) < 0.0005 ) )
			: (string) $a === (string) $b;
		if ( $same ) {
			continue;
		}
		$set[ $col ] = $b;
		if ( ! in_array( $col, array( 'name_norm', 'month' ), true ) ) {
			ps_log( $id, 'update', 'project', $id, $col, ps_field_display( $col, $a ), ps_field_display( $col, $b ), $user_id );
		}
	}
	$set['updated_by'] = $user_id;
	$set['updated_at'] = $now;
	$wpdb->update( ps_table( 'projects' ), $set, array( 'id' => $id ) );
}

/**
 * Intervenants : lien vers l'annuaire (société existante, ou nouvelle fiche) + coordonnées
 * propres au projet. Les coordonnées vides de la fiche société sont complétées.
 */
function ps_project_save_parties( int $project_id, array $parties, array $old_parties, int $user_id, string $now, bool $log ): void {
	global $wpdb;
	$t = ps_table( 'project_parties' );

	foreach ( $parties as $role => $p ) {
		$company_data = array(
			'person'     => $p['person'],
			'email'      => $p['email'],
			'phone'      => $p['phone'],
			'address'    => $p['address'],
			'position'   => $p['position'],
			'rank_title' => $p['rank_title'],
			'website'    => $p['location'],
		);
		$company_id = null;
		if ( $p['company_id'] && ps_dir_get( 'companies', $p['company_id'] ) ) {
			$company_id = ps_dir_resolve( 'companies', $p['company_id'] );
			ps_company_fill_empty( $company_id, $company_data );
		} elseif ( $p['company_name'] ) {
			$company_id = ps_dir_find_or_create( 'companies', $p['company_name'], $company_data, $user_id );
		}

		$row = array( 'company_id' => $company_id );
		foreach ( PS_PARTY_FIELDS as $col ) {
			$row[ $col ] = $p[ $col ];
		}
		$is_empty = ! $company_id && ! array_filter( array_intersect_key( $row, array_flip( PS_PARTY_FIELDS ) ) );
		$old      = $old_parties[ $role ] ?? null;

		if ( $old ) {
			$changes = array();
			foreach ( $row as $col => $v ) {
				if ( (string) $old[ $col ] !== (string) $v ) {
					$changes[ $col ] = array( $old[ $col ], $v );
				}
			}
			if ( $is_empty ) {
				$wpdb->delete( $t, array( 'id' => (int) $old['id'] ) );
			} elseif ( $changes ) {
				$wpdb->update( $t, array_merge( $row, array( 'updated_at' => $now ) ), array( 'id' => (int) $old['id'] ) );
			}
			if ( $log ) {
				foreach ( $changes as $col => list( $a, $b ) ) {
					$disp = fn( $v ) => 'company_id' === $col ? ( $v ? ( ps_dir_get( 'companies', (int) $v )['name'] ?? null ) : null ) : $v;
					ps_log( $project_id, 'update', 'party', (int) $old['id'], $role . '.' . $col, $disp( $a ), $disp( $b ), $user_id );
				}
			}
		} elseif ( ! $is_empty ) {
			$wpdb->insert( $t, array_merge( $row, array( 'project_id' => $project_id, 'role' => $role, 'created_at' => $now, 'updated_at' => $now ) ) );
			if ( $log ) {
				$name = $company_id ? ps_dir_get( 'companies', $company_id )['name'] : $row['person'];
				ps_log( $project_id, 'create', 'party', (int) $wpdb->insert_id, $role, null, $name, $user_id );
			}
		}
	}
}

/**
 * أصناف : ajout, modification de la structure, retrait (seulement s'ils sont encore
 * « قيد الإعداد » — vérifié par la validation).
 */
function ps_project_save_items( int $project_id, array $items, array $old_items, int $user_id, string $now, bool $log ): void {
	global $wpdb;
	$t   = ps_table( 'items' );
	$old = array();
	foreach ( $old_items as $it ) {
		$old[ (int) $it['id'] ] = $it;
	}
	$kept = array();

	foreach ( $items as $sort => $it ) {
		$row = array( 'sort_order' => ( $sort + 1 ) * 10 );
		foreach ( PS_ITEM_FIELDS as $col ) {
			$row[ $col ] = $it[ $col ];
		}
		if ( $it['id'] && isset( $old[ $it['id'] ] ) ) {
			$prev   = $old[ $it['id'] ];
			$kept[] = (int) $it['id'];
			$diff   = array();
			foreach ( PS_ITEM_FIELDS as $col ) {
				$same = 'quantity' === $col
					? ( ( null === $prev[ $col ] && null === $row[ $col ] ) || ( null !== $prev[ $col ] && null !== $row[ $col ] && abs( (float) $prev[ $col ] - (float) $row[ $col ] ) < 0.0005 ) )
					: (string) $prev[ $col ] === (string) $row[ $col ];
				if ( ! $same ) {
					$diff[ $col ] = array( $prev[ $col ], $row[ $col ] );
				}
			}
			if ( $diff || (int) $prev['sort_order'] !== $row['sort_order'] ) {
				$wpdb->update( $t, array_merge( $row, array( 'updated_at' => $now ) ), array( 'id' => (int) $it['id'] ) );
			}
			foreach ( $diff as $col => list( $a, $b ) ) {
				ps_log( $project_id, 'update', 'item', (int) $it['id'], $col, ps_field_display( $col, $a ), ps_field_display( $col, $b ), $user_id );
			}
		} else {
			$wpdb->insert(
				$t,
				array_merge(
					$row,
					array(
						'project_id'         => $project_id,
						'offer_status_id'    => ps_list_id_by_code( 'offer_status', 'preparing' ),
						'delivery_status_id' => ps_list_id_by_code( 'delivery_status', 'waiting' ),
						'created_at'         => $now,
						'updated_at'         => $now,
					)
				)
			);
			if ( $log ) {
				ps_log( $project_id, 'create', 'item', (int) $wpdb->insert_id, null, null, ps_list_label( $row['item_type_id'] ), $user_id );
			}
		}
	}

	foreach ( $old as $id => $it ) {
		if ( ! in_array( $id, $kept, true ) ) {
			$wpdb->delete( $t, array( 'id' => $id ) );
			ps_log( $project_id, 'delete', 'item', $id, null, ps_list_label( $it['item_type_id'] ), null, $user_id );
		}
	}
}

/**
 * منافسون du projet (lignes remplacées ; chaque concurrent est rattaché à l'annuaire).
 */
function ps_project_save_competitors( int $project_id, array $rows, array $old_rows, int $user_id, string $now, bool $log ): void {
	global $wpdb;
	$t   = ps_table( 'project_competitors' );
	$old = array();
	foreach ( $old_rows as $r ) {
		$old[ (int) $r['id'] ] = $r;
	}
	$summary = fn( $name, $brand ) => trim( $name . ( $brand ? ' (' . $brand . ')' : '' ) );
	$kept    = array();

	foreach ( $rows as $sort => $c ) {
		if ( $c['competitor_id'] && ps_dir_get( 'competitors', $c['competitor_id'] ) ) {
			$cid = ps_dir_resolve( 'competitors', $c['competitor_id'] );
		} else {
			$cid = $c['competitor_name'] ? ps_dir_find_or_create( 'competitors', $c['competitor_name'], array(), $user_id ) : null;
		}
		$row = array(
			'competitor_id' => $cid,
			'brand'         => $c['brand'],
			'item_type_ids' => $c['item_type_ids'] ? implode( ',', $c['item_type_ids'] ) : null,
			'notes'         => $c['notes'],
			'sort_order'    => ( $sort + 1 ) * 10,
		);
		$name = $cid ? ps_dir_get( 'competitors', $cid )['name'] : '';
		if ( $c['id'] && isset( $old[ $c['id'] ] ) ) {
			$prev   = $old[ $c['id'] ];
			$kept[] = (int) $c['id'];
			$changed = false;
			foreach ( $row as $col => $v ) {
				if ( (string) $prev[ $col ] !== (string) $v ) {
					$changed = true;
				}
			}
			if ( $changed ) {
				$wpdb->update( $t, array_merge( $row, array( 'updated_at' => $now ) ), array( 'id' => (int) $c['id'] ) );
				ps_log( $project_id, 'update', 'competitor', (int) $c['id'], null, $summary( (string) $prev['competitor_name'], $prev['brand'] ), $summary( $name, $row['brand'] ), $user_id );
			}
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'project_id' => $project_id, 'created_at' => $now, 'updated_at' => $now ) ) );
			if ( $log ) {
				ps_log( $project_id, 'create', 'competitor', (int) $wpdb->insert_id, null, null, $summary( $name, $row['brand'] ), $user_id );
			}
		}
	}
	foreach ( $old as $id => $r ) {
		if ( ! in_array( $id, $kept, true ) ) {
			$wpdb->delete( $t, array( 'id' => $id ) );
			ps_log( $project_id, 'delete', 'competitor', $id, null, $summary( (string) $r['competitor_name'], $r['brand'] ), null, $user_id );
		}
	}
}

/* =========================================================================
 * Corbeille (الإدارة) et modification groupée
 * ====================================================================== */

function ps_project_soft_delete( int $id, int $user_id ): void {
	global $wpdb;
	$wpdb->update( ps_table( 'projects' ), array( 'deleted_at' => ps_now(), 'deleted_by' => $user_id ), array( 'id' => $id ) );
	ps_log( $id, 'delete', 'project', $id, null, null, null, $user_id );
}

function ps_project_restore( int $id, int $user_id ): void {
	global $wpdb;
	$wpdb->update( ps_table( 'projects' ), array( 'deleted_at' => null, 'deleted_by' => null ), array( 'id' => $id ) );
	ps_log( $id, 'restore', 'project', $id, null, null, null, $user_id );
}

/**
 * Attribue un مصدر à plusieurs projets (décision n°3 : corriger les « غير محدد » importés).
 *
 * @return int Nombre de projets modifiés.
 */
function ps_projects_bulk_source( array $ids, int $source_id, int $user_id ): int {
	global $wpdb;
	$t     = ps_table( 'projects' );
	$count = 0;
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
		$row = ps_project_row( $id );
		if ( ! $row || $row['deleted_at'] || (int) $row['source_id'] === $source_id ) {
			continue;
		}
		$wpdb->update( $t, array( 'source_id' => $source_id, 'updated_by' => $user_id, 'updated_at' => ps_now() ), array( 'id' => $id ) );
		ps_log( $id, 'update', 'project', $id, 'source_id', ps_list_label( $row['source_id'] ), ps_list_label( $source_id ), $user_id );
		++$count;
	}
	return $count;
}

/* =========================================================================
 * Sortie : format de l'API
 * ====================================================================== */

/**
 * Valeurs calculées d'un projet (inc/calculs.php).
 */
function ps_project_computed( array $fields, array $items ): array {
	$lists = ps_lists_by_id();
	$w     = fn( $id ) => $id && isset( $lists[ (int) $id ] ) && null !== $lists[ (int) $id ]['weight'] ? (int) $lists[ (int) $id ]['weight'] : null;
	return array(
		'offerStatus' => ps_calc_project_offer_status( array_map( fn( $i ) => (string) ( $i['offer_status_code'] ?? 'preparing' ), $items ) ),
		'priority'    => ps_calc_priority( $w( $fields['urgency_id'] ), $w( $fields['importance_size_id'] ) ),
		'margin'      => ps_calc_margin( $fields['offer_amount'], $fields['offer_currency_id'], $fields['purchase_amount'], $fields['purchase_currency_id'] ),
	);
}

/**
 * Ancien numéro d'un projet importé, quand il diffère du numéro actuel (1.5.0) :
 * fichier Excel (legacy_id « xlsx:P029 ») ou prototype (« no » du JSON d'origine). Sinon null.
 */
function ps_project_old_no( array $r ): ?string {
	$id  = (string) ( $r['legacy_id'] ?? '' );
	$old = null;
	if ( str_starts_with( $id, 'xlsx:' ) && ! preg_match( '/^[0-9a-f]{32}$/', substr( $id, 5 ) ) ) {
		$old = substr( $id, 5 );
	} elseif ( '' !== $id && ! empty( $r['legacy_raw'] ) ) {
		$raw = json_decode( (string) $r['legacy_raw'], true );
		$old = is_array( $raw ) && ! empty( $raw['no'] ) && is_string( $raw['no'] ) ? trim( $raw['no'] ) : null;
	}
	return $old && $old !== $r['project_no'] ? $old : null;
}

/**
 * Projet complet au format de l'API (camelCase) + calculs + droits de l'utilisateur.
 */
function ps_project_to_api( array $p, int $user_id ): array {
	$f   = $p['fields'];
	$out = array(
		'id'           => (int) $f['id'],
		'projectNo'    => $f['project_no'],
		'oldNo'        => ps_project_old_no( $f ),
		'seq'          => (int) $f['seq'],
		'receivedYear' => (int) substr( $f['received_date'], 0, 4 ),
		'month'        => (int) $f['month'],
	);
	foreach ( PS_PROJECT_FIELDS as $col ) {
		$v = $f[ $col ];
		if ( str_ends_with( $col, '_id' ) ) {
			$v = $v ? (int) $v : null;
		} elseif ( in_array( $col, array( 'offer_amount', 'purchase_amount' ), true ) ) {
			$v = null === $v ? null : (float) $v;
		} elseif ( 'is_project' === $col ) {
			$out['projectState'] = ps_project_state_code( $v ); // 1.9.0 : yes | no | pending
			$v                   = ps_project_state_api( $v );
		}
		$out[ ps_camel( $col ) ] = $v;
	}
	$user = fn( $id ) => $id && ( $u = get_userdata( (int) $id ) ) ? array( 'id' => (int) $id, 'name' => $u->display_name ) : null;
	$out['createdAt'] = $f['created_at'];
	$out['createdBy'] = $user( $f['created_by'] );
	$out['updatedAt'] = $f['updated_at'];
	$out['updatedBy'] = $user( $f['updated_by'] );
	$out['deletedAt'] = $f['deleted_at'];

	$out['parties'] = array();
	foreach ( array_keys( ps_party_roles() ) as $role ) {
		$r = $p['parties'][ $role ] ?? null;
		$o = array(
			'companyId'   => $r && $r['company_id'] ? (int) $r['company_id'] : null,
			'companyName' => $r['company_name'] ?? null,
		);
		foreach ( PS_PARTY_FIELDS as $col ) {
			$o[ ps_camel( $col ) ] = $r[ $col ] ?? null;
		}
		// 1.6.0 : coordonnées venant de l'annuaire (société liée) — affichées telles que dans la fiche société.
		$o['fromDirectory'] = array();
		$c                  = $o['companyId'] ? ps_dir_get( 'companies', ps_dir_resolve( 'companies', $o['companyId'] ) ) : null;
		if ( $c ) {
			foreach ( array( 'person' => 'person', 'email' => 'email', 'phone' => 'phone', 'address' => 'address', 'position' => 'position', 'rank_title' => 'rank_title', 'location' => 'website' ) as $col => $ccol ) {
				if ( null !== $c[ $ccol ] && '' !== trim( (string) $c[ $ccol ] ) ) {
					$o[ ps_camel( $col ) ] = trim( (string) $c[ $ccol ] );
					$o['fromDirectory'][]  = ps_camel( $col );
				}
			}
		}
		$out['parties'][ $role ] = $o;
	}

	$out['items'] = array_map(
		static fn( $i ) => array(
			'id'                  => (int) $i['id'],
			'itemTypeId'          => $i['item_type_id'] ? (int) $i['item_type_id'] : null,
			'quantity'            => null === $i['quantity'] ? null : (float) $i['quantity'],
			'employeeId'          => $i['employee_id'] ? (int) $i['employee_id'] : null,
			'dueDate'             => $i['due_date'],
			'offerStatusId'       => $i['offer_status_id'] ? (int) $i['offer_status_id'] : null,
			'offerStatusCode'     => $i['offer_status_code'],
			'offerDate'           => $i['offer_date'],
			'notSentReasonId'     => $i['not_sent_reason_id'] ? (int) $i['not_sent_reason_id'] : null,
			'offerInvoiceNo'      => $i['offer_invoice_no'],
			'deliveryStatusId'    => $i['delivery_status_id'] ? (int) $i['delivery_status_id'] : null,
			'deliveryStatusCode'  => $i['delivery_status_code'],
		),
		$p['items']
	);

	$out['competitors'] = array_map(
		static fn( $c ) => array(
			'id'             => (int) $c['id'],
			'competitorId'   => $c['competitor_id'] ? (int) $c['competitor_id'] : null,
			'competitorName' => $c['competitor_name'],
			'brand'          => $c['brand'],
			'itemTypeIds'    => $c['item_type_ids'] ? array_map( 'intval', explode( ',', $c['item_type_ids'] ) ) : array(),
			'notes'          => $c['notes'],
		),
		$p['competitors']
	);

	$out['computed'] = ps_project_computed( $f, $p['items'] );
	// Alertes (retards, suivi) : mêmes règles que مهامي et le Recap (inc/calculs.php).
	$fact                      = ps_stats_facts( array( 'ids' => array( (int) $f['id'] ) ) )[0] ?? null;
	$out['computed']['alerts'] = array(
		'needsFollowup'  => $fact ? $fact['needs_followup'] : false,
		'nextFollowup'   => $fact['next_followup'] ?? null,
		'lateOffers'     => $fact ? count( array_filter( $fact['items'], fn( $i ) => $i['late'] ) ) : 0,
		'lateDeliveries' => $fact ? count( array_filter( $fact['items'], fn( $i ) => $i['delivery_late'] ) ) : 0,
	);
	$by_item = array();
	foreach ( $fact['items'] ?? array() as $i ) {
		$by_item[ $i['id'] ] = $i;
	}
	foreach ( $out['items'] as &$it ) {
		$c              = $by_item[ $it['id'] ] ?? null;
		$it['computed'] = array(
			'late'            => $c ? $c['late'] : false,
			'deliveryLate'    => $c ? $c['delivery_late'] : false,
			'delayDays'       => $c ? $c['delay'] : 0,
			'deliveryDisplay' => $c ? $c['delivery_display'] : null,
		);
	}
	unset( $it );

	$reason             = ps_project_edit_reason( $f, $user_id );
	$out['permissions'] = array(
		'canEdit'        => null !== $reason,
		'editReason'     => $reason,
		'editUntil'      => 'creator' === $reason ? ps_creator_edit_until( $f ) : null,
		// 1.4.0 : موظف créateur → seulement ses أصناف ; montants verrouillés s'il y a des أصناف d'autres employés.
		'ownItemsOnly'   => 'creator' === $reason && ! user_can( $user_id, 'ps_edit_own_projects' ),
		'canAddFollowup' => user_can( $user_id, 'ps_add_followup' ) && ps_user_involved_in_project( $f, array_column( $p['items'], 'employee_id' ), $user_id ),
		'canDelete'      => user_can( $user_id, 'ps_delete' ),
	);
	return $out;
}

/* =========================================================================
 * Base Projets : liste filtrée, triée, paginée
 * ====================================================================== */

/**
 * Filtres acceptés (paramètres de l'API GET /projects) → colonne.
 */
function ps_project_list_filters(): array {
	return array(
		'placeId'           => 'place_id',
		'urgencyId'         => 'urgency_id',
		'importanceKindId'  => 'importance_kind_id',
		'importanceSizeId'  => 'importance_size_id',
		'categoryId'        => 'category_id',
		'followerEmpId'     => 'follower_emp_id',
		'intermediaryEmpId' => 'intermediary_emp_id',
		'responsibleEmpId'  => 'responsible_emp_id',
		'followupStatusId'  => 'followup_status_id',
		'sourceId'          => 'source_id',
	);
}

/**
 * Liste des projets pour Base Projets.
 *
 * Les filtres et le tri sont appliqués en PHP sur l'ensemble des projets (quelques milliers
 * au plus) : cela permet de filtrer aussi sur les valeurs calculées (حالة العرض، الأولوية)
 * et de chercher en arabe normalisé.
 *
 * @param array $args q, year, month, offerStatus, followupType, priority, itemTypeId, companyId, trash, isProject,
 *                    sort, dir (asc|desc), page, perPage + ps_project_list_filters().
 */
function ps_projects_list( array $args ): array {
	$uid = get_current_user_id();
	global $wpdb;
	$pt    = ps_table( 'projects' );
	$trash = ! empty( $args['trash'] );
	$rows  = $wpdb->get_results(
		"SELECT id, project_no, seq, offer_no, name, received_date, sent_date, month, responsible_emp_id, follower_emp_id, intermediary_emp_id, place_id, category_id, urgency_id, importance_kind_id, importance_size_id, followup_status_id, source_id, offer_amount, offer_currency_id, files_link, note, created_by, created_at, deleted_at, legacy_id, legacy_raw, is_project, non_project_reason_id, non_project_note FROM $pt WHERE deleted_at IS " . ( $trash ? 'NOT NULL' : 'NULL' ), // phpcs:ignore
		ARRAY_A
	);
	if ( ! $rows ) {
		return array( 'total' => 0, 'rows' => array() );
	}

	// Intervenants (المالك = اسم الشركة ; الشخص المباشر) et أصناف, en deux requêtes.
	$ids     = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
	$parties = array();
	foreach ( $wpdb->get_results( 'SELECT pp.project_id, pp.role, pp.company_id, pp.person, pp.phone, c.name FROM ' . ps_table( 'project_parties' ) . ' pp LEFT JOIN ' . ps_table( 'companies' ) . " c ON c.id = pp.company_id WHERE pp.project_id IN ($ids) AND pp.role IN ('owner','direct')", ARRAY_A ) as $r ) { // phpcs:ignore
		$parties[ $r['project_id'] ][ $r['role'] ] = $r;
	}
	$items = array();
	foreach ( $wpdb->get_results( 'SELECT i.project_id, i.item_type_id, i.employee_id, os.code AS offer_status_code FROM ' . ps_table( 'items' ) . ' i LEFT JOIN ' . ps_table( 'lists' ) . " os ON os.id = i.offer_status_id WHERE i.project_id IN ($ids) ORDER BY i.sort_order, i.id", ARRAY_A ) as $r ) { // phpcs:ignore
		$items[ $r['project_id'] ][] = $r;
	}

	$lists   = ps_lists_by_id();
	$q       = ps_normalize_text( (string) ( $args['q'] ?? '' ) );
	$filters = ps_project_list_filters();
	$out     = array();
	$facets  = array( 'yes' => 0, 'no' => 0, 'pending' => 0 );

	foreach ( $rows as $r ) {
		$pid   = $r['id'];
		$its   = $items[ $pid ] ?? array();
		$owner = $parties[ $pid ]['owner'] ?? array();
		$dir   = $parties[ $pid ]['direct'] ?? array();
		$comp  = ps_project_computed( $r + array( 'purchase_amount' => null, 'purchase_currency_id' => null ), $its );

		// Filtres sur colonnes.
		$year = (int) substr( $r['received_date'], 0, 4 );
		if ( ! empty( $args['year'] ) && (int) $args['year'] !== $year ) {
			continue;
		}
		if ( ! empty( $args['month'] ) && (int) $args['month'] !== (int) substr( $r['received_date'], 5, 2 ) ) {
			continue;
		}
		$skip = false;
		foreach ( $filters as $param => $col ) {
			if ( isset( $args[ $param ] ) && '' !== (string) $args[ $param ] ) {
				// « unspecified » = مصدر غير محدد (projets importés) ; « none » = champ vide.
				$want = (string) $args[ $param ];
				if ( 'none' === $want ) {
					$skip = $skip || ! empty( $r[ $col ] );
				} elseif ( 'sourceId' === $param && 'unspecified' === $want ) {
					$skip = $skip || 'unspecified' !== ( $lists[ (int) $r[ $col ] ]['code'] ?? '' );
				} else {
					$skip = $skip || (int) $r[ $col ] !== (int) $want;
				}
			}
		}
		if ( $skip ) {
			continue;
		}
		// Type de حالة المتابعة (clic sur le graphique mensuel du Recap) : ongoing | confirmed | rejected.
		if ( ! empty( $args['followupType'] ) ) {
			$ft = $lists[ (int) $r['followup_status_id'] ]['type'] ?? 'ongoing';
			$ft = 'delivered' === $ft ? 'confirmed' : $ft;
			if ( $ft !== $args['followupType'] || 'cancelled' === $comp['offerStatus'] ) {
				continue;
			}
		}
		if ( ! empty( $args['offerStatus'] ) && $comp['offerStatus'] !== $args['offerStatus'] ) {
			continue;
		}
		if ( ! empty( $args['priority'] ) && $comp['priority'] !== $args['priority'] ) {
			continue;
		}
		if ( ! empty( $args['itemTypeId'] ) && ! in_array( (int) $args['itemTypeId'], array_map( 'intval', array_column( $its, 'item_type_id' ) ), true ) ) {
			continue;
		}
		if ( ! empty( $args['companyId'] ) && (int) ( $owner['company_id'] ?? 0 ) !== (int) $args['companyId'] ) {
			continue;
		}

		$item_labels = array_map( fn( $i ) => $lists[ (int) $i['item_type_id'] ]['label'] ?? '', $its );

		// Recherche générale (texte normalisé).
		if ( '' !== $q ) {
			$hay = ps_normalize_text(
				implode(
					' ',
					array(
						$r['project_no'], ps_project_old_no( $r ), $r['offer_no'], $r['name'], $owner['name'] ?? '', $dir['person'] ?? '',
						$dir['phone'] ?? '', $r['note'], implode( ' ', $item_labels ),
						$lists[ (int) $r['place_id'] ]['code'] ?? '', $lists[ (int) $r['place_id'] ]['label'] ?? '',
					)
				)
			);
			if ( ! str_contains( $hay, $q ) ) {
				continue;
			}
		}

		// 1.6.0 : « مشروع؟ » — isProject=1 (مشاريع) ou 0 (غير مشروع). Compteurs (1.7.0) avant ce filtre.
		$facets[ ps_project_state_code( $r['is_project'] ) ]++;
		if ( isset( $args['isProject'] ) && '' !== (string) $args['isProject'] && (int) $args['isProject'] !== (int) $r['is_project'] ) {
			continue;
		}

		$out[] = array(
			'id'                => (int) $pid,
			'projectNo'         => $r['project_no'],
			'oldNo'             => ps_project_old_no( $r ),
			'seq'               => (int) $r['seq'],
			'offerNo'           => $r['offer_no'],
			'receivedYear'      => $year,
			'month'             => (int) substr( $r['received_date'], 5, 2 ),
			'placeId'           => $r['place_id'] ? (int) $r['place_id'] : null,
			'urgencyId'         => $r['urgency_id'] ? (int) $r['urgency_id'] : null,
			'importanceKindId'  => $r['importance_kind_id'] ? (int) $r['importance_kind_id'] : null,
			'importanceSizeId'  => $r['importance_size_id'] ? (int) $r['importance_size_id'] : null,
			'categoryId'        => $r['category_id'] ? (int) $r['category_id'] : null,
			'companyId'         => ! empty( $owner['company_id'] ) ? (int) $owner['company_id'] : null,
			'companyName'       => $owner['name'] ?? null,
			'directPerson'      => $dir['person'] ?? null,
			'directPhone'       => $dir['phone'] ?? null,
			'name'              => $r['name'],
			'itemTypeIds'       => array_map( 'intval', array_column( $its, 'item_type_id' ) ),
			'receivedDate'      => $r['received_date'],
			'sentDate'          => $r['sent_date'],
			'followerEmpId'     => $r['follower_emp_id'] ? (int) $r['follower_emp_id'] : null,
			'intermediaryEmpId' => $r['intermediary_emp_id'] ? (int) $r['intermediary_emp_id'] : null,
			'responsibleEmpId'  => $r['responsible_emp_id'] ? (int) $r['responsible_emp_id'] : null,
			'followupStatusId'  => $r['followup_status_id'] ? (int) $r['followup_status_id'] : null,
			'sourceId'          => $r['source_id'] ? (int) $r['source_id'] : null,
			'offerStatus'       => $comp['offerStatus'],
			'priority'          => $comp['priority'],
			'offerAmount'       => null === $r['offer_amount'] ? null : (float) $r['offer_amount'],
			'offerCurrencyId'   => $r['offer_currency_id'] ? (int) $r['offer_currency_id'] : null,
			'note'              => $r['note'],
			'isProject'         => ps_project_state_api( $r['is_project'] ),
			'projectState'      => ps_project_state_code( $r['is_project'] ), // 1.9.0
			'nonProjectReasonId'=> $r['non_project_reason_id'] ? (int) $r['non_project_reason_id'] : null,
			'nonProjectNote'    => $r['non_project_note'] ?? null,
			'canEdit'           => null !== ps_project_edit_reason( $r, $uid ), // 1.8.0 : « مشروع؟ » modifiable dans la liste
			'filesLink'         => $r['files_link'],
			'deletedAt'         => $r['deleted_at'],
		);
	}

	// Tri (par défaut : du plus récent au plus ancien).
	$sort = (string) ( $args['sort'] ?? 'receivedDate' );
	$dir  = 'asc' === ( $args['dir'] ?? 'desc' ) ? 1 : -1;
	$key  = static function ( $row ) use ( $sort, $lists ) {
		$v = $row[ $sort ] ?? null;
		if ( str_ends_with( $sort, 'Id' ) && $v ) {
			return (int) ( $lists[ $v ]['sort_order'] ?? 0 ); // ordre des listes de الإعدادات
		}
		return is_string( $v ) ? ps_normalize_text( $v ) : $v;
	};
	usort(
		$out,
		static function ( $a, $b ) use ( $key, $dir ) {
			$c = $key( $a ) <=> $key( $b );
			return 0 !== $c ? $c * $dir : ( $b['id'] <=> $a['id'] );
		}
	);

	$total    = count( $out );
	$per_page = max( 1, min( 500, (int) ( $args['perPage'] ?? 50 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	return array(
		'total'   => $total,
		'page'    => $page,
		'perPage' => $per_page,
		'facets'  => array( 'isProject' => $facets ), // 1.7.0 : compteurs des filtres rapides
		'rows'    => array_slice( $out, ( $page - 1 ) * $per_page, $per_page ),
	);
}

/**
 * 1.8.0 — « مشروع؟ » changé directement depuis la liste des projets (le projet devient — ou cesse d'être — une offre).
 * Mêmes règles que la fiche : « لا » → سبب obligatoire (liste non_project_reason) ; « نعم » et « لم يُحدد بعد » (1.9.0)
 * effacent le سبب et la note.
 * Chaque champ modifié est journalisé (سجل التعديلات). Les droits sont vérifiés par l'appelant.
 *
 * @return array|WP_Error { isProject, nonProjectReasonId, itemsCount }
 */
function ps_project_set_is_project( int $id, int $state, $reason_id, ?string $note, int $user_id ) {
	global $wpdb;
	$row = ps_project_row( $id );
	if ( ! $row || $row['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'المشروع غير موجود' ), array( 'status' => 404 ) );
	}
	$no  = PS_PROJECT_NO === $state; // seul « لا » garde un سبب et une note
	$new = array(
		'is_project'            => $state,
		'non_project_reason_id' => $no && $reason_id ? (int) $reason_id : null,
		'non_project_note'      => $no && '' !== trim( (string) $note ) ? trim( (string) $note ) : null,
	);
	if ( $no ) {
		$lists_t = ps_table( 'lists' );
		$lr      = $new['non_project_reason_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT list_key, is_hidden FROM $lists_t WHERE id = %d", $new['non_project_reason_id'] ), ARRAY_A ) : null; // phpcs:ignore
		$err     = null;
		if ( ! $new['non_project_reason_id'] ) {
			$err = ps__( 'خانة إجبارية: ' ) . ps__( 'سبب عدم اعتباره مشروعا' );
		} elseif ( ! $lr || 'non_project_reason' !== $lr['list_key'] ) {
			$err = ps__( 'قيمة غير موجودة في القائمة: ' ) . ps__( 'سبب عدم اعتباره مشروعا' );
		} elseif ( $lr['is_hidden'] && (int) $row['non_project_reason_id'] !== $new['non_project_reason_id'] ) {
			$err = ps__( 'هذه القيمة لم تعد متاحة: ' ) . ps__( 'سبب عدم اعتباره مشروعا' );
		}
		if ( $err ) {
			return new WP_Error( 'ps_validation', $err, array( 'status' => 422, 'errors' => array( 'non_project_reason_id' => $err ) ) );
		}
	}
	$set = array();
	foreach ( $new as $col => $val ) {
		if ( (string) $row[ $col ] === (string) $val ) {
			continue;
		}
		$set[ $col ] = $val;
		ps_log( $id, 'update', 'project', $id, $col, ps_field_display( $col, $row[ $col ] ), ps_field_display( $col, $val ), $user_id );
	}
	if ( $set ) {
		$set['updated_by'] = $user_id;
		$set['updated_at'] = ps_now();
		$wpdb->update( ps_table( 'projects' ), $set, array( 'id' => $id ) );
	}
	return array(
		'isProject'          => ps_project_state_api( $state ),
		'projectState'       => ps_project_state_code( $state ),
		'nonProjectReasonId' => $new['non_project_reason_id'],
		'itemsCount'         => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d', $id ) ), // phpcs:ignore
	);
}

/**
 * 1.10.0 — Mettre plusieurs projets à la corbeille d'un coup (الإدارة, case à cocher de « المشاريع »).
 * Rien n'est effacé : chaque projet reste restaurable depuis « سلة المحذوفات ».
 *
 * @return int Nombre de projets mis à la corbeille.
 */
function ps_projects_bulk_trash( array $ids, int $user_id ): int {
	$n = 0;
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
		$row = ps_project_row( $id );
		if ( $row && ! $row['deleted_at'] ) {
			ps_project_soft_delete( $id, $user_id );
			++$n;
		}
	}
	return $n;
}

/**
 * 1.10.0 — Suppression DÉFINITIVE de projets déjà dans la corbeille (demande du client : « je dois pouvoir supprimer
 * des projets, en tant qu'admin »). Exception voulue à la règle n°1, encadrée comme l'option « مسح الكل » de l'import :
 *   - الإدارة seulement (contrôlé par la route), projets de la corbeille seulement (deleted_at non vide) ;
 *   - sauvegarde JSON complète AVANT (pas de sauvegarde = rien n'est effacé) → restauration possible ;
 *   - effacés : le projet, ses أصناف, intervenants, concurrents, سجل المتابعة, fiches de fichiers, factures (+ alertes),
 *     notifications ; les fichiers sur le disque / Drive sont GARDÉS ; les tâches libres liées restent (lien retiré) ;
 *   - le journal garde une ligne « حذف نهائي » (numéro + nom) pour chaque projet.
 *
 * @return array|WP_Error { deleted: int, backup: string }
 */
function ps_projects_purge( array $ids, int $user_id ) {
	global $wpdb;
	$ids  = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
	$rows = array();
	foreach ( $ids as $id ) {
		$row = ps_project_row( $id );
		if ( $row && $row['deleted_at'] ) {
			$rows[ $id ] = $row;
		}
	}
	if ( ! $rows ) {
		return new WP_Error( 'ps_validation', ps__( 'لا يُحذف نهائيًا إلا مشروع موجود في سلة المحذوفات.' ), array( 'status' => 422 ) );
	}
	$backup = ps_backup_write_auto( 'before-project-delete' );
	if ( ! $backup ) {
		return new WP_Error( 'ps_backup', ps__( 'تعذّر حفظ نسخة احتياطية: لم يُحذف أي شيء.' ), array( 'status' => 500 ) );
	}
	$in       = implode( ',', array_map( 'intval', array_keys( $rows ) ) );
	$invoices = $wpdb->get_col( 'SELECT id FROM ' . ps_table( 'invoices' ) . " WHERE project_id IN ($in)" ); // phpcs:ignore
	$wpdb->query( 'START TRANSACTION' );
	try {
		if ( $invoices ) {
			$wpdb->query( 'DELETE FROM ' . ps_table( 'alerts' ) . ' WHERE invoice_id IN (' . implode( ',', array_map( 'intval', $invoices ) ) . ')' ); // phpcs:ignore
		}
		foreach ( array( 'invoices', 'items', 'project_parties', 'project_competitors', 'followups', 'files', 'notifications' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . ps_table( $t ) . " WHERE project_id IN ($in)" ); // phpcs:ignore
		}
		$wpdb->query( 'UPDATE ' . ps_table( 'tasks' ) . " SET project_id = NULL WHERE project_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . ps_table( 'projects' ) . " WHERE id IN ($in) AND deleted_at IS NOT NULL" ); // phpcs:ignore
		$wpdb->query( 'COMMIT' );
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ps_db', $e->getMessage(), array( 'status' => 500 ) );
	}
	foreach ( $rows as $id => $row ) {
		ps_log( $id, 'purge', 'project', $id, null, $row['project_no'] . ' — ' . $row['name'], 'حذف نهائي', $user_id );
	}
	return array( 'deleted' => count( $rows ), 'backup' => basename( (string) $backup ) );
}
