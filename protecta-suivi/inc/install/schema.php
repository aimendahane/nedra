<?php
/**
 * Schéma complet de la base (toujours la DERNIÈRE version).
 *
 * Appliqué via dbDelta() par les migrations : dbDelta crée les tables manquantes
 * et AJOUTE les colonnes / index manquants. Il ne supprime jamais de colonne ni de
 * table → les données existantes sont conservées.
 *
 * RÈGLES (voir CLAUDE.md) :
 *   - ne jamais renommer ni supprimer une colonne : en ajouter une nouvelle ;
 *   - toute modification ici doit être accompagnée d'une nouvelle migration
 *     (inc/install/migrations.php) et d'une augmentation de PS_DB_VERSION ;
 *   - format dbDelta : un champ par ligne, deux espaces après PRIMARY KEY, « KEY » et non « INDEX ».
 *
 * Les listes déroulantes sont référencées par leur id (ps_lists.id), jamais par
 * leur texte : renommer une valeur ne modifie pas les projets.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renvoie les requêtes CREATE TABLE, indexées par nom court de table.
 */
function ps_schema_sql(): array {
	global $wpdb;
	$c = $wpdb->get_charset_collate();
	$t = 'ps_table';

	$sql = array();

	// Toutes les listes déroulantes (§8) : urgence, employés, statuts, أصناف, lieux…
	$sql['lists'] = "CREATE TABLE {$t('lists')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_key varchar(40) NOT NULL,
  code varchar(40) DEFAULT NULL,
  label varchar(190) NOT NULL,
  type varchar(20) DEFAULT NULL,
  weight int(11) DEFAULT NULL,
  color varchar(20) DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_hidden tinyint(1) NOT NULL DEFAULT 0,
  is_system tinyint(1) NOT NULL DEFAULT 0,
  aliases text DEFAULT NULL,
  meta text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY list_key (list_key,sort_order),
  KEY list_code (list_key,code),
  KEY user_id (user_id)
) $c;";

	// Projets (Fiche Projet / Base Projets).
	$sql['projects'] = "CREATE TABLE {$t('projects')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_no varchar(30) NOT NULL,
  year smallint(5) unsigned NOT NULL,
  seq int(10) unsigned NOT NULL,
  month tinyint(3) unsigned NOT NULL,
  offer_no varchar(50) DEFAULT NULL,
  name varchar(255) NOT NULL,
  name_norm varchar(255) NOT NULL DEFAULT '',
  received_date date NOT NULL,
  sent_date date DEFAULT NULL,
  responsible_emp_id bigint(20) unsigned DEFAULT NULL,
  follower_emp_id bigint(20) unsigned DEFAULT NULL,
  intermediary_emp_id bigint(20) unsigned DEFAULT NULL,
  place_id bigint(20) unsigned DEFAULT NULL,
  category_id bigint(20) unsigned DEFAULT NULL,
  urgency_id bigint(20) unsigned DEFAULT NULL,
  importance_kind_id bigint(20) unsigned DEFAULT NULL,
  importance_size_id bigint(20) unsigned DEFAULT NULL,
  followup_status_id bigint(20) unsigned DEFAULT NULL,
  source_id bigint(20) unsigned DEFAULT NULL,
  loss_reason_id bigint(20) unsigned DEFAULT NULL,
  offer_amount decimal(15,3) DEFAULT NULL,
  offer_currency_id bigint(20) unsigned DEFAULT NULL,
  purchase_amount decimal(15,3) DEFAULT NULL,
  purchase_currency_id bigint(20) unsigned DEFAULT NULL,
  files_link varchar(500) DEFAULT NULL,
  note longtext DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_by bigint(20) unsigned DEFAULT NULL,
  legacy_id varchar(64) DEFAULT NULL,
  legacy_raw longtext DEFAULT NULL,
  drive_folder_id varchar(128) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY project_no (project_no),
  UNIQUE KEY year_seq (year,seq),
  UNIQUE KEY offer_no (offer_no),
  KEY received_date (received_date),
  KEY follower_emp_id (follower_emp_id),
  KEY responsible_emp_id (responsible_emp_id),
  KEY source_id (source_id),
  KEY deleted_at (deleted_at)
) $c;";

	// الأطراف المتدخلة : المالك / الشركة المنفذة / الوسيط / الشخص المباشر.
	$sql['project_parties'] = "CREATE TABLE {$t('project_parties')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL,
  company_id bigint(20) unsigned DEFAULT NULL,
  person varchar(190) DEFAULT NULL,
  email varchar(190) DEFAULT NULL,
  phone varchar(60) DEFAULT NULL,
  address varchar(255) DEFAULT NULL,
  position varchar(190) DEFAULT NULL,
  rank_title varchar(190) DEFAULT NULL,
  margin varchar(60) DEFAULT NULL,
  location varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY project_role (project_id,role),
  KEY company_id (company_id)
) $c;";

	// Annuaire des sociétés (une fiche par société, fusion possible).
	$sql['companies'] = "CREATE TABLE {$t('companies')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  name_norm varchar(255) NOT NULL DEFAULT '',
  person varchar(190) DEFAULT NULL,
  email varchar(190) DEFAULT NULL,
  phone varchar(60) DEFAULT NULL,
  address varchar(255) DEFAULT NULL,
  position varchar(190) DEFAULT NULL,
  rank_title varchar(190) DEFAULT NULL,
  website varchar(255) DEFAULT NULL,
  notes text DEFAULT NULL,
  merged_into_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name_norm (name_norm(100)),
  KEY merged_into_id (merged_into_id)
) $c;";

	// Annuaire des منافسين.
	$sql['competitors'] = "CREATE TABLE {$t('competitors')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  name_norm varchar(255) NOT NULL DEFAULT '',
  notes text DEFAULT NULL,
  merged_into_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name_norm (name_norm(100)),
  KEY merged_into_id (merged_into_id)
) $c;";

	// Concurrents présents sur un projet (§3.3).
	$sql['project_competitors'] = "CREATE TABLE {$t('project_competitors')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  competitor_id bigint(20) unsigned DEFAULT NULL,
  brand varchar(190) DEFAULT NULL,
  item_type_ids varchar(255) DEFAULT NULL,
  notes text DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id),
  KEY competitor_id (competitor_id)
) $c;";

	// الأصناف : source de Suivi Offres et Suivi Livraison.
	$sql['items'] = "CREATE TABLE {$t('items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  item_type_id bigint(20) unsigned DEFAULT NULL,
  quantity decimal(15,3) DEFAULT NULL,
  employee_id bigint(20) unsigned DEFAULT NULL,
  due_date date DEFAULT NULL,
  offer_status_id bigint(20) unsigned DEFAULT NULL,
  offer_date date DEFAULT NULL,
  not_sent_reason_id bigint(20) unsigned DEFAULT NULL,
  not_sent_note text DEFAULT NULL,
  offer_invoice_no varchar(100) DEFAULT NULL,
  offer_notes text DEFAULT NULL,
  supplier_invoice_no varchar(100) DEFAULT NULL,
  availability_id bigint(20) unsigned DEFAULT NULL,
  delivery_due_date date DEFAULT NULL,
  delivery_actual_date date DEFAULT NULL,
  delivery_status_id bigint(20) unsigned DEFAULT NULL,
  delivery_notes text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id),
  KEY employee_id (employee_id),
  KEY offer_status_id (offer_status_id),
  KEY due_date (due_date)
) $c;";

	// سجل المتابعة : chronologie des suivis.
	$sql['followups'] = "CREATE TABLE {$t('followups')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  entry_date date NOT NULL,
  author_id bigint(20) unsigned DEFAULT NULL,
  text longtext NOT NULL,
  next_date date DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY project_date (project_id,entry_date),
  KEY next_date (next_date)
) $c;";

	// Fichiers joints (stockés dans uploads/protecta-suivi/files, accès protégé).
	$sql['files'] = "CREATE TABLE {$t('files')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  original_name varchar(255) NOT NULL,
  stored_name varchar(255) NOT NULL,
  mime varchar(100) DEFAULT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  display_name varchar(255) DEFAULT NULL,
  md5 char(32) DEFAULT NULL,
  storage varchar(10) NOT NULL DEFAULT 'local',
  drive_id varchar(128) DEFAULT NULL,
  drive_error varchar(255) DEFAULT NULL,
  drive_tries smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY project_id (project_id),
  KEY storage (storage)
) $c;";

	// Journal des modifications (qui, quoi, quand).
	$sql['changelog'] = "CREATE TABLE {$t('changelog')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  action varchar(20) NOT NULL,
  entity varchar(30) NOT NULL,
  entity_id bigint(20) unsigned DEFAULT NULL,
  field varchar(60) DEFAULT NULL,
  old_value longtext DEFAULT NULL,
  new_value longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id,created_at),
  KEY entity (entity,entity_id)
) $c;";

	// Tâches libres (1.0.1, migration 2) ; message vocal voice_* (1.2.0, migration 4) : demandées par الإدارة / مسؤول مشروع à un employé
	// (« مهمة جديدة »), affichées dans مهامي. Jamais supprimées : status open → done | cancelled.
	$sql['tasks'] = "CREATE TABLE {$t('tasks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  employee_id bigint(20) unsigned NOT NULL,
  project_id bigint(20) unsigned DEFAULT NULL,
  title varchar(255) NOT NULL,
  note text DEFAULT NULL,
  due_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  created_by bigint(20) unsigned DEFAULT NULL,
  done_by bigint(20) unsigned DEFAULT NULL,
  done_at datetime DEFAULT NULL,
  done_note text DEFAULT NULL,
  voice_path varchar(255) DEFAULT NULL,
  voice_mime varchar(60) DEFAULT NULL,
  voice_size bigint(20) unsigned DEFAULT NULL,
  voice_seconds int(10) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY employee_status (employee_id,status),
  KEY created_by (created_by),
  KEY project_id (project_id)
) $c;";

	// Notifications 🔔 (1.0.2, migration 3) : une ligne par personne prévenue. Jamais supprimées.
	$sql['notifications'] = "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  project_id bigint(20) unsigned DEFAULT NULL,
  actor_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(20) NOT NULL DEFAULT 'update',
  title varchar(255) NOT NULL,
  body text DEFAULT NULL,
  created_at datetime NOT NULL,
  read_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_read (user_id,read_at),
  KEY project_id (project_id)
) $c;";

	return $sql;
}

/**
 * Applique le schéma (création / ajout de colonnes). Ne supprime rien.
 */
function ps_apply_schema(): void {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( ps_schema_sql() as $sql ) {
		dbDelta( $sql );
	}
}
