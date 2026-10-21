<?php
/**
 * Réglages généraux de l'application (option WordPress « ps_settings »).
 *
 * Règle de conservation des données : les valeurs enregistrées par la الإدارة
 * ne sont JAMAIS écrasées par une mise à jour. Les valeurs par défaut ci-dessous
 * ne servent qu'à compléter les clés manquantes (nouveaux réglages d'une
 * nouvelle version).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs par défaut. Pour ajouter un réglage : l'ajouter ici, il sera
 * automatiquement disponible sans toucher aux réglages existants.
 */
function ps_default_settings(): array {
	return array(
		// Sécurité.
		'inactivity_minutes'   => 120, // Déconnexion après 2 h sans activité.
		'login_max_attempts'   => 5,   // Tentatives échouées avant blocage…
		'login_window_minutes' => 15,  // …pendant cette durée.

		// Règles métier (utilisées à partir de l'étape 2-3).
		'followup_alert_days'   => 7,   // Alerte suivi : pas de suivi depuis N jours.
		'duplicate_window_days' => 60,  // Détection de doublons : fenêtre en jours.
		'creator_edit_hours'    => 24,  // Le créateur d'un projet peut modifier ses infos pendant N heures.

		// E-mail récapitulatif du matin (étape 5).
		'digest_enabled' => false,

		// Expéditeur et serveur SMTP (1.0.1, voir inc/mail.php). Vide = envoi par défaut de l'hébergeur.
		// Le mot de passe SMTP est gardé à part (option ps_smtp_pass), jamais dans la sauvegarde JSON.
		'mail_from_email' => '',
		'mail_from_name'  => 'متابعة المشاريع',
		'smtp_enabled'    => false,
		'smtp_host'       => '',
		'smtp_port'       => 465,
		'smtp_secure'     => 'ssl', // ssl | tls | none
		'smtp_user'       => '',

		// Telegram (1.2.0, voir inc/services/telegram.php). Le token est gardé à part (option ps_telegram_token).
		'telegram_enabled' => false,

		// Jeton secret de l'URL Cron (généré à l'installation, régénérable).
		'cron_token' => '',

		// Règles de validation (cahier §11) — modifiables dans الإعدادات (étape 5).
		// Voir inc/validation.php pour la liste des champs possibles.
		'validation' => array(
			'required'            => ps_default_required_fields(),
			'sent_after_received' => true, // تاريخ الإرسال ≥ تاريخ الاستلام
			'email_format'        => true, // الإيميل بصيغة صحيحة
			'phone_format'        => true, // الهاتف أرقام فقط (+ espaces / tirets tolérés)
		),
	);
}

/**
 * Champs obligatoires par défaut (cahier §3 + brief : مصدر الطلب obligatoire,
 * تاريخ إرسال العرض optionnel à la saisie).
 */
function ps_default_required_fields(): array {
	return array(
		'offer_no', 'responsible_emp_id', 'follower_emp_id', 'name', 'received_date',
		'place_id', 'category_id', 'urgency_id', 'source_id',
		'owner.company', 'owner.person', 'owner.phone',
		'executor.company',
		'direct.person', 'direct.phone',
		'items.min_one', 'item.item_type_id', 'item.employee_id', 'item.due_date',
	);
}

/**
 * Fusion « défauts + valeurs enregistrées » : les tableaux associatifs sont fusionnés
 * récursivement (un nouveau sous-réglage apparaît sans écraser les autres) ; les listes
 * simples (ex. champs obligatoires) enregistrées remplacent celles par défaut.
 */
function ps_merge_settings( array $defaults, array $stored ): array {
	foreach ( $stored as $key => $value ) {
		if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] )
			&& ! array_is_list( $defaults[ $key ] ) && ! array_is_list( $value ) ) {
			$defaults[ $key ] = ps_merge_settings( $defaults[ $key ], $value );
		} else {
			$defaults[ $key ] = $value;
		}
	}
	return $defaults;
}

/**
 * Lecture des réglages : valeurs enregistrées complétées par les valeurs par défaut.
 */
function ps_settings(): array {
	$stored = get_option( 'ps_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return ps_merge_settings( ps_default_settings(), $stored );
}

/**
 * Lecture d'un réglage.
 */
function ps_setting( string $key ) {
	$all = ps_settings();
	return $all[ $key ] ?? null;
}

/**
 * Enregistre un ou plusieurs réglages (fusion, n'efface pas les autres clés).
 */
function ps_update_settings( array $values ): void {
	$stored = get_option( 'ps_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	update_option( 'ps_settings', array_merge( $stored, $values ), false );
}

/**
 * Génère un nouveau jeton Cron : 64 caractères hexadécimaux (256 bits aléatoires).
 */
function ps_generate_cron_token(): string {
	return bin2hex( random_bytes( 32 ) );
}

/**
 * Régénère le jeton Cron (bouton dans الإعدادات) et renvoie le nouveau jeton.
 */
function ps_regenerate_cron_token(): string {
	$token = ps_generate_cron_token();
	ps_update_settings( array( 'cron_token' => $token ) );
	return $token;
}

/**
 * Initialise les réglages à l'installation : complète uniquement ce qui manque.
 * Appelée par les migrations ; ne remplace jamais une valeur existante.
 */
function ps_init_settings(): void {
	$stored = get_option( 'ps_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	if ( empty( $stored['cron_token'] ) ) {
		$stored['cron_token'] = ps_generate_cron_token();
	}
	update_option( 'ps_settings', ps_merge_settings( ps_default_settings(), $stored ), false );
}
