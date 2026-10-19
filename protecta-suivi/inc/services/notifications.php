<?php
/**
 * Notifications 🔔 (version 1.0.2).
 *
 * Principe : chaque modification d'un projet passe déjà par le journal (ps_log). Ce fichier
 * « écoute » le journal pendant la requête, regroupe les changements PAR PROJET, puis, à la fin
 * de la requête (ps_notify_flush, crochet shutdown), crée UNE notification par personne et par
 * projet (une sauvegarde de la fiche = une notification, pas dix).
 *
 * Qui est prévenu (comptes liés aux employés de la liste, actifs) :
 *   - nouveau projet                        → المسؤول + المتابع + employés des أصناف ;
 *   - صنف ajouté / confié à un autre employé → cet employé (+ المسؤول + المتابع) ;
 *   - tout autre changement du projet (حالة العرض, حالة المتابعة, dates, montants, أطراف,
 *     سجل المتابعة, fichiers…)                → المسؤول + المتابع ;
 *   - JAMAIS celui qui a fait la modification.
 * Non notifiés : import du prototype, restauration, tâches libres (elles ont leur propre e-mail).
 *
 * Canaux : la cloche dans l'application (table ps_notifications, jamais supprimée) et, si
 * l'utilisateur l'a demandé (préférence « ps_instant_email »), un e-mail immédiat.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tampon de la requête : project_id => [ 'actors' => [], 'lines' => [], 'kinds' => [], 'items' => [] ].
 */
function &ps_notify_buffer(): array {
	static $buffer = array();
	return $buffer;
}

/** Interrupteur (tests, import, restauration) : false = ne rien collecter. */
function ps_notify_enabled( ?bool $set = null ): bool {
	static $on = true;
	if ( null !== $set ) {
		$on = $set;
	}
	return $on;
}

/** Libellés des champs d'un صنف pour les messages. */
function ps_notify_item_fields(): array {
	return array(
		'offer_status_id'      => 'حالة العرض',
		'offer_date'           => 'تاريخ تقديم العرض',
		'not_sent_reason_id'   => 'سبب عدم الإرسال',
		'delivery_status_id'   => 'حالة التسليم',
		'delivery_due_date'    => 'أجل التسليم',
		'delivery_actual_date' => 'تاريخ التسليم الفعلي',
		'employee_id'          => 'الموظف المسؤول',
		'due_date'             => 'الأجل المطلوب',
		'quantity'             => 'الكمية',
		'item_type_id'         => 'الصنف',
		'offer_invoice_no'     => 'فاتورة العرض',
		'supplier_invoice_no'  => 'فاتورة المورد',
		'availability_id'      => 'المتوفر',
	);
}

/**
 * Ajoute un changement au tampon.
 *
 * @param string   $kind     created | deleted | status | followup | file | item | update
 * @param string   $line     Texte lisible (« حالة العرض (UPS): قيد الإعداد ← مرسل »).
 * @param int|null $item_id  صنف concerné : son employé sera prévenu aussi.
 */
function ps_notify_collect( int $project_id, string $kind, string $line, ?int $actor_id = null, ?int $item_id = null ): void {
	if ( ! ps_notify_enabled() || $project_id <= 0 ) {
		return;
	}
	$buf = &ps_notify_buffer();
	if ( ! isset( $buf[ $project_id ] ) ) {
		$buf[ $project_id ] = array( 'actors' => array(), 'lines' => array(), 'kinds' => array(), 'items' => array() );
	}
	$b = &$buf[ $project_id ];
	$b['actors'][ (int) ( $actor_id ?? get_current_user_id() ) ] = true;
	$b['kinds'][ $kind ] = true;
	if ( '' !== $line && ! in_array( $line, $b['lines'], true ) ) {
		$b['lines'][] = $line;
	}
	if ( $item_id ) {
		$b['items'][ $item_id ] = true;
	}
}

/**
 * Traduit une ligne du journal (ps_log) en changement à notifier.
 */
function ps_notify_from_log( ?int $project_id, string $action, string $entity, ?int $entity_id, ?string $field, $old, $new, ?int $user_id ): void {
	if ( ! $project_id || ! ps_notify_enabled() || in_array( $action, array( 'import', 'merge' ), true ) || 'task' === $entity ) {
		return;
	}
	$arrow  = static fn( $o, $n ) => ( null === $o || '' === $o ? '—' : $o ) . ' ← ' . ( null === $n || '' === $n ? '—' : $n );
	$labels = ps_validation_fields();
	switch ( $entity ) {
		case 'project':
			if ( 'create' === $action ) {
				ps_notify_collect( $project_id, 'created', 'مشروع جديد', $user_id );
			} elseif ( 'delete' === $action ) {
				ps_notify_collect( $project_id, 'deleted', 'حُذف المشروع (في سلة المحذوفات)', $user_id );
			} elseif ( 'restore' === $action ) {
				ps_notify_collect( $project_id, 'update', 'استُرجع المشروع', $user_id );
			} elseif ( $field ) {
				$kind = 'followup_status_id' === $field ? 'status' : 'update';
				ps_notify_collect( $project_id, $kind, ( $labels[ $field ] ?? $field ) . ': ' . $arrow( $old, $new ), $user_id );
			}
			break;
		case 'item':
			$type = $entity_id ? ps_list_label( (int) ps_notify_item_type( (int) $entity_id ) ) : '';
			if ( 'create' === $action ) {
				ps_notify_collect( $project_id, 'item', 'صنف جديد: ' . (string) $new, $user_id, $entity_id );
			} elseif ( 'delete' === $action ) {
				ps_notify_collect( $project_id, 'item', 'حُذف صنف: ' . (string) $old, $user_id );
			} elseif ( $field ) {
				$kind = in_array( $field, array( 'offer_status_id', 'delivery_status_id' ), true ) ? 'status' : 'item';
				// Nouvel employé du صنف → il est prévenu.
				ps_notify_collect( $project_id, $kind, ( ps_notify_item_fields()[ $field ] ?? $field ) . ( $type ? ' (' . $type . ')' : '' ) . ': ' . $arrow( $old, $new ), $user_id, 'employee_id' === $field ? $entity_id : null );
			}
			break;
		case 'file':
			ps_notify_collect( $project_id, 'file', ( 'delete' === $action ? 'حُذف ملف: ' . $old : 'ملف جديد: ' . $new ), $user_id );
			break;
		case 'followup':
			ps_notify_collect( $project_id, 'followup', 'تعديل في سجل المتابعة', $user_id );
			break;
		case 'party':
			ps_notify_collect( $project_id, 'update', 'تعديل الأطراف المتدخلة', $user_id );
			break;
		case 'competitor':
			ps_notify_collect( $project_id, 'update', 'تعديل المنافسين', $user_id );
			break;
	}
}

/** Type (id de liste) d'un صنف, pour l'afficher dans le message. */
function ps_notify_item_type( int $item_id ): ?int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT item_type_id FROM ' . ps_table( 'items' ) . ' WHERE id = %d', $item_id ) ) ?: null;
}

/**
 * Crée les notifications du tampon (une par personne et par projet) et envoie les e-mails
 * immédiats demandés. Appelée automatiquement à la fin de chaque requête.
 *
 * @return int Nombre de notifications créées.
 */
function ps_notify_flush(): int {
	global $wpdb;
	$buf = &ps_notify_buffer();
	if ( ! $buf ) {
		return 0;
	}
	$pending = $buf;
	$buf     = array();
	$created = 0;
	$now     = ps_now();
	$order   = array( 'created', 'deleted', 'status', 'followup', 'file', 'item', 'update' );
	foreach ( $pending as $pid => $b ) {
		$p = ps_project_row( (int) $pid );
		if ( ! $p ) {
			continue;
		}
		// Employés concernés.
		$emps = array( (int) $p['responsible_emp_id'], (int) $p['follower_emp_id'] );
		$item_ids = array_keys( $b['items'] );
		if ( isset( $b['kinds']['created'] ) ) {
			$item_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d', $pid ) ) );
		}
		if ( $item_ids ) {
			$emps = array_merge( $emps, array_map( 'intval', $wpdb->get_col( 'SELECT employee_id FROM ' . ps_table( 'items' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $item_ids ) ) . ')' ) ) ); // phpcs:ignore
		}
		$users = array();
		foreach ( array_unique( array_filter( $emps ) ) as $emp ) {
			foreach ( ps_users_for_employee( $emp ) as $u ) {
				if ( empty( $b['actors'][ $u->ID ] ) ) {
					$users[ $u->ID ] = $u;
				}
			}
		}
		if ( ! $users ) {
			continue;
		}
		$kind  = current( array_intersect( $order, array_keys( $b['kinds'] ) ) ) ?: 'update';
		$actor = (int) array_key_first( $b['actors'] );
		$lines = isset( $b['kinds']['created'] ) ? array( 'مشروع جديد' ) : $b['lines'];
		$title = $p['project_no'] . ' — ' . $p['name'];
		$body  = implode( "\n", array_slice( $lines, 0, 6 ) ) . ( count( $lines ) > 6 ? "\n" . sprintf( 'و %d تغييرات أخرى', count( $lines ) - 6 ) : '' );
		foreach ( $users as $u ) {
			$wpdb->insert(
				ps_table( 'notifications' ),
				array( 'user_id' => $u->ID, 'project_id' => (int) $pid, 'actor_id' => $actor ?: null, 'kind' => $kind, 'title' => mb_substr( $title, 0, 255 ), 'body' => $body, 'created_at' => $now )
			);
			++$created;
			if ( get_user_meta( $u->ID, 'ps_instant_email', true ) ) {
				ps_notify_email( $u, (int) $pid, $title, $lines, $actor );
			}
		}
	}
	return $created;
}
add_action( 'shutdown', 'ps_notify_flush' );

/**
 * E-mail immédiat (préférence de l'utilisateur). Un échec n'empêche rien.
 */
function ps_notify_email( WP_User $u, int $project_id, string $title, array $lines, int $actor_id ): void {
	if ( ! is_email( $u->user_email ) ) {
		return;
	}
	$who  = $actor_id ? get_userdata( $actor_id ) : null;
	$link = esc_url( home_url( '/#/projet?id=' . $project_id ) );
	$h    = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;color:#1F2733;max-width:640px;margin:auto;border-top:4px solid #E8571F;padding:16px">';
	$h   .= '<h2 style="color:#385B9A;margin:0 0 8px">' . esc_html( $title ) . '</h2>';
	$h   .= $who ? '<p style="margin:0 0 8px;color:#5D6878">' . esc_html( $who->display_name ) . '</p>' : '';
	$h   .= '<ul style="padding-right:18px">' . implode( '', array_map( fn( $l ) => '<li>' . esc_html( $l ) . '</li>', array_slice( $lines, 0, 20 ) ) ) . '</ul>';
	$h   .= '<p style="margin-top:20px"><a href="' . $link . '" style="background:#385B9A;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">فتح المشروع</a></p>';
	$h   .= '<p style="color:#868e96;font-size:12px">لإيقاف هذا البريد: 🔔 ← «بريد فوري عند كل تغيير».</p></div>';
	ps_mail_send( $u->user_email, '🔔 ' . $title, $h );
}

/**
 * Notifications d'un utilisateur (les plus récentes d'abord).
 */
function ps_notifications_for( int $user_id, int $limit = 30 ): array {
	global $wpdb;
	$rows = $limit > 0 ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'notifications' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', $user_id, $limit ), ARRAY_A ) : array();
	return array_map(
		static function ( $r ) {
			$a = $r['actor_id'] ? get_userdata( (int) $r['actor_id'] ) : null;
			return array(
				'id'        => (int) $r['id'],
				'projectId' => $r['project_id'] ? (int) $r['project_id'] : null,
				'kind'      => $r['kind'],
				'title'     => $r['title'],
				'body'      => $r['body'],
				'actorName' => $a ? $a->display_name : null,
				'createdAt' => $r['created_at'],
				'read'      => null !== $r['read_at'],
			);
		},
		$rows ?: array()
	);
}

function ps_notifications_unread( int $user_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'notifications' ) . ' WHERE user_id = %d AND read_at IS NULL', $user_id ) );
}

/**
 * Marque comme lues (seulement les notifications de cet utilisateur).
 *
 * @param int[]|null $ids null = toutes.
 */
function ps_notifications_mark_read( int $user_id, ?array $ids = null ): int {
	global $wpdb;
	$t   = ps_table( 'notifications' );
	$now = ps_now();
	if ( null === $ids ) {
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE user_id = %d AND read_at IS NULL", $now, $user_id ) ); // phpcs:ignore
	}
	$ids = array_filter( array_map( 'intval', $ids ) );
	if ( ! $ids ) {
		return 0;
	}
	return (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND id IN (" . implode( ',', $ids ) . ')', $now, $user_id ) ); // phpcs:ignore
}
