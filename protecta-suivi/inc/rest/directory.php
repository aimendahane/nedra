<?php
/**
 * API des annuaires (sociétés et منافسون).
 *
 *   GET  /companies?q=…&page=…      liste (avec nombre de projets) ou recherche
 *   GET  /companies/search?q=…      autocomplétion (10 résultats)
 *   POST /companies                 ajouter               — ps_add_directory
 *   PUT  /companies/{id}            modifier              — ps_manage_directory (الإدارة)
 *   (mêmes routes pour /competitors)
 *
 * La fusion des doublons (الإدارة) est ajoutée à l'étape 5.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_directory_routes' );
function ps_register_directory_routes(): void {
	foreach ( array( 'companies', 'competitors' ) as $kind ) {
		register_rest_route(
			PS_REST_NS,
			'/' . $kind,
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => ps_rest_permission(),
					'callback'            => static function ( WP_REST_Request $r ) use ( $kind ) {
						$res = ps_dir_list( $kind, (string) $r->get_param( 'q' ), max( 1, (int) $r->get_param( 'page' ) ), 50 );
						return rest_ensure_response(
							array(
								'total' => $res['total'],
								'rows'  => array_map( fn( $row ) => ps_dir_to_api( $kind, $row ), $res['rows'] ),
							)
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => ps_rest_permission( 'ps_add_directory' ),
					'callback'            => static function ( WP_REST_Request $r ) use ( $kind ) {
						$data = ps_rest_directory_input( $kind, (array) $r->get_json_params() );
						if ( null === $data['name'] ) {
							return new WP_Error( 'ps_validation', 'اكتب الاسم', array( 'status' => 422, 'errors' => array( 'name' => 'خانة إجبارية' ) ) );
						}
						$existing = ps_dir_find_by_name( $kind, $data['name'] );
						if ( $existing ) {
							return new WP_Error( 'ps_exists', 'الاسم موجود في الدليل', array( 'status' => 409, 'existing' => ps_dir_to_api( $kind, $existing ) ) );
						}
						$id = ps_dir_create( $kind, $data );
						return rest_ensure_response( ps_dir_to_api( $kind, ps_dir_get( $kind, $id ) ) );
					},
				),
			)
		);
		register_rest_route(
			PS_REST_NS,
			'/' . $kind . '/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => static function ( WP_REST_Request $r ) use ( $kind ) {
					return rest_ensure_response( array_map( fn( $row ) => ps_dir_to_api( $kind, $row ), ps_dir_search( $kind, (string) $r->get_param( 'q' ) ) ) );
				},
			)
		);
		register_rest_route(
			PS_REST_NS,
			'/' . $kind . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => ps_rest_permission(),
					'callback'            => static function ( WP_REST_Request $r ) use ( $kind ) {
						$row = ps_dir_get( $kind, ps_dir_resolve( $kind, (int) $r['id'] ) );
						return $row ? rest_ensure_response( ps_dir_to_api( $kind, $row ) ) : new WP_Error( 'ps_not_found', 'غير موجود', array( 'status' => 404 ) );
					},
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => ps_rest_permission( 'ps_manage_directory' ),
					'callback'            => static function ( WP_REST_Request $r ) use ( $kind ) {
						$row = ps_dir_get( $kind, (int) $r['id'] );
						if ( ! $row || $row['merged_into_id'] ) {
							return new WP_Error( 'ps_not_found', 'غير موجود', array( 'status' => 404 ) );
						}
						$data = ps_rest_directory_input( $kind, (array) $r->get_json_params() );
						if ( null === $data['name'] ) {
							unset( $data['name'] );
						}
						if ( ! ps_dir_update( $kind, (int) $row['id'], $data ) ) {
							return new WP_Error( 'ps_exists', 'الاسم موجود في الدليل (استعمل الدمج)', array( 'status' => 409 ) );
						}
						return rest_ensure_response( ps_dir_to_api( $kind, ps_dir_get( $kind, (int) $row['id'] ) ) );
					},
				),
			)
		);
	}
}

function ps_rest_directory_input( string $kind, array $b ): array {
	$data = array(
		'name'  => ps_clean_text( $b['name'] ?? null ),
		'notes' => ps_clean_text( $b['notes'] ?? null, true ),
	);
	if ( 'companies' === $kind ) {
		foreach ( array( 'person', 'email', 'phone', 'address', 'position', 'website' ) as $f ) {
			if ( array_key_exists( $f, $b ) ) {
				$data[ $f ] = ps_clean_text( $b[ $f ] );
			}
		}
		if ( array_key_exists( 'rank', $b ) ) {
			$data['rank_title'] = ps_clean_text( $b['rank'] );
		}
	}
	return $data;
}
