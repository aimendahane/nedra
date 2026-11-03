<?php
// Rend le jeu de démonstration réaliste pour la vidéo (noms, numéros d'offre, factures, suivis). Site de TEST seulement.
$_SERVER['HTTP_HOST'] = 'localhost:8081'; $_SERVER['REQUEST_URI'] = '/';
require '/srv/wp/wp-load.php';
global $wpdb;
$names = array(
 'D01'=>'إنارة مجمع النور التجاري — طرابلس','D02'=>'شبكة ألياف لمقر مؤسسة الأفق','D03'=>'منظومة UPS لمستشفى الهدى',
 'D04'=>'خزائن شبكات لمبنى البناء الحديث','D05'=>'إنارة واجهات فندق الأفق — بنغازي','D06'=>'حساسات إنذار حريق — مخازن الأمان',
 'D07'=>'كابلات وخزائن — كلية الهندسة','D08'=>'UPS لمحطة الأمان — درنة','D09'=>'منزل ذكي وشبكة — جناح العمليات',
 'D10'=>'منظومة إطفاء آلي — مصنع النور','D11'=>'إنارة شوارع بلدية الكفرة','D12'=>'كابلات كهرباء — مشروع الإسكان',
 'D13'=>'تجهيز غرف الخوادم — وزارة الصحة','D14'=>'UPS مركز البيانات — الأمان','D15'=>'إنذار ودخان — مكتبة الجامعة',
 'D16'=>'كابلات توزيع — مستشفى المدينة','D17'=>'إنارة ملعب الكفرة البلدي','D18'=>'إنارة RELLI — معرض النور',
 'D19'=>'شبكة ومنزل ذكي — عيادات الهدى','D20'=>'خزائن Rack — مستودع البناء','D21'=>'UPS للبرج الإداري — النور',
 'D22'=>'كابلات مبنى الإدارة الجامعية','D23'=>'إنارة مكاتب وزارة الصحة','D24'=>'حساسات حركة — فرع الأمان',
 'E01'=>'إنارة مركز النور 2025','E02'=>'شبكة فرع الأفق 2025','E03'=>'UPS قسم الطوارئ 2025','E04'=>'إنارة مبنى الوزارة 2025',
 'E05'=>'خزائن شبكات الأمان 2025','E06'=>'تجهيزات مستودع البناء الحديث',
);
$pt = ps_table( 'projects' );
$n = 0;
foreach ( $wpdb->get_results( "SELECT id, name, project_no, year, seq FROM $pt", ARRAY_A ) as $p ) {
	if ( ! preg_match( '/مشروع ([DE]\d\d)/u', $p['name'], $m ) || empty( $names[ $m[1] ] ) ) continue;
	$wpdb->update( $pt, array( 'name' => $names[ $m[1] ], 'name_norm' => ps_normalize_text( $names[ $m[1] ] ), 'offer_no' => sprintf( 'OFR-%d-%03d', $p['year'], 40 + (int) $p['seq'] ), 'note' => 'طلب عرض أسعار حسب الكميات المرفقة.' ), array( 'id' => $p['id'] ) );
	++$n;
}
// Factures sur quelques offres envoyées / acceptées.
$admin = (int) get_user_by( 'login', 'idara' )->ID;
wp_set_current_user( $admin );
$k = 0;
foreach ( $wpdb->get_results( "SELECT DISTINCT p.* FROM $pt p JOIN " . ps_table( 'items' ) . " i ON i.project_id = p.id JOIN " . ps_table( 'lists' ) . " l ON l.id = i.offer_status_id WHERE l.code IN ('accepted','sent') AND p.year = 2026 ORDER BY p.seq LIMIT 6", ARRAY_A ) as $p ) {
	++$k;
	$sent = gmdate( 'Y-m-d', strtotime( '2026-10-19 -' . ( 4 + 5 * $k ) . ' days' ) );
	ps_invoice_create( $p, array( 'invoiceNo' => sprintf( 'FA-2026-%03d', 100 + $k ), 'sentDate' => $sent, 'answer' => $k % 3 === 0 ? 'yes' : 'pending', 'amount' => 4000 + 1500 * $k, 'currencyId' => ps_list_default_id( 'currency' ) ), $admin );
}
echo "noms: $n, factures: $k\n";
