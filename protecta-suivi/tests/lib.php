<?php
/**
 * Mini-bibliothèque de tests (pas de dépendance : pas de PHPUnit à installer).
 *
 *   ps_test_section( 'Titre' );
 *   ps_assert( $condition, 'description', $détail_optionnel );
 *   ps_assert_same( $attendu, $obtenu, 'description' );
 */

$GLOBALS['ps_test_stats'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );

function ps_test_section( string $title ): void {
	echo "\n== $title ==\n";
}

function ps_assert( $condition, string $label, $detail = null ): void {
	$ok = (bool) $condition;
	$GLOBALS['ps_test_stats'][ $ok ? 'pass' : 'fail' ]++;
	$suffix = null === $detail ? '' : '  [' . ( is_scalar( $detail ) ? $detail : json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) . ']';
	echo ( $ok ? '  ✔ ' : '  ✘ ' ) . $label . ( $ok ? '' : $suffix ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['ps_test_stats']['failures'][] = $label . $suffix;
	}
}

function ps_assert_same( $expected, $actual, string $label ): void {
	ps_assert(
		$expected === $actual,
		$label,
		'attendu ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ', obtenu ' . json_encode( $actual, JSON_UNESCAPED_UNICODE )
	);
}

function ps_test_summary(): int {
	$s = $GLOBALS['ps_test_stats'];
	echo "\n----------------------------------------\n";
	echo sprintf( "Résultat : %d réussis, %d échoués\n", $s['pass'], $s['fail'] );
	foreach ( $s['failures'] as $f ) {
		echo "  ✘ $f\n";
	}
	return $s['fail'] ? 1 : 0;
}
