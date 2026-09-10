<?php

defined('ABSPATH') || exit;
?>
<div class="rrze-ac-denied">
    <div class="rrze-ac-panel-head">
        <h1 id="rrze-ac-error-title"><?php echo esc_html($defaultTitle); ?></h1>
    </div>

    <div class="rrze-ac-panel-content">
        <div class="rrze-ac-denied-details">
            <?php echo wp_kses_post(wpautop(esc_html($defaultMessage))); ?>
        </div>

        <?php if (!empty($loginMethods)) : ?>
            <div class="login-methods">
                <?php foreach ($loginMethods as $method) : ?>
                    <div class="<?php echo esc_attr($method['class']); ?>">
                        <h2><?php echo esc_html($method['title']); ?></h2>
                    <?php echo wp_kses_post(wpautop(esc_html($method['message']))); ?>

                    <?php if ($method['type'] === 'password') : ?>
                        <form method="post">
                            <?php wp_nonce_field('rrze_ac_submit_password_wpnonce', '_wpnonce'); ?>
                            <input type="password" name="<?php echo esc_attr($method['field_name']); ?>" value="">
                            <input type="submit" name="rrze_ac_submit_password" class="button button-primary" value="<?php echo esc_attr($method['link_text']); ?>">
                        </form>
                    <?php else : ?>
                        <a class="<?php echo esc_attr($method['type'] === 'sso' ? 'sso-link' : 'wp-link'); ?>" href="<?php echo esc_url($method['link_url']); ?>">
                            <?php echo esc_html($method['link_text']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            </div>
        <?php endif; ?>

        <?php echo wp_kses_post($contact); ?>
    </div>
</div>
