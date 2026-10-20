<?php
/**
 * Page de connexion personnalisée (logo, arabe, RTL).
 * Le formulaire est traité par ps_handle_login_post() dans inc/auth.php.
 */

defined( 'ABSPATH' ) || exit;

$ps_messages = array(
	'failed'    => 'اسم المستخدم أو كلمة السر غير صحيحين.',
	'locked'    => sprintf( 'محاولات كثيرة. حاول مرة أخرى بعد %d دقيقة.', (int) ps_setting( 'login_window_minutes' ) ),
	'expired'   => 'انتهت الجلسة بسبب عدم النشاط. الرجاء تسجيل الدخول من جديد.',
	'loggedout' => 'تم تسجيل الخروج.',
	'disabled'  => 'هذا الحساب معطّل. اتصل بالإدارة.',
);
$ps_msg_key = sanitize_key( $_GET['ps_msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
$ps_msg     = $ps_messages[ $ps_msg_key ] ?? '';
$ps_is_info = in_array( $ps_msg_key, array( 'loggedout', 'expired' ), true );
?><!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body class="ps-login-page">
<main class="ps-login">
	<section class="ps-login__hero" aria-hidden="true">
		<div class="ps-login__orbs"><span></span><span></span><span></span></div>
		<div class="ps-login__hero-inner">
			<p class="ps-login__kicker">Protecta Group · AMC Group</p>
			<p class="ps-login__headline">كل مشاريعك وعروضك<br>في مكان واحد.</p>
			<ul class="ps-login__points">
				<li>متابعة العروض والتسليم لحظة بلحظة</li>
				<li>مهام الفريق والتنبيهات في صفحة واحدة</li>
				<li>مؤشرات Recap لاتخاذ القرار</li>
			</ul>
		</div>
	</section>

	<section class="ps-login__panel">
		<div class="ps-login__card">
			<div class="ps-login__brand">
				<img src="<?php echo esc_url( PS_URL . '/assets/img/logo-banner.png' ); ?>" alt="Protecta Group — AMC Group" class="ps-login__banner">
			</div>
			<h1 class="ps-login__title">متابعة المشاريع والعروض</h1>
			<p class="ps-login__subtitle">سجّل الدخول للمتابعة</p>

			<?php if ( $ps_msg ) : ?>
				<p class="ps-alert <?php echo $ps_is_info ? 'ps-alert--info' : 'ps-alert--error'; ?>" role="alert"><?php echo esc_html( $ps_msg ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="ps-login__form" autocomplete="on">
				<input type="hidden" name="ps_action" value="login">
				<?php wp_nonce_field( 'ps_login', 'ps_login_nonce' ); ?>
				<label class="ps-field">
					<span class="ps-field__label">اسم المستخدم</span>
					<input type="text" name="log" required autocomplete="username" autocapitalize="none" spellcheck="false" dir="auto">
				</label>
				<label class="ps-field">
					<span class="ps-field__label">كلمة السر</span>
					<input type="password" name="pwd" required autocomplete="current-password">
				</label>
				<button type="submit" class="ps-btn ps-btn--primary ps-btn--block ps-btn--lg">دخول</button>
			</form>
		</div>
		<footer class="ps-login__footer">
			<img src="<?php echo esc_url( PS_URL . '/assets/img/logo-marques.png' ); ?>" alt="RELLI, inim, VDS, legrand, ELAN, FADINI" class="ps-brands">
			<p>شركة بروتكتا الحديثة لاستيراد الأجهزة والمواد الكهربائية</p>
		</footer>
	</section>
</main>
<?php wp_footer(); ?>
</body>
</html>
