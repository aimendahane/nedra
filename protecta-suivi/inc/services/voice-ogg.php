<?php
/**
 * Conversion WebM (Opus) → Ogg (Opus), en PHP pur (version 1.3.1).
 *
 * Chrome / Edge / Android enregistrent les messages vocaux en WebM. Telegram ne lit pas ce format
 * (« sendAudio » l'accepte mais le message reste muet) : il veut de l'Ogg/Opus pour un vrai message vocal.
 * Le son n'est PAS réencodé : les paquets Opus sont recopiés tels quels dans un conteneur Ogg
 * (aucune perte de qualité, aucune extension PHP ni ffmpeg nécessaire sur l'hébergement).
 *
 * Références : Matroska/EBML (IDs ci-dessous), RFC 7845 (Opus dans Ogg), RFC 6716 §3.1 (octet TOC).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convertit un fichier WebM/Opus en fichier Ogg/Opus.
 *
 * @return bool true si le fichier $dest a été écrit (sinon le WebM n'était pas de l'Opus lisible).
 */
function ps_voice_webm_to_ogg( string $src, string $dest ): bool {
	$data = @file_get_contents( $src ); // phpcs:ignore
	if ( false === $data || strlen( $data ) < 64 || "\x1A\x45\xDF\xA3" !== substr( $data, 0, 4 ) ) {
		return false;
	}
	$parsed = ps_voice_webm_packets( $data );
	if ( ! $parsed || ! $parsed['packets'] ) {
		return false;
	}
	$ogg = ps_voice_ogg_build( $parsed['head'], $parsed['packets'] );
	return '' !== $ogg && false !== file_put_contents( $dest, $ogg ); // phpcs:ignore
}

/** Lit un entier de longueur variable EBML. $keep_marker : identifiants (marqueur conservé). */
function ps_voice_vint( string $d, int &$pos, bool $keep_marker ): ?int {
	if ( $pos >= strlen( $d ) ) {
		return null;
	}
	$first = ord( $d[ $pos ] );
	$len   = 1;
	while ( $len <= 8 && ! ( $first & ( 0x80 >> ( $len - 1 ) ) ) ) {
		++$len;
	}
	if ( $len > 8 || $pos + $len > strlen( $d ) ) {
		return null;
	}
	$val     = $keep_marker ? $first : ( $first & ( 0xFF >> $len ) );
	$all_one = ( $val === ( 0xFF >> $len ) );
	for ( $i = 1; $i < $len; $i++ ) {
		$b       = ord( $d[ $pos + $i ] );
		$val     = ( $val << 8 ) | $b;
		$all_one = $all_one && 0xFF === $b;
	}
	$pos += $len;
	return ( ! $keep_marker && $all_one ) ? -1 : $val; // -1 = taille inconnue (enregistrement en direct)
}

/**
 * Parcourt le WebM : en-tête Opus (CodecPrivate) + paquets de la piste audio, dans l'ordre.
 *
 * @return array{head:string,packets:string[]}|null
 */
function ps_voice_webm_packets( string $d ): ?array {
	// Éléments « conteneurs » : on entre dedans (leur taille peut être inconnue pendant un enregistrement).
	$masters = array( 0x18538067, 0x1F43B675, 0x1654AE6B, 0xAE, 0xA0 ); // Segment, Cluster, Tracks, TrackEntry, BlockGroup
	$len     = strlen( $d );
	$pos     = 0;
	$head    = '';
	$codec   = '';
	$track   = 0;
	$entry   = array();
	$packets = array();
	while ( $pos < $len ) {
		$id   = ps_voice_vint( $d, $pos, true );
		$size = ps_voice_vint( $d, $pos, false );
		if ( null === $id || null === $size ) {
			break;
		}
		if ( in_array( $id, $masters, true ) ) {
			if ( 0xAE === $id ) {
				$entry = array();
			}
			continue;
		}
		if ( $size < 0 || $pos + $size > $len ) {
			$size = $len - $pos; // fichier tronqué : on garde ce qui est complet
		}
		$body = substr( $d, $pos, $size );
		$pos += $size;
		switch ( $id ) {
			case 0xD7: // TrackNumber
				$entry['n'] = (int) hexdec( bin2hex( $body ) );
				break;
			case 0x86: // CodecID
				if ( 'A_OPUS' === $body && isset( $entry['n'] ) && ! $track ) {
					$track = $entry['n'];
					$codec = 'opus';
				}
				$entry['codec'] = $body;
				break;
			case 0x63A2: // CodecPrivate = « OpusHead »
				if ( str_starts_with( $body, 'OpusHead' ) ) {
					$head = $body;
				}
				break;
			case 0xA3: // SimpleBlock
			case 0xA1: // Block (dans un BlockGroup)
				$p = 0;
				$n = ps_voice_vint( $body, $p, false );
				if ( null === $n || ( $track && $n !== $track ) || strlen( $body ) < $p + 3 ) {
					break;
				}
				$flags = ord( $body[ $p + 2 ] );
				if ( $flags & 0x06 ) {
					return null; // « lacing » : jamais produit par les navigateurs pour l'audio, non géré
				}
				$frame = substr( $body, $p + 3 );
				if ( '' !== $frame ) {
					$packets[] = $frame;
				}
				break;
		}
	}
	if ( 'opus' !== $codec ) {
		return null;
	}
	if ( '' === $head ) {
		// En-tête par défaut (mono, 48 kHz, pré-saut standard de 312 échantillons).
		$head = 'OpusHead' . pack( 'CCvVvC', 1, 1, 312, 48000, 0, 0 );
	}
	return array( 'head' => $head, 'packets' => $packets );
}

/** Nombre d'échantillons (à 48 kHz) d'un paquet Opus, d'après son octet TOC (RFC 6716 §3.1). */
function ps_voice_opus_samples( string $pkt ): int {
	if ( '' === $pkt ) {
		return 0;
	}
	$toc    = ord( $pkt[0] );
	$config = $toc >> 3;
	if ( $config < 12 ) {        // SILK : 10, 20, 40, 60 ms
		$frame = array( 480, 960, 1920, 2880 )[ $config & 3 ];
	} elseif ( $config < 16 ) {  // Hybride : 10, 20 ms
		$frame = ( $config & 1 ) ? 960 : 480;
	} else {                     // CELT : 2,5, 5, 10, 20 ms
		$frame = array( 120, 240, 480, 960 )[ $config & 3 ];
	}
	$code   = $toc & 3;
	$frames = 0 === $code ? 1 : ( 3 === $code ? ( strlen( $pkt ) > 1 ? ord( $pkt[1] ) & 0x3F : 0 ) : 2 );
	return $frame * $frames;
}

/** CRC des pages Ogg (polynôme 0x04C11DB7, sans réflexion, valeur initiale 0). */
function ps_voice_ogg_crc( string $s ): int {
	static $t = null;
	if ( null === $t ) {
		$t = array();
		for ( $i = 0; $i < 256; $i++ ) {
			$r = $i << 24;
			for ( $j = 0; $j < 8; $j++ ) {
				$r = ( $r & 0x80000000 ) ? ( ( $r << 1 ) ^ 0x04C11DB7 ) : ( $r << 1 );
				$r &= 0xFFFFFFFF;
			}
			$t[ $i ] = $r;
		}
	}
	$crc = 0;
	$n   = strlen( $s );
	for ( $i = 0; $i < $n; $i++ ) {
		$crc = ( ( $crc << 8 ) & 0xFFFFFFFF ) ^ $t[ ( ( $crc >> 24 ) ^ ord( $s[ $i ] ) ) & 0xFF ];
	}
	return $crc;
}

/** Une page Ogg contenant des paquets entiers. */
function ps_voice_ogg_page( array $packets, int $granule, int $serial, int $seq, int $flags ): string {
	$lacing = '';
	foreach ( $packets as $p ) {
		$lacing .= str_repeat( "\xFF", intdiv( strlen( $p ), 255 ) ) . chr( strlen( $p ) % 255 );
	}
	$page = 'OggS' . chr( 0 ) . chr( $flags ) . pack( 'P', $granule ) . pack( 'V', $serial ) . pack( 'V', $seq ) . pack( 'V', 0 )
		. chr( strlen( $lacing ) ) . $lacing . implode( '', $packets );
	return substr_replace( $page, pack( 'V', ps_voice_ogg_crc( $page ) ), 22, 4 );
}

/** Assemble le fichier Ogg/Opus : OpusHead, OpusTags, puis les paquets audio (RFC 7845). */
function ps_voice_ogg_build( string $head, array $packets ): string {
	$serial = random_int( 1, 0x7FFFFFFF );
	$out    = ps_voice_ogg_page( array( $head ), 0, $serial, 0, 0x02 ); // début du flux
	$vendor = 'Protecta Suivi';
	$out   .= ps_voice_ogg_page( array( 'OpusTags' . pack( 'V', strlen( $vendor ) ) . $vendor . pack( 'V', 0 ) ), 0, $serial, 1, 0 );
	$seq     = 2;
	$granule = 0; // échantillons décodés depuis le début (pré-saut compris)
	$page    = array();
	$segs    = 0;
	$count   = count( $packets );
	foreach ( $packets as $i => $p ) {
		$need = intdiv( strlen( $p ), 255 ) + 1;
		if ( $page && ( $segs + $need > 255 || count( $page ) >= 50 ) ) {
			$out .= ps_voice_ogg_page( $page, $granule, $serial, $seq++, 0 );
			$page = array();
			$segs = 0;
		}
		$page[]   = $p;
		$segs    += $need;
		$granule += ps_voice_opus_samples( $p );
		if ( $i === $count - 1 ) {
			$out .= ps_voice_ogg_page( $page, $granule, $serial, $seq++, 0x04 ); // fin du flux
		}
	}
	return $out;
}
