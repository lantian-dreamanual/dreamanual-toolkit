<?php
/**
 * 搜索推送模块 — 文章发布/更新时自动推送到百度、Bing
 *
 * 每个搜索引擎独立开关，互不耦合。
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class Search_Push extends Module_Base {

    /** @var string 模块 ID */
    const MODULE_ID = 'search-push';

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
        return __('Search Push', 'dreamanual-toolkit' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Auto-push links to Baidu and Bing on post publish/update, improving search engine indexing efficiency.', 'dreamanual-toolkit' );
    }

    /**
     * 获取设置页 URL
     */
    public function get_settings_url(): string {
        return admin_url( 'admin.php?page=drea-sp' );
    }

    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // 管理菜单 & 资源
        $this->on( 'admin_menu', [ $this, 'add_admin_menu' ] );
        $this->on( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX
        $this->on( 'wp_ajax_drea_sp_save_settings', [ $this, 'ajax_save_settings' ] );
        $this->on( 'wp_ajax_drea_sp_get_settings', [ $this, 'ajax_get_settings' ] );
        $this->on( 'wp_ajax_drea_sp_test_push', [ $this, 'ajax_test_push' ] );

        // ─── 发布/更新文章时推送 ───
        $any_enabled = (
            $this->get_option( 'baidu_enabled', false ) ||
            $this->get_option( 'bing_enabled', false )
        );
        if ( $any_enabled ) {
            $this->on( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );
            $this->on( 'drea_sp_delayed_push', [ $this, 'do_delayed_push' ] );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function on_activate(): void {
        // 迁移旧 baidu-submit-link 插件设置
        $old = get_option( 'bsl_option' );
        if ( is_array( $old ) && false === get_option( 'drea_search_push_baidu_enabled' ) ) {
            // 百度
            $baidu_token = isset( $old['token'] ) ? $old['token'] : '';
            if ( ! empty( $baidu_token ) && ! empty( $old['in_bd_active'] ) ) {
                update_option( 'drea_search_push_baidu_enabled', true );
                // token 可能是完整 URL 或纯 token
                if ( 0 === strpos( $baidu_token, 'http' ) ) {
                    // 从完整 URL 提取 site 和 token 参数
                    $parts = wp_parse_url( $baidu_token );
                    if ( ! empty( $parts['query'] ) ) {
                        wp_parse_str( $parts['query'], $q );
                        if ( ! empty( $q['token'] ) ) {
                            update_option( 'drea_search_push_baidu_token', sanitize_text_field( $q['token'] ) );
                        }
                        if ( ! empty( $q['site'] ) ) {
                            // 百度 site 参数可能包含协议，如 https://www.example.com
                            $site_host = wp_parse_url( $q['site'], PHP_URL_HOST );
                            update_option( 'drea_search_push_baidu_site', sanitize_text_field( $site_host ?: $q['site'] ) );
                        }
                    }
                } else {
                    update_option( 'drea_search_push_baidu_token', sanitize_text_field( $baidu_token ) );
                    // 无完整 URL 时默认使用当前站点域名
                    update_option( 'drea_search_push_baidu_site', wp_parse_url( home_url(), PHP_URL_HOST ) );
                }
            }

            // Bing
            if ( ! empty( $old['bing_key'] ) && ( ! empty( $old['bing_auto'] ) || ! empty( $old['bing_manual'] ) ) ) {
                update_option( 'drea_search_push_bing_enabled', true );
                update_option( 'drea_search_push_bing_key', sanitize_text_field( $old['bing_key'] ) );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(): void {
        $options = [
            'drea_search_push_baidu_enabled',
            'drea_search_push_baidu_token',
            'drea_search_push_baidu_site',
            'drea_search_push_bing_enabled',
            'drea_search_push_bing_key',
        ];
        foreach ( $options as $opt ) {
            delete_option( $opt );
        }
    }

    // ─── 管理菜单 ─────────────────────────────────────

    public function add_admin_menu(): void {
        add_submenu_page(
            'dreamanual-toolkit',
            __('Search Push', 'dreamanual-toolkit' ),
            __('Search Push', 'dreamanual-toolkit' ),
            'manage_options',
            'drea-sp',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__('You do not have permission to access this page.', 'dreamanual-toolkit' ) );
        }
        include __DIR__ . '/admin/settings-page.php';
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( false === strpos( $hook, 'drea-sp' ) ) return;

        $module_url  = DREA_URL . 'modules/search-push';
        $module_path = DREA_PATH . 'modules/search-push';

        wp_enqueue_style(
            'drea-sp-admin',
            $module_url . '/assets/css/admin.css',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/css/admin.css' )
        );

        wp_enqueue_script(
            'drea-sp-admin',
            $module_url . '/assets/js/admin.js',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/js/admin.js' ),
            true
        );

        wp_localize_script( 'drea-sp-admin', 'dreaSp', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'drea_sp_nonce' ),
            'i18n'    => [
                'saved'              => __('Settings saved.', 'dreamanual-toolkit' ),
                'failed'             => __('Save failed, please retry.', 'dreamanual-toolkit' ),
                'error'              => __('Operation failed, please retry later.', 'dreamanual-toolkit' ),
                'testOk'             => __('Push request sent, please check results on the search resource platform.', 'dreamanual-toolkit' ),
                'testFail'           => __('Push failed, please check configuration.', 'dreamanual-toolkit' ),
                'baiduTokenRequired' => __('Baidu push enabled, please fill in push token first.', 'dreamanual-toolkit' ),
                'baiduSiteRequired'  => __('Baidu push enabled, please fill in site domain first.', 'dreamanual-toolkit' ),
                'bingKeyRequired'    => __('Bing push enabled, please fill in API Key first.', 'dreamanual-toolkit' ),
                'testUnsaved'        => __('Current settings may not be saved yet. Test will use the saved configuration. Continue?', 'dreamanual-toolkit' ),
            ],
        ] );
    }

    // ─── AJAX ─────────────────────────────────────────

    public function ajax_save_settings(): void {
        check_ajax_referer( 'drea_sp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $baidu_enabled = isset( $_POST['baidu_enabled'] ) ? boolval( $_POST['baidu_enabled'] ) : false;
        $baidu_token   = isset( $_POST['baidu_token'] ) ? sanitize_text_field( wp_unslash( $_POST['baidu_token'] ) ) : '';
        $baidu_site    = isset( $_POST['baidu_site'] ) ? sanitize_text_field( wp_unslash( $_POST['baidu_site'] ) ) : '';
        $bing_enabled  = isset( $_POST['bing_enabled'] ) ? boolval( $_POST['bing_enabled'] ) : false;
        $bing_key      = isset( $_POST['bing_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bing_key'] ) ) : '';

        // 后端配置完整性校验 (F-10)
        if ( $baidu_enabled ) {
            if ( '' === $baidu_token ) {
                wp_send_json_error( [ 'message' => __('Baidu push enabled, please fill in push token first.', 'dreamanual-toolkit' ) ] );
            }
            if ( '' === $baidu_site ) {
                wp_send_json_error( [ 'message' => __('Baidu push enabled, please fill in site domain first.', 'dreamanual-toolkit' ) ] );
            }
        }
        if ( $bing_enabled && '' === $bing_key ) {
            wp_send_json_error( [ 'message' => __('Bing push enabled, please fill in API Key first.', 'dreamanual-toolkit' ) ] );
        }

        // 保存设置 (F-07: 检查返回值)
        $settings_to_save = [
            'drea_search_push_baidu_enabled' => $baidu_enabled,
            'drea_search_push_baidu_token'   => $baidu_token,
            'drea_search_push_baidu_site'    => $baidu_site,
            'drea_search_push_bing_enabled'  => $bing_enabled,
            'drea_search_push_bing_key'      => $bing_key,
        ];

        $save_failed = false;
        foreach ( $settings_to_save as $key => $value ) {
            $result = update_option( $key, $value );
            if ( false === $result && get_option( $key ) != $value ) {
                $save_failed = true;
            }
        }

        if ( $save_failed ) {
            wp_send_json_error( [ 'message' => __('Save failed, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('Settings saved.', 'dreamanual-toolkit' ) ] );
    }

    public function ajax_get_settings(): void {
        check_ajax_referer( 'drea_sp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [
            'baidu_enabled' => (bool) $this->get_option( 'baidu_enabled', false ),
            'baidu_token'   => $this->get_option( 'baidu_token', '' ),
            'baidu_site'    => $this->get_option( 'baidu_site', '' ),
            'bing_enabled'  => (bool) $this->get_option( 'bing_enabled', false ),
            'bing_key'      => $this->get_option( 'bing_key', '' ),
        ] );
    }

    /**
     * AJAX: 测试推送
     */
    public function ajax_test_push(): void {
        check_ajax_referer( 'drea_sp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $engine   = isset( $_POST['engine'] ) ? sanitize_text_field( wp_unslash( $_POST['engine'] ) ) : '';

        // 测试推送使用用户配置的站点域名，而非 home_url
        $baidu_site = $this->get_option( 'baidu_site', '' );
        if ( ! $baidu_site ) {
            $baidu_site = wp_parse_url( home_url(), PHP_URL_HOST );
        }
        $test_url = ( 0 === strpos( $baidu_site, 'http' ) ? $baidu_site : 'https://' . $baidu_site ) . '/';

        $result = false;
        switch ( $engine ) {
            case 'baidu':
                $result = $this->push_baidu( [ $test_url ] );
                break;
            case 'bing':
                $result = $this->push_bing( [ $test_url ] );
                break;
            default:
                wp_send_json_error( [ 'message' => __('Unknown search engine.', 'dreamanual-toolkit' ) ] );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => __('Push request sent, please check results on the search resource platform.', 'dreamanual-toolkit' ) ] );
    }

    // ─── 推送触发 ─────────────────────────────────────

    /**
     * 文章状态变更时触发推送
     *
     * @param string   $new_status 新状态。
     * @param string   $old_status 旧状态。
     * @param \WP_Post $post       文章对象。
     */
    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        // 仅处理 post 类型
        if ( 'post' !== $post->post_type ) return;

        // 仅在首次发布或从非 publish 变为 publish 时推送
        if ( 'publish' !== $new_status ) return;
        if ( 'publish' === $old_status && 'publish' === $new_status ) return;

        // 延迟 30 秒推送，避免阻塞发布流程
        $scheduled = wp_schedule_single_event( time() + 30, 'drea_sp_delayed_push', [ $post->ID ] );
        if ( false === $scheduled ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 记录调度失败到服务器日志，用于运维排查
            error_log( sprintf( '[DREA Search Push] Failed to schedule delayed push task (Post ID %d)', $post->ID ) );
        }
    }

    /**
     * 延迟推送回调
     *
     * @param int $post_id 文章 ID。
     */
    public function do_delayed_push( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) return;

        $url = get_permalink( $post_id );
        if ( ! $url ) return;

        $urls = [ $url ];

        if ( $this->get_option( 'baidu_enabled', false ) ) {
            $result = $this->push_baidu( $urls );
            if ( is_wp_error( $result ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 记录推送失败到服务器日志，用于运维排查
                error_log( sprintf( '[DREA Search Push] Baidu push failed (Post ID %d): %s', $post_id, $result->get_error_message() ) );
            }
        }
        if ( $this->get_option( 'bing_enabled', false ) ) {
            $result = $this->push_bing( $urls );
            if ( is_wp_error( $result ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 记录推送失败到服务器日志，用于运维排查
                error_log( sprintf( '[DREA Search Push] Bing push failed (Post ID %d): %s', $post_id, $result->get_error_message() ) );
            }
        }
    }

    // ─── 百度推送 ─────────────────────────────────────

    /**
     * 推送 URL 到百度普通收录
     *
     * @param string[] $urls URL 列表。
     * @return true|\WP_Error
     */
    protected function push_baidu( array $urls ) {
        $token    = $this->get_option( 'baidu_token', '' );
        $site     = $this->get_option( 'baidu_site', '' );
        // 回退：若未设置 baidu_site 则使用当前站点域名
        if ( ! $site ) {
            $site = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        if ( ! $token || ! $site ) {
            return new \WP_Error( 'drea_sp_baidu', __('Baidu push token or site domain not configured.', 'dreamanual-toolkit' ) );
        }

        // 优化 1：百度推送接口改为 HTTPS
        $api_url = 'https://data.zz.baidu.com/urls?site=' . urlencode( $site ) . '&token=' . urlencode( $token );

        $response = wp_remote_post( $api_url, [
            'body'    => implode( "\n", $urls ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'drea_sp_baidu', $this->translate_push_error( $response->get_error_message(), 'Baidu' ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $msg = isset( $body['message'] ) ? $body['message'] : __('Unknown error', 'dreamanual-toolkit' );
            /* translators: 1: HTTP status code, 2: error message from the Baidu push API */
            return new \WP_Error( 'drea_sp_baidu', sprintf( __('Baidu push failed (%1$d): %2$s', 'dreamanual-toolkit' ), $code, $msg ) );
        }

        return true;
    }

    // ─── Bing 推送 ─────────────────────────────────────

    /**
     * 推送 URL 到 Bing
     *
     * @param string[] $urls URL 列表。
     * @return true|\WP_Error
     */
    protected function push_bing( array $urls ) {
        $key = $this->get_option( 'bing_key', '' );
        if ( ! $key ) {
            return new \WP_Error( 'drea_sp_bing', __('Bing API Key not configured.', 'dreamanual-toolkit' ) );
        }

        $site_url = home_url( '/' );
        $is_batch = count( $urls ) > 1;

        $api_url = $is_batch
            ? 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey=' . urlencode( $key )
            : 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrl?apikey=' . urlencode( $key );

        $body = $is_batch
            ? wp_json_encode( [ 'siteUrl' => $site_url, 'urlList' => $urls ] )
            : wp_json_encode( [ 'siteUrl' => $site_url, 'url' => reset( $urls ) ] );

        $response = wp_remote_post( $api_url, [
            'headers' => [ 'Content-Type' => 'text/json; charset=utf-8' ],
            'body'    => $body,
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'drea_sp_bing', $this->translate_push_error( $response->get_error_message(), 'Bing' ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $res  = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $res['ErrorCode'] ) && 0 !== (int) $res['ErrorCode'] ) {
            /* translators: %d: error code returned by the Bing Webmaster API */
            return new \WP_Error( 'drea_sp_bing', sprintf( __('Bing push failed: ErrorCode %1$d', 'dreamanual-toolkit' ), (int) $res['ErrorCode'] ) );
        }

        if ( $code >= 400 ) {
            /* translators: %d: HTTP status code */
            return new \WP_Error( 'drea_sp_bing', sprintf( __('Bing push failed (HTTP %1$d)', 'dreamanual-toolkit' ), $code ) );
        }

        return true;
    }

    /**
     * 将推送网络错误翻译为用户友好的中文提示
     *
     * @param string $error   原始错误信息。
     * @param string $engine  搜索引擎名称（百度/Bing）。
     * @return string 友好的中文错误提示。
     */
    private function translate_push_error( string $error, string $engine ): string {
        // 优化 3：复用公共网络错误翻译器
        return Error_Translator::translate_network_error( $error, $engine . ' push' );
    }
}

// 注册模块
Core::get_instance()->register_module( new Search_Push() );
