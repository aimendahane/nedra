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
<html lang="ar" dir="rtl" data-theme="auto">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body class="ps-login-page">
<main class="ps-login"><div class="ps-login__card">
	<h1 class="ps-login__title">لا توجد صلاحية</h1>
	<p>حسابك غير مرتبط بالتطبيق. اتصل بالإدارة.</p>
	<a class="ps-btn ps-btn--primary ps-btn--block" href="<?php echo esc_url( wp_logout_url( add_query_arg( 'ps_msg', 'loggedout', home_url( '/' ) ) ) ); ?>">تسجيل الخروج</a>
</div></main>
<?php wp_footer(); ?>
</body></html>
	<?php
	return;
endif;
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?php echo esc_attr( $ps_user['prefs']['theme'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#385B9A">
<?php wp_head(); ?>
</head>
<body class="ps-app">
<a class="ps-skip" href="#ps-main">انتقل إلى المحتوى</a>

<header class="ps-header">
	<button type="button" class="ps-header__menu" id="ps-menu-toggle" aria-controls="ps-nav" aria-expanded="false" aria-label="القائمة">
		<span aria-hidden="true">☰</span>
	</button>
	<a class="ps-header__brand" href="#/taches">
		<span class="ps-logo-plate"><img src="<?php echo esc_url( PS_URL . '/assets/img/logo.png' ); ?>" alt="Protecta Group" class="ps-logo"></span>
		<span class="ps-header__title">متابعة المشاريع والعروض</span>
	</a>
	<div class="ps-header__user">
		<div class="ps-bell">
			<button type="button" class="ps-icon-btn ps-bell__btn" id="ps-bell" aria-haspopup="true" aria-expanded="false" aria-controls="ps-bell-panel" aria-label="الإشعارات" title="الإشعارات"><span aria-hidden="true">🔔</span><span class="ps-bell__count" id="ps-bell-count" hidden></span></button>
			<div class="ps-bell__panel" id="ps-bell-panel" hidden></div>
		</div>
		<button type="button" class="ps-icon-btn" id="ps-theme-toggle" aria-label="الوضع الليلي" title="الوضع الليلي / النهاري"><span aria-hidden="true" id="ps-theme-icon">🌓</span></button>
		<span class="ps-user">
			<span class="ps-user__name"><?php echo esc_html( $ps_user['name'] ); ?></span>
			<span class="ps-user__role"><?php echo esc_html( $ps_user['role'] ); ?></span>
		</span>
		<button type="button" class="ps-btn ps-btn--ghost" id="ps-logout">خروج</button>
	</div>
</header>

<div class="ps-layout">
	<nav class="ps-nav" id="ps-nav" aria-label="القائمة الرئيسية"></nav>
	<div class="ps-nav-backdrop" id="ps-nav-backdrop" hidden></div>
	<main class="ps-main" id="ps-main" tabindex="-1">
		<div class="ps-loading" role="status">جاري التحميل…</div>
	</main>
</div>

<footer class="ps-footer">
	<img src="<?php echo esc_url( PS_URL . '/assets/img/logo-marques.png' ); ?>" alt="RELLI, inim, VDS, legrand, ELAN, FADINI" class="ps-brands">
	<p class="ps-footer__meta">Protecta Group · v<?php echo esc_html( PS_VERSION ); ?></p>
</footer>

<div class="ps-toasts" id="ps-toasts" aria-live="polite"></div>
<?php wp_footer(); ?>
</body>
</html>
