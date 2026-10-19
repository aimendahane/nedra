<?php
/**
 * API données (الإدارة, capacité ps_export) : export, sauvegarde / restauration, import du prototype.
 *
 *   GET  /export/projects?format=csv|xlsx&<filtres Base Projets>
 *   GET  /export/items?view=offers|delivery&format=csv|xlsx&<filtres>
 *   GET  /backup/download              sauvegarde JSON complète (téléchargement)
 *   GET  /backup/list                  sauvegardes automatiques du serveur
 *   GET  /backup/file?name=…           télécharger une sauvegarde du serveur
 *   POST /backup/restore               fichier « file » + confirm=استرجاع
 *   POST /import/prototype             fichier « file » + dryRun (1|0), urgentAs (U|VU), currencyId
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_data_routes' );
function ps_register_data_routes(): void {
	$perm = ps_rest_permission( 'ps_export' );
	register_rest_route( PS_REST_NS, '/export/projects', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => 'ps_rest_export_projects' ) );
	register_rest_route( PS_REST_NS, '/export/items', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => 'ps_rest_export_items' ) );
	register_rest_route(
		PS_REST_NS,
		'/backup/download',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => static function () {
				ps_log( null, 'create', 'backup', null, null, null, 'download' );
				ps_send_download( wp_json_encode( ps_backup_build( 'manual' ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), 'protecta-suivi-backup-' . current_time( 'Y-m-d-His' ) . '.json', 'application/json; charset=utf-8' );
			},
		)
	);
	register_rest_route( PS_REST_NS, '/backup/list', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( ps_backup_list() ) ) );
	register_rest_route(
		PS_REST_NS,
		'/backup/file',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $r ) {
				$name = basename( (string) $r->get_param( 'name' ) );
				$path = ps_storage_dir( 'backups' ) . '/' . $name;
				if ( ! preg_match( '/^[A-Za-z0-9._-]+\.json$/', $name ) || ! is_file( $path ) ) {
					return new WP_Error( 'ps_not_found', 'الملف غير موجود', array( 'status' => 404 ) );
				}
				ps_send_download( (string) file_get_contents( $path ), $name, 'application/json; charset=utf-8' );
			},
		)
	);
	register_rest_route( PS_REST_NS, '/backup/restore', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'ps_rest_backup_restore' ) );
	register_rest_route( PS_REST_NS, '/import/prototype', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'ps_rest_import_prototype' ) );
}

/** Contenu du fichier envoyé (champ « file ») ou du corps « json ». */
function ps_rest_uploaded_json( WP_REST_Request $r ) {
	$files = $r->get_file_params();
	if ( ! empty( $files['file']['tmp_name'] ) && empty( $files['file']['error'] ) ) {
		if ( (int) $files['file']['size'] > 50 * MB_IN_BYTES ) {
			return new WP_Error( 'ps_upload_size', 'الملف كبير جدًا', array( 'status' => 400 ) );
		}
		return (string) file_get_contents( $files['file']['tmp_name'] );
	}
	$json = $r->get_param( 'json' );
	return is_string( $json ) && '' !== $json ? $json : new WP_Error( 'ps_upload', 'اختر ملف JSON', array( 'status' => 400 ) );
}

function ps_rest_export_projects( WP_REST_Request $r ) {
	list( $h, $rows ) = ps_export_projects_table( $r->get_params() );
	ps_log( null, 'create', 'export', null, 'projects', null, (string) count( $rows ) );
	ps_rest_send_table( $h, $rows, 'projects', (string) $r->get_param( 'format' ) );
}

function ps_rest_export_items( WP_REST_Request $r ) {
	list( $h, $rows ) = ps_export_items_table( $r->get_params(), get_current_user_id() );
	ps_rest_send_table( $h, $rows, 'delivery' === $r->get_param( 'view' ) ? 'delivery' : 'offers', (string) $r->get_param( 'format' ) );
}

function ps_rest_send_table( array $headers, array $rows, string $name, string $format ): void {
	$file = 'protecta-' . $name . '-' . current_time( 'Y-m-d' );
	if ( 'xlsx' === $format ) {
		$bin = ps_xlsx( $headers, $rows, $name );
		if ( null !== $bin ) {
			ps_send_download( $bin, $file . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		}
	}
	ps_send_download( ps_csv( $headers, $rows ), $file . '.csv', 'text/csv; charset=utf-8' );
}

function ps_rest_backup_restore( WP_REST_Request $r ) {
	if ( 'استرجاع' !== trim( (string) $r->get_param( 'confirm' ) ) ) {
		return new WP_Error( 'ps_confirm', 'اكتب «استرجاع» للتأكيد', array( 'status' => 422 ) );
	}
	$json = ps_rest_uploaded_json( $r );
	if ( is_wp_error( $json ) ) {
		return $json;
	}
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'ps_restore_format', 'الملف ليس JSON صالحًا', array( 'status' => 422 ) );
	}
	$res = ps_backup_restore( $data, get_current_user_id() );
	return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
}

function ps_rest_import_prototype( WP_REST_Request $r ) {
	$json = ps_rest_uploaded_json( $r );
	if ( is_wp_error( $json ) ) {
		return $json;
	}
	$projects = ps_import_parse( $json );
	if ( is_wp_error( $projects ) ) {
		return $projects;
	}
	$dry = '0' !== (string) $r->get_param( 'dryRun' );
	return rest_ensure_response(
		ps_import_prototype(
			$projects,
			array( 'urgentAs' => (string) $r->get_param( 'urgentAs' ), 'currencyId' => $r->get_param( 'currencyId' ) ),
			$dry,
			get_current_user_id()
		)
	);
}
