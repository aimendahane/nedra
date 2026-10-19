<?php
/**
 * Normalisation du texte arabe (recherche, annuaire, détection de doublons).
 *
 * « شَرِكة الإنارة » et « شركه الانارة » doivent être reconnus comme identiques :
 *   - suppression des voyelles (tashkeel) et de l'étirement (tatweel ـ) ;
 *   - أ إ آ ٱ → ا ; ى → ي ; ة → ه ; ؤ → و ; ئ → ي ;
 *   - chiffres arabes-indiens → chiffres latins ;
 *   - lettres latines en minuscules ; ponctuation retirée ; espaces simplifiés.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forme normalisée d'un texte (pour comparer, jamais pour afficher).
 */
function ps_normalize_text( ?string $text ): string {
	$t = (string) $text;
	$t = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t ); // tashkeel + tatweel
	$t = strtr(
		$t,
		array(
			'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
			'ى' => 'ي', 'ئ' => 'ي', 'ة' => 'ه', 'ؤ' => 'و',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		)
	);
	$t = mb_strtolower( $t, 'UTF-8' );
	$t = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t ); // ponctuation → espace
	return trim( preg_replace( '/\s+/u', ' ', $t ) );
}

/**
 * Distance de Levenshtein sur des caractères Unicode (levenshtein() de PHP compte les octets).
 */
function ps_mb_levenshtein( string $a, string $b ): int {
	$a = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
	$b = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
	$la = count( $a );
	$lb = count( $b );
	if ( 0 === $la ) {
		return $lb;
	}
	if ( 0 === $lb ) {
		return $la;
	}
	$prev = range( 0, $lb );
	for ( $i = 1; $i <= $la; $i++ ) {
		$cur = array( $i );
		for ( $j = 1; $j <= $lb; $j++ ) {
			$cost    = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
			$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
		}
		$prev = $cur;
	}
	return $prev[ $lb ];
}

/**
 * Similarité entre deux textes, de 0 (différents) à 1 (identiques), après normalisation.
 * On garde le meilleur de deux mesures :
 *   - caractères : 1 − distance de Levenshtein ÷ longueur ;
 *   - mots : part des mots du plus court présents dans le plus long
 *     (« إنارة مدرسة » ≈ « مشروع إنارة مدرسة الأمل »).
 */
function ps_text_similarity( string $a, string $b ): float {
	$a = ps_normalize_text( $a );
	$b = ps_normalize_text( $b );
	if ( '' === $a || '' === $b ) {
		return 0.0;
	}
	if ( $a === $b ) {
		return 1.0;
	}
	$max   = max( mb_strlen( $a ), mb_strlen( $b ) );
	$chars = 1 - ps_mb_levenshtein( $a, $b ) / $max;

	$wa = array_unique( explode( ' ', $a ) );
	$wb = array_unique( explode( ' ', $b ) );
	if ( count( $wa ) > count( $wb ) ) {
		list( $wa, $wb ) = array( $wb, $wa );
	}
	$words = count( $wa ) >= 2 ? count( array_intersect( $wa, $wb ) ) / count( $wa ) : 0.0;

	return max( $chars, $words );
}
