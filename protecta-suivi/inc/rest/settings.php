<?php
/**
 * API de la page الإعدادات (الإدارة).
 *
 * Listes :   POST /lists/{key} (ajout) · PUT /lists/item/{id} · DELETE /lists/item/{id} · POST /lists/{key}/reorder
 * Réglages : GET /settings · PUT /settings · POST /settings/cron-token (régénérer) · POST /settings/test-email
 * Comptes :  GET /users · POST /users · PUT /users/{id}
 *
 * Capacités : ps_manage_settings (listes, réglages), ps_manage_users (comptes).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_settings_routes' );
function ps_register_settings_routes(): void {
	$keys = implode( '|', array_keys( ps_list_labels() ) );
	register_rest_route( PS_REST_NS, '/lists/(?P<key>' . $keys . ')', array( 'methods' => 'POST', 'permission_callback' => ps_rest_permission( 'ps_manage_settings' ), 'callback' => 'ps_rest_list_add' ) );
	register_rest_route( PS_REST_NS, '/lists/(?P<key>' . $keys . ')/reorder', array( 'methods' => 'POST', 'permission_callback' => ps_rest_permission( 'ps_manage_settings' ), 'callback' => 'ps_rest_list_reorder' ) );
	register_rest_route(
		PS_REST_NS,
		'/lists/item/(?P<id>\d+)',
		array(
			array( 'methods' => 'PUT', 'permission_callback' => ps_rest_permission( 'ps_manage_settings' ), 'callback' => 'ps_rest_list_update' ),
			array( 'methods' => 'DELETE', 'permission_callback' => ps_rest_permission( 'ps_manage_settings' ), 'callback' => 'ps_rest_list_delete' ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/settings',
		array(
			array( 'methods' => 'GET', 'permission_callback' => ps_rest_permission( 'ps_manage_settings' ), 'callback' => 'ps_rest_settings_get' ),
			array( 'methods' => 'PUT', 'permission_callback' => ps_rest_permission( 'ps_manage_settings' ), 'callback' => 'ps_rest_settings_put' ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/settings/cron-token',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission( 'ps_manage_settings' ),
			'callback'            => static function () {
				ps_regenerate_cron_token();
				ps_log( null, 'update', 'settings', null, 'cron_token', null, '(تم تجديد الرمز)' );
				return ps_rest_settings_get();
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/settings/test-email',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission( 'ps_manage_settings' ),
			'callback'            => 'ps_rest_test_email',
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/users',
		array(
			array( 'methods' => 'GET', 'permission_callback' => ps_rest_permission( 'ps_manage_users' ), 'callback' => 'ps_rest_users_list' ),
			array( 'methods' => 'POST', 'permission_callback' => ps_rest_permission( 'ps_manage_users' ), 'callback' => 'ps_rest_user_create' ),
		)
	);
	register_rest_route( PS_REST_NS, '/users/(?P<id>\d+)', array( 'methods' => 'PUT', 'permission_callback' => ps_rest_permission( 'ps_manage_users' ), 'callback' => 'ps_rest_user_update' ) );
}

/* ---------------------------------------------------------------------- */
/* Listes                                                                  */
/* ---------------------------------------------------------------------- */

function ps_rest_list_add( WP_REST_Request $r ) {
	global $wpdb;
	$key = (string) $r['key'];
	$set = ps_list_value_input( $key, (array) $r->get_json_params(), null );
	if ( is_wp_error( $set ) ) {
		return $set;
	}
	$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s', $key ) );
	$id  = ps_insert_list_value( $key, array( 'label' => $set['label'], 'code' => $set['code'] ?? null, 'color' => $set['color'] ?? null, 'type' => $set['type'] ?? null ), $max + 10 );
	ps_log( null, 'create', 'list', $id, $key, null, $set['label'] );
	return rest_ensure_response( ps_list_row( ps_list_value_raw( $id ) ) );
}

function ps_rest_list_update( WP_REST_Request $r ) {
	global $wpdb;
	$row = ps_list_value_raw( (int) $r['id'] );
	if ( ! $row ) {
		return new WP_Error( 'ps_not_found', 'غير موجود', array( 'status' => 404 ) );
	}
	$set = ps_list_value_input( $row['list_key'], (array) $r->get_json_params(), $row );
	if ( is_wp_error( $set ) ) {
		return $set;
	}
	foreach ( $set as $col => $v ) {
		if ( (string) $row[ $col ] !== (string) $v ) {
			ps_log( null, 'update', 'list', (int) $row['id'], $row['list_key'] . '.' . $col, $row[ $col ], $v );
		}
	}
	$set['updated_at'] = ps_now();
	$wpdb->update( ps_table( 'lists' ), $set, array( 'id' => (int) $row['id'] ) );
	return rest_ensure_response( ps_list_row( ps_list_value_raw( (int) $row['id'] ) ) );
}

function ps_rest_list_delete( WP_REST_Request $r ) {
	global $wpdb;
	$row = ps_list_value_raw( (int) $r['id'] );
	if ( ! $row ) {
		return new WP_Error( 'ps_not_found', 'غير موجود', array( 'status' => 404 ) );
	}
	if ( $row['is_system'] ) {
		return new WP_Error( 'ps_system', 'قيمة أساسية في الحسابات: لا يمكن حذفها (يمكن تغيير اسمها)', array( 'status' => 409 ) );
	}
	$used = ps_list_usage_count( (int) $row['id'] );
	if ( $used ) {
		return new WP_Error( 'ps_in_use', sprintf( 'القيمة مستعملة (%d) ولا يمكن حذفها؛ يمكنك إخفاؤها بدل ذلك.', $used ), array( 'status' => 409, 'usage' => $used ) );
	}
	$wpdb->delete( ps_table( 'lists' ), array( 'id' => (int) $row['id'] ) );
	ps_log( null, 'delete', 'list', (int) $row['id'], $row['list_key'], $row['label'], null );
	return rest_ensure_response( array( 'ok' => true ) );
}

function ps_rest_list_reorder( WP_REST_Request $r ) {
	global $wpdb;
	$key = (string) $r['key'];
	$ids = array_map( 'intval', (array) $r->get_param( 'ids' ) );
	$own = array_column( ps_list_values( $key ), 'id' );
	if ( count( $ids ) !== count( $own ) || array_diff( $ids, $own ) ) {
		return new WP_Error( 'ps_validation', 'الترتيب غير صالح', array( 'status' => 422 ) );
	}
	foreach ( $ids as $i => $id ) {
		$wpdb->update( ps_table( 'lists' ), array( 'sort_order' => ( $i + 1 ) * 10 ), array( 'id' => $id ) );
	}
	return rest_ensure_response( ps_list_values( $key ) );
}

/**
 * Bouton « إرسال بريد تجريبي » : envoie le résumé du matin (même vide) à l'adresse choisie
 * (défaut : celle du compte) et renvoie la VRAIE raison d'un échec.
 */
function ps_rest_test_email( WP_REST_Request $r ) {
	$u  = wp_get_current_user();
	$to = trim( (string) ( $r->get_param( 'to' ) ?: $u->user_email ) );
	if ( ! is_email( $to ) ) {
		return new WP_Error( 'ps_mail_to', 'عنوان البريد غير صالح: «' . $to . '». صحّح بريد حسابك (الإعدادات ← المستخدمون) أو اكتب عنوانًا آخر.', array( 'status' => 422 ) );
	}
	if ( ps_digest_send_to_user( $u, true, null, $to ) ) {
		return rest_ensure_response( array( 'ok' => true, 'to' => $to, 'smtp' => (bool) ps_setting( 'smtp_enabled' ) ) );
	}
	$why = ps_mail_last_error() ?: 'سبب غير معروف';
	return new WP_Error( 'ps_mail', 'تعذّر الإرسال: ' . $why . ' — فعّل «الإرسال عبر SMTP» بحساب بريد حقيقي من cPanel.', array( 'status' => 500, 'detail' => $why ) );
}

/* ---------------------------------------------------------------------- */
/* Réglages                                                                */
/* ---------------------------------------------------------------------- */

/** Réglages numériques modifiables : clé => [min, max, libellé]. */
function ps_numeric_settings(): array {
	return array(
		'inactivity_minutes'    => array( 5, 1440, 'الخروج التلقائي بعد عدم النشاط (دقيقة)' ),
		'login_max_attempts'    => array( 3, 50, 'محاولات الدخول الخاطئة قبل الحظر' ),
		'login_window_minutes'  => array( 1, 1440, 'مدة الحظر (دقيقة)' ),
		'followup_alert_days'   => array( 1, 90, 'تنبيه المتابعة بعد (أيام)' ),
		'duplicate_window_days' => array( 1, 365, 'كشف المشاريع المكررة خلال (أيام)' ),
		'creator_edit_hours'    => array( 0, 720, 'مدة تعديل المشروع من طرف منشئه (ساعات)' ),
	);
}

function ps_cron_url(): string {
	return add_query_arg( 'ps_cron', ps_setting( 'cron_token' ), home_url( '/' ) );
}

function ps_rest_settings_get() {
	return rest_ensure_response(
		array(
			'settings'       => array_diff_key( ps_settings(), array( 'cron_token' => 1 ) ),
			'numeric'        => ps_numeric_settings(),
			'fields'         => ps_validation_fields(),
			'cronToken'      => ps_setting( 'cron_token' ),
			'cronUrl'        => ps_cron_url(),
			'cronCli'        => 'php ' . PS_DIR . '/cron.php',
			'digestLastRun'  => get_option( 'ps_digest_last_run' ),
			'mailFrom'       => get_option( 'admin_email' ),
			'smtpPassSet'    => '' !== (string) get_option( 'ps_smtp_pass', '' ),
			'myEmail'        => wp_get_current_user()->user_email,
		)
	);
}

function ps_rest_settings_put( WP_REST_Request $r ) {
	$b      = (array) $r->get_json_params();
	$errors = array();
	$set    = array();
	foreach ( ps_numeric_settings() as $key => list( $min, $max, $label ) ) {
		if ( ! array_key_exists( $key, $b ) ) {
			continue;
		}
		$v = $b[ $key ];
		if ( ! is_numeric( $v ) || (int) $v < $min || (int) $v > $max ) {
			$errors[ $key ] = sprintf( '%s: بين %d و %d', $label, $min, $max );
		} else {
			$set[ $key ] = (int) $v;
		}
	}
	if ( array_key_exists( 'digest_enabled', $b ) ) {
		$set['digest_enabled'] = (bool) $b['digest_enabled'];
	}
	// Expéditeur et SMTP (inc/mail.php).
	if ( array_key_exists( 'mail_from_email', $b ) ) {
		$v = trim( (string) $b['mail_from_email'] );
		if ( '' === $v || is_email( $v ) ) {
			$set['mail_from_email'] = $v;
		} else {
			$errors['mail_from_email'] = 'بريد المرسل غير صالح';
		}
	}
	foreach ( array( 'mail_from_name', 'smtp_host', 'smtp_user' ) as $k ) {
		if ( array_key_exists( $k, $b ) ) {
			$set[ $k ] = sanitize_text_field( (string) $b[ $k ] );
		}
	}
	if ( array_key_exists( 'smtp_enabled', $b ) ) {
		$set['smtp_enabled'] = (bool) $b['smtp_enabled'];
	}
	if ( array_key_exists( 'smtp_port', $b ) ) {
		$p = $b['smtp_port'];
		if ( is_numeric( $p ) && (int) $p >= 1 && (int) $p <= 65535 ) {
			$set['smtp_port'] = (int) $p;
		} else {
			$errors['smtp_port'] = 'المنفذ بين 1 و 65535';
		}
	}
	if ( array_key_exists( 'smtp_secure', $b ) ) {
		if ( in_array( $b['smtp_secure'], array( 'ssl', 'tls', 'none' ), true ) ) {
			$set['smtp_secure'] = $b['smtp_secure'];
		} else {
			$errors['smtp_secure'] = 'نوع التشفير غير صالح';
		}
	}
	if ( ! empty( $set['smtp_enabled'] ) && '' === trim( (string) ( $set['smtp_host'] ?? ps_setting( 'smtp_host' ) ) ) ) {
		$errors['smtp_host'] = 'اكتب خادم SMTP (مثلًا mail.موقعك.com)';
	}
	// Mot de passe : jamais renvoyé ; champ vide = garder l'ancien.
	$new_pass = isset( $b['smtp_pass'] ) ? (string) $b['smtp_pass'] : '';
	if ( isset( $b['validation'] ) && is_array( $b['validation'] ) ) {
		$v   = $b['validation'];
		$cur = ps_validation_rules();
		if ( isset( $v['required'] ) ) {
			$cur['required'] = array_values( array_intersect( (array) $v['required'], array_keys( ps_validation_fields() ) ) );
		}
		foreach ( array( 'sent_after_received', 'email_format', 'phone_format' ) as $k ) {
			if ( array_key_exists( $k, $v ) ) {
				$cur[ $k ] = (bool) $v[ $k ];
			}
		}
		$set['validation'] = $cur;
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', 'يرجى تصحيح القيم', array( 'status' => 422, 'errors' => $errors ) );
	}
	$old = ps_settings();
	ps_update_settings( $set );
	if ( '' !== $new_pass ) {
		update_option( 'ps_smtp_pass', $new_pass, false );
		ps_log( null, 'update', 'settings', null, 'smtp_pass', null, '(تم تغيير كلمة السر)' );
	}
	foreach ( $set as $k => $v ) {
		if ( wp_json_encode( $old[ $k ] ?? null ) !== wp_json_encode( $v ) ) {
			ps_log( null, 'update', 'settings', null, $k, is_array( $old[ $k ] ?? null ) ? wp_json_encode( $old[ $k ], JSON_UNESCAPED_UNICODE ) : (string) ( $old[ $k ] ?? '' ), is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : (string) $v );
		}
	}
	return ps_rest_settings_get();
}

/* ---------------------------------------------------------------------- */
/* Comptes utilisateurs                                                    */
/* ---------------------------------------------------------------------- */

/** Rôles proposés dans la gestion des comptes. */
function ps_assignable_roles(): array {
	return array_map( fn( $d ) => $d['label'], ps_role_definitions() );
}

function ps_user_to_api( WP_User $u ): array {
	$role = '';
	foreach ( array_keys( ps_role_definitions() ) as $r ) {
		if ( in_array( $r, $u->roles, true ) ) {
			$role = $r;
		}
	}
	return array(
		'id'          => (int) $u->ID,
		'login'       => $u->user_login,
		'name'        => $u->display_name,
		'email'       => $u->user_email,
		'role'        => $role ?: ( in_array( 'administrator', $u->roles, true ) ? 'administrator' : '' ),
		'roleLabel'   => ps_user_role_label( $u ) ?: ( in_array( 'administrator', $u->roles, true ) ? 'مدير الموقع' : '—' ),
		'employeeId'  => ps_user_employee_id( (int) $u->ID ),
		'dailyEmail'  => (bool) get_user_meta( $u->ID, 'ps_daily_email', true ),
		'disabled'    => (bool) get_user_meta( $u->ID, 'ps_disabled', true ),
		'lastLogin'   => ( $t = (int) get_user_meta( $u->ID, 'ps_last_activity', true ) ) ? wp_date( 'Y-m-d H:i', $t ) : null,
		'isWpAdmin'   => in_array( 'administrator', $u->roles, true ),
	);
}

function ps_rest_users_list() {
	$users = get_users( array( 'role__in' => array_merge( array_keys( ps_role_definitions() ), array( 'administrator' ) ), 'orderby' => 'display_name' ) );
	return rest_ensure_response( array( 'users' => array_map( 'ps_user_to_api', $users ), 'roles' => ps_assignable_roles() ) );
}

/**
 * Vérifie les champs d'un compte. @return array|WP_Error
 */
function ps_user_input( array $b, ?WP_User $existing ) {
	$errors = array();
	$out    = array();
	if ( ! $existing ) {
		$login = sanitize_user( (string) ( $b['login'] ?? '' ), true );
		if ( '' === $login || strlen( $login ) < 3 ) {
			$errors['login'] = 'اسم المستخدم (3 أحرف لاتينية على الأقل)';
		} elseif ( username_exists( $login ) ) {
			$errors['login'] = 'اسم المستخدم موجود';
		}
		$out['user_login'] = $login;
	}
	if ( array_key_exists( 'name', $b ) || ! $existing ) {
		$name = ps_clean_text( $b['name'] ?? null );
		if ( null === $name ) {
			$errors['name'] = 'اكتب الاسم';
		}
		$out['display_name'] = $name;
	}
	if ( array_key_exists( 'email', $b ) || ! $existing ) {
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			$errors['email'] = 'بريد إلكتروني غير صحيح';
		} else {
			$other = email_exists( $email );
			if ( $other && ( ! $existing || (int) $other !== (int) $existing->ID ) ) {
				$errors['email'] = 'البريد مستعمل في حساب آخر';
			}
		}
		$out['user_email'] = $email;
	}
	if ( ! empty( $b['password'] ) || ! $existing ) {
		$pw = (string) ( $b['password'] ?? '' );
		if ( strlen( $pw ) < 8 ) {
			$errors['password'] = 'كلمة السر: 8 أحرف على الأقل';
		}
		$out['user_pass'] = $pw;
	}
	if ( array_key_exists( 'role', $b ) || ! $existing ) {
		$role = (string) ( $b['role'] ?? '' );
		if ( ! isset( ps_role_definitions()[ $role ] ) ) {
			$errors['role'] = 'اختر الدور';
		}
		$out['role'] = $role;
	}
	if ( array_key_exists( 'employeeId', $b ) ) {
		$emp = ps_clean_id( $b['employeeId'] );
		if ( $emp && 'employee' !== ps_list_key_of( $emp ) ) {
			$errors['employeeId'] = 'موظف غير موجود';
		} elseif ( $emp ) {
			$taken = get_users( array( 'meta_key' => 'ps_employee_id', 'meta_value' => (string) $emp, 'fields' => 'ids' ) );
			$taken = array_diff( array_map( 'intval', $taken ), array( $existing ? (int) $existing->ID : 0 ) );
			if ( $taken ) {
				$errors['employeeId'] = 'هذا الموظف مرتبط بحساب آخر';
			}
		}
		$out['employee'] = $emp;
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', 'يرجى تصحيح الخانات', array( 'status' => 422, 'errors' => $errors ) );
	}
	return $out;
}

function ps_rest_user_create( WP_REST_Request $r ) {
	$b  = (array) $r->get_json_params();
	$in = ps_user_input( $b, null );
	if ( is_wp_error( $in ) ) {
		return $in;
	}
	$id = wp_insert_user( array_intersect_key( $in, array_flip( array( 'user_login', 'display_name', 'user_email', 'user_pass', 'role' ) ) ) );
	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'ps_user', $id->get_error_message(), array( 'status' => 422 ) );
	}
	if ( ! empty( $in['employee'] ) ) {
		update_user_meta( $id, 'ps_employee_id', $in['employee'] );
	}
	update_user_meta( $id, 'ps_daily_email', ! empty( $b['dailyEmail'] ) ? 1 : 0 );
	ps_log( null, 'create', 'user', $id, null, null, $in['user_login'] );
	return rest_ensure_response( ps_user_to_api( get_userdata( $id ) ) );
}

function ps_rest_user_update( WP_REST_Request $r ) {
	$u = get_userdata( (int) $r['id'] );
	if ( ! $u ) {
		return new WP_Error( 'ps_not_found', 'الحساب غير موجود', array( 'status' => 404 ) );
	}
	$b    = (array) $r->get_json_params();
	$self = (int) $u->ID === get_current_user_id();
	if ( in_array( 'administrator', $u->roles, true ) && ( isset( $b['role'] ) || ! empty( $b['disabled'] ) ) ) {
		return new WP_Error( 'ps_forbidden', 'حساب مدير الموقع لا يُعدّل دوره من هنا.', array( 'status' => 403 ) );
	}
	if ( $self && ( ( isset( $b['role'] ) && 'ps_admin' !== $b['role'] ) || ! empty( $b['disabled'] ) ) ) {
		return new WP_Error( 'ps_forbidden', 'لا يمكنك تغيير دورك أو تعطيل حسابك بنفسك.', array( 'status' => 403 ) );
	}
	$in = ps_user_input( $b, $u );
	if ( is_wp_error( $in ) ) {
		return $in;
	}
	$data = array_intersect_key( $in, array_flip( array( 'display_name', 'user_email', 'user_pass' ) ) );
	if ( $data ) {
		$data['ID'] = $u->ID;
		$res        = wp_update_user( $data );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'ps_user', $res->get_error_message(), array( 'status' => 422 ) );
		}
	}
	if ( isset( $in['role'] ) ) {
		$u->set_role( $in['role'] );
	}
	if ( array_key_exists( 'employee', $in ) ) {
		$in['employee'] ? update_user_meta( $u->ID, 'ps_employee_id', $in['employee'] ) : delete_user_meta( $u->ID, 'ps_employee_id' );
	}
	if ( array_key_exists( 'dailyEmail', $b ) ) {
		update_user_meta( $u->ID, 'ps_daily_email', ! empty( $b['dailyEmail'] ) ? 1 : 0 );
	}
	if ( array_key_exists( 'disabled', $b ) ) {
		if ( ! empty( $b['disabled'] ) ) {
			update_user_meta( $u->ID, 'ps_disabled', 1 );
			WP_Session_Tokens::get_instance( $u->ID )->destroy_all(); // déconnexion immédiate
		} else {
			delete_user_meta( $u->ID, 'ps_disabled' );
		}
	}
	ps_log( null, 'update', 'user', (int) $u->ID, null, null, $u->user_login );
	return rest_ensure_response( ps_user_to_api( get_userdata( $u->ID ) ) );
}
