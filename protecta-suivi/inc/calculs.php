<?php
/**
 * ============================================================================
 *  CALCULS MÉTIER — fichier unique (brief §6)
 * ============================================================================
 *
 * Toutes les règles de calcul de l'application sont ici, et seulement ici.
 * Pour changer une règle : modifier la fonction concernée (chaque fonction est
 * indépendante et commentée). L'API renvoie ces résultats à l'interface : il n'y a
 * pas de deuxième version en JavaScript.
 *
 * Principes :
 *   - fonctions « pures » : elles reçoivent des valeurs simples (codes, dates, nombres)
 *     et ne lisent pas la base → faciles à tester (tests/test-calculs.php) ;
 *   - les statuts sont identifiés par leur CODE (offre, livraison, urgence) ou leur TYPE
 *     (حالة المتابعة), jamais par leur texte, qui peut être renommé dans الإعدادات ;
 *   - quand une règle dépend de la date du jour, elle reçoit $today (« AAAA-MM-JJ »)
 *     en paramètre.
 *
 * Codes utilisés :
 *   Statut d'offre d'un صنف : preparing (قيد الإعداد), sent (مرسل), not_sent (لم يُرسل),
 *                             accepted (مقبول), rejected (مرفوض), cancelled (ملغى)
 *   Type de حالة المتابعة  : ongoing (جارية), rejected (مرفوض), confirmed (مؤكد), delivered (مسلّم)
 *
 * Sommaire :
 *   1. Types de حالة المتابعة (مؤكد، مغلق)
 *   2. حالة العرض d'un projet
 *   3. Indicateurs : العروض المرسلة، لم تُرسل، نسبة النجاح، نسبة الإرسال، montants par devise
 *   4. الأولوية التلقائية
 *   5. هامش الربح
 *   6. Retards : عرض متأخر / لازم قرار, تسليم متأخر, تأخير (أيام), حالة التسليم automatique, couleur des lignes
 *   7. Alerte de suivi (7 jours)
 *   8. Tâche libre en retard (1.0.1)
 *   9. Factures : آخر أجل للرد, retard, alertes J-3 / jour J / retard ; projet « غير مشروع » (1.6.0)
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * حالة المتابعة : types
 * ------------------------------------------------------------------------ */

/**
 * Projet « مؤكد » : statut de type مؤكد OU مسلّم (un projet livré a aussi été confirmé).
 */
function ps_calc_is_confirmed( ?string $followup_type ): bool {
	return in_array( $followup_type, array( 'confirmed', 'delivered' ), true );
}

/**
 * Projet « مغلق » (clôturé) : statut de type مرفوض OU مسلّم.
 */
function ps_calc_is_closed( ?string $followup_type ): bool {
	return in_array( $followup_type, array( 'rejected', 'delivered' ), true );
}

/* ---------------------------------------------------------------------------
 * حالة العرض d'un projet, déduite des statuts de ses أصناف
 * ------------------------------------------------------------------------ */

/**
 * Règle (brief §6), dans cet ordre :
 *   1. aucun صنف, ou tous ملغى                → cancelled (ملغى) — sauf aucun صنف : preparing
 *   puis en IGNORANT les أصناف ملغى :
 *   2. au moins un مقبول                       → accepted  (مقبول)
 *   3. tous مرفوض                              → rejected  (مرفوض)
 *   4. au moins un مرسل                        → sent      (مرسل)
 *   5. tous لم يُرسل                           → not_sent  (لم يُرسل)
 *   6. sinon                                   → preparing (قيد الإعداد)
 *
 * @param string[] $item_codes Codes des statuts d'offre des أصناف du projet.
 */
function ps_calc_project_offer_status( array $item_codes ): string {
	if ( ! $item_codes ) {
		return 'preparing';
	}
	$active = array_values( array_filter( $item_codes, fn( $c ) => 'cancelled' !== $c ) );
	if ( ! $active ) {
		return 'cancelled';
	}
	if ( in_array( 'accepted', $active, true ) ) {
		return 'accepted';
	}
	if ( count( array_filter( $active, fn( $c ) => 'rejected' === $c ) ) === count( $active ) ) {
		return 'rejected';
	}
	if ( in_array( 'sent', $active, true ) ) {
		return 'sent';
	}
	if ( count( array_filter( $active, fn( $c ) => 'not_sent' === $c ) ) === count( $active ) ) {
		return 'not_sent';
	}
	return 'preparing';
}

/* ---------------------------------------------------------------------------
 * Indicateurs (Recap, مهامي)
 * ------------------------------------------------------------------------ */

/**
 * « العروض المرسلة » : le projet compte comme offre envoyée si
 *   حالة العرض ∈ { مرسل، مقبول، مرفوض }
 *   OU le type de حالة المتابعة ∈ { مؤكد، مرفوض، مسلّم } (le client a répondu, donc l'offre est partie).
 * Un projet « لم يُرسل » ne compte pas (sauf si le suivi indique une réponse du client).
 */
function ps_calc_is_offer_sent( string $project_offer_status, ?string $followup_type ): bool {
	return in_array( $project_offer_status, array( 'sent', 'accepted', 'rejected' ), true )
		|| in_array( $followup_type, array( 'confirmed', 'rejected', 'delivered' ), true );
}

/**
 * « لم تُرسل » : حالة العرض du projet = لم يُرسل, et le projet n'est pas compté comme envoyé.
 */
function ps_calc_is_offer_not_sent( string $project_offer_status, ?string $followup_type ): bool {
	return 'not_sent' === $project_offer_status && ! ps_calc_is_offer_sent( $project_offer_status, $followup_type );
}

/**
 * نسبة النجاح = المؤكدة ÷ العروض المرسلة × 100, arrondie à 1 décimale.
 * null (affiché « — ») si aucune offre envoyée.
 */
function ps_calc_success_rate( int $confirmed, int $sent ): ?float {
	return $sent > 0 ? round( $confirmed / $sent * 100, 1 ) : null;
}

/**
 * نسبة الإرسال = المرسلة ÷ (المرسلة + لم تُرسل) × 100, arrondie à 1 décimale.
 * null si les deux sont à zéro.
 */
function ps_calc_send_rate( int $sent, int $not_sent ): ?float {
	return ( $sent + $not_sent ) > 0 ? round( $sent / ( $sent + $not_sent ) * 100, 1 ) : null;
}

/**
 * Montants TOUJOURS séparés par devise, jamais additionnés entre devises.
 *
 * @param array $rows Liste de [ montant, id_devise ] (montants vides ignorés).
 * @return array id_devise => total (arrondi à 3 décimales).
 */
function ps_calc_amounts_by_currency( array $rows ): array {
	$out = array();
	foreach ( $rows as $r ) {
		list( $amount, $currency ) = $r;
		if ( null === $amount || '' === $amount || ! $currency ) {
			continue;
		}
		$out[ (int) $currency ] = round( ( $out[ (int) $currency ] ?? 0 ) + (float) $amount, 3 );
	}
	ksort( $out );
	return $out;
}

/* ---------------------------------------------------------------------------
 * الأولوية التلقائية
 * ------------------------------------------------------------------------ */

/**
 * Priorité = poids de درجة الاستعجال + poids de الأهمية كحجم.
 *   استعجال : VU=4, U=3, N=2, L=1        (poids réglés dans الإعدادات, liste « urgency »)
 *   الأهمية كحجم : عالية=3, متوسطة=2, ضعيفة=1, vide=1
 *   Total 6-7 → P1 ; 4-5 → P2 ; 2-3 → P3.
 *
 * @param int|null $urgency_weight Poids de l'urgence (null si non renseignée → pas de priorité).
 * @param int|null $size_weight    Poids de الأهمية كحجم (null si vide → compte 1).
 * @return string|null 'P1' | 'P2' | 'P3' | null
 */
function ps_calc_priority( ?int $urgency_weight, ?int $size_weight ): ?string {
	if ( null === $urgency_weight ) {
		return null;
	}
	$total = $urgency_weight + ( $size_weight ?? 1 );
	if ( $total >= 6 ) {
		return 'P1';
	}
	if ( $total >= 4 ) {
		return 'P2';
	}
	return 'P3';
}

/* ---------------------------------------------------------------------------
 * هامش الربح
 * ------------------------------------------------------------------------ */

/**
 * هامش الربح = مبلغ العرض − مبلغ الشراء ; نسبة الهامش = الهامش ÷ مبلغ العرض × 100 (1 décimale).
 * Calculé SEULEMENT si les deux montants existent et sont dans la même devise
 * (et مبلغ العرض ≠ 0 pour le pourcentage).
 *
 * @return array|null [ 'amount' => float, 'percent' => float|null ] ou null si non calculable.
 */
function ps_calc_margin( $offer_amount, $offer_currency_id, $purchase_amount, $purchase_currency_id ): ?array {
	if ( null === $offer_amount || '' === $offer_amount || null === $purchase_amount || '' === $purchase_amount ) {
		return null;
	}
	if ( ! $offer_currency_id || (int) $offer_currency_id !== (int) $purchase_currency_id ) {
		return null;
	}
	$offer  = (float) $offer_amount;
	$margin = round( $offer - (float) $purchase_amount, 3 );
	return array(
		'amount'  => $margin,
		'percent' => 0.0 === $offer ? null : round( $margin / $offer * 100, 1 ),
	);
}

/* ---------------------------------------------------------------------------
 * Retards et couleurs
 * ------------------------------------------------------------------------ */

/**
 * « عرض متأخر » (= alerte « لازم قرار ») pour un صنف :
 *   statut d'offre « قيد الإعداد » ET الأجل المطلوب < aujourd'hui ET urgence du projet ≠ L.
 * (Le jour même du أجل n'est pas encore un retard.)
 */
function ps_calc_is_offer_late( ?string $item_offer_code, ?string $due_date, ?string $urgency_code, string $today ): bool {
	return 'preparing' === ( $item_offer_code ?? 'preparing' )
		&& ! empty( $due_date ) && $due_date < $today
		&& 'L' !== $urgency_code;
}

/**
 * « تسليم متأخر » pour un صنف :
 *   صنف مقبول ET حالة التسليم ≠ تم التسليم ET
 *   ( date réelle > date demandée  OU  ( pas de date réelle ET date demandée < aujourd'hui ) ).
 */
function ps_calc_is_delivery_late( ?string $item_offer_code, ?string $delivery_code, ?string $due, ?string $actual, string $today ): bool {
	if ( 'accepted' !== $item_offer_code || 'delivered' === $delivery_code || empty( $due ) ) {
		return false;
	}
	return ! empty( $actual ) ? $actual > $due : $due < $today;
}

/**
 * تأخير (أيام) :
 *   date réelle − date demandée ; sans date réelle et date dépassée : aujourd'hui − date demandée ; sinon 0.
 *   Jamais négatif.
 */
function ps_calc_delivery_delay_days( ?string $due, ?string $actual, string $today ): int {
	if ( empty( $due ) ) {
		return 0;
	}
	if ( ! empty( $actual ) ) {
		return max( 0, ps_calc_days_between( $due, $actual ) );
	}
	return $due < $today ? ps_calc_days_between( $due, $today ) : 0;
}

/**
 * Nombre de jours de $from à $to (dates « AAAA-MM-JJ » ; négatif si $to est avant $from).
 */
function ps_calc_days_between( string $from, string $to ): int {
	return (int) round( ( strtotime( substr( $to, 0, 10 ) . ' 00:00:00 UTC' ) - strtotime( substr( $from, 0, 10 ) . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
}

/**
 * حالة التسليم affichée : passe automatiquement à « متأخر » (late) quand la livraison est en retard,
 * sinon le statut choisi.
 */
function ps_calc_effective_delivery_status( ?string $delivery_code, bool $is_late ): ?string {
	return $is_late ? 'late' : $delivery_code;
}

/**
 * Couleur d'une ligne de Suivi Offres : 'late' (rouge) = offre en retard (hors L),
 * 'done' (vert) = مقبول, sinon null.
 */
function ps_calc_offer_row_color( ?string $item_offer_code, ?string $due_date, ?string $urgency_code, string $today ): ?string {
	if ( ps_calc_is_offer_late( $item_offer_code, $due_date, $urgency_code, $today ) ) {
		return 'late';
	}
	return 'accepted' === $item_offer_code ? 'done' : null;
}

/* ---------------------------------------------------------------------------
 * Alerte de suivi
 * ------------------------------------------------------------------------ */

/**
 * Alerte « مشروع يحتاج متابعة » :
 *   projet NON مغلق ET urgence ≠ L ET
 *   ( dernière entrée du سجل المتابعة — ou dernière modification du projet si le سجل est vide —
 *     date de PLUS de $days jours (7 par défaut)
 *     OU prochaine date de suivi dépassée (< aujourd'hui) ).
 *
 * @param string|null $last_followup_date Date de la dernière entrée du سجل (Y-m-d) ou null.
 * @param string      $last_modified      Dernière modification du projet (Y-m-d ou Y-m-d H:i:s).
 * @param string|null $next_date          Prochaine date de suivi de la dernière entrée.
 */
function ps_calc_needs_followup( ?string $followup_type, ?string $urgency_code, ?string $last_followup_date, string $last_modified, ?string $next_date, string $today, int $days = 7 ): bool {
	if ( ps_calc_is_closed( $followup_type ) || 'L' === $urgency_code ) {
		return false;
	}
	$ref = substr( $last_followup_date ?: $last_modified, 0, 10 );
	if ( ps_calc_days_between( $ref, $today ) > $days ) {
		return true;
	}
	return ! empty( $next_date ) && $next_date < $today;
}

/* ---------------------------------------------------------------------------
 * Tâches libres (« مهمة جديدة », version 1.0.1)
 * ------------------------------------------------------------------------ */

/**
 * Tâche « متأخرة » : encore ouverte ET أجل dépassé (< aujourd'hui).
 * Comme pour les أصناف, le jour même du أجل n'est pas un retard ; sans أجل, jamais en retard.
 */
function ps_calc_task_late( string $status, ?string $due_date, string $today ): bool {
	return 'open' === $status && ! empty( $due_date ) && $due_date < $today;
}

/* ---------------------------------------------------------------------------
 * Factures (version 1.6.0)
 *   جواب الحريف : pending (في الانتظار, défaut) / yes (نعم) / no (لا).
 * ------------------------------------------------------------------------ */

/**
 * « آخر أجل للرد » = تاريخ إرسال الفاتورة + N jours (réglage invoice_reply_days, 15 par défaut).
 * Jamais saisi ni stocké : changer N recalcule toutes les échéances sans toucher aux dates d'envoi.
 */
function ps_calc_invoice_deadline( string $sent_date, int $days ): string {
	return gmdate( 'Y-m-d', strtotime( substr( $sent_date, 0, 10 ) . ' 00:00:00 UTC' ) + max( 0, $days ) * DAY_IN_SECONDS );
}

/**
 * Facture « en retard » : réponse encore « في الانتظار » ET آخر أجل dépassé (< aujourd'hui).
 * Le jour même de l'échéance n'est pas un retard (comme pour les أصناف et les tâches).
 */
function ps_calc_invoice_late( string $answer, string $deadline, string $today ): bool {
	return 'pending' === $answer && $deadline < $today;
}

/** Jours de retard d'une facture (0 si elle n'est pas en retard). */
function ps_calc_invoice_days_late( string $answer, string $deadline, string $today ): int {
	return ps_calc_invoice_late( $answer, $deadline, $today ) ? ps_calc_days_between( $deadline, $today ) : 0;
}

/**
 * Alerte du jour pour une facture (Telegram / e-mail) — tant que la réponse est « في الانتظار » :
 *   « before » : l'échéance est dans 1 à $before jours (rappel, 3 jours avant par défaut — envoyé une seule fois) ;
 *   « due »    : c'est le jour de l'échéance ;
 *   « late »   : l'échéance est dépassée (1.11.0 : un rappel tous les N jours — réglage invoice_remind_days — filtré dans alerts.php) ;
 *   null       : rien à envoyer (réponse reçue, ou échéance encore lointaine).
 */
function ps_calc_invoice_alert_kind( string $answer, string $deadline, string $today, int $before = 3 ): ?string {
	if ( 'pending' !== $answer ) {
		return null;
	}
	$left = ps_calc_days_between( $today, $deadline );
	if ( $left < 0 ) {
		return 'late';
	}
	if ( 0 === $left ) {
		return 'due';
	}
	return $left <= $before ? 'before' : null;
}

/**
 * Taux de réponse des factures : part des « نعم » et des « لا » parmi les factures qui ont une réponse.
 * null (affiché « — ») si aucune réponse.
 */
function ps_calc_invoice_answer_rates( int $yes, int $no ): array {
	$total = $yes + $no;
	return array(
		'yes' => $total ? round( $yes / $total * 100, 1 ) : null,
		'no'  => $total ? round( $no / $total * 100, 1 ) : null,
	);
}
