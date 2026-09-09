<?php
/**
 * 站点优化模块 —— WordPress 功能精简与优化
 *
 * 每个优化项独立开关，互不耦合。
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class Site_Optimize extends Module_Base {

    /** @var string 模块 ID */
    const MODULE_ID = 'site-optimize';

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
        return __('Site Optimization', 'dreamanual-toolkit' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('WordPress feature slimming, Chinese typography, admin ad blocker — each with independent toggle.', 'dreamanual-toolkit' );
    }

    /**
     * 获取设置页 URL
     */
    public function get_settings_url(): string {
        return admin_url( 'admin.php?page=drea-so' );
    }

    /**
     * 获取所有优化项定义（key => 默认值）
     *
     * @return array<string, bool>
     */
    public static function get_features(): array {
        return [
            // 常规功能
            'disable_revisions'       => true,
            'disable_trackback'       => true,
            'disable_xmlrpc'          => true,
            'disable_feed'            => false,
            'disable_admin_email_ver' => true,
            // 转换功能
            'disable_emoji'           => true,
            'disable_text_transform'  => true,
            'disable_capital_p'       => false,
            // 后台功能
            'remove_gdpr_page'        => true,
            'remove_dashboard_news'   => true,
            'remove_help_tabs'        => true,
            'remove_screen_options'   => true,
            // 页面功能
            'remove_wp_version'       => true,
            'remove_toolbar_option'   => true,
            // 嵌入功能
            'disable_auto_embeds'     => true,
            'disable_wp_embed'        => true,
            // 性能优化
            'enable_speculative'      => true,

            // ─── 内容排版（源自 wp-china-yes，纯本地实现） ───
            'typography_space'        => true,
            'typography_align'        => true,
            'typography_quotes'       => false,
            'typography_indent'       => false,

            // ─── 后台广告拦截（源自 wp-china-yes） ───
            'adblock_enabled'         => true,
        ];
    }

    /**
     * 获取默认广告拦截规则（CSS 选择器列表）
     *
     * 复用 wp-china-yes 默认规则集，覆盖 Yoast / RankMath / Smush / Elementor
     * 等常见插件的升级推销与广告横幅。
     *
     * @return string[]
     */
    public static function get_default_adblock_rules(): array {
        return [
            '.wpseo_content_wrapper #sidebar-container',
            '.yoast_premium_upsell',
            '#wpseo-local-seo-upsell',
            '.yoast-settings-section-upsell',
            '#rank_math_review_plugin_notice',
            '#bwp-get-social',
            '.bwp-button-paypal',
            '#bwp-sidebar-right',
            '#duplicate-post-notice #newsletter-subscribe-form',
            'div[id^="dnh-wrm"]',
            '.notice-info.dst-notice',
            '.fw-brz-dismiss',
            'div.elementor-message[data-notice_id="elementor_dev_promote"]',
            '.notice-success.wpcf7r-notice',
            '#ws_sidebar_pro_ad',
            '.pa-new-feature-notice',
            '#redux-connect-message',
            '.frash-notice-email',
            '.frash-notice-rate',
            '#smush-box-pro-features',
            '#wp-smush-bulk-smush-upsell-row',
            '#easy-updates-manager-dashnotice',
            '#metaslider-optin-notice',
            '#extendifysdk_announcement',
            '.ml-discount-ad',
            '.mo-admin-notice',
            '.post-smtp-donation',
            '.neve-notice-upsell',
            '#pagelayer_promo',
            '.sfsi_new_prmium_follw',
            '.tribe-notice-event-tickets-install',
            '.webpLoader__popup.webpPopup',
            '.put-dismiss-notice',
            '.wp-mail-smtp-review-notice',
            '#wp-mail-smtp-pro-banner',
            '.analytify-review-thumbnail',
            '.analytify-review-notice',
            '.jitm-banner.is-upgrade-premium',
            'div[data-name*="wbcr_factory_notice_adverts"]',
            '.sui-subscription-notice',
            '#sui-cross-sell-footer',
            '.forminator-rating-notice',
            '.cff-settings-cta',
            '.cff-header-upgrade-notice',
            '#elementskit-lite-go-pro-noti2ce',
            '.yarpp-review-notice',
            '.villatheme-dashboard.updated',
            '#njt-FileBird-review',
            '.wpdeveloper-review-notice',
            '#sg-backup-review-wrapper',
            '.notice-getgenie-go-pro-noti2ce',
            'div.notice.bundle-notice',
            '.edac-review-notice',
            '.notice-iworks-rate',
            '#monterinsights-admin-menu-tooltip',
            '.monsterinsights-floating-bar',
            '#metform-unsupported-metform-pro-version',
            '.lwptocRate',
            '.iworks-rate-notice',
            '[id^="wpmet-jhanda-"]',
            '#wpmet-stories',
            '#ti-optml-notice-helper',
            '.catch-bells-admin-notice',
            '.wpdt-bundles-notice',
            '.td-admin-web-services',
            '.cf-plugin-popup',
            '.wpzinc-review-media-library-organizer',
            '.oxi-image-notice',
        ];
    }

    /**
     * 获取广告拦截规则（合并默认规则与用户自定义）
     *
     * @return string[]
     */
    public static function get_adblock_rules(): array {
        $rules = get_option( 'drea_site_optimize_adblock_rules', [] );
        if ( ! is_array( $rules ) || empty( $rules ) ) {
            return self::get_default_adblock_rules();
        }
        return array_values( array_filter( array_map( 'trim', $rules ) ) );
    }

    /**
     * 获取分组信息
     *
     * @return array<string, array{label: string, features: array<string, string>}>
     */
    public static function get_groups(): array {
        return [
            'general' => [
                'label'    => __('General', 'dreamanual-toolkit' ),
                'features' => [
                    'disable_revisions'       => __('Disable post revisions, slim post table data', 'dreamanual-toolkit' ),
                    'disable_trackback'       => __('Completely disable Trackback to prevent spam', 'dreamanual-toolkit' ),
                    'disable_xmlrpc'          => __('Disable XML-RPC, only publish posts via admin', 'dreamanual-toolkit' ),
                    'disable_feed'            => __('Disable site Feed to prevent content scraping', 'dreamanual-toolkit' ),
                    'disable_admin_email_ver' => __('Disable periodic admin email verification', 'dreamanual-toolkit' ),
                ],
            ],
            'transform' => [
                'label'    => __('Conversion', 'dreamanual-toolkit' ),
                'features' => [
                    'disable_emoji'          => __('Disable Emoji-to-image conversion, use Emoji directly', 'dreamanual-toolkit' ),
                    'disable_text_transform' => __('Disable character-to-formatted HTML entity conversion', 'dreamanual-toolkit' ),
                    'disable_capital_p'      => __('Disable WordPress capitalization correction, write as you prefer', 'dreamanual-toolkit' ),
                ],
            ],
            'admin' => [
                'label'    => __('Admin', 'dreamanual-toolkit' ),
                'features' => [
                    'remove_gdpr_page'      => __('Remove pages generated for European GDPR', 'dreamanual-toolkit' ),
                    'remove_dashboard_news' => __('Remove "WordPress Events and News" from dashboard', 'dreamanual-toolkit' ),
                    'remove_help_tabs'      => __('Remove "Help" tab in top-right of admin', 'dreamanual-toolkit' ),
                    'remove_screen_options' => __('Remove "Screen Options" tab in top-right of admin', 'dreamanual-toolkit' ),
                ],
            ],
            'page' => [
                'label'    => __('Page', 'dreamanual-toolkit' ),
                'features' => [
                    'remove_wp_version'     => __('Remove version number and service discovery tags from page head', 'dreamanual-toolkit' ),
                    'remove_toolbar_option' => __('Remove toolbar-related options from admin bar and profile', 'dreamanual-toolkit' ),
                ],
            ],
            'embed' => [
                'label'    => __('Embeds', 'dreamanual-toolkit' ),
                'features' => [
                    'disable_auto_embeds' => __('Disable Auto Embeds to speed up page parsing', 'dreamanual-toolkit' ),
                    'disable_wp_embed'    => __('Disable Embed for other WordPress posts', 'dreamanual-toolkit' ),
                ],
            ],
            'performance' => [
                'label'    => __('Performance', 'dreamanual-toolkit' ),
                'features' => [
                    'enable_speculative' => __('Enable speculative loading, browser pre-renders linked pages (Chrome 121+)', 'dreamanual-toolkit' ),
                ],
            ],
            'typography' => [
                'label'    => __('Chinese Typography', 'dreamanual-toolkit' ),
                'features' => [
                    'typography_space'   => __('Insert space between Chinese and Latin/digits for better readability', 'dreamanual-toolkit' ),
                    'typography_align'   => __('Justify text in single posts (text-align: justify)', 'dreamanual-toolkit' ),
                    'typography_quotes'  => __('Convert straight quotes to Chinese corner brackets 「」『』', 'dreamanual-toolkit' ),
                    'typography_indent'  => __('Indent first line of paragraphs by 2em', 'dreamanual-toolkit' ),
                ],
            ],
            'adblock' => [
                'label'    => __('Admin Ad Blocker', 'dreamanual-toolkit' ),
                'features' => [
                    'adblock_enabled' => __('Hide third-party plugin advertisement banners in admin', 'dreamanual-toolkit' ),
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // 管理菜单 & 资源
        $this->on( 'admin_menu', [ $this, 'add_admin_menu' ] );
        $this->on( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX
        $this->on( 'wp_ajax_drea_so_save_settings', [ $this, 'ajax_save_settings' ] );
        $this->on( 'wp_ajax_drea_so_get_settings', [ $this, 'ajax_get_settings' ] );

        $features = self::get_features();

        foreach ( $features as $key => $default ) {
            if ( ! $this->get_option( $key, $default ) ) {
                continue;
            }

            switch ( $key ) {
                // ─── 常规功能 ───
                case 'disable_revisions':
                    $this->filter( 'wp_revisions_to_keep', '__return_zero' );
                    break;

                case 'disable_trackback':
                    $this->filter( 'pings_open', '__return_false', 20 );
                    $this->on( 'pre_ping', [ $this, 'disable_self_ping' ] );
                    break;

                case 'disable_xmlrpc':
                    $this->filter( 'xmlrpc_enabled', '__return_false' );
                    $this->filter( 'wp_headers', [ $this, 'remove_xmlrpc_header' ] );
                    break;

                case 'disable_feed':
                    $this->on( 'do_feed', [ $this, 'disable_feed_redirect' ], 1 );
                    $this->on( 'do_feed_rdf', [ $this, 'disable_feed_redirect' ], 1 );
                    $this->on( 'do_feed_rss', [ $this, 'disable_feed_redirect' ], 1 );
                    $this->on( 'do_feed_rss2', [ $this, 'disable_feed_redirect' ], 1 );
                    $this->on( 'do_feed_atom', [ $this, 'disable_feed_redirect' ], 1 );
                    $this->remove( 'wp_head', 'feed_links_extra', 3 );
                    $this->remove( 'wp_head', 'feed_links', 2 );
                    break;

                case 'disable_admin_email_ver':
                    $this->remove( 'admin_enqueue_scripts', 'wp_auth_check_load' );
                    $this->filter( 'admin_email_check_interval', '__return_zero' );
                    break;

                // ─── 转换功能 ───
                case 'disable_emoji':
                    $this->remove( 'wp_head', 'print_emoji_detection_script', 7 );
                    $this->remove( 'admin_print_scripts', 'print_emoji_detection_script' );
                    $this->remove( 'wp_print_styles', 'print_emoji_styles' );
                    $this->remove( 'admin_print_styles', 'print_emoji_styles' );
                    $this->remove( 'the_content_feed', 'wp_staticize_emoji' );
                    $this->remove( 'comment_text_rss', 'wp_staticize_emoji' );
                    $this->remove( 'wp_mail', 'wp_staticize_emoji_for_email' );
                    $this->filter( 'tiny_mce_plugins', [ $this, 'remove_emoji_tinymce' ] );
                    $this->filter( 'wp_resource_hints', [ $this, 'remove_emoji_dns' ], 10, 2 );
                    break;

                case 'disable_text_transform':
                    $this->remove( 'the_content', 'wptexturize' );
                    $this->remove( 'the_title', 'wptexturize' );
                    $this->remove( 'the_excerpt', 'wptexturize' );
                    $this->remove( 'comment_text', 'wptexturize' );
                    $this->remove( 'widget_text', 'wptexturize' );
                    $this->remove( 'list_cats', 'wptexturize' );
                    break;

                case 'disable_capital_p':
                    $this->remove( 'the_title', 'capital_P_dangit', 11 );
                    $this->remove( 'the_content', 'capital_P_dangit', 11 );
                    $this->remove( 'comment_text', 'capital_P_dangit', 31 );
                    break;

                // ─── 后台功能 ───
                case 'remove_gdpr_page':
                    $this->on( 'admin_menu', [ $this, 'remove_privacy_page' ], 999 );
                    $this->on( 'admin_init', [ $this, 'remove_privacy_admin_notices' ] );
                    break;

                case 'remove_dashboard_news':
                    $this->on( 'admin_init', [ $this, 'remove_dashboard_widgets' ] );
                    break;

                case 'remove_help_tabs':
                    $this->on( 'admin_head', [ $this, 'remove_help_tabs' ], 999 );
                    break;

                case 'remove_screen_options':
                    $this->filter( 'screen_options_show_screen', '__return_false' );
                    break;

                // ─── 页面功能 ───
                case 'remove_wp_version':
                    $this->remove( 'wp_head', 'wp_generator' );
                    $this->remove( 'wp_head', 'rest_output_link_wp_head' );
                    $this->remove( 'wp_head', 'wp_oembed_add_discovery_links' );
                    $this->remove( 'wp_head', 'wp_oembed_add_host_js' );
                    $this->remove( 'template_redirect', 'rest_output_link_header', 11 );
                    $this->filter( 'the_generator', '__return_empty_string' );
                    break;

                case 'remove_toolbar_option':
                    $this->on( 'admin_init', [ $this, 'remove_toolbar_option' ] );
                    $this->filter( 'show_admin_bar', '__return_false' );
                    break;

                // ─── 嵌入功能 ───
                case 'disable_auto_embeds':
                    $this->remove( 'parse_query', 'wp_oembed_parse_query' );
                    $this->remove( 'wp_head', 'wp_oembed_add_discovery_links' );
                    $this->remove( 'wp_head', 'wp_oembed_add_host_js' );
                    $this->filter( 'embed_oembed_discover', '__return_false' );
                    break;

                case 'disable_wp_embed':
                    $this->on( 'wp_enqueue_scripts', [ $this, 'deregister_wp_embed' ], 99 );
                    $this->on( 'admin_enqueue_scripts', [ $this, 'deregister_wp_embed' ], 99 );
                    break;

                // ─── 性能优化 ───
                case 'enable_speculative':
                    $this->on( 'wp_head', [ $this, 'output_speculation_rules' ], 99 );
                    break;

                // ─── 内容排版 ───
                case 'typography_space':
                    $this->filter( 'the_content', [ $this, 'typography_space_content' ] );
                    $this->filter( 'the_excerpt', [ $this, 'typography_space_content' ] );
                    break;

                case 'typography_align':
                    $this->on( 'wp_head', [ $this, 'typography_align_css' ], 99 );
                    break;

                case 'typography_quotes':
                    $this->filter( 'the_content', [ $this, 'typography_quotes_content' ] );
                    $this->filter( 'the_excerpt', [ $this, 'typography_quotes_content' ] );
                    break;

                case 'typography_indent':
                    $this->on( 'wp_head', [ $this, 'typography_indent_css' ], 99 );
                    break;

                // ─── 后台广告拦截 ───
                case 'adblock_enabled':
                    $this->on( 'admin_head', [ $this, 'admin_adblock_css' ], 99 );
                    break;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function on_activate(): void {
        // 优化 4：get_features() 已提供默认值，各处 get_option($key, $default) 自动回退
        // 无需在激活时逐个写库，减少不必要的 option 行
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(): void {
        foreach ( array_keys( self::get_features() ) as $key ) {
            delete_option( 'drea_site_optimize_' . $key );
        }
        delete_option( 'drea_site_optimize_adblock_rules' );
    }

    /**
     * 清洗广告拦截规则输入（textarea 逐行 → 字符串数组）
     *
     * 每行一个 CSS 选择器；去除行首尾空白、空行与明显不可信字符，
     * 仅保留 CSS 选择器常用字符集。
     *
     * @param string $raw 原始 textarea 内容。
     * @return string[]
     */
    private function sanitize_adblock_rules( string $raw ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        $rules = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            // 与 admin_adblock_css() 保持一致的字符白名单
            $safe = preg_replace( '/[^\w\s.#\[\]="\'\-_:>+,*()~|]/u', '', $line );
            if ( '' === $safe ) {
                continue;
            }
            $rules[] = $safe;
        }
        return array_values( array_unique( $rules ) );
    }

    // ─── 管理菜单 ─────────────────────────────────────

    /**
     * 注册子菜单
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'dreamanual-toolkit',
            __('Site Optimization', 'dreamanual-toolkit' ),
            __('Site Optimization', 'dreamanual-toolkit' ),
            'manage_options',
            'drea-so',
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
        if ( false === strpos( $hook, 'drea-so' ) ) return;

        $module_url  = DREA_URL . 'modules/site-optimize';
        $module_path = DREA_PATH . 'modules/site-optimize';

        wp_enqueue_style(
            'drea-so-admin',
            $module_url . '/assets/css/admin.css',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/css/admin.css' )
        );

        wp_enqueue_script(
            'drea-so-admin',
            $module_url . '/assets/js/admin.js',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/js/admin.js' ),
            true
        );

        wp_localize_script( 'drea-so-admin', 'dreaSo', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'drea_so_nonce' ),
            'i18n'    => [
                'saved'  => __('Settings saved.', 'dreamanual-toolkit' ),
                'failed' => __('Save failed, please retry.', 'dreamanual-toolkit' ),
                'error'  => __('Operation failed.', 'dreamanual-toolkit' ),
            ],
        ] );
    }

    // ─── AJAX ─────────────────────────────────────────

    /**
     * AJAX: 保存设置
     */
    public function ajax_save_settings(): void {
        check_ajax_referer( 'drea_so_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $save_failed = false;
        foreach ( array_keys( self::get_features() ) as $key ) {
            // F-16: 只更新表单中明确提交的字段，缺失字段不覆盖为 false
            if ( ! isset( $_POST[ $key ] ) ) {
                continue;
            }
            $value  = boolval( $_POST[ $key ] );
            $result = update_option( 'drea_site_optimize_' . $key, $value );
            if ( false === $result && get_option( 'drea_site_optimize_' . $key ) != $value ) {
                $save_failed = true;
            }
        }

        // 广告拦截规则（textarea，非 bool feature，单独处理）
        if ( isset( $_POST['adblock_rules'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_adblock_rules() below
            $rules = $this->sanitize_adblock_rules( wp_unslash( $_POST['adblock_rules'] ) );
            $result = update_option( 'drea_site_optimize_adblock_rules', $rules );
            if ( false === $result && get_option( 'drea_site_optimize_adblock_rules' ) != $rules ) {
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
        check_ajax_referer( 'drea_so_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $data = [];
        foreach ( self::get_features() as $key => $default ) {
            $data[ $key ] = (bool) $this->get_option( $key, $default );
        }

        wp_send_json_success( $data );
    }

    // ─── Hook 回调 ────────────────────────────────────

    /**
     * 禁止自我 Pingback
     */
    public function disable_self_ping( array &$links ): void {
        $home = home_url();
        foreach ( $links as $l => $link ) {
            if ( 0 === strpos( $link, $home ) ) {
                unset( $links[ $l ] );
            }
        }
    }

    /**
     * 移除 XML-RPC 响应头
     */
    public function remove_xmlrpc_header( array $headers ): array {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    /**
     * Feed 重定向到首页
     */
    public function disable_feed_redirect(): void {
        wp_safe_redirect( home_url(), 301 );
        exit;
    }

    /**
     * 移除 TinyMCE Emoji 插件
     */
    public function remove_emoji_tinymce( array $plugins ): array {
        return array_diff( $plugins, [ 'wpemoji' ] );
    }

    /**
     * 移除 Emoji DNS 预解析
     */
    public function remove_emoji_dns( array $urls, string $relation_type ): array {
        if ( 'dns-prefetch' !== $relation_type ) {
            return $urls;
        }
        // 匹配核心 emoji 资源路径（wp-includes/images/core/emoji/），避免构造完整 URL
        foreach ( $urls as $key => $url ) {
            if ( false !== strpos( $url, '/images/core/emoji/' ) ) {
                unset( $urls[ $key ] );
            }
        }
        return $urls;
    }

    /**
     * 移除隐私政策页面
     */
    public function remove_privacy_page(): void {
        remove_submenu_page( 'tools.php', 'privacy.php' );
        remove_submenu_page( 'options-general.php', 'options-privacy.php' );
    }

    /**
     * 移除隐私相关后台通知
     */
    public function remove_privacy_admin_notices(): void {
        remove_action( 'admin_notices', [ 'WP_Privacy_Policy_Content', 'notice' ] );
        remove_action( 'admin_notices', [ 'WP_Privacy_Data_Removal_Requests_Table', 'scheduled_delete_notice' ] );
    }

    /**
     * 移除仪表盘小工具
     */
    public function remove_dashboard_widgets(): void {
        remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
    }

    /**
     * 移除帮助标签
     */
    public function remove_help_tabs(): void {
        $screen = get_current_screen();
        if ( $screen ) {
            $screen->remove_help_tabs();
        }
    }

    /**
     * 移除个人资料中的工具栏选项
     */
    public function remove_toolbar_option(): void {
        remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
        remove_action( 'personal_options', 'toolbar_preferences' );
    }

    /**
     * 注销 WP Embed 脚本
     */
    public function deregister_wp_embed(): void {
        wp_deregister_script( 'wp-embed' );
    }

    /**
     * 输出推测加载规则（Speculation Rules API）
     *
     * Chrome 121+ 支持在用户悬停链接时预渲染页面。
     */
    public function output_speculation_rules(): void {
        $rules = [
            'prerender' => [
                [ 'where' => [ 'href_matches' => '/*' ] ],
            ],
        ];
        echo '<script type="speculationrules">' . wp_json_encode( $rules ) . '</script>' . "\n";
    }

    // ─── 内容排版 ────────────────────────────────────────

    /**
     * 中英文/数字间自动加空格（the_content / the_excerpt 过滤器）
     *
     * Bug 4 修复：从输出缓冲全页替换改为仅过滤正文，避免误伤脚本/HTML属性
     *
     * @param string $content 文章正文 HTML。
     * @return string 处理后的 HTML。
     */
    public function typography_space_content( string $content ): string {
        // 提取 HTML 标签外的文本内容进行替换，标签内部不动
        // 用回调函数对纯文本部分做正则替换
        return preg_replace_callback(
            '/([^<]*)(<[^>]*>)?/s',
            function ( $matches ) {
                $text  = $matches[1];
                $tag   = $matches[2] ?? '';
                $text  = preg_replace( '~(\p{Han})([a-zA-Z0-9\p{Ps}\p{Pi}])~u', '\1 \2', $text );
                $text  = preg_replace( '~([a-zA-Z0-9\p{Pe}\p{Pf}])(\p{Han})~u', '\1 \2', $text );
                $text  = preg_replace( '~([!?‽:;,.%])(\p{Han})~u', '\1 \2', $text );
                $text  = preg_replace( '~(\p{Han})([@$#])~u', '\1 \2', $text );
                return $text . $tag;
            },
            $content
        );
    }

    /**
     * 文章正文两端对齐（仅 single 页面注入 CSS）
     *
     * @return void
     */
    public function typography_align_css(): void {
        if ( ! is_single() ) {
            return;
        }
        $css = '.entry-content p{text-align:justify;}'
            . '.entry-content .wp-block-group p,.entry-content .wp-block-columns p,'
            . '.entry-content .wp-block-media-text p,.entry-content .wp-block-quote p{text-align:unset!important;}'
            . '.entry-content .wp-block-columns .has-text-align-center{text-align:center!important;}';
        wp_register_style( 'drea-typography-align', false, [], DREA_VERSION );
        wp_add_inline_style( 'drea-typography-align', $css );
        wp_enqueue_style( 'drea-typography-align' );
    }

    /**
     * 弯引号：直引号转「」『』（the_content / the_excerpt 过滤器）
     *
     * Bug 4 修复：从输出缓冲全页替换改为仅过滤正文，避免误伤 HTML 属性与脚本
     *
     * @param string $content 文章正文 HTML。
     * @return string 处理后的 HTML。
     */
    public function typography_quotes_content( string $content ): string {
        // 仅对标签外文本做替换
        return preg_replace_callback(
            '/([^<]*)(<[^>]*>)?/s',
            function ( $matches ) {
                $text = $matches[1];
                $tag  = $matches[2] ?? '';
                // 英文缩写撇号保留
                $text = str_replace( [ 'n’t', '’s', '’m', '’re', '’ve', '’d', '’ll' ], [ "n&rsquo;t", '&rsquo;s', '&rsquo;m', '&rsquo;re', '&rsquo;ve', '&rsquo;d', '&rsquo;ll' ], $text );
                $text = str_replace( '“', '&#12300;', $text );
                $text = str_replace( '”', '&#12301;', $text );
                $text = str_replace( '‘', '&#12302;', $text );
                $text = str_replace( '’', '&#12303;', $text );
                return $text . $tag;
            },
            $content
        );
    }

    /**
     * 段首缩进 2em（注入 CSS，排除 Gutenberg 版式区块）
     *
     * @return void
     */
    public function typography_indent_css(): void {
        $css = '.entry-content p{text-indent:2em;}'
            . '.entry-content .wp-block-group p,.entry-content .wp-block-columns p,'
            . '.entry-content .wp-block-media-text p,.entry-content .wp-block-quote p{text-indent:0;}';
        wp_register_style( 'drea-typography-indent', false, [], DREA_VERSION );
        wp_add_inline_style( 'drea-typography-indent', $css );
        wp_enqueue_style( 'drea-typography-indent' );
    }

    // ─── 后台广告拦截 ─────────────────────────────────────

    /**
     * 输出广告拦截 CSS（admin_head）
     *
     * @return void
     */
    public function admin_adblock_css(): void {
        $rules = self::get_adblock_rules();
        if ( empty( $rules ) ) {
            return;
        }
        $css = '';
        foreach ( $rules as $rule ) {
            $selector = trim( $rule );
            if ( '' === $selector ) {
                continue;
            }
            // 使用 CSS.escape 友好的简单转义：去掉潜在注入风险字符
            $safe = preg_replace( '/[^\w\s.#\[\]="\'\-_:>+,*()~|]/u', '', $selector );
            if ( '' === $safe ) {
                continue;
            }
            $css .= $safe . '{display:none!important;}';
        }
        if ( '' === $css ) {
            return;
        }
        wp_register_style( 'drea-admin-adblock', false, [], DREA_VERSION );
        wp_add_inline_style( 'drea-admin-adblock', $css );
        wp_enqueue_style( 'drea-admin-adblock' );
    }
}

// 注册模块
Core::get_instance()->register_module( new Site_Optimize() );
