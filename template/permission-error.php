<?php

defined('ABSPATH') || exit;

$siteTitle = get_bloginfo('name');
$siteLogo = get_custom_logo();
$termsMenu = wp_get_nav_menu_object('rrze-tos-menu');
$termsMenuMarkup = '';

if ($termsMenu) {
    $termsMenuMarkup = wp_nav_menu([
        'menu' => $termsMenu,
        'container' => false,
        'depth' => 1,
        'echo' => false,
        'fallback_cb' => false,
        'menu_class' => 'rrze-ac-footer-menu',
        'menu_id' => ''
    ]);
}
?>
<!doctype html>
<html <?php echo wp_kses(get_language_attributes(), ['html' => ['dir' => true, 'lang' => true]]); ?>>
<head>
    <meta charset="<?php echo esc_attr($charset); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($title); ?></title>
    <style id="rrze-ac-frontend-css">
        <?php echo wp_kses($styles, []); ?>
    </style>
</head>
<body class="rrze-ac">
    <div class="rrze-ac-page">
        <header class="rrze-ac-site-header">
            <div class="rrze-ac-header-inner">
                <div class="rrze-ac-site-brand<?php echo $siteLogo ? ' rrze-ac-has-logo' : ''; ?>">
                    <?php echo wp_kses_post($siteLogo); ?>
                    <p class="rrze-ac-site-title"><?php echo esc_html($siteTitle); ?></p>
                </div>
            </div>
        </header>

        <main class="rrze-ac-error-main">
            <section id="rrze-ac-error-page" class="rrze-ac-error-page" aria-labelledby="rrze-ac-error-title">
                <?php echo wp_kses($content, \RRZE\AccessControl\Access::allowedErrorHtml()); ?>
            </section>
        </main>

        <footer class="rrze-ac-site-footer">
            <div class="rrze-ac-footer-inner">
                <div class="rrze-ac-footer-logo-slot" aria-hidden="true"></div>
                <nav class="rrze-ac-footer-nav" aria-label="<?php echo esc_attr__('Rechtliche Hinweise', 'rrze-ac'); ?>">
                    <?php if ($termsMenuMarkup) : ?>
                        <?php echo wp_kses_post($termsMenuMarkup); ?>
                    <?php else : ?>
                        <a href="<?php echo esc_url(home_url('/impressum')); ?>"><?php esc_html_e('Impressum', 'rrze-ac'); ?></a>
                        <a href="<?php echo esc_url(home_url('/datenschutz')); ?>"><?php esc_html_e('Datenschutz', 'rrze-ac'); ?></a>
                        <a href="<?php echo esc_url(home_url('/barrierefreiheit')); ?>"><?php esc_html_e('Barrierefreiheit', 'rrze-ac'); ?></a>
                    <?php endif; ?>
                </nav>
            </div>
        </footer>
    </div>
</body>
</html>
