<?php
/**
 * Contrôle du parcours réel du client avec le zip (développement uniquement) :
 *   1 = WordPress neuf + « Téléverser un thème » + activation ; 2 = ajout de données ;
 *   3 = ré-upload du zip en mode « remplacer » (mise à jour) ; 4 = vérification que tout est conservé.
 * Utilise une copie /srv/wp2 (préfixe wpzip_) et le zip copié en /srv/pkg.zip.
 *   for p in 1 2 3 4; do php tests/zip-update-flow.php $p; done
 */
// Phase 1 : installation WP neuve + upload du zip + activation. Phase 2 : données. Phase 3 : ré-upload (remplacement). Phase 4 : contrôle.
$phase = $argv[1];
$_SERVER['HTTP_HOST']='localhost:8080'; $_SERVER['REQUEST_URI']='/';
if ($phase==='1') define('WP_INSTALLING', true);
require '/srv/wp2/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php'; require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
global $wpdb;
$upload = function($overwrite){
  $up = new Theme_Upgrader(new Automatic_Upgrader_Skin());
  $r = $up->install('/srv/pkg.zip', ['overwrite_package'=>$overwrite]);
  return $r===true ? 'OK' : (is_wp_error($r) ? $r->get_error_message() : var_export($r,true).' '.implode(' | ',$up->skin->get_upgrade_messages()));
};
if ($phase==='1') {
  foreach ($wpdb->get_col("SHOW TABLES LIKE 'wpzip\\_%'") as $t) $wpdb->query("DROP TABLE `$t`");
  wp_cache_flush();
  wp_install('Zip Test','admin','a@example.com',false,'','x-'.wp_generate_password());
  echo "upload neuf: ", $upload(false), "\n";
  switch_theme('protecta-suivi'); echo "activé: ", get_option('stylesheet'), "\n";
}
if ($phase==='2') {
  echo "db_version après activation: ", get_option('ps_db_version'), "\n";
  $now=ps_now();
  $wpdb->insert(ps_table('projects'),['project_no'=>'PRJ-2026-007','year'=>2026,'seq'=>7,'month'=>10,'name'=>'مشروع حقيقي','received_date'=>'2026-10-05','created_at'=>$now,'updated_at'=>$now]);
  $wpdb->update(ps_table('lists'),['label'=>'وفاء ح.'],['list_key'=>'employee','label'=>'وفاء حامدي']);
  ps_update_settings(['inactivity_minutes'=>180]);
  file_put_contents(ps_storage_dir('files').'/doc.pdf','%PDF test');
  echo "données ajoutées\n";
}
if ($phase==='3') { echo "ré-upload (remplacement): ", $upload(true), "\n"; }
if ($phase==='4') {
  echo "thème actif: ", get_option('stylesheet'), " v", wp_get_theme()->get('Version'), "\n";
  echo "projet conservé: ", $wpdb->get_var("SELECT name FROM ".ps_table('projects')." WHERE project_no='PRJ-2026-007'"), "\n";
  echo "liste renommée conservée: ", $wpdb->get_var("SELECT label FROM ".ps_table('lists')." WHERE label='وفاء ح.'"), "\n";
  echo "réglage conservé: ", ps_setting('inactivity_minutes'), "\n";
  echo "fichier conservé: ", file_exists(ps_storage_dir('files').'/doc.pdf') ? 'oui':'NON', "\n";
  echo "nb valeurs de listes: ", $wpdb->get_var("SELECT COUNT(*) FROM ".ps_table('lists')), "\n";
}
