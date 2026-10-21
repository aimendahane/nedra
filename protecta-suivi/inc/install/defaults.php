<?php
/**
 * Valeurs par défaut des listes déroulantes (cahier des charges §8 + brief).
 *
 * RÈGLE DE CONSERVATION (voir CLAUDE.md) :
 *   - une liste n'est remplie avec ces valeurs qu'UNE SEULE FOIS, à sa création.
 *     Les clés déjà remplies sont mémorisées dans l'option « ps_seeded_lists ».
 *   - une mise à jour du thème ne remet JAMAIS une liste à ses valeurs par défaut,
 *     ne renomme rien, ne ré-affiche rien, ne recrée pas une valeur supprimée.
 *   - seule exception : les valeurs « système » (is_system=1) dont dépend la logique
 *     (statuts d'offre, urgences…) sont recréées si leur code a disparu ; une valeur
 *     système existante n'est jamais modifiée.
 *
 * Format de chaque valeur :
 *   label (obligatoire), code, type, weight, color, hidden, system, aliases (anciennes
 *   valeurs reconnues à l'import), meta (tableau → JSON).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Libellés des listes (affichés dans الإعدادات).
 */
function ps_list_labels(): array {
	return array(
		'urgency'         => ps__( 'درجة الاستعجال' ),
		'employee'        => ps__( 'الموظفون' ),
		'followup_status' => ps__( 'حالة المتابعة' ),
		'item_type'       => ps__( 'الأصناف' ),
		'place'           => ps__( 'المكان' ),
		'category'        => ps__( 'التصنيف' ),
		'importance'      => ps__( 'الأهمية' ),
		'source'          => ps__( 'مصدر الطلب' ),
		'offer_status'    => ps__( 'حالة العرض' ),
		'not_sent_reason' => ps__( 'سبب عدم الإرسال' ),
		'loss_reason'     => ps__( 'سبب الرفض أو الخسارة' ),
		'delivery_status' => ps__( 'حالة التسليم' ),
		'availability'    => ps__( 'المتوفر' ),
		'currency'        => ps__( 'العملات' ),
	);
}

/**
 * Types possibles d'une حالة المتابعة. Les calculs utilisent le TYPE, jamais le texte.
 */
function ps_followup_types(): array {
	return array(
		'ongoing'   => ps__( 'جارية' ),
		'rejected'  => ps__( 'مرفوض' ),
		'confirmed' => ps__( 'مؤكد' ),
		'delivered' => ps__( 'مسلّم' ),
	);
}

/**
 * Valeurs par défaut de chaque liste.
 */
function ps_default_lists(): array {
	$v = static function ( string $label, array $extra = array() ): array {
		return array_merge( array( 'label' => $label ), $extra );
	};

	return array(
		// §8.1 — Codes fixes (VU/U/N/L), poids utilisés par la priorité automatique.
		'urgency' => array(
			$v( 'VU – Very Urgent', array( 'code' => 'VU', 'weight' => 4, 'color' => '#d93025', 'system' => 1 ) ),
			$v( 'U – Urgent', array( 'code' => 'U', 'weight' => 3, 'color' => '#f08c00', 'system' => 1, 'aliases' => array( 'عاجل', 'متوسط' ) ) ),
			$v( 'N – Normal', array( 'code' => 'N', 'weight' => 2, 'color' => '#2f9e44', 'system' => 1, 'aliases' => array( 'عادي' ) ) ),
			$v( 'L – Long term', array( 'code' => 'L', 'weight' => 1, 'color' => '#868e96', 'system' => 1 ) ),
		),

		// §8.2 — « موقع العطاءات » n'est plus un employé : c'est un مصدر الطلب.
		'employee' => array_map(
			fn( $n ) => $v( $n ),
			array( 'وفاء حامدي', 'غفران دريدي', 'محمد حسين', 'أميرة السعيداني', 'مروان فرحاني', 'وليد جدير', 'مجدي ميلادي', 'هاشم الخراز', 'بدر ناجي', 'أمير بن مبروك', 'صفاء' )
		),

		// §8.3 — Le TYPE pilote les calculs (جارية / مرفوض / مؤكد / مسلّم).
		'followup_status' => array(
			$v( 'في دراسة', array( 'type' => 'ongoing', 'color' => '#1c7ed6', 'meta' => array( 'default' => true ), 'aliases' => array( 'قيد المتابعة', 'جديد' ) ) ),
			$v( 'في انتظار معلومات', array( 'type' => 'ongoing', 'color' => '#f59f00' ) ),
			$v( 'في انتظار الرد', array( 'type' => 'ongoing', 'color' => '#f59f00', 'aliases' => array( 'في انتظار الحريف' ) ) ),
			$v( 'رفض العرض', array( 'type' => 'rejected', 'color' => '#e03131', 'aliases' => array( 'مرفوض' ) ) ),
			$v( 'تم تأكيد الطلبية', array( 'type' => 'confirmed', 'color' => '#8ce99a', 'aliases' => array( 'مقبول' ) ) ),
			$v( 'في انتظار الدفع', array( 'type' => 'confirmed', 'color' => '#fcc419' ) ),
			$v( 'في انتظار تكملة الدفع', array( 'type' => 'confirmed', 'color' => '#fcc419' ) ),
			$v( 'في انتظار التوريد', array( 'type' => 'confirmed', 'color' => '#fcc419' ) ),
			$v( 'تم تسليم جزء', array( 'type' => 'confirmed', 'color' => '#fd7e14' ) ),
			$v( 'تم التسليم', array( 'type' => 'delivered', 'color' => '#2b8a3e', 'aliases' => array( 'مغلق' ) ) ),
		),

		// §8.4 — Orthographe corrigée (منظومة، Earthing).
		'item_type' => array_map(
			fn( $n ) => $v( $n ),
			array( 'RELLI', 'متعدد الأصناف', 'CABLE', 'Rack', 'حساسات', 'منظومة إطفاء', 'Pop up box', 'محركات أبواب', 'Patch cord', 'إنذار حريق', 'مواد كهربائية', 'كمرات مراقبة', 'منظومة نداء ممرضات', 'تاريض', 'UPS', 'Data center', 'إنارة', 'Earthing Sys', 'مفاتيح وبرايز', 'كابلات', 'لوحات كهربائية', 'تركيبات', 'زبارات', 'Smart home', 'فواصل', 'FIREPRO' )
		),

		// §8.5 — Code affiché dans les tableaux, nom complet dans la liste. Zewiya → ZW.
		'place' => array(
			$v( 'Tripoli', array( 'code' => 'T' ) ),
			$v( 'Misurata', array( 'code' => 'M' ) ),
			$v( 'Benghazi', array( 'code' => 'BG' ) ),
			$v( 'Zewiya', array( 'code' => 'ZW' ) ),
			$v( 'Sabha', array( 'code' => 'S' ) ),
			$v( 'Jdebia', array( 'code' => 'J' ) ),
			$v( 'DERNA', array( 'code' => 'D' ) ),
			$v( 'Libya', array( 'code' => 'LY' ) ),
			$v( 'Al-Koufrah', array( 'code' => 'K' ) ),
		),

		// Liste provisoire en attendant la liste réelle de la direction.
		'category' => array_map(
			fn( $n ) => $v( $n ),
			array( 'كهرباء', 'إنارة', 'شبكات', 'أنظمة أمان وإنذار', 'تجهيزات', 'أخرى' )
		),

		// Utilisée pour الأهمية كصنف et الأهمية كحجم ; poids pour la priorité (vide = 1).
		'importance' => array(
			$v( 'عالية', array( 'code' => 'high', 'weight' => 3, 'system' => 1 ) ),
			$v( 'متوسطة', array( 'code' => 'medium', 'weight' => 2, 'system' => 1 ) ),
			$v( 'ضعيفة', array( 'code' => 'low', 'weight' => 1, 'system' => 1 ) ),
		),

		// Brief §4.3. « غير محدد » : réservé aux projets importés, jamais proposé à la saisie.
		'source' => array(
			$v( 'مباشر', array( 'code' => 'direct' ) ),
			$v( 'موقع العطاءات', array( 'code' => 'tenders' ) ),
			$v( 'وسيط', array( 'code' => 'broker' ) ),
			$v( 'إيميل', array( 'code' => 'email' ) ),
			$v( 'هاتف', array( 'code' => 'phone' ) ),
			$v( 'معرض', array( 'code' => 'fair' ) ),
			$v( 'أخرى', array( 'code' => 'other' ) ),
			$v( 'غير محدد', array( 'code' => 'unspecified', 'hidden' => 1, 'system' => 1, 'meta' => array( 'import_only' => true ) ) ),
		),

		// Brief §5 — statuts d'offre d'un صنف (codes fixes utilisés par les calculs).
		'offer_status' => array(
			$v( 'قيد الإعداد', array( 'code' => 'preparing', 'color' => '#1c7ed6', 'system' => 1, 'meta' => array( 'default' => true ) ) ),
			$v( 'مرسل', array( 'code' => 'sent', 'color' => '#7048e8', 'system' => 1 ) ),
			$v( 'لم يُرسل', array( 'code' => 'not_sent', 'color' => '#868e96', 'system' => 1 ) ),
			$v( 'مقبول', array( 'code' => 'accepted', 'color' => '#2b8a3e', 'system' => 1 ) ),
			$v( 'مرفوض', array( 'code' => 'rejected', 'color' => '#e03131', 'system' => 1 ) ),
			$v( 'ملغى', array( 'code' => 'cancelled', 'color' => '#495057', 'system' => 1 ) ),
		),

		'not_sent_reason' => array_map(
			fn( $n ) => $v( $n ),
			array( 'المنتج غير متوفر', 'السعر غير منافس', 'الأجل قصير', 'خارج اختصاصنا', 'معلومات ناقصة من الحريف', 'قرار الإدارة', 'أخرى' )
		),

		'loss_reason' => array_map(
			fn( $n ) => $v( $n ),
			array( 'السعر', 'الأجل', 'المنافس', 'إلغاء المشروع', 'أخرى' )
		),

		// « متأخر » est calculé automatiquement (jamais choisi à la main).
		'delivery_status' => array(
			$v( 'في الانتظار', array( 'code' => 'waiting', 'color' => '#1c7ed6', 'system' => 1, 'meta' => array( 'default' => true ) ) ),
			$v( 'جاهز', array( 'code' => 'ready', 'color' => '#f59f00', 'system' => 1 ) ),
			$v( 'تم التسليم', array( 'code' => 'delivered', 'color' => '#2b8a3e', 'system' => 1 ) ),
			$v( 'متأخر', array( 'code' => 'late', 'color' => '#e03131', 'system' => 1, 'meta' => array( 'computed' => true ) ) ),
		),

		'availability' => array(
			$v( 'نعم', array( 'code' => 'yes', 'system' => 1 ) ),
			$v( 'لا', array( 'code' => 'no', 'system' => 1 ) ),
			$v( 'جزئي', array( 'code' => 'partial', 'system' => 1 ) ),
		),

		// meta.rate_to_lyd : prévu pour la conversion en LYD (hors version 1).
		'currency' => array(
			$v( 'دينار ليبي', array( 'code' => 'LYD', 'meta' => array( 'default' => true, 'symbol' => 'LYD' ) ) ),
			$v( 'دولار أمريكي', array( 'code' => 'USD', 'meta' => array( 'symbol' => 'USD' ) ) ),
			$v( 'يورو', array( 'code' => 'EUR', 'meta' => array( 'symbol' => 'EUR' ) ) ),
			$v( 'دينار تونسي', array( 'code' => 'TND', 'meta' => array( 'symbol' => 'TND' ) ) ),
		),
	);
}

/**
 * Insère une valeur de liste.
 */
function ps_insert_list_value( string $list_key, array $value, int $sort ): int {
	global $wpdb;
	$now = ps_now();
	$wpdb->insert(
		ps_table( 'lists' ),
		array(
			'list_key'   => $list_key,
			'code'       => $value['code'] ?? null,
			'label'      => $value['label'],
			'type'       => $value['type'] ?? null,
			'weight'     => $value['weight'] ?? null,
			'color'      => $value['color'] ?? null,
			'sort_order' => $sort,
			'is_hidden'  => (int) ( $value['hidden'] ?? 0 ),
			'is_system'  => (int) ( $value['system'] ?? 0 ),
			'aliases'    => isset( $value['aliases'] ) ? wp_json_encode( $value['aliases'], JSON_UNESCAPED_UNICODE ) : null,
			'meta'       => isset( $value['meta'] ) ? wp_json_encode( $value['meta'], JSON_UNESCAPED_UNICODE ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	return (int) $wpdb->insert_id;
}

/**
 * Remplit les listes jamais remplies + garantit la présence des valeurs système.
 * Idempotent et sans écrasement (voir règle en tête de fichier).
 */
function ps_seed_lists(): void {
	global $wpdb;
	$table  = ps_table( 'lists' );
	$seeded = get_option( 'ps_seeded_lists', array() );
	if ( ! is_array( $seeded ) ) {
		$seeded = array();
	}

	foreach ( ps_default_lists() as $key => $values ) {
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE list_key = %s", $key ) );

		if ( ! in_array( $key, $seeded, true ) && 0 === $existing ) {
			// Première fois : on remplit toute la liste.
			foreach ( array_values( $values ) as $i => $value ) {
				ps_insert_list_value( $key, $value, ( $i + 1 ) * 10 );
			}
		} else {
			// Liste déjà existante : on ajoute seulement les valeurs système manquantes.
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order),0) FROM $table WHERE list_key = %s", $key ) );
			foreach ( $values as $value ) {
				if ( empty( $value['system'] ) || empty( $value['code'] ) ) {
					continue;
				}
				$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE list_key = %s AND code = %s", $key, $value['code'] ) );
				if ( ! $has ) {
					$max += 10;
					ps_insert_list_value( $key, $value, $max );
				}
			}
		}

		if ( ! in_array( $key, $seeded, true ) ) {
			$seeded[] = $key;
		}
	}

	update_option( 'ps_seeded_lists', $seeded, false );
}
