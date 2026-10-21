<?php
/**
 * Export CSV / Excel (الإدارة) — Base Projets, Suivi Offres, Suivi Livraison.
 *
 *   - CSV : UTF-8 avec BOM (s'ouvre correctement dans Excel), séparateur virgule ;
 *   - Excel : vrai fichier .xlsx (feuille de droite à gauche) produit avec ZipArchive,
 *     sans bibliothèque externe ; si ZipArchive manque chez l'hébergeur → CSV.
 * Les filtres de la page sont appliqués (on exporte ce qui est affiché, toutes pages confondues).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lignes de Base Projets (en-têtes + valeurs lisibles).
 */
function ps_export_projects_table( array $args ): array {
	$args['page']    = 1;
	$args['perPage'] = 100000;
	$rows            = ps_projects_list( $args )['rows'];
	$lab             = fn( $id ) => $id ? ps_list_label_t( $id ) : '';
	$code            = fn( $id ) => $id ? ( ps_list_code( $id ) ?: ps_list_label_t( $id ) ) : '';
	$offer           = fn( $c ) => ps_list_label_t( ps_list_id_by_code( 'offer_status', $c ) );
	$months          = array( ps__( 'جانفي' ), ps__( 'فيفري' ), ps__( 'مارس' ), ps__( 'أفريل' ), ps__( 'ماي' ), ps__( 'جوان' ), ps__( 'جويلية' ), ps__( 'أوت' ), ps__( 'سبتمبر' ), ps__( 'أكتوبر' ), ps__( 'نوفمبر' ), ps__( 'ديسمبر' ) );
	$headers         = array( ps__( 'السنة' ), ps__( 'رقم' ), ps__( 'المكان' ), ps__( 'درجة الاستعجال' ), ps__( 'الأهمية كصنف' ), ps__( 'الأهمية كحجم' ), ps__( 'الشهر' ), ps__( 'التصنيف' ), ps__( 'اسم الشركة' ), ps__( 'شخص المباشر' ), ps__( 'رقم شخص المباشر' ), ps__( 'اسم المشروع' ), ps__( 'الأصناف' ), ps__( 'تاريخ استلام الطلب' ), ps__( 'تاريخ إرسال الطلب' ), ps__( 'الموظف المتابع' ), ps__( 'الموظف الوسيط' ), ps__( 'رقم المشروع' ), ps__( 'حالة المتابعة' ), ps__( 'حالة العرض' ), 'NOTE', ps__( 'رابط ملف المشاريع' ), ps__( 'رقم العرض' ), ps__( 'مصدر الطلب' ), ps__( 'الأولوية' ), ps__( 'مبلغ العرض' ), ps__( 'العملة' ) );
	$out             = array();
	foreach ( $rows as $r ) {
		$out[] = array(
			$r['receivedYear'], $r['seq'], $code( $r['placeId'] ), $code( $r['urgencyId'] ), $lab( $r['importanceKindId'] ), $lab( $r['importanceSizeId'] ),
			$months[ $r['month'] - 1 ] ?? '', $lab( $r['categoryId'] ), $r['companyName'], $r['directPerson'], $r['directPhone'], $r['name'],
			implode( ps__( '، ' ), array_map( 'ps_list_label_t', $r['itemTypeIds'] ) ), $r['receivedDate'], $r['sentDate'], $lab( $r['followerEmpId'] ),
			$lab( $r['intermediaryEmpId'] ), $r['projectNo'], $lab( $r['followupStatusId'] ), $offer( $r['offerStatus'] ), $r['note'], $r['filesLink'],
			$r['offerNo'], $lab( $r['sourceId'] ), $r['priority'], $r['offerAmount'], $code( $r['offerCurrencyId'] ),
		);
	}
	return array( $headers, $out );
}

/**
 * Lignes de Suivi Offres (view=offers) ou Suivi Livraison (view=delivery).
 */
function ps_export_items_table( array $args, int $user_id ): array {
	$args['page']    = 1;
	$args['perPage'] = 100000;
	$rows            = ps_items_list( $args, $user_id )['rows'];
	$lab             = fn( $id ) => $id ? ps_list_label_t( $id ) : '';
	if ( 'delivery' === ( $args['view'] ?? '' ) ) {
		$headers = array( ps__( 'رقم المشروع' ), ps__( 'اسم المشروع' ), ps__( 'الصنف' ), ps__( 'فاتورة العرض' ), ps__( 'فاتورة المورد' ), ps__( 'المتوفر' ), ps__( 'أجل التسليم المطلوب' ), ps__( 'أجل التسليم الفعلي' ), ps__( 'تأخير (أيام)' ), ps__( 'الحالة' ), ps__( 'ملاحظات' ) );
		$out     = array_map( fn( $r ) => array( $r['projectNo'], $r['projectName'], $lab( $r['itemTypeId'] ), $r['offerInvoiceNo'], $r['supplierInvoiceNo'], $lab( $r['availabilityId'] ), $r['deliveryDueDate'], $r['deliveryActualDate'], $r['computed']['delayDays'], ps_list_label_t( ps_list_id_by_code( 'delivery_status', (string) $r['computed']['deliveryDisplay'] ) ), $r['deliveryNotes'] ), $rows );
	} else {
		$headers = array( ps__( 'رقم المشروع' ), ps__( 'اسم المشروع' ), ps__( 'الشركة' ), ps__( 'الصنف' ), ps__( 'الكمية' ), ps__( 'الموظف المسؤول' ), ps__( 'الأجل المطلوب' ), ps__( 'تاريخ تقديم العرض' ), ps__( 'الحالة' ), ps__( 'سبب عدم الإرسال' ), ps__( 'رقم فاتورة العرض' ), ps__( 'ملاحظات' ), ps__( 'لازم قرار' ) );
		$out     = array_map( fn( $r ) => array( $r['projectNo'], $r['projectName'], $r['companyName'], $lab( $r['itemTypeId'] ), $r['quantity'], $lab( $r['employeeId'] ), $r['dueDate'], $r['offerDate'], $lab( $r['offerStatusId'] ), $lab( $r['notSentReasonId'] ), $r['offerInvoiceNo'], $r['offerNotes'], $r['computed']['late'] ? ps__( 'نعم' ) : '' ), $rows );
	}
	return array( $headers, $out );
}

/**
 * Contenu CSV (UTF-8 avec BOM).
 */
function ps_csv( array $headers, array $rows ): string {
	$h = fopen( 'php://temp', 'w+' );
	fwrite( $h, "\xEF\xBB\xBF" );
	fputcsv( $h, $headers, ',', '"', '' );
	foreach ( $rows as $r ) {
		// Une cellule commençant par = + - @ serait interprétée comme formule par Excel : on la neutralise.
		fputcsv( $h, array_map( fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v, $r ), ',', '"', '' );
	}
	rewind( $h );
	$csv = stream_get_contents( $h );
	fclose( $h );
	return $csv;
}

/**
 * Contenu d'un fichier .xlsx minimal (une feuille, en-têtes en gras, de droite à gauche).
 *
 * @return string|null null si ZipArchive est indisponible.
 */
function ps_xlsx( array $headers, array $rows, string $sheet = 'Projets' ): ?string {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return null;
	}
	$x    = fn( $s ) => htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	$col  = static function ( int $i ): string {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	};
	$cell = static function ( $v, string $ref, bool $bold = false ) use ( $x ) {
		$st = $bold ? ' s="1"' : '';
		if ( is_int( $v ) || is_float( $v ) ) {
			return '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
		}
		if ( null === $v || '' === $v ) {
			return '';
		}
		return '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . $x( $v ) . '</t></is></c>';
	};
	$data = '';
	foreach ( array_merge( array( $headers ), $rows ) as $ri => $row ) {
		$data .= '<row r="' . ( $ri + 1 ) . '">';
		foreach ( array_values( $row ) as $ci => $v ) {
			$data .= $cell( $v, $col( $ci ) . ( $ri + 1 ), 0 === $ri );
		}
		$data .= '</row>';
	}
	$files = array(
		'[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
		'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
		'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x( $sheet ) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
		'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
		'xl/styles.xml'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>',
		'xl/worksheets/sheet1.xml'   => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>' . $data . '</sheetData></worksheet>',
	);
	$tmp = tempnam( get_temp_dir(), 'ps-xlsx' ); // (wp_tempnam n'existe que dans wp-admin)
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
		return null;
	}
	foreach ( $files as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
	$bin = file_get_contents( $tmp );
	wp_delete_file( $tmp );
	return $bin;
}

/**
 * Envoie un fichier au navigateur et termine la requête.
 */
function ps_send_download( string $content, string $filename, string $mime ): void {
	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . strlen( $content ) );
	// Nom simple (ASCII) + nom UTF-8 : compatible avec tous les navigateurs.
	$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '-', remove_accents( $filename ) );
	header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
	header( 'X-Content-Type-Options: nosniff' );
	echo $content; // phpcs:ignore
	exit;
}
