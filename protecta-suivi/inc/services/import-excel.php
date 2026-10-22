<?php
/**
 * Import du fichier Excel de suivi utilisé par l'équipe avant l'application (version 1.4.0).
 *
 * Colonnes reconnues par leur TITRE (l'ordre et les espaces n'ont pas d'importance) :
 *   السنة · رقم · المكان · درجة الاستعجال · الاهمية كصنف · الاهمية كحجم · الشهر · التصنيف · اسم الشركة ·
 *   شخص المباشر · رقم شخص المباشر · اسم المشروع · الاصناف · تاريخ استلام الطلب · تاريخ ارسال الطلب ·
 *   الموظف المتابع · الموظف الوسيط · رقم المشروع · حالة المتابعة · حالة العرض · NOTE · رابط ملف المشاريع
 *
 * Une ligne = un صنف. Les lignes qui ont le même « رقم » (P030…) forment UN projet avec plusieurs أصناف.
 * Chaque projet est converti au format du prototype puis passe par le même import (ps_import_prototype) :
 * aperçu sans rien écrire, sauvegarde automatique avant, jamais d'écrasement (un numéro déjà présent est
 * ignoré : on peut réimporter le même fichier), valeurs inconnues ajoutées aux listes, fichier d'origine gardé.
 *
 * Sens des colonnes du fichier (vérifié sur le fichier du 22/10/2026) :
 *   - « حالة المتابعة » du fichier = l'offre a-t-elle été remise au client (تم التسليم) ou attend-on des
 *     informations (في انتظار معلومات) → statut d'OFFRE du صنف (مرسل / قيد الإعداد) ;
 *   - « حالة العرض » du fichier = réponse du client (في انتظار الرد، تم تأكيد الطلبية، تم الرفض…)
 *     → « حالة المتابعة » du projet dans l'application, et مقبول / مرفوض pour le صنف.
 */

defined( 'ABSPATH' ) || exit;

/** Titres de colonnes du fichier → clé interne. */
function ps_xl_columns(): array {
	return array(
		'السنة'              => 'year',
		'رقم'                => 'no',
		'المكان'             => 'place',
		'درجة الاستعجال'     => 'urg',
		'الاهمية كصنف'       => 'imc',
		'الاهمية كحجم'       => 'imv',
		'الشهر'              => 'month',
		'التصنيف'            => 'cls',
		'اسم الشركة'         => 'company',
		'شخص المباشر'        => 'person',
		'رقم شخص المباشر'    => 'phone',
		'اسم المشروع'        => 'name',
		'الاصناف'            => 'item',
		'تاريخ استلام الطلب' => 'recv',
		'تاريخ ارسال الطلب'  => 'sent',
		'الموظف المتابع'     => 'emp',
		'الموظف الوسيط'      => 'mid',
		'رقم المشروع'        => 'no2',
		'حالة المتابعة'      => 'xl_fu',
		'حالة العرض'         => 'xl_offer',
		'note'               => 'note',
		'رابط ملف المشاريع'  => 'link',
	);
}

/** Titre de colonne normalisé (espaces multiples, hamza, majuscules). */
function ps_xl_norm_header( $h ): string {
	return ps_normalize_text( preg_replace( '/\s+/u', ' ', trim( (string) $h ) ) );
}

/**
 * Lit la première feuille d'un .xlsx (ou un .csv) : tableau de lignes, chaque ligne = tableau de cellules (texte).
 * Les dates Excel (nombres de jours) sont laissées en nombre : converties ensuite colonne par colonne.
 *
 * @return array|WP_Error
 */
function ps_xl_read( string $path ) {
	$head = (string) file_get_contents( $path, false, null, 0, 4 );
	if ( "PK\x03\x04" !== $head ) {
		return ps_xl_read_csv( $path );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'ps_xl_zip', ps__( 'الخادم لا يقرأ ملفات Excel (ZipArchive غير متاح): احفظ الملف بصيغة CSV ثم أعد المحاولة.' ), array( 'status' => 422 ) );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'ps_xl_open', ps__( 'تعذّر فتح ملف Excel' ), array( 'status' => 422 ) );
	}
	// Chaînes partagées.
	$strings = array();
	$xml     = $zip->getFromName( 'xl/sharedStrings.xml' );
	if ( false !== $xml ) {
		$sx = @simplexml_load_string( $xml ); // phpcs:ignore
		foreach ( $sx ? $sx->si : array() as $si ) {
			$t = '';
			if ( isset( $si->t ) ) {
				$t = (string) $si->t;
			}
			foreach ( $si->r as $run ) {
				$t .= (string) $run->t;
			}
			$strings[] = $t;
		}
	}
	// Première feuille (d'après workbook.xml.rels, sinon sheet1.xml).
	$sheet = 'xl/worksheets/sheet1.xml';
	$wb    = @simplexml_load_string( (string) $zip->getFromName( 'xl/workbook.xml' ) ); // phpcs:ignore
	$rels  = @simplexml_load_string( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) ); // phpcs:ignore
	if ( $wb && $rels && isset( $wb->sheets->sheet[0] ) ) {
		$rid = (string) $wb->sheets->sheet[0]->attributes( 'r', true )->id;
		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = ltrim( (string) $rel['Target'], '/' );
				$sheet  = str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
			}
		}
	}
	$sx = @simplexml_load_string( (string) $zip->getFromName( $sheet ) ); // phpcs:ignore
	$zip->close();
	if ( ! $sx || ! isset( $sx->sheetData ) ) {
		return new WP_Error( 'ps_xl_sheet', ps__( 'لم يتم العثور على ورقة في ملف Excel' ), array( 'status' => 422 ) );
	}
	$rows = array();
	foreach ( $sx->sheetData->row as $row ) {
		$cells = array();
		foreach ( $row->c as $c ) {
			$col = ps_xl_col_index( (string) $c['r'] );
			$t   = (string) $c['t'];
			if ( 's' === $t ) {
				$v = $strings[ (int) $c->v ] ?? '';
			} elseif ( 'inlineStr' === $t ) {
				$v = (string) $c->is->t;
				foreach ( $c->is->r as $run ) {
					$v .= (string) $run->t;
				}
			} else {
				$v = isset( $c->v ) ? (string) $c->v : '';
			}
			$cells[ $col ] = $v;
		}
		if ( $cells ) {
			$max  = max( array_keys( $cells ) );
			$line = array_fill( 0, $max + 1, '' );
			foreach ( $cells as $i => $v ) {
				$line[ $i ] = $v;
			}
			$rows[ (int) $row['r'] ] = $line;
		}
	}
	return array_values( $rows );
}

/** « AB12 » → index de colonne 27. */
function ps_xl_col_index( string $ref ): int {
	preg_match( '/^([A-Z]+)/', strtoupper( $ref ), $m );
	$n = 0;
	foreach ( str_split( $m[1] ?? 'A' ) as $ch ) {
		$n = $n * 26 + ( ord( $ch ) - 64 );
	}
	return $n - 1;
}

/** CSV (UTF-8, virgule ou point-virgule) : même résultat que ps_xl_read. */
function ps_xl_read_csv( string $path ) {
	$raw = (string) file_get_contents( $path );
	$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
	if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		return new WP_Error( 'ps_xl_format', ps__( 'الملف ليس Excel (.xlsx) ولا CSV بترميز UTF-8' ), array( 'status' => 422 ) );
	}
	$first = strtok( $raw, "\n" );
	$sep   = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';
	$fh    = fopen( 'php://temp', 'w+' ); // phpcs:ignore
	fwrite( $fh, $raw ); // phpcs:ignore
	rewind( $fh );
	$rows = array();
	while ( false !== ( $r = fgetcsv( $fh, 0, $sep, '"', '\\' ) ) ) {
		$rows[] = array_map( 'strval', $r );
	}
	fclose( $fh ); // phpcs:ignore
	return $rows;
}

/** Date d'une cellule : nombre de jours Excel, AAAA-MM-JJ, JJ/MM/AAAA ; sinon null. */
function ps_xl_date( string $v ): ?string {
	$v = trim( $v );
	if ( '' === $v ) {
		return null;
	}
	if ( is_numeric( $v ) && (float) $v > 1000 && (float) $v < 80000 ) {
		return gmdate( 'Y-m-d', (int) round( ( (float) $v - 25569 ) * 86400 ) ); // 25569 = 01/01/1970 en jours Excel
	}
	if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
	}
	if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $v, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
		return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
	}
	return null;
}

/** Texte d'une cellule : espaces multiples réduits ; nombre entier sans « .0 » ni notation 2.18E11. */
function ps_xl_text( $v ): string {
	$v = trim( preg_replace( '/\s+/u', ' ', (string) $v ) );
	if ( preg_match( '/^-?\d+(\.\d+)?(E\+?\d+)?$/i', $v ) && (float) $v == floor( (float) $v ) && abs( (float) $v ) < 1e15 ) {
		return number_format( (float) $v, 0, '.', '' );
	}
	return $v;
}

/**
 * Convertit les lignes du fichier en projets au format du prototype (+ avertissements « ce qui manque »).
 *
 * @return array|WP_Error { projects: [...], warnings: [...], rows: n, missing: { champ: nombre } }
 */
function ps_xl_to_projects( array $rows ) {
	// Ligne d'en-tête : la première qui contient « رقم » et « اسم الشركة ».
	$cols   = ps_xl_columns();
	$wanted = array();
	foreach ( $cols as $title => $key ) {
		$wanted[ ps_xl_norm_header( $title ) ] = $key;
	}
	$map = null;
	foreach ( array_slice( $rows, 0, 10, true ) as $ri => $r ) {
		$m = array();
		foreach ( $r as $i => $h ) {
			$k = $wanted[ ps_xl_norm_header( $h ) ] ?? null;
			if ( $k && ! isset( $m[ $k ] ) ) {
				$m[ $k ] = $i;
			}
		}
		if ( isset( $m['no'], $m['company'] ) ) {
			$map  = $m;
			$rows = array_slice( $rows, $ri + 1 );
			break;
		}
	}
	if ( ! $map ) {
		return new WP_Error( 'ps_xl_header', ps__( 'لم يتم العثور على عناوين الأعمدة (رقم، اسم الشركة…) في السطر الأول من الملف' ), array( 'status' => 422 ) );
	}

	$warnings = array();
	$missing  = array();
	$miss     = static function ( string $what ) use ( &$missing ) {
		$missing[ $what ] = ( $missing[ $what ] ?? 0 ) + 1;
	};
	$groups = array();
	$count  = 0;
	foreach ( $rows as $i => $r ) {
		$g = array();
		foreach ( $map as $k => $ci ) {
			$g[ $k ] = $r[ $ci ] ?? '';
		}
		$texts = array_map( 'ps_xl_text', $g );
		if ( '' === implode( '', $texts ) ) {
			continue; // ligne vide
		}
		++$count;
		$line = $i + 2; // numéro de ligne dans Excel (titre = 1)
		$no   = strtoupper( $texts['no'] ?: ( $texts['no2'] ?? '' ) );
		// Ligne incomplète (ni numéro, ni صنف, ni date, ni nom de projet) : ignorée et signalée.
		if ( '' === $no && '' === $texts['item'] && '' === $texts['recv'] && '' === $texts['name'] ) {
			$warnings[] = sprintf( ps__( 'السطر %1$d: غير مكتمل (%2$s) — تم تجاهله' ), $line, implode( ' · ', array_filter( $texts ) ) );
			continue;
		}
		$key  = '' !== $no ? $no : 'L' . $line;
		$label = '' !== $no ? $no : sprintf( ps__( 'السطر %d' ), $line );

		// Dates : réception (sinon 1er du mois indiqué par السنة / الشهر), envoi.
		$recv = ps_xl_date( (string) $g['recv'] );
		$sent = ps_xl_date( (string) ( $g['sent'] ?? '' ) );
		$year = (int) $texts['year'];
		if ( ! $recv ) {
			$miss( 'تاريخ استلام الطلب' );
			$month = (int) ( $texts['month'] ?? 0 );
			if ( $year >= 2015 && $year <= 2035 ) {
				$recv       = sprintf( '%04d-%02d-01', $year, $month >= 1 && $month <= 12 ? $month : 1 );
				$warnings[] = sprintf( ps__( '%1$s: تاريخ الاستلام فارغ ← %2$s (من السنة والشهر)' ), $label, $recv );
			}
		} elseif ( $year >= 2015 && $year <= 2035 && (int) substr( $recv, 0, 4 ) !== $year ) {
			// Faute de frappe évidente (2005 au lieu de 2025) quand le mois correspond à la colonne الشهر : corrigée.
			if ( (int) substr( $recv, 5, 2 ) === (int) ( $texts['month'] ?? 0 ) ) {
				$fixed      = $year . substr( $recv, 4 );
				$warnings[] = sprintf( ps__( '%1$s: تاريخ الاستلام %2$s صُحّح إلى %3$s (حسب عمودي السنة والشهر)' ), $label, $recv, $fixed );
				$recv       = $fixed;
			} else {
				$warnings[] = sprintf( ps__( '%1$s: سنة تاريخ الاستلام (%2$s) لا تطابق عمود السنة (%3$d) — صحّح الملف إن كان خطأ' ), $label, $recv, $year );
			}
		}
		if ( '' !== $texts['year'] && ( $year < 2015 || $year > 2035 ) ) {
			$warnings[] = sprintf( ps__( '%1$s: قيمة غير صحيحة في عمود السنة «%2$s»' ), $label, $texts['year'] );
		}
		if ( ! $sent ) {
			$miss( 'تاريخ ارسال الطلب' );
		}
		foreach ( array( 'name' => 'اسم المشروع', 'company' => 'اسم الشركة', 'item' => 'الاصناف', 'emp' => 'الموظف المتابع', 'cls' => 'التصنيف', 'person' => 'شخص المباشر', 'phone' => 'رقم شخص المباشر', 'urg' => 'درجة الاستعجال', 'place' => 'المكان' ) as $k => $title ) {
			if ( '' === ( $texts[ $k ] ?? '' ) ) {
				$miss( $title );
			}
		}

		// Statuts : « حالة العرض » du fichier = réponse du client ; « حالة المتابعة » du fichier = offre remise ou non.
		$answer  = $texts['xl_offer'] ?? '';
		$handed  = $texts['xl_fu'] ?? '';
		$n       = ps_normalize_text( $answer );
		$is      = static fn( $words ) => (bool) array_filter( (array) $words, fn( $w ) => str_contains( $n, ps_normalize_text( $w ) ) );
		$fu_txt  = $is( 'رفض' ) ? 'رفض العرض' : $answer;
		if ( $is( 'رفض' ) ) {
			$st = 'مرفوض';
		} elseif ( $is( array( 'تأكيد', 'تسليم', 'دفع', 'مسلم' ) ) ) {
			$st = 'مقبول';
		} elseif ( str_contains( ps_normalize_text( $handed ), ps_normalize_text( 'تسليم' ) ) || $is( 'انتظار الرد' ) ) {
			$st = 'مرسل';
		} elseif ( str_contains( ps_normalize_text( $handed . ' ' . $answer ), ps_normalize_text( 'معلومات' ) ) ) {
			$st = 'قيد الإعداد'; // on attend des informations : offre pas encore remise
		} elseif ( $sent ) {
			$st = 'مرسل';
		} else {
			$st = 'قيد الإعداد';
		}
		$ds = ps_normalize_text( $answer ) === ps_normalize_text( 'تم التسليم' ) ? 'تم التسليم' : '';

		if ( ! isset( $groups[ $key ] ) ) {
			$link = $texts['link'] ?? '';
			$groups[ $key ] = array(
				'no'    => $no,
				'id'    => 'xlsx:' . ( '' !== $no ? $no : md5( wp_json_encode( $texts, JSON_UNESCAPED_UNICODE ) ) ),
				'recv'  => $recv,
				'sent'  => $sent,
				'name'  => $texts['name'],
				'resp'  => $texts['emp'],
				'emp'   => $texts['emp'],
				'mid'   => $texts['mid'],
				'place' => $texts['place'],
				'urg'   => $texts['urg'],
				'imc'   => $texts['imc'],
				'imv'   => $texts['imv'],
				'cls'   => $texts['cls'],
				'fu'    => $fu_txt,
				'link'  => preg_match( '#^https?://#i', $link ) ? $link : null,
				'notes' => array(),
				'own_co' => $texts['company'],
				'dir_pe' => $texts['person'],
				'dir_ph' => $texts['phone'],
				'items' => array(),
				'xl'    => array(),
			);
		} else {
			// Lignes suivantes du même projet : on complète ce qui manque, on signale les différences.
			$p = &$groups[ $key ];
			foreach ( array( 'name' => 'name', 'own_co' => 'company', 'dir_pe' => 'person', 'dir_ph' => 'phone', 'place' => 'place', 'mid' => 'mid' ) as $pk => $tk ) {
				if ( '' === (string) $p[ $pk ] && '' !== $texts[ $tk ] ) {
					$p[ $pk ] = $texts[ $tk ];
				}
			}
			if ( '' !== $texts['company'] && ps_normalize_text( $texts['company'] ) !== ps_normalize_text( (string) $p['own_co'] ) ) {
				$warnings[] = sprintf( ps__( '%1$s: نفس الرقم مع شركة مختلفة «%2$s» — تم اعتباره صنفًا من نفس المشروع' ), $label, $texts['company'] );
			}
			// حالة المتابعة du projet = la plus avancée de ses lignes (تسليم > تأكيد > رفض > انتظار الرد > autre).
			if ( ps_xl_fu_rank( $fu_txt ) > ps_xl_fu_rank( (string) $p['fu'] ) ) {
				$p['fu'] = $fu_txt;
			}
			if ( $recv && ( ! $p['recv'] || $recv < $p['recv'] ) ) {
				$p['recv'] = $recv; // date de réception = la plus ancienne des lignes
			}
			if ( $sent && ( ! $p['sent'] || $sent > $p['sent'] ) ) {
				$p['sent'] = $sent; // date d'envoi = la plus récente
			}
			unset( $p );
		}
		if ( '' !== $texts['note'] ) {
			$groups[ $key ]['notes'][] = $texts['note'];
		}
		if ( '' !== $texts['item'] ) {
			$groups[ $key ]['items'][] = array(
				'n'   => $texts['item'],
				'emp' => $texts['emp'],
				'st'  => $st,
				'od'  => $sent,
				'on'  => '' !== $texts['note'] ? $texts['note'] : null,
				'ds'  => $ds,
			);
		}
		$groups[ $key ]['xl'][] = array_combine( array_keys( $g ), array_map( 'strval', $g ) );
	}

	$projects = array();
	foreach ( $groups as $p ) {
		if ( ! $p['recv'] ) {
			$warnings[] = sprintf( ps__( '%s: لا يوجد تاريخ استلام ولا سنة: سيأخذ تاريخ اليوم' ), $p['no'] ?: '—' );
		}
		if ( ! $p['items'] ) {
			$warnings[] = sprintf( ps__( '%s: بدون صنف (عمود الاصناف فارغ)' ), $p['no'] ?: '—' );
		}
		$p['note'] = implode( "\n", array_unique( $p['notes'] ) );
		unset( $p['notes'] );
		$projects[] = $p;
	}
	arsort( $missing );
	return array( 'projects' => $projects, 'warnings' => $warnings, 'rows' => $count, 'missing' => $missing );
}

/** Avancement d'une réponse du client (pour choisir la حالة المتابعة d'un projet à plusieurs lignes). */
function ps_xl_fu_rank( string $txt ): int {
	$n = ps_normalize_text( $txt );
	foreach ( array( 5 => 'تسليم', 4 => 'تأكيد', 3 => 'دفع', 2 => 'رفض', 1 => 'انتظار الرد' ) as $rank => $w ) {
		if ( str_contains( $n, ps_normalize_text( $w ) ) ) {
			return $rank + 1;
		}
	}
	return '' === $n ? 0 : 1;
}
