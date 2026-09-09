<?php
/**
 * 站点增强模块 —— 合并 Back To Top Button + Maintenance + 特色图片 三个小功能
 *
 * 每个子功能独立开关，互不耦合。
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class Site_Enhance extends Module_Base {

    /** @var string 模块 ID */
    const MODULE_ID = 'site-enhance';

    /** @var string SMTP 测试失败时的错误信息 */
    private $_smtp_test_error = '';

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return self::MODULE_ID;
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __('Site Enhancement', 'dreamanual-toolkit' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Back-to-top button, maintenance mode, featured image management, comment avatar, SMTP mail — each with independent toggle.', 'dreamanual-toolkit' );
    }

    /**
     * 获取设置页 URL
     */
    public function get_settings_url(): string {
        return admin_url( 'admin.php?page=drea-se' );
    }

    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // 管理菜单 & 资源
        $this->on( 'admin_menu', [ $this, 'add_admin_menu' ] );
        $this->on( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX
        $this->on( 'wp_ajax_drea_se_save_settings', [ $this, 'ajax_save_settings' ] );
        $this->on( 'wp_ajax_drea_se_get_settings', [ $this, 'ajax_get_settings' ] );

        // ─── 回到顶部 ───
        if ( $this->get_option( 'btt_enabled', false ) ) {
            $this->on( 'wp_footer', [ $this, 'render_back_to_top' ] );
            $this->on( 'wp_enqueue_scripts', [ $this, 'enqueue_btt_assets' ] );
        }

        // ─── 维护模式 ───
        if ( $this->get_option( 'maintenance_enabled', false ) ) {
            $this->on( 'template_redirect', [ $this, 'maintenance_mode' ] );
        }

        // ─── 特色图片筛选器 ───
        if ( $this->get_option( 'feat_img_enabled', false ) ) {
            $this->on( 'restrict_manage_posts', [ $this, 'add_feat_img_filter' ] );
            $this->filter( 'parse_query', [ $this, 'parse_feat_img_filter' ] );
        }

        // ─── 特色图片列 ───
        if ( $this->get_option( 'feat_img_col_enabled', false ) ) {
            $this->filter( 'manage_posts_columns', [ $this, 'add_feat_img_column' ] );
            $this->on( 'manage_posts_custom_column', [ $this, 'render_feat_img_column' ], 10, 2 );
        }

        // ─── 默认特色图片 ───
        if ( $this->get_option( 'default_feat_img_enabled', false ) && $this->get_option( 'default_feat_img_id', 0 ) ) {
            $this->filter( 'post_thumbnail_html', [ $this, 'default_featured_image' ], 10, 5 );
        }

        // ─── 摘要快速编辑 ───
        if ( $this->get_option( 'quickedit_excerpt_enabled', false ) ) {
            $this->filter( 'manage_posts_columns', [ $this, 'add_excerpt_data_column' ] );
            $this->on( 'manage_posts_custom_column', [ $this, 'render_excerpt_data_column' ], 10, 2 );
            $this->on( 'quick_edit_custom_box', [ $this, 'quick_edit_excerpt_box' ], 10, 2 );
            $this->on( 'admin_head-edit.php', [ $this, 'quick_edit_excerpt_script' ] );
        }

        // ─── SMTP 发信 ───
        if ( $this->get_option( 'smtp_enabled', false ) ) {
            $this->on( 'phpmailer_init', [ $this, 'configure_smtp' ] );
            $this->filter( 'wp_mail_from', [ $this, 'smtp_mail_from' ] );
            $this->filter( 'wp_mail_from_name', [ $this, 'smtp_mail_from_name' ] );
        }

        // ─── 评论头像优化 ───
        if ( $this->get_option( 'avatar_fallback_enabled', false ) ) {
            $this->filter( 'get_avatar_url', [ $this, 'avatar_optimize_url' ], 10, 3 );
        }

        // ─── SMTP 测试发信 AJAX ───
        $this->on( 'wp_ajax_drea_se_smtp_test', [ $this, 'ajax_smtp_test' ] );
    }

    /**
     * {@inheritdoc}
     */
    public function on_activate(): void {
        // 迁移旧 BTT 插件设置
        $old_btt = get_option( 'back_to_top_settings' );
        if ( false !== $old_btt && false === get_option( 'drea_site_enhance_btt_enabled' ) ) {
            update_option( 'drea_site_enhance_btt_enabled', true );
            if ( isset( $old_btt['color'] ) ) {
                update_option( 'drea_site_enhance_btt_color', sanitize_hex_color( $old_btt['color'] ) );
            }
            if ( isset( $old_btt['position'] ) ) {
                update_option( 'drea_site_enhance_btt_position', sanitize_text_field( $old_btt['position'] ) );
            }
        }

        // 迁移旧维护模式设置
        $old_maint = get_option( 'maintenance_options' );
        if ( false !== $old_maint && false === get_option( 'drea_site_enhance_maintenance_enabled' ) ) {
            // 仅在旧插件明确启用时才迁移为 enabled
            $was_active = ! empty( $old_maint['state'] );
            update_option( 'drea_site_enhance_maintenance_enabled', $was_active );
            if ( $was_active && isset( $old_maint['description'] ) ) {
                update_option( 'drea_site_enhance_maintenance_msg', sanitize_textarea_field( $old_maint['description'] ) );
            }
        }

        // 默认值
        if ( false === get_option( 'drea_site_enhance_btt_color' ) ) {
            update_option( 'drea_site_enhance_btt_color', '#2271b1' );
        }
        if ( false === get_option( 'drea_site_enhance_btt_icon_color' ) ) {
            update_option( 'drea_site_enhance_btt_icon_color', '#ffffff' );
        }
        if ( false === get_option( 'drea_site_enhance_btt_position' ) ) {
            update_option( 'drea_site_enhance_btt_position', 'right-bottom' );
        }

        // 评论头像优化默认值
        if ( false === get_option( 'drea_site_enhance_avatar_fallback_enabled' ) ) {
            update_option( 'drea_site_enhance_avatar_fallback_enabled', false );
        }
        if ( false === get_option( 'drea_site_enhance_avatar_fallback_url' ) ) {
            update_option( 'drea_site_enhance_avatar_fallback_url', '' );
        }
        if ( false === get_option( 'drea_site_enhance_avatar_mirror' ) ) {
            update_option( 'drea_site_enhance_avatar_mirror', 'cn.cravatar.com' );
        }
        if ( false === get_option( 'drea_site_enhance_avatar_replace_gravatar' ) ) {
            update_option( 'drea_site_enhance_avatar_replace_gravatar', true );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(): void {
        $options = [
            'drea_site_enhance_btt_enabled',
            'drea_site_enhance_btt_color',
            'drea_site_enhance_btt_icon_color',
            'drea_site_enhance_btt_position',
            'drea_site_enhance_maintenance_enabled',
            'drea_site_enhance_maintenance_msg',
            'drea_site_enhance_feat_img_enabled',
            'drea_site_enhance_feat_img_col_enabled',
            'drea_site_enhance_default_feat_img_enabled',
            'drea_site_enhance_default_feat_img_id',
            'drea_site_enhance_quickedit_excerpt_enabled',
            'drea_site_enhance_smtp_enabled',
            'drea_site_enhance_smtp_host',
            'drea_site_enhance_smtp_port',
            'drea_site_enhance_smtp_encryption',
            'drea_site_enhance_smtp_user',
            'drea_site_enhance_smtp_pass',
            'drea_site_enhance_smtp_from_name',
            'drea_site_enhance_smtp_from_email',
            'drea_site_enhance_avatar_fallback_enabled',
            'drea_site_enhance_avatar_fallback_url',
            'drea_site_enhance_avatar_mirror',
            'drea_site_enhance_avatar_replace_gravatar',
        ];
        foreach ( $options as $opt ) {
            delete_option( $opt );
        }
    }

    // ─── 管理菜单 ─────────────────────────────────────

    /**
     * 注册子菜单
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'dreamanual-toolkit',
            __('Site Enhancement', 'dreamanual-toolkit' ),
            __('Site Enhancement', 'dreamanual-toolkit' ),
            'manage_options',
            'drea-se',
            [ $this, 'render_page' ]
        );
    }

    /**
     * 渲染设置页
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__('You do not have permission to access this page.', 'dreamanual-toolkit' ) );
        }
        include __DIR__ . '/admin/settings-page.php';
    }

    /**
     * 加载管理资源
     */
    public function enqueue_admin_assets( string $hook ): void {
        $module_url  = DREA_URL . 'modules/site-enhance';
        $module_path = DREA_PATH . 'modules/site-enhance';

        // 文章列表页：特色图片列或筛选器启用时加载样式
        if ( 'edit.php' === $hook && ( $this->get_option( 'feat_img_enabled', false ) || $this->get_option( 'feat_img_col_enabled', false ) ) ) {
            wp_enqueue_style(
                'drea-se-admin',
                $module_url . '/assets/css/admin.css',
                [ 'drea-toolkit-common' ],
                $this->asset_version( $module_path . '/assets/css/admin.css' )
            );
            return;
        }

        if ( false === strpos( $hook, 'drea-se' ) ) return;

        wp_enqueue_media();

        wp_enqueue_style(
            'drea-se-admin',
            $module_url . '/assets/css/admin.css',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/css/admin.css' )
        );

        wp_enqueue_script(
            'drea-se-admin',
            $module_url . '/assets/js/admin.js',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/js/admin.js' ),
            true
        );

        wp_localize_script( 'drea-se-admin', 'dreaSe', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'drea_se_nonce' ),
            'i18n'    => [
                'saved'               => __('Settings saved.', 'dreamanual-toolkit' ),
                'failed'              => __('Save failed, please retry.', 'dreamanual-toolkit' ),
                'error'               => __('Operation failed, please retry later.', 'dreamanual-toolkit' ),
                'smtpTestNoTo'        => __('Please enter recipient email.', 'dreamanual-toolkit' ),
                'smtpTestSuccess'     => __('Test email sent, please check the inbox.', 'dreamanual-toolkit' ),
                'smtpTestFail'        => __('Send failed, please check SMTP settings.', 'dreamanual-toolkit' ),
                'smtpHostRequired'    => __('SMTP mail enabled, please fill in SMTP host first.', 'dreamanual-toolkit' ),
                'smtpUserRequired'    => __('SMTP mail enabled, please fill in username first.', 'dreamanual-toolkit' ),
                'smtpPortRange'       => __('SMTP port must be between 1-65535.', 'dreamanual-toolkit' ),
                'smtpNotEnabled'      => __('Please enable SMTP mail and save settings before testing.', 'dreamanual-toolkit' ),
                'maintenanceConfirm'  => __('After enabling maintenance mode, visitors will see the maintenance page and cannot access the site normally. Confirm enabling?', 'dreamanual-toolkit' ),
            ],
        ] );
    }

    // ─── AJAX ─────────────────────────────────────────

    /**
     * AJAX: 保存设置
     */
    public function ajax_save_settings(): void {
        check_ajax_referer( 'drea_se_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        // 只更新表单中明确提交的字段，缺失字段不覆盖（与 site-optimize 逻辑对齐）
        $field_map = [
            // BTT
            'btt_enabled'              => ['key' => 'drea_site_enhance_btt_enabled',              'type' => 'bool'],
            'btt_color'                => ['key' => 'drea_site_enhance_btt_color',                'type' => 'hex_color',  'default' => '#2271b1'],
            'btt_icon_color'           => ['key' => 'drea_site_enhance_btt_icon_color',           'type' => 'hex_color',  'default' => '#ffffff'],
            'btt_position'             => ['key' => 'drea_site_enhance_btt_position',             'type' => 'text',       'default' => 'right-bottom'],
            // 维护模式
            'maintenance_enabled'      => ['key' => 'drea_site_enhance_maintenance_enabled',      'type' => 'bool'],
            'maintenance_msg'          => ['key' => 'drea_site_enhance_maintenance_msg',          'type' => 'textarea',   'default' => ''],
            // 特色图片
            'feat_img_enabled'         => ['key' => 'drea_site_enhance_feat_img_enabled',         'type' => 'bool'],
            'feat_img_col_enabled'     => ['key' => 'drea_site_enhance_feat_img_col_enabled',     'type' => 'bool'],
            'default_feat_img_enabled' => ['key' => 'drea_site_enhance_default_feat_img_enabled', 'type' => 'bool'],
            'default_feat_img_id'      => ['key' => 'drea_site_enhance_default_feat_img_id',      'type' => 'absint',     'default' => 0],
            'quickedit_excerpt_enabled'=> ['key' => 'drea_site_enhance_quickedit_excerpt_enabled','type' => 'bool'],
            // SMTP
            'smtp_enabled'             => ['key' => 'drea_site_enhance_smtp_enabled',             'type' => 'bool'],
            'smtp_host'                => ['key' => 'drea_site_enhance_smtp_host',                'type' => 'text',       'default' => ''],
            'smtp_port'                => ['key' => 'drea_site_enhance_smtp_port',                'type' => 'absint',     'default' => 465],
            'smtp_encryption'          => ['key' => 'drea_site_enhance_smtp_encryption',          'type' => 'text',       'default' => 'ssl'],
            'smtp_user'                => ['key' => 'drea_site_enhance_smtp_user',                'type' => 'email',      'default' => ''],
            'smtp_from_name'           => ['key' => 'drea_site_enhance_smtp_from_name',           'type' => 'text',       'default' => ''],
            'smtp_from_email'          => ['key' => 'drea_site_enhance_smtp_from_email',          'type' => 'email',      'default' => ''],
            // 评论头像
            'avatar_fallback_enabled'  => ['key' => 'drea_site_enhance_avatar_fallback_enabled', 'type' => 'bool'],
            'avatar_fallback_url'      => ['key' => 'drea_site_enhance_avatar_fallback_url',     'type' => 'url',        'default' => ''],
            'avatar_mirror'            => ['key' => 'drea_site_enhance_avatar_mirror',            'type' => 'text',       'default' => 'cn.cravatar.com'],
            'avatar_replace_gravatar'  => ['key' => 'drea_site_enhance_avatar_replace_gravatar', 'type' => 'bool'],
        ];

        $save_failed = false;

        // --- 先处理 SMTP 密码（单独处理，不进 $field_map 因为有加密逻辑） ---
        // 密码不可用通用 sanitize（会破坏特殊字符），但必须 wp_unslash 处理再加密存储
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 密码需保留原始字符用于 AES 加密，sanitize 会破坏特殊字符
        if ( isset( $_POST['smtp_pass'] ) && '' !== $_POST['smtp_pass']
            && '\xe2\x80\xa2\xe2\x80\xa2\xe2\x80\xa2\xe2\x80\xa2\xe2\x80\xa2\xe2\x80\xa2\xe2\x80\xa2\xe2\x80\xa2' !== $_POST['smtp_pass']
        ) {
            $smtp_pass = (string) wp_unslash( $_POST['smtp_pass'] );
            update_option( 'drea_site_enhance_smtp_pass', Crypto::encrypt( $smtp_pass ) );
        }
        // phpcs:enable

        // --- SMTP 完整性校验（仅当 smtp_enabled 在本次提交中存在且为 true 时触发） ---
        $smtp_enabled = isset( $_POST['smtp_enabled'] ) ? boolval( $_POST['smtp_enabled'] ) : null;
        if ( true === $smtp_enabled ) {
            $smtp_host = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
            $smtp_user = isset( $_POST['smtp_user'] ) ? sanitize_email( wp_unslash( $_POST['smtp_user'] ) ) : '';
            if ( '' === $smtp_host ) {
                wp_send_json_error( [ 'message' => __('SMTP mail enabled, please fill in SMTP host first.', 'dreamanual-toolkit' ) ] );
            }
            if ( '' === $smtp_user ) {
                wp_send_json_error( [ 'message' => __('SMTP mail enabled, please fill in username first.', 'dreamanual-toolkit' ) ] );
            }
        }

        // --- 遍历字段表，只保存本次提交中存在的字段 ---
        foreach ( $field_map as $post_key => $spec ) {
            if ( ! isset( $_POST[ $post_key ] ) ) {
                continue;
            }

            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 动态字段按 type 分支 sanitize，PHPCS 无法静态追踪
            $raw = wp_unslash( $_POST[ $post_key ] );
            // phpcs:enable
            switch ( $spec['type'] ) {
                case 'bool':
                    $value = boolval( $raw );
                    break;
                case 'hex_color':
                    $value = sanitize_hex_color( $raw );
                    if ( empty( $value ) ) {
                        $value = $spec['default'] ?? '';
                    }
                    break;
                case 'text':
                    $value = sanitize_text_field( $raw );
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field( $raw );
                    break;
                case 'absint':
                    $value = absint( $raw );
                    break;
                case 'email':
                    $value = sanitize_email( $raw );
                    break;
                case 'url':
                    $value = esc_url_raw( $raw );
                    break;
                default:
                    $value = sanitize_text_field( $raw );
                    break;
            }

            // 端口范围校验 (F-12)
            if ( 'smtp_port' === $post_key ) {
                $value = max( 1, min( 65535, $value ) );
            }

            $result = update_option( $spec['key'], $value );
            if ( false === $result && get_option( $spec['key'] ) != $value ) {
                $save_failed = true;
            }
        }

        if ( $save_failed ) {
            wp_send_json_error( [ 'message' => __('Save failed, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('Settings saved.', 'dreamanual-toolkit' ) ] );
    }

    /**
     * AJAX: 获取设置
     */
    public function ajax_get_settings(): void {
        check_ajax_referer( 'drea_se_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [
            'btt_enabled'         => (bool) $this->get_option( 'btt_enabled', false ),
            'btt_color'           => $this->get_option( 'btt_color', '#2271b1' ),
            'btt_icon_color'      => $this->get_option( 'btt_icon_color', '#ffffff' ),
            'btt_position'        => $this->get_option( 'btt_position', 'right-bottom' ),
            'maintenance_enabled' => (bool) $this->get_option( 'maintenance_enabled', false ),
            'maintenance_msg'     => $this->get_option( 'maintenance_msg', '' ),
            'feat_img_enabled'    => (bool) $this->get_option( 'feat_img_enabled', false ),
            'feat_img_col_enabled' => (bool) $this->get_option( 'feat_img_col_enabled', false ),
            'default_feat_img_enabled' => (bool) $this->get_option( 'default_feat_img_enabled', false ),
            'default_feat_img_id' => (int) $this->get_option( 'default_feat_img_id', 0 ),
            'quickedit_excerpt_enabled' => (bool) $this->get_option( 'quickedit_excerpt_enabled', false ),
            'smtp_enabled'        => (bool) $this->get_option( 'smtp_enabled', false ),
            'smtp_host'           => $this->get_option( 'smtp_host', '' ),
            'smtp_port'           => (int) $this->get_option( 'smtp_port', 465 ),
            'smtp_encryption'     => $this->get_option( 'smtp_encryption', 'ssl' ),
            'smtp_user'           => $this->get_option( 'smtp_user', '' ),
            'smtp_from_name'      => $this->get_option( 'smtp_from_name', '' ),
            'smtp_from_email'     => $this->get_option( 'smtp_from_email', '' ),
            'avatar_fallback_enabled' => (bool) $this->get_option( 'avatar_fallback_enabled', false ),
            'avatar_fallback_url' => $this->get_option( 'avatar_fallback_url', '' ),
            'avatar_mirror'       => $this->get_option( 'avatar_mirror', 'cn.cravatar.com' ),
            'avatar_replace_gravatar' => (bool) $this->get_option( 'avatar_replace_gravatar', true ),
        ] );
    }

    // ─── 回到顶部 ─────────────────────────────────────

    /**
     * 前端输出回到顶部 HTML
     */
    public function render_back_to_top(): void {
        $bg_color   = $this->get_option( 'btt_color', '#2271b1' );
        $icon_color = $this->get_option( 'btt_icon_color', '#ffffff' );
        $position   = $this->get_option( 'btt_position', 'right-bottom' );

        // 白名单校验位置值
        $allowed_positions = [ 'right-bottom', 'left-bottom', 'right-top', 'left-top' ];
        if ( ! in_array( $position, $allowed_positions, true ) ) {
            $position = 'right-bottom';
        }

        // noscript 降级用的内联定位样式
        $pos_styles = [
            'right-bottom' => 'right:24px;bottom:24px;',
            'left-bottom'  => 'left:24px;bottom:24px;',
            'right-top'    => 'right:24px;top:24px;',
            'left-top'     => 'left:24px;top:24px;',
        ];
        $pos_style = $pos_styles[ $position ];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $svg is static safe SVG markup
        echo '<button id="drea-btt" data-position="' . esc_attr( $position ) . '" style="background:' . esc_attr( $bg_color ) . ';color:' . esc_attr( $icon_color ) . ';" aria-label="' . esc_attr__('Back to Top', 'dreamanual-toolkit' ) . '">' . $svg . '</button>';

        // JS 禁用时提供降级锚链接 (F-26)
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $svg is static safe SVG markup
        echo '<noscript><a href="#top" style="position:fixed;' . $pos_style . 'z-index:9999;width:44px;height:44px;border:none;border-radius:50%;background:' . esc_attr( $bg_color ) . ';color:' . esc_attr( $icon_color ) . ';text-decoration:none;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.2);">' . $svg . '</a></noscript>';
    }

    /**
     * 加载回到顶部前端 JS
     */
    public function enqueue_btt_assets(): void {
        $module_url  = DREA_URL . 'modules/site-enhance';
        $module_path = DREA_PATH . 'modules/site-enhance';

        wp_enqueue_style(
            'drea-btt',
            $module_url . '/assets/css/btt.css',
            [],
            $this->asset_version( $module_path . '/assets/css/btt.css' )
        );

        $js = <<<'JS'
(function(){
    var btn=document.getElementById('drea-btt');
    if(!btn)return;
    var visible=false;
    window.addEventListener('scroll',function(){
        var shouldShow=window.pageYOffset>400;
        if(shouldShow!==visible){
            visible=shouldShow;
            btn.classList.toggle('is-visible',visible);
        }
    },{passive:true});
    btn.addEventListener('click',function(){
        window.scrollTo({top:0,behavior:'smooth'});
    });
})();
JS;
        wp_register_script( 'drea-btt', false, [], DREA_VERSION, true );
        wp_enqueue_script( 'drea-btt' );
        wp_add_inline_script( 'drea-btt', $js );
    }

    // ─── 维护模式 ─────────────────────────────────────

    /**
     * 维护模式拦截
     */
    public function maintenance_mode(): void {
        // 管理员和 AJAX 请求不受影响
        if ( current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
            return;
        }

        $msg  = $this->get_option( 'maintenance_msg', '' );
        $site_name = get_bloginfo( 'name' );
        $default_msg = __('Site is under maintenance, please visit later.', 'dreamanual-toolkit' );

        // 维护页为独立 503 页面，样式通过 WordPress 样式 API 输出（Plugin Check 合规）
        $body_classes    = implode( ' ', get_body_class( [ 'drea-maintenance' ] ) );

        $css = '.drea-maintenance{display:flex;justify-content:center;align-items:center;min-height:100vh;padding:24px;}'
            . '.drea-maintenance__card{text-align:center;max-width:480px;width:100%;}'
            . '.drea-maintenance__title{font-size:1.75rem;font-weight:700;margin-bottom:.5rem;}'
            . '.drea-maintenance__divider{width:48px;height:2px;margin:0 auto 1rem;}'
            . '.drea-maintenance__msg{font-size:1rem;line-height:1.75;margin-bottom:1.5rem;}'
            . '.drea-maintenance__footer{font-size:.875rem;opacity:.6;}';

        // 503 页面在 wp_head 之前 exit，需注册后手动打印该样式句柄
        wp_register_style( 'drea-maintenance', false, [], DREA_VERSION );
        wp_add_inline_style( 'drea-maintenance', $css );
        wp_enqueue_style( 'drea-maintenance' );
        ob_start();
        wp_print_styles( [ 'drea-maintenance' ] );
        $style_tags = ob_get_clean();

        // Bug 3 修复：lang 属性使用动态获取的站点语言，不再硬编码 zh-CN
        $html = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__('Under Maintenance', 'dreamanual-toolkit' ) . ' — ' . esc_html( $site_name ) . '</title>';
        $html .= $style_tags;
        $html .= '</head><body class="' . esc_attr( $body_classes ) . '">';
        $html .= '<div class="drea-maintenance__card">';
        $html .= '<div class="drea-maintenance__title">' . esc_html__('Under Maintenance', 'dreamanual-toolkit' ) . '</div>';
        $html .= '<div class="drea-maintenance__divider"></div>';
        $html .= '<div class="drea-maintenance__msg">' . esc_html( $msg ?: $default_msg ) . '</div>';
        $html .= '<div class="drea-maintenance__footer">' . esc_html( $site_name ) . '</div>';
        $html .= '</div></body></html>';

        status_header( 503 );
        nocache_headers();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built with esc_html() above
        echo $html;
        exit;
    }

    // ─── 特色图片筛选 ─────────────────────────────────

    /**
     * 添加特色图片筛选器
     */
    public function add_feat_img_filter(): void {
        global $typenow;
        if ( 'post' !== $typenow ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 文章列表只读筛选参数，不修改数据
        $current = isset( $_GET['drea_feat_img'] ) ? sanitize_text_field( wp_unslash( $_GET['drea_feat_img'] ) ) : '';
        echo '<select name="drea_feat_img">';
        echo '<option value="">' . esc_html__('All Featured Images', 'dreamanual-toolkit' ) . '</option>';
        echo '<option value="missing"' . selected( $current, 'missing', false ) . '>' . esc_html__('Missing Featured Image', 'dreamanual-toolkit' ) . '</option>';
        echo '<option value="has"' . selected( $current, 'has', false ) . '>' . esc_html__('Has Featured Image', 'dreamanual-toolkit' ) . '</option>';
        echo '</select>';
    }

    /**
     * 解析特色图片筛选
     */
    public function parse_feat_img_filter( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 文章列表只读筛选参数，不修改数据
        $filter = isset( $_GET['drea_feat_img'] ) ? sanitize_text_field( wp_unslash( $_GET['drea_feat_img'] ) ) : '';
        if ( ! $filter ) return;

        if ( 'missing' === $filter ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 后台列表筛选，数据量受分页限制
            $query->query_vars['meta_query'] = [
                [
                    'key'     => '_thumbnail_id',
                    'compare' => 'NOT EXISTS',
                ],
            ];
        } elseif ( 'has' === $filter ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 后台列表筛选，受分页限制
            $query->query_vars['meta_key']     = '_thumbnail_id';
            $query->query_vars['meta_compare'] = 'EXISTS';
        }
    }

    // ─── 特色图片列 ────────────────────────────────

    /**
     * 在文章列表中添加特色图片列（紧跟复选框之后）
     *
     * @param string[] $columns 已有列。
     * @return string[]
     */
    public function add_feat_img_column( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'cb' === $key ) {
                $new['drea_feat_img'] = '';
            }
        }
        return $new;
    }

    /**
     * 渲染特色图片列：有图显示缩略图，无图显示虚线占位框
     *
     * @param string $column   列名。
     * @param int    $post_id  文章 ID。
     */
    public function render_feat_img_column( string $column, int $post_id ): void {
        if ( 'drea_feat_img' !== $column ) return;

        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            echo wp_get_attachment_image( $thumb_id, [ 50, 50 ], false, [
                'class' => 'drea-se-feat-img__thumb',
            ] );
        } else {
            echo '<span class="drea-se-feat-img__missing" title="' . esc_attr__('No Featured Image Set', 'dreamanual-toolkit' ) . '"></span>';
        }
    }

    // ─── 默认特色图片 ────────────────────────────────

    /**
     * 前台拦截：当文章无特色图片时，用默认图片替换
     *
     * @param string       $html         输出 HTML。
     * @param int          $post_id      文章 ID。
     * @param int          $thumb_id     缩略图 ID。
     * @param string|int[] $size         图片尺寸。
     * @param string|array $attr         属性。
     * @return string
     */
    public function default_featured_image( string $html, int $post_id, int $thumb_id, $size, $attr ): string {
        if ( $thumb_id ) {
            return $html;
        }

        $default_id = (int) $this->get_option( 'default_feat_img_id', 0 );
        if ( ! $default_id ) {
            return $html;
        }

        // 检查附件是否存在 (F-25)
        $attachment = get_post( $default_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return $html;
        }

        return wp_get_attachment_image( $default_id, $size, false, $attr );
    }

    // ─── 摘要快速编辑 ────────────────────────────────

    /**
     * 添加隐藏的摘要数据列（用于 JS 读取摘要内容）
     *
     * @param string[] $columns 已有列。
     * @return string[]
     */
    public function add_excerpt_data_column( array $columns ): array {
        $columns['drea_excerpt'] = ''; // 空表头，CSS 隐藏
        return $columns;
    }

    /**
     * 渲染隐藏的摘要数据（data 属性供 JS 读取）
     *
     * @param string $column   列名。
     * @param int    $post_id  文章 ID。
     */
    public function render_excerpt_data_column( string $column, int $post_id ): void {
        if ( 'drea_excerpt' !== $column ) return;
        $excerpt = get_the_excerpt( $post_id );
        echo '<span class="drea-quickedit-excerpt-data" data-excerpt="' . esc_attr( $excerpt ) . '"></span>';
    }

    /**
     * 在快速编辑面板中添加摘要字段
     *
     * @param string $column_name 列名。
     * @param string $post_type   文章类型。
     */
    public function quick_edit_excerpt_box( string $column_name, string $post_type ): void {
        if ( 'drea_excerpt' !== $column_name ) return;
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php esc_html_e('Excerpt', 'dreamanual-toolkit' ); ?></span>
                    <textarea cols="22" rows="3" name="excerpt" class="drea-quickedit-excerpt-field"></textarea>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * 输出快速编辑摘要的内联 JS（在 edit.php 页面头部）
     */
    public function quick_edit_excerpt_script(): void {
        $module_url  = DREA_URL . 'modules/site-enhance';
        $module_path = DREA_PATH . 'modules/site-enhance';

        wp_enqueue_style(
            'drea-quickedit-excerpt',
            $module_url . '/assets/css/quickedit-excerpt.css',
            [],
            $this->asset_version( $module_path . '/assets/css/quickedit-excerpt.css' )
        );

        wp_enqueue_script(
            'drea-quickedit-excerpt',
            $module_url . '/assets/js/quickedit-excerpt.js',
            [ 'jquery' ],
            $this->asset_version( $module_path . '/assets/js/quickedit-excerpt.js' ),
            true
        );
    }

    // ─── SMTP 发信 ─────────────────────────────────────

    /**
     * 配置 PHPMailer 使用 SMTP
     *
     * @param \PHPMailer $phpmailer PHPMailer 实例。
     */
    public function configure_smtp( $phpmailer ): void {
        $host       = $this->get_option( 'smtp_host', '' );
        $port       = (int) $this->get_option( 'smtp_port', 465 );
        $encryption = $this->get_option( 'smtp_encryption', 'ssl' );
        $user       = $this->get_option( 'smtp_user', '' );
        $pass_enc   = get_option( 'drea_site_enhance_smtp_pass', '' );
        $pass       = $pass_enc ? Crypto::decrypt( $pass_enc ) : '';

        if ( ! $host || ! $user ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->Port       = $port;
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $user;
        $phpmailer->Password   = $pass;

        if ( 'ssl' === $encryption ) {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ( 'tls' === $encryption ) {
            $phpmailer->SMTPSecure = 'tls';
        }

        $phpmailer->SMTPAutoTLS = false;
    }

    /**
     * 强制发件人邮箱
     *
     * @param string $email 默认邮箱。
     * @return string
     */
    public function smtp_mail_from( string $email ): string {
        $from = $this->get_option( 'smtp_from_email', '' );
        return $from ?: $email;
    }

    /**
     * 强制发件人名称
     *
     * @param string $name 默认名称。
     * @return string
     */
    public function smtp_mail_from_name( string $name ): string {
        $from_name = $this->get_option( 'smtp_from_name', '' );
        return $from_name ?: $name;
    }

    /**
     * AJAX: 测试 SMTP 发信
     */
    public function ajax_smtp_test(): void {
        check_ajax_referer( 'drea_se_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $to      = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
        $subject = __('DM Toolkit SMTP Test', 'dreamanual-toolkit' );
        $body    = __('This is a test email sent by Dreamanual Toolkit SMTP feature.', 'dreamanual-toolkit' );

        if ( ! $to ) {
            wp_send_json_error( [ 'message' => __('Please enter recipient email.', 'dreamanual-toolkit' ) ] );
        }

        // 捕获 PHPMailer 异常以返回具体错误信息
        add_action( 'phpmailer_init', [ $this, 'smtp_test_enable_exceptions' ] );
        add_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );

        $this->_smtp_test_error = '';
        $result = wp_mail( $to, $subject, $body );

        remove_action( 'phpmailer_init', [ $this, 'smtp_test_enable_exceptions' ] );
        remove_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );

        if ( $result ) {
            wp_send_json_success( [ 'message' => __('Test email sent, please check the inbox.', 'dreamanual-toolkit' ) ] );
        } else {
            $error_detail = $this->_smtp_test_error;
            if ( $error_detail ) {
                $friendly = $this->translate_smtp_error( $error_detail );
                wp_send_json_error( [ 'message' => $friendly ] );
            } else {
                wp_send_json_error( [ 'message' => __('Send failed, please verify SMTP host, port, username, and password.', 'dreamanual-toolkit' ) ] );
            }
        }
    }

    /**
     * 将 PHPMailer/SMTP 原始英文错误翻译为用户友好的中文提示
     *
     * @param string $error 原始错误信息。
     * @return string 友好的中文错误提示。
     */
    private function translate_smtp_error( string $error ): string {
        $error_lower = strtolower( $error );

        // 优化 3：连接类错误复用公共网络错误翻译器
        if ( false !== strpos( $error_lower, 'connect() failed' )
            || false !== strpos( $error_lower, 'connection refused' )
            || false !== strpos( $error_lower, 'connection timed out' )
            || false !== strpos( $error_lower, 'network is unreachable' )
        ) {
            return Error_Translator::translate_network_error( $error, 'SMTP' );
        }

        // 以下为 SMTP 专属错误，保留在此处

        // 认证类错误
        if ( false !== strpos( $error_lower, 'could not authenticate' )
            || false !== strpos( $error_lower, 'authentication failed' )
            || false !== strpos( $error_lower, '535' )
            || false !== strpos( $error_lower, '530' )
            || false !== strpos( $error_lower, 'invalid credentials' )
        ) {
            return __( 'Authentication failed, please verify SMTP username and password.', 'dreamanual-toolkit' );
        }

        // 加密/SSL/TLS 类错误
        if ( false !== strpos( $error_lower, 'ssl' )
            || false !== strpos( $error_lower, 'tls' )
            || false !== strpos( $error_lower, 'certificate' )
            || false !== strpos( $error_lower, 'encryption' )
        ) {
            return __( 'Encrypted connection failed, please verify encryption method (SSL/TLS/None) is correct.', 'dreamanual-toolkit' );
        }

        // 发件人地址被拒绝
        if ( false !== strpos( $error_lower, 'sender address rejected' )
            || false !== strpos( $error_lower, 'sender not allowed' )
            || ( false !== strpos( $error_lower, 'from' ) && false !== strpos( $error_lower, 'rejected' ) )
        ) {
            return __( 'Sender address rejected, please verify the from email matches the SMTP account.', 'dreamanual-toolkit' );
        }

        // 收件人地址被拒绝
        if ( false !== strpos( $error_lower, 'recipient address rejected' )
            || false !== strpos( $error_lower, 'user unknown' )
            || false !== strpos( $error_lower, 'no such user' )
        ) {
            return __( 'Recipient address rejected, please verify the recipient email.', 'dreamanual-toolkit' );
        }

        // 发送频率限制
        if ( false !== strpos( $error_lower, 'rate limit' )
            || false !== strpos( $error_lower, 'too many' )
            || false !== strpos( $error_lower, 'exceed' )
        ) {
            return __( 'Send frequency limit exceeded, please retry later.', 'dreamanual-toolkit' );
        }

        // 邮箱容量满
        if ( false !== strpos( $error_lower, 'quota' )
            || false !== strpos( $error_lower, 'mailbox full' )
            || false !== strpos( $error_lower, 'insufficient' )
        ) {
            return __( 'Mailbox full or quota exceeded.', 'dreamanual-toolkit' );
        }

        // 兜底：友好中文 + 简略原始错误
        /* translators: %s: original SMTP error message */
        return sprintf( __( 'Send failed, please verify SMTP settings. Original error: %s', 'dreamanual-toolkit' ), $error );
    }

    /**
     * 启用 PHPMailer 异常模式（测试发信用）
     *
     * @param \PHPMailer $phpmailer PHPMailer 实例。
     */
    public function smtp_test_enable_exceptions( $phpmailer ): void {
        $phpmailer->SMTPDebug = 0; // 不输出调试信息，只捕获异常
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API
        $phpmailer->exceptions = true;
    }

    /**
     * 捕获 wp_mail_failed 错误信息
     *
     * @param \WP_Error $error 邮件错误对象。
     */
    public function capture_mail_error( \WP_Error $error ): void {
        $this->_smtp_test_error = $error->get_error_message();
    }

    // ─── 评论头像优化 ─────────────────────────────────

    /**
     * 评论头像优化：gravatar 源替换镜像 + 自定义默认头像
     *
     * 处理两个独立诉求：
     * 1. 服务器访问 gravatar.com 超时 → 替换为镜像源（默认 cn.cravatar.com）。
     * 2. 未注册用户（无自定义头像）→ 显示后台配置的默认头像。
     *
     * 实现原理：gravatar/cravatar 镜像源的 URL 中 d= 参数指定「无头像时的回退图」。
     * 当用户在 gravatar 注册了头像，镜像源返回该头像；未注册时镜像源返回 d= 指向的图片。
     * 因此把 d= 参数设置为自定义默认头像 URL，即可让镜像源自行决定返回哪张图，
     * 无需本地判断用户是否有头像——完美匹配「先显示默认头像，有注册头像再替换」的需求。
     * 注意：d= 参数需要 urlencode，且镜像源必须支持 404/redirect 回退模式。
     *
     * @param string         $url         头像 URL。
     * @param int|string|object $id_or_email 用户 ID / 邮箱 / 对象。
     * @param array          $args        头像参数。
     * @return string
     */
    public function avatar_optimize_url( string $url, $id_or_email, array $args ): string {
        // ① gravatar 系域名 → 镜像源
        if ( $this->get_option( 'avatar_replace_gravatar', true ) ) {
            $mirror = trim( $this->get_option( 'avatar_mirror', 'cn.cravatar.com' ) );
            $mirror = preg_replace( '#^https?://#i', '', $mirror );
            $mirror = rtrim( $mirror, '/' );
            if ( $mirror ) {
                $url = preg_replace(
                    '#^https?://(?:[0-9]\.)?(?:secure\.)?gravatar\.com#i',
                    'https://' . $mirror,
                    $url
                );
            }
        }

        // ② 自定义默认头像：将 URL 中 d= 参数替换为后台配置的默认头像 URL
        //    镜像源在用户无注册头像时返回 d= 指向的图片，有则返回真实头像
        $fallback = trim( $this->get_option( 'avatar_fallback_url', '' ) );
        if ( $fallback ) {
            $url = $this->replace_avatar_default_param( $url, $fallback );
        }

        return $url;
    }

    /**
     * 替换头像 URL 中的 d= 参数为自定义默认头像
     *
     * Gravatar/Cravatar URL 格式：?s=96&d=mm&r=g
     * d= 参数支持：mm(mystery) / identicon / monsterid / retrowave / 404 / URL
     * 当 d= 为 URL 时，镜像源在无头像时 302 重定向到该 URL。
     * 当用户有 gravatar 头像时，镜像源忽略 d= 直接返回真实头像。
     *
     * @param string $url      完整头像 URL。
     * @param string $fallback 自定义默认头像 URL。
     * @return string
     */
    private function replace_avatar_default_param( string $url, string $fallback ): string {
        $parsed = wp_parse_url( $url );
        if ( ! isset( $parsed['query'] ) ) {
            // 无 query string，追加 d= 参数
            $separator = isset( $parsed['fragment'] ) ? '&#' : '?';
            return $url . $separator . 'd=' . rawurlencode( $fallback );
        }

        parse_str( $parsed['query'], $params );
        $params['d'] = $fallback; // rawurlencode 由 build_query 处理

        $new_query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
        $path = isset( $parsed['path'] ) ? $parsed['path'] : '';
        $host = isset( $parsed['host'] ) ? $parsed['host'] : '';
        $scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
        $result = $scheme . '://' . $host . $path . '?' . $new_query;

        if ( isset( $parsed['fragment'] ) ) {
            $result .= '#' . $parsed['fragment'];
        }

        return $result;
    }
}

// 注册模块
Core::get_instance()->register_module( new Site_Enhance() );
