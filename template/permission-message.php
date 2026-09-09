<?php

defined('ABSPATH') || exit;

echo $styles;
?>
<div class="rrze-ac-denied">
    <h3><?php echo esc_html($defaultTitle); ?></h3>

    <?php if (!empty($loginMethods)) : ?>
        <div class="login-methods">
            <?php foreach ($loginMethods as $method) : ?>
                <div class="<?php echo esc_attr($method['class']); ?>">
                    <?php if ($method['type'] === 'login' || $method['type'] === 'sso') : ?>
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M480-120v-80h280v-560H480v-80h280q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H480Zm-80-160-55-58 102-102H120v-80h327L345-622l55-58 200 200-200 200Z"/></svg>
                    <?php else : ?>
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M80-200v-80h800v80H80Zm46-242-52-30 34-60H40v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Zm320 0-52-30 34-60h-68v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Zm320 0-52-30 34-60h-68v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Z"/></svg>
                    <?php endif; ?>

                    <h3><?php echo esc_html($method['title']); ?></h3>
                    <?php echo wpautop(esc_html($method['message'])); ?>

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

            <div class="rrze-ac-denied-details">
                <?php echo wpautop(esc_html($defaultMessage)); ?>
                <?php echo $contact; ?>
            </div>
        </div>
    <?php else : ?>
        <?php echo wpautop(esc_html($defaultMessage)); ?>
        <a class="wp-link" href="<?php echo esc_url($defaultLogin['link_url']); ?>">
            <?php echo esc_html($defaultLogin['link_text']); ?>
        </a>
        <?php echo $contact; ?>
    <?php endif; ?>
</div>
