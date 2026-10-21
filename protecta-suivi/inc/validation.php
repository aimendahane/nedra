<?php
/**
 * Règles de validation d'un projet (cahier §11 + brief §5), appliquées CÔTÉ SERVEUR.
 *
 * Les règles sont lues dans les réglages (ps_settings['validation']) et seront
 * modifiables depuis الإعدادات (étape 5) :
 *   - required            : liste des champs obligatoires (clés de ps_validation_fields()) ;
 *   - sent_after_received : تاريخ الإرسال ≥ تاريخ الاستلام ;
 *   - email_format        : e-mails au bon format ;
 *   - phone_format        : téléphones en chiffres (+, espaces, tirets tolérés).
 *
 * Règles toujours appliquées (non désactivables, elles protègent la cohérence des données) :
 *   - رقم العرض unique ; dates réelles ; montants positifs ;
 *   - chaque valeur de liste doit exister dans SA liste, et être visible (une valeur masquée
 *     n'est acceptée que si le projet l'avait déjà) ;
 *   - تاريخ إرسال العرض obligatoire dès qu'un صنف est مرسل / مقبول / مرفوض (décision n°5).
 *
 * Résultat : tableau « clé du champ » => « message en arabe » (vide = tout est correct).
 * Clés : « name », « parties.owner.phone », « items.2.due_date »… (l'interface les affiche
 * sous le bon champ).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Champs du projet → liste déroulante correspondante.
 */
function ps_project_list_fields(): array {
	return array(
		'responsible_emp_id'   => 'employee',
		'follower_emp_id'      => 'employee',
		'intermediary_emp_id'  => 'employee',
		'place_id'             => 'place',
		'category_id'          => 'category',
		'urgency_id'           => 'urgency',
		'importance_kind_id'   => 'importance',
		'importance_size_id'   => 'importance',
		'followup_status_id'   => 'followup_status',
		'source_id'            => 'source',
		'loss_reason_id'       => 'loss_reason',
		'offer_currency_id'    => 'currency',
		'purchase_currency_id' => 'currency',
	);
}

/**
 * Libellés des champs (messages d'erreur, journal des modifications, réglages).
 */
function ps_validation_fields(): array {
	return array(
		'offer_no'             => ps__( 'رقم العرض' ),
		'name'                 => ps__( 'اسم المشروع' ),
		'received_date'        => ps__( 'تاريخ استلام الطلب' ),
		'sent_date'            => ps__( 'تاريخ إرسال العرض' ),
		'responsible_emp_id'   => ps__( 'المسؤول عن المشروع' ),
		'follower_emp_id'      => ps__( 'الموظف المتابع' ),
		'intermediary_emp_id'  => ps__( 'الموظف الوسيط' ),
		'place_id'             => ps__( 'المكان' ),
		'category_id'          => ps__( 'التصنيف' ),
		'urgency_id'           => ps__( 'درجة الاستعجال' ),
		'importance_kind_id'   => ps__( 'الأهمية كصنف' ),
		'importance_size_id'   => ps__( 'الأهمية كحجم' ),
		'followup_status_id'   => ps__( 'حالة المتابعة' ),
		'source_id'            => ps__( 'مصدر الطلب' ),
		'loss_reason_id'       => ps__( 'سبب الرفض أو الخسارة' ),
		'offer_amount'         => ps__( 'مبلغ العرض' ),
		'offer_currency_id'    => ps__( 'عملة العرض' ),
		'purchase_amount'      => ps__( 'مبلغ الشراء' ),
		'purchase_currency_id' => ps__( 'عملة الشراء' ),
		'files_link'           => ps__( 'رابط ملف المشاريع' ),
		'note'                 => ps__( 'NOTE / نص الطلب' ),
		'owner.company'        => ps__( 'المالك: اسم الشركة' ),
		'owner.person'         => ps__( 'المالك: الشخص المسؤول' ),
		'owner.phone'          => ps__( 'المالك: الهاتف' ),
		'owner.email'          => ps__( 'المالك: الإيميل' ),
		'executor.company'     => ps__( 'الشركة المنفذة: اسم الشركة' ),
		'executor.person'      => ps__( 'الشركة المنفذة: الشخص المسؤول' ),
		'executor.phone'       => ps__( 'الشركة المنفذة: الهاتف' ),
		'direct.person'        => ps__( 'الشخص المباشر: الاسم' ),
		'direct.phone'         => ps__( 'الشخص المباشر: الهاتف' ),
		'broker.company'       => ps__( 'الوسيط: اسم الشركة' ),
		'items.min_one'        => ps__( 'صنف واحد على الأقل' ),
		'item.item_type_id'    => ps__( 'الصنف' ),
		'item.quantity'        => ps__( 'الكمية' ),
		'item.employee_id'     => ps__( 'الموظف المسؤول عن الصنف' ),
		'item.due_date'        => ps__( 'الأجل المطلوب' ),
	);
}

/**
 * Libellés arabes des rôles des أطراف.
 */
function ps_party_roles(): array {
	return array(
		'owner'    => ps__( 'المالك' ),
		'executor' => ps__( 'الشركة المنفذة' ),
		'direct'   => ps__( 'الشخص المباشر' ),
		'broker'   => ps__( 'الوسيط' ),
	);
}

function ps_validation_rules(): array {
	return (array) ps_setting( 'validation' );
}

function ps_is_valid_date( $d ): bool {
	return is_string( $d ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/**
 * Téléphone valide : chiffres seulement, « + » au début toléré ; espaces, tirets, points et
 * parenthèses ignorés ; 6 à 15 chiffres.
 */
function ps_is_valid_phone( string $phone ): bool {
	$clean = preg_replace( '/[\s\-\.\(\)]/', '', $phone );
	return (bool) preg_match( '/^\+?\d{6,15}$/', $clean );
}

/**
 * Valide un projet.
 *
 * @param array      $in       Données normalisées (voir ps_project_input() dans inc/repo/projects.php).
 * @param array|null $existing Projet existant (modification) : ['fields' => ligne brute, 'items' => [...]]
 * @return array Erreurs « clé » => « message ».
 */
function ps_validate_project( array $in, ?array $existing = null ): array {
	global $wpdb;
	$rules    = ps_validation_rules();
	$required = array_flip( (array) ( $rules['required'] ?? array() ) );
	$labels   = ps_validation_fields();
	$f        = $in['fields'];
	$old      = $existing['fields'] ?? array();
	$errors   = array();
	$need     = fn( $key ) => isset( $required[ $key ] );
	$empty    = fn( $v ) => null === $v || '' === $v || ( is_string( $v ) && '' === trim( $v ) );
	$msg_req  = fn( $key ) => ps__( 'خانة إجبارية: ' ) . ( $labels[ $key ] ?? $key );

	// 1. Champs obligatoires du projet.
	foreach ( array_keys( $labels ) as $key ) {
		if ( ! str_contains( $key, '.' ) && $need( $key ) && $empty( $f[ $key ] ?? null ) ) {
			$errors[ $key ] = $msg_req( $key );
		}
	}

	// 2. Valeurs de listes : existent dans la bonne liste ; visibles sauf si déjà enregistrées.
	$lists_t = ps_table( 'lists' );
	foreach ( ps_project_list_fields() as $key => $list_key ) {
		$id = $f[ $key ] ?? null;
		if ( $empty( $id ) || isset( $errors[ $key ] ) ) {
			continue;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT list_key, is_hidden FROM $lists_t WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row || $row['list_key'] !== $list_key ) {
			$errors[ $key ] = ps__( 'قيمة غير موجودة في القائمة: ' ) . $labels[ $key ];
		} elseif ( $row['is_hidden'] && (int) ( $old[ $key ] ?? 0 ) !== (int) $id ) {
			$errors[ $key ] = ps__( 'هذه القيمة لم تعد متاحة: ' ) . $labels[ $key ];
		}
	}

	// 3. Dates.
	foreach ( array( 'received_date', 'sent_date' ) as $key ) {
		if ( ! $empty( $f[ $key ] ?? null ) && ! ps_is_valid_date( $f[ $key ] ) ) {
			$errors[ $key ] = ps__( 'تاريخ غير صحيح: ' ) . $labels[ $key ];
		}
	}
	if ( ! empty( $rules['sent_after_received'] ) && ! isset( $errors['sent_date'] ) && ! isset( $errors['received_date'] )
		&& ! $empty( $f['sent_date'] ?? null ) && ! $empty( $f['received_date'] ?? null ) && $f['sent_date'] < $f['received_date'] ) {
		$errors['sent_date'] = ps__( 'تاريخ الإرسال لازم يكون بعد تاريخ الاستلام أو نفس اليوم' );
	}

	// تاريخ إرسال العرض obligatoire dès qu'un صنف est envoyé (مرسل / مقبول / مرفوض).
	$sent_codes = array( 'sent', 'accepted', 'rejected' );
	$has_sent   = false;
	foreach ( (array) ( $existing['items'] ?? array() ) as $it ) {
		if ( in_array( $it['offer_status_code'] ?? '', $sent_codes, true ) ) {
			$has_sent = true;
		}
	}
	if ( $has_sent && $empty( $f['sent_date'] ?? null ) && ! isset( $errors['sent_date'] ) ) {
		$errors['sent_date'] = ps__( 'تاريخ إرسال العرض إجباري لأن عرضًا واحدًا على الأقل تم إرساله' );
	}

	// 4. رقم العرض unique.
	if ( ! $empty( $f['offer_no'] ?? null ) ) {
		$dup = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT project_no FROM ' . ps_table( 'projects' ) . ' WHERE offer_no = %s AND id <> %d',
				trim( $f['offer_no'] ),
				(int) ( $old['id'] ?? 0 )
			)
		);
		if ( $dup ) {
			$errors['offer_no'] = ps__( 'رقم العرض مستعمل في المشروع ' ) . $dup;
		}
	}

	// 5. Montants.
	foreach ( array( 'offer_amount' => 'offer_currency_id', 'purchase_amount' => 'purchase_currency_id' ) as $key => $cur ) {
		$v = $f[ $key ] ?? null;
		if ( $empty( $v ) ) {
			continue;
		}
		if ( ! is_numeric( $v ) || (float) $v < 0 ) {
			$errors[ $key ] = ps__( 'مبلغ غير صحيح: ' ) . $labels[ $key ];
		} elseif ( $empty( $f[ $cur ] ?? null ) ) {
			$errors[ $cur ] = ps__( 'اختر العملة' );
		}
	}

	// 6. Lien.
	if ( ! $empty( $f['files_link'] ?? null ) && ! wp_http_validate_url( $f['files_link'] ) && ! preg_match( '#^https?://#i', $f['files_link'] ) ) {
		$errors['files_link'] = ps__( 'رابط غير صحيح (يبدأ بـ https://)' );
	}

	// 7. Intervenants.
	foreach ( ps_party_roles() as $role => $role_label ) {
		$p = $in['parties'][ $role ] ?? array();
		foreach ( array( 'company' => 'company_name', 'person' => 'person', 'phone' => 'phone', 'email' => 'email' ) as $short => $col ) {
			$key = "$role.$short";
			if ( $need( $key ) && $empty( $p[ $col ] ?? null ) && ! ( 'company' === $short && ! empty( $p['company_id'] ) ) ) {
				$errors[ "parties.$key" ] = $msg_req( $key );
			}
		}
		if ( ! empty( $rules['email_format'] ) && ! $empty( $p['email'] ?? null ) && ! is_email( $p['email'] ) ) {
			$errors[ "parties.$role.email" ] = ps__( 'الإيميل غير صحيح (' ) . $role_label . ')';
		}
		if ( ! empty( $rules['phone_format'] ) && ! $empty( $p['phone'] ?? null ) && ! ps_is_valid_phone( $p['phone'] ) ) {
			$errors[ "parties.$role.phone" ] = ps__( 'الهاتف أرقام فقط (' ) . $role_label . ')';
		}
	}

	// 8. أصناف.
	$items = (array) ( $in['items'] ?? array() );
	if ( $need( 'items.min_one' ) && ! $items ) {
		$errors['items'] = ps__( 'لازم صنف واحد على الأقل' );
	}
	$old_items = array();
	foreach ( (array) ( $existing['items'] ?? array() ) as $it ) {
		$old_items[ (int) $it['id'] ] = $it;
	}
	foreach ( $items as $i => $it ) {
		foreach ( array( 'item_type_id', 'employee_id', 'due_date', 'quantity' ) as $col ) {
			if ( $need( "item.$col" ) && $empty( $it[ $col ] ?? null ) ) {
				$errors[ "items.$i.$col" ] = $msg_req( "item.$col" );
			}
		}
		if ( ! $empty( $it['due_date'] ?? null ) && ! ps_is_valid_date( $it['due_date'] ) ) {
			$errors[ "items.$i.due_date" ] = ps__( 'تاريخ غير صحيح' );
		}
		if ( ! $empty( $it['quantity'] ?? null ) && ( ! is_numeric( $it['quantity'] ) || (float) $it['quantity'] < 0 ) ) {
			$errors[ "items.$i.quantity" ] = ps__( 'كمية غير صحيحة' );
		}
		if ( ! empty( $it['id'] ) && ! isset( $old_items[ (int) $it['id'] ] ) ) {
			$errors[ "items.$i" ] = ps__( 'صنف غير موجود في هذا المشروع' );
		}
		$prev = $old_items[ (int) ( $it['id'] ?? 0 ) ] ?? array();
		foreach ( array( 'item_type_id' => 'item_type', 'employee_id' => 'employee' ) as $col => $list_key ) {
			if ( $empty( $it[ $col ] ?? null ) || isset( $errors[ "items.$i.$col" ] ) ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT list_key, is_hidden FROM $lists_t WHERE id = %d", (int) $it[ $col ] ), ARRAY_A );
			if ( ! $row || $row['list_key'] !== $list_key ) {
				$errors[ "items.$i.$col" ] = ps__( 'قيمة غير موجودة في القائمة' );
			} elseif ( $row['is_hidden'] && (int) ( $prev[ $col ] ?? 0 ) !== (int) $it[ $col ] ) {
				$errors[ "items.$i.$col" ] = ps__( 'هذه القيمة لم تعد متاحة' );
			}
		}
	}
	// Un صنف déjà traité (statut ≠ قيد الإعداد) ne peut pas être retiré : passer à « ملغى ».
	$kept = array_map( fn( $it ) => (int) ( $it['id'] ?? 0 ), $items );
	foreach ( $old_items as $id => $it ) {
		if ( ! in_array( $id, $kept, true ) && 'preparing' !== ( $it['offer_status_code'] ?? 'preparing' ) ) {
			$errors['items'] = ps__( 'لا يمكن حذف صنف تمت معالجته؛ غيّر حالته إلى «ملغى» من صفحة العروض' );
		}
	}

	// 9. منافسون : un nom ou un concurrent de l'annuaire par ligne.
	foreach ( (array) ( $in['competitors'] ?? array() ) as $i => $c ) {
		if ( empty( $c['competitor_id'] ) && $empty( $c['competitor_name'] ?? null ) ) {
			$errors[ "competitors.$i.competitor_name" ] = ps__( 'اكتب اسم المنافس أو احذف السطر' );
		}
		foreach ( (array) ( $c['item_type_ids'] ?? array() ) as $tid ) {
			if ( ps_list_key_of( (int) $tid ) !== 'item_type' ) {
				$errors[ "competitors.$i.item_type_ids" ] = ps__( 'صنف غير موجود' );
			}
		}
	}

	return $errors;
}

/**
 * Liste à laquelle appartient une valeur (ou null).
 */
function ps_list_key_of( int $id ): ?string {
	global $wpdb;
	$k = $wpdb->get_var( $wpdb->prepare( 'SELECT list_key FROM ' . ps_table( 'lists' ) . ' WHERE id = %d', $id ) );
	return $k ?: null;
}
