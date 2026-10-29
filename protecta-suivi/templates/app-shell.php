<?php
/**
 * Coquille de l'application : en-tête, menu, zone de page, pied de page.
 * Le contenu des pages est construit en JavaScript (assets/js/pages/*.js),
 * le menu par assets/js/core/router.js.
 */

defined( 'ABSPATH' ) || exit;

$ps_user = ps_current_user_payload();

if ( ! current_user_can( 'ps_access' ) ) :
	// Compte WordPress sans rôle de l'application.
	?><!DOCTYPE html>
<html <?php echo ps_html_lang_attrs(); // phpcs:ignore ?> data-theme="auto">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body class="ps-login-page">
<main class="ps-login"><div class="ps-login__card">
	<h1 class="ps-login__title"><?php ps_e( 'لا توجد صلاحية' ); ?></h1>
	<p><?php ps_e( 'حسابك غير مرتبط بالتطبيق. اتصل بالإدارة.' ); ?></p>
	<a class="ps-btn ps-btn--primary ps-btn--block" href="<?php echo esc_url( wp_logout_url( add_query_arg( 'ps_msg', 'loggedout', home_url( '/' ) ) ) ); ?>"><?php ps_e( 'تسجيل الخروج' ); ?></a>
</div></main>
<?php wp_footer(); ?>
</body></html>
	<?php
	return;
endif;
?><!DOCTYPE html>
<html <?php echo ps_html_lang_attrs(); // phpcs:ignore ?> data-theme="<?php echo esc_attr( $ps_user['prefs']['theme'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#385B9A">
<?php wp_head(); ?>
</head>
<body class="ps-app">
<a class="ps-skip" href="#ps-main"><?php ps_e( 'انتقل إلى المحتوى' ); ?></a>

<header class="ps-header">
	<button type="button" class="ps-header__menu" id="ps-menu-toggle" aria-controls="ps-nav" aria-expanded="false" aria-label="<?php ps_e( 'القائمة' ); ?>">
		<svg class="ps-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
	</button>
	<a class="ps-header__brand" href="#/taches">
		<span class="ps-logo-plate"><img src="<?php echo esc_url( PS_URL . '/assets/img/logo.png' ); ?>" alt="Protecta Group" class="ps-logo"></span>
		<span class="ps-header__title"><?php ps_e( 'متابعة المشاريع والعروض' ); ?></span>
	</a>
	<div class="ps-header__user">
		<div class="ps-bell">
			<button type="button" class="ps-icon-btn ps-bell__btn" id="ps-bell" aria-haspopup="true" aria-expanded="false" aria-controls="ps-bell-panel" aria-label="<?php ps_e( 'الإشعارات' ); ?>" title="<?php ps_e( 'الإشعارات' ); ?>"><svg class="ps-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><span class="ps-bell__count" id="ps-bell-count" hidden></span></button>
			<div class="ps-bell__panel" id="ps-bell-panel" hidden></div>
		</div>
		<?php /* 1.12.0 : une seule « قائمة الحساب » (langue, mode nuit, خروج) au lieu de petites icônes sans nom. */ ?>
		<div class="ps-usermenu">
			<button type="button" class="ps-user ps-usermenu__btn" id="ps-usermenu" aria-haspopup="true" aria-expanded="false" aria-controls="ps-usermenu-panel" title="<?php ps_e( 'قائمة الحساب' ); ?>">
				<?php /* 1.12.0 : icône de personne (une lettre arabe isolée, ex. « و », ressemblait à une lettre latine). */ ?>
				<span class="ps-avatar" aria-hidden="true"><svg class="ps-svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
				<span class="ps-user__text">
					<span class="ps-user__name"><?php echo esc_html( $ps_user['name'] ); ?></span>
					<span class="ps-user__role"><?php echo esc_html( $ps_user['role'] ); ?></span>
				</span>
				<svg class="ps-svg ps-usermenu__caret" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>
			</button>
			<div class="ps-usermenu__panel" id="ps-usermenu-panel" hidden>
				<div class="ps-usermenu__who"><strong><?php echo esc_html( $ps_user['name'] ); ?></strong><span><?php echo esc_html( $ps_user['role'] ); ?></span></div>
				<div class="ps-usermenu__label"><?php ps_e( 'اللغة' ); ?></div>
				<div class="ps-usermenu__langs" id="ps-lang-panel">
					<?php foreach ( ps_languages() as $ps_code => $ps_l ) : ?>
						<button type="button" data-lang="<?php echo esc_attr( $ps_code ); ?>" lang="<?php echo esc_attr( $ps_code ); ?>" <?php echo ps_lang() === $ps_code ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $ps_l['label'] ); ?></button>
					<?php endforeach; ?>
				</div>
				<button type="button" class="ps-usermenu__item" id="ps-theme-toggle"><span aria-hidden="true" id="ps-theme-icon" class="ps-icon-slot"></span><span id="ps-theme-label"><?php ps_e( 'الوضع الليلي' ); ?></span></button>
				<button type="button" class="ps-usermenu__item ps-usermenu__item--danger ps-logout" id="ps-logout"><svg class="ps-svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span class="ps-logout__label"><?php ps_e( 'تسجيل الخروج' ); ?></span></button>
			</div>
		</div>
	</div>
</header>

<div class="ps-layout">
	<nav class="ps-nav" id="ps-nav" aria-label="<?php ps_e( 'القائمة الرئيسية' ); ?>"></nav>
	<div class="ps-nav-backdrop" id="ps-nav-backdrop" hidden></div>
	<main class="ps-main" id="ps-main" tabindex="-1">
		<div class="ps-loading" role="status"><?php ps_e( 'جاري التحميل…' ); ?></div>
	</main>
</div>

<?php /* 1.12.0 : barre du bas sur téléphone (remplie par router.js). */ ?>
<nav class="ps-tabbar" id="ps-tabbar" aria-label="<?php ps_e( 'التنقل السريع' ); ?>"></nav>

<footer class="ps-footer">
	<img src="<?php echo esc_url( PS_URL . '/assets/img/logo-marques.png' ); ?>" alt="RELLI, inim, VDS, legrand, ELAN, FADINI" class="ps-brands">
	<p class="ps-footer__meta">Protecta Group · v<?php echo esc_html( PS_VERSION ); ?></p>
</footer>

<div class="ps-toasts" id="ps-toasts" aria-live="polite"></div>
<?php wp_footer(); ?>
</body>
</html>
