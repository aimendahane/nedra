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
 * Étape 2 : statut d'offre du projet, types de suivi, priorité, marge.
 * Étape 3 : retards, تأخير, alertes de suivi, indicateurs (seront ajoutés ici).
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
