<?php
/**
 * AI 优化模块 —— 迁移自 Dreamanual AI Tag Optimizer v1.3.1
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class AI_Optimizer extends Module_Base {

    /** @var string 模块 ID */
    const MODULE_ID = 'ai-optimizer';

    /**
     * 默认摘要提示词（与 AI_Client::build_prompt 中的默认规则一致）
     */
    const DEFAULT_EXCERPT_PROMPT = "请为这篇文章生成简介。规则如下：\n1. 如果文章是小说/故事类，直接从文中选取最有吸引力的原句作为简介。\n2. 如果是个人评论/随笔类且原文用第一人称写作，简介也请保持第一人称（用\"我\"而非\"作者\"），直接引用原文精彩观点或句子。\n3．其他类型文章提炼核心观点。\n控制在{excerpt_length}字以内，不要硬凑字数。";

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
        return __('AI Optimizer', 'dreamanual-toolkit' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('AI auto-generates tags, slugs, and excerpts. Supports DeepSeek / Kimi / OpenAI / Claude.', 'dreamanual-toolkit' );
    }

    /**
     * 获取模块设置页 URL
     *
     * @return string
     */
    public function get_settings_url(): string {
        return admin_url( 'admin.php?page=drea-ai-settings' );
    }

    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        $this->on( 'admin_menu', [ $this, 'add_admin_menu' ] );
        $this->on( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        $this->on( 'add_meta_boxes', [ $this, 'add_meta_box' ] );

        // AJAX handlers
        $this->on( 'wp_ajax_drea_ai_get_posts', [ $this, 'ajax_get_posts' ] );
        $this->on( 'wp_ajax_drea_ai_generate', [ $this, 'ajax_generate' ] );
        $this->on( 'wp_ajax_drea_ai_apply', [ $this, 'ajax_apply' ] );
        $this->on( 'wp_ajax_drea_ai_get_existing_tags', [ $this, 'ajax_get_existing_tags' ] );
        $this->on( 'wp_ajax_drea_ai_save_settings', [ $this, 'ajax_save_settings' ] );
        $this->on( 'wp_ajax_drea_ai_get_settings', [ $this, 'ajax_get_settings' ] );
        $this->on( 'wp_ajax_drea_ai_generate_single', [ $this, 'ajax_generate_single' ] );
    }

    /**
     * {@inheritdoc}
     * 从旧插件 dreaaita_* 迁移 option 数据
     */
    public function on_activate(): void {
        // 旧 → 新 option 映射
        $migrations = [
            'dreaaita_provider'       => 'drea_ai_optimizer_provider',
            'dreaaita_model'          => 'drea_ai_optimizer_model',
            'dreaaita_api_key'        => 'drea_ai_optimizer_api_key',
            'dreaaita_opt_tags'       => 'drea_ai_optimizer_opt_tags',
            'dreaaita_opt_slug'       => 'drea_ai_optimizer_opt_slug',
            'dreaaita_opt_excerpt'    => 'drea_ai_optimizer_opt_excerpt',
            'dreaaita_excerpt_length' => 'drea_ai_optimizer_excerpt_length',
            'dreaaita_excerpt_prompt' => 'drea_ai_optimizer_excerpt_prompt',
        ];

        foreach ( $migrations as $old => $new ) {
            if ( false !== get_option( $old ) && false === get_option( $new ) ) {
                $value = get_option( $old );
                // API Key 需要加密存储
                if ( 'dreaaita_api_key' === $old && ! empty( $value ) ) {
                    $value = AI_Client::encrypt( $value );
                }
                update_option( $new, $value );
            }
        }
    }

    /**
     * {@inheritdoc}
     * 删除该模块的所有 option
     */
    public function uninstall(): void {
        $options = [
            'drea_ai_optimizer_provider',
            'drea_ai_optimizer_model',
            'drea_ai_optimizer_api_key',
            'drea_ai_optimizer_opt_tags',
            'drea_ai_optimizer_opt_slug',
            'drea_ai_optimizer_opt_excerpt',
            'drea_ai_optimizer_tag_limit',
            'drea_ai_optimizer_excerpt_length',
            'drea_ai_optimizer_excerpt_prompt',
        ];
        foreach ( $options as $opt ) {
            delete_option( $opt );
        }
    }

    // ─── 管理菜单 ──────────────────────────────────────

    /**
     * 注册子菜单
     */
    public function add_admin_menu(): void {
        // 批量处理页
        add_submenu_page(
            'dreamanual-toolkit',
            __('AI Optimizer — Batch Process', 'dreamanual-toolkit' ),
            __('AI Optimizer', 'dreamanual-toolkit' ),
            'manage_options',
            'drea-ai-batch',
            [ $this, 'render_batch_page' ]
        );

        // 设置页
        add_submenu_page(
            'dreamanual-toolkit',
            __('AI Optimizer — Settings', 'dreamanual-toolkit' ),
            __('AI Settings', 'dreamanual-toolkit' ),
            'manage_options',
            'drea-ai-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * 渲染批量处理页
     */
    public function render_batch_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__('You do not have permission to access this page.', 'dreamanual-toolkit' ) );
        }
        include __DIR__ . '/admin/batch-page.php';
    }

    /**
     * 渲染设置页
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__('You do not have permission to access this page.', 'dreamanual-toolkit' ) );
        }
        include __DIR__ . '/admin/settings-page.php';
    }

    // ─── Meta Box ───────────────────────────────────────

    /**
     * 注册文章编辑器侧边栏 Meta Box
     */
    public function add_meta_box(): void {
        add_meta_box(
            'drea_ai_meta',
            __('AI Optimizer', 'dreamanual-toolkit' ),
            [ $this, 'render_meta_box' ],
            [ 'post', 'page' ],
            'side',
            'high'
        );
    }

    /**
     * 渲染 Meta Box
     *
     * @param \WP_Post $post 当前文章。
     */
    public function render_meta_box( \WP_Post $post ): void {
        include __DIR__ . '/admin/meta-box.php';
    }

    // ─── 资源加载 ───────────────────────────────────────

    /**
     * 加载后台资源
     *
     * @param string $hook 当前页面 hook。
     */
    public function enqueue_admin_assets( string $hook ): void {
        $module_url  = DREA_URL . 'modules/ai-optimizer';
        $module_path = DREA_PATH . 'modules/ai-optimizer';

        // 批量处理页
        if ( false !== strpos( $hook, 'drea-ai-batch' ) ) {
            wp_enqueue_style(
                'drea-ai-admin',
                $module_url . '/assets/css/admin.css',
                [ 'drea-toolkit-common' ],
                $this->asset_version( $module_path . '/assets/css/admin.css' )
            );
            wp_enqueue_script(
                'drea-ai-batch',
                $module_url . '/assets/js/batch.js',
                [ 'drea-toolkit-common' ],
                $this->asset_version( $module_path . '/assets/js/batch.js' ),
                true
            );
            $this->localize_script( 'drea-ai-batch', 'batch' );
        }

        // 设置页
        if ( false !== strpos( $hook, 'drea-ai-settings' ) ) {
            wp_enqueue_style(
                'drea-ai-admin',
                $module_url . '/assets/css/admin.css',
                [ 'drea-toolkit-common' ],
                $this->asset_version( $module_path . '/assets/css/admin.css' )
            );
            wp_enqueue_script(
                'drea-ai-settings',
                $module_url . '/assets/js/settings.js',
                [ 'drea-toolkit-common' ],
                $this->asset_version( $module_path . '/assets/js/settings.js' ),
                true
            );
            $this->localize_script( 'drea-ai-settings', 'settings' );
        }

        // 文章编辑器 Meta Box
        $screen = get_current_screen();
        if ( $screen && in_array( $screen->id, [ 'post', 'page' ], true ) ) {
            wp_enqueue_style(
                'drea-ai-admin',
                $module_url . '/assets/css/admin.css',
                [ 'drea-toolkit-common' ],
                $this->asset_version( $module_path . '/assets/css/admin.css' )
            );
            wp_enqueue_script(
                'drea-ai-meta',
                $module_url . '/assets/js/meta-box.js',
                [],
                $this->asset_version( $module_path . '/assets/js/meta-box.js' ),
                true
            );
            $this->localize_script( 'drea-ai-meta', 'meta' );
        }
    }

    /**
     * 本地化脚本数据
     *
     * @param string $handle 脚本 handle。
     * @param string $context 上下文 (batch/settings/meta)。
     */
    private function localize_script( string $handle, string $context ): void {
        $common_i18n = [
            'unknownError' => __('Unknown error', 'dreamanual-toolkit' ),
            'networkError' => __('Network error, please check connection.', 'dreamanual-toolkit' ),
        ];

        $context_i18n = [];

        if ( 'batch' === $context ) {
            $context_i18n = [
                'pleaseConfigureApiKey' => __('Please configure API Key in settings first.', 'dreamanual-toolkit' ),
                'failedToLoadSettings'  => __('Failed to load settings, please refresh.', 'dreamanual-toolkit' ),
                'postsLoaded'           => __(' posts loaded', 'dreamanual-toolkit' ),
                'noPostsInCategory'     => __('No posts in this category.', 'dreamanual-toolkit' ),
                'loadFailed'            => __('Load failed: ', 'dreamanual-toolkit' ),
                'noPosts'               => __('No posts', 'dreamanual-toolkit' ),
                'noTags'                => __('No tags', 'dreamanual-toolkit' ),
                'notGenerated'          => __('Not generated', 'dreamanual-toolkit' ),
                'generate'              => __('Generate', 'dreamanual-toolkit' ),
                'regenerate'            => __('Regenerate', 'dreamanual-toolkit' ),
                'apply'                 => __('Apply', 'dreamanual-toolkit' ),
                'processing'            => __('Processing...', 'dreamanual-toolkit' ),
                'done'                  => __('Done!', 'dreamanual-toolkit' ),
                'generating'            => __('Generating...', 'dreamanual-toolkit' ),
                'generatedSuccessfully' => __(' generated successfully', 'dreamanual-toolkit' ),
                'failed'                => __(' failed: ', 'dreamanual-toolkit' ),
                'retry'                 => __('Retry', 'dreamanual-toolkit' ),
                'loadTagsFailed'        => __('Failed to load existing tags, tag deduplication will not work.', 'dreamanual-toolkit' ),
                'applyConfirmTitle'     => __('Apply AI suggestions to "{title}"?', 'dreamanual-toolkit' ),
                'applyConfirmTags'      => __('Tags:', 'dreamanual-toolkit' ),
                'applyConfirmSlug'      => __('Slug:', 'dreamanual-toolkit' ),
                'applyConfirmExcerpt'   => __('Excerpt:', 'dreamanual-toolkit' ),
                'noPendingChanges'      => __('No pending changes.', 'dreamanual-toolkit' ),
                'applyAiSuggestionsTo'  => __('Apply AI suggestions to ', 'dreamanual-toolkit' ),
                'postsQuestion'         => __(' posts?', 'dreamanual-toolkit' ),
                'slugChangeNote'        => __('Note: Changing the Slug will cause old URLs to return 404. Ensure proper redirects are in place.', 'dreamanual-toolkit' ),
                'excerptOverwriteNote'  => __('Excerpt changes will overwrite current content.', 'dreamanual-toolkit' ),
                'applying'              => __('applying ', 'dreamanual-toolkit' ),
                'allChangesApplied'     => __('All changes applied! Refresh page to see updates.', 'dreamanual-toolkit' ),
                'changesApplied'        => __(' changes applied', 'dreamanual-toolkit' ),
                'applyFailed'           => __(' apply failed: ', 'dreamanual-toolkit' ),
                'networkErrorShort'     => __(' network error', 'dreamanual-toolkit' ),
                'loadSettingsFailed'    => __('Failed to load settings, please refresh and retry.', 'dreamanual-toolkit' ),
                'invalidTagLimit'       => __('Tag count must be between 1-20.', 'dreamanual-toolkit' ),
                'invalidExcerptLength'  => __('Excerpt length must be between 50-500.', 'dreamanual-toolkit' ),
                'prev'                  => __('Previous', 'dreamanual-toolkit' ),
                'next'                  => __('Next', 'dreamanual-toolkit' ),
                'page'                  => __('page ', 'dreamanual-toolkit' ),
                'generateForSelected'   => __('for selected ', 'dreamanual-toolkit' ),
                'selected'              => __(' posts to generate AI suggestions', 'dreamanual-toolkit' ),
                'batchSummary'          => __('Succeeded {0}, failed {1}', 'dreamanual-toolkit' ),
                'batchPartialFail'      => __('Some posts failed to apply, please check details above.', 'dreamanual-toolkit' ),
            ];
        } elseif ( 'settings' === $context ) {
            $context_i18n = [
                'settingsSaved'       => __('Settings saved.', 'dreamanual-toolkit' ),
                'saveFailed'          => __('Save failed: ', 'dreamanual-toolkit' ),
                'loadSettingsFailed'  => __('Failed to load settings, please refresh and retry.', 'dreamanual-toolkit' ),
                'networkError'        => __('Network error, please check connection.', 'dreamanual-toolkit' ),
                'invalidTagLimit'     => __('Tag count must be between 1-20.', 'dreamanual-toolkit' ),
                'invalidExcerptLength'=> __('Excerpt length must be between 50-500.', 'dreamanual-toolkit' ),
                'collapse'            => __('(click to collapse)', 'dreamanual-toolkit' ),
                'expand'              => __('(click to expand)', 'dreamanual-toolkit' ),
            ];
        } elseif ( 'meta' === $context ) {
            $context_i18n = [
                'pleaseSaveDraft'      => __('Please save the post draft first.', 'dreamanual-toolkit' ),
                'generationFailed'     => __('Generation failed: ', 'dreamanual-toolkit' ),
                'noTagsGenerated'      => __('No tags generated', 'dreamanual-toolkit' ),
                'applying'             => __('Applying...', 'dreamanual-toolkit' ),
                'applied'              => __('Applied! Page will refresh.', 'dreamanual-toolkit' ),
                'applyChanges'         => __('Apply Changes', 'dreamanual-toolkit' ),
                'applyFailed'          => __('Apply failed: ', 'dreamanual-toolkit' ),
                'notGenerated'         => __('Not generated', 'dreamanual-toolkit' ),
            ];
        }

        wp_localize_script( $handle, 'dreaAi', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'drea_ai_nonce' ),
            'i18n'    => array_merge( $common_i18n, $context_i18n ),
        ] );
    }

    // ─── AJAX 处理器 ────────────────────────────────────

    /**
     * AJAX: 获取文章列表
     */
    public function ajax_get_posts(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $page     = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, intval( $_POST['per_page'] ) ) ) : 20;
        $category = isset( $_POST['category'] ) ? intval( $_POST['category'] ) : 0;
        // 低危 1 修复：支持前端传入的 post_type 参数
        $post_type_raw = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
        $post_type     = in_array( $post_type_raw, get_post_types( [ 'public' => true ], 'names' ), true ) ? $post_type_raw : 'post';

        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( $category ) {
            $args['cat'] = $category;
        }

        $query = new \WP_Query( $args );
        $posts = [];

        foreach ( $query->posts as $post ) {
            $tags = get_the_tags( $post->ID );
            $posts[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'slug'    => $post->post_name,
                'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
                'tags'    => $tags && ! is_wp_error( $tags ) ? wp_list_pluck( $tags, 'name' ) : [],
                'date'    => $post->post_date,
            ];
        }

        wp_send_json_success( [
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ] );
    }

    /**
     * AJAX: 生成 AI 建议（批量页用，从后端设置读取 API Key）
     */
    public function ajax_generate(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $post_id        = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $existing_tags  = isset( $_POST['existing_tags'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['existing_tags'] ) ) : [];
        // F-33: 限制 existing_tags 数量上限，避免请求体过大
        if ( count( $existing_tags ) > 500 ) {
            $existing_tags = array_slice( $existing_tags, 0, 500 );
        }
        $opt_tags       = isset( $_POST['opt_tags'] ) ? boolval( $_POST['opt_tags'] ) : true;
        $opt_slug       = isset( $_POST['opt_slug'] ) ? boolval( $_POST['opt_slug'] ) : true;
        $opt_excerpt    = isset( $_POST['opt_excerpt'] ) ? boolval( $_POST['opt_excerpt'] ) : false;
        $tag_limit      = isset( $_POST['tag_limit'] ) ? max( 1, min( 20, intval( $_POST['tag_limit'] ) ) ) : 5;
        $excerpt_length = isset( $_POST['excerpt_length'] ) ? max( 50, min( 500, intval( $_POST['excerpt_length'] ) ) ) : 100;
        $excerpt_prompt = isset( $_POST['excerpt_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt_prompt'] ) ) : '';

        if ( ! $post_id ) {
            wp_send_json_error( __('Invalid post ID.', 'dreamanual-toolkit' ) );
        }

        // Bug 5 修复：从后端设置读取 provider/model/api_key，不再从前端接收
        $settings = $this->get_settings_array();
        if ( empty( $settings['api_key'] ) ) {
            wp_send_json_error( __('Please configure API Key in "AI Optimizer → Settings" first.', 'dreamanual-toolkit' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( __('Post not found.', 'dreamanual-toolkit' ) );
        }

        $content = wp_strip_all_tags( $post->post_content );
        if ( mb_strlen( $content ) > 3000 ) {
            $content = mb_substr( $content, 0, 3000 ) . '...';
        }

        $current_tags      = get_the_tags( $post_id );
        $current_tag_names = $current_tags && ! is_wp_error( $current_tags ) ? wp_list_pluck( $current_tags, 'name' ) : [];

        $ai     = new AI_Client( $settings['provider'], $settings['api_key'], $settings['model'] );
        $result = $ai->generate_tags_and_slug( $post->post_title, $content, $current_tag_names, $existing_tags, $opt_tags, $opt_slug, $opt_excerpt, $excerpt_length, $excerpt_prompt, $tag_limit );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $this->friendly_error( $result ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 生成 AI 建议（Meta Box 用，从设置读取 API Key）
     */
    public function ajax_generate_single(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( __('Invalid post ID.', 'dreamanual-toolkit' ) );
        }

        $settings = $this->get_settings_array();
        if ( empty( $settings['api_key'] ) ) {
            wp_send_json_error( __('Please configure API Key in "AI Optimizer → Settings" first.', 'dreamanual-toolkit' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( __('Post not found.', 'dreamanual-toolkit' ) );
        }

        // Bug 1 修复：文章级权限校验，防止越权读取/处理他人文章内容
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'You do not have permission to edit this post.', 'dreamanual-toolkit' ) );
        }

        $content = wp_strip_all_tags( $post->post_content );
        if ( mb_strlen( $content ) > 3000 ) {
            $content = mb_substr( $content, 0, 3000 ) . '...';
        }

        $current_tags      = get_the_tags( $post_id );
        $current_tag_names = $current_tags && ! is_wp_error( $current_tags ) ? wp_list_pluck( $current_tags, 'name' ) : [];

        $all_tags = get_terms( [
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'fields'     => 'names',
        ] );
        $existing_tags = $all_tags && ! is_wp_error( $all_tags ) ? $all_tags : [];

        $ai     = new AI_Client( $settings['provider'], $settings['api_key'], $settings['model'] );
        $result = $ai->generate_tags_and_slug(
            $post->post_title, $content, $current_tag_names, $existing_tags,
            $settings['opt_tags'], $settings['opt_slug'], $settings['opt_excerpt'],
            $settings['excerpt_length'], $settings['excerpt_prompt'], $settings['tag_limit']
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $this->friendly_error( $result ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 应用 AI 建议
     */
    public function ajax_apply(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $tags    = isset( $_POST['tags'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) ) : [];
        $slug    = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $excerpt = isset( $_POST['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) ) : '';

        if ( ! $post_id ) {
            wp_send_json_error( __('Invalid post ID.', 'dreamanual-toolkit' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( __('Post not found.', 'dreamanual-toolkit' ) );
        }

        // Bug 1 修复：文章级权限校验，防止越权修改他人文章
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'You do not have permission to edit this post.', 'dreamanual-toolkit' ) );
        }

        $update_data = [ 'ID' => $post_id ];

        if ( ! empty( $tags ) ) {
            $tag_result = wp_set_post_tags( $post_id, $tags );
            if ( is_wp_error( $tag_result ) ) {
                wp_send_json_error( __('Tag save failed, please retry.', 'dreamanual-toolkit' ) );
            }
        }

        if ( ! empty( $slug ) && $slug !== $post->post_name ) {
            $slug = wp_unique_post_slug( $slug, $post_id, $post->post_status, $post->post_type, $post->post_parent );
            $update_data['post_name'] = $slug;
        }

        if ( ! empty( $excerpt ) ) {
            $update_data['post_excerpt'] = $excerpt;
        }

        if ( count( $update_data ) > 1 ) {
            $updated = wp_update_post( $update_data );
            if ( is_wp_error( $updated ) || 0 === $updated ) {
                wp_send_json_error( __('Post update failed, please retry.', 'dreamanual-toolkit' ) );
            }
        }

        wp_send_json_success( [
            'message' => __('Changes applied.', 'dreamanual-toolkit' ),
            'tags'    => $tags,
            'slug'    => $slug,
            'excerpt' => $excerpt,
        ] );
    }

    /**
     * AJAX: 获取所有已有标签
     */
    public function ajax_get_existing_tags(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $tags = get_terms( [
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'fields'     => 'names',
        ] );

        if ( is_wp_error( $tags ) ) {
            wp_send_json_error( __('Failed to load tag list, please retry.', 'dreamanual-toolkit' ) );
        }

        wp_send_json_success( [
            'tags' => $tags ? $tags : [],
        ] );
    }

    /**
     * 将 AI_Client 返回的 WP_Error 转换为用户友好的中文提示
     *
     * AI_Client 内部已对大部分错误做了中文翻译，
     * 此方法作为兜底：如果错误消息中仍残留英文，则替换为通用提示。
     *
     * @param \WP_Error $error AI 返回的错误对象。
     * @return string 友好的中文错误提示。
     */
    private function friendly_error( \WP_Error $error ): string {
        $msg = $error->get_error_message();
        // 简单判断：如果消息中包含常见英文错误标记，使用通用兜底
        if ( preg_match( '/^[[:print:]\s]*$/', $msg ) && preg_match( '/\b(error|failed|invalid|not found|unable|denied)\b/i', $msg ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log original error to server log for debugging
            error_log( '[DREA AI] Original error: ' . $msg );
            return __('AI processing failed, please retry later. If the issue persists, check AI Optimizer settings.', 'dreamanual-toolkit' );
        }
        return $msg;
    }

    /**
     * AJAX: 保存设置
     */
    public function ajax_save_settings(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $provider       = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'deepseek';
        $model          = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
        $api_key        = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $opt_tags       = isset( $_POST['opt_tags'] ) ? boolval( $_POST['opt_tags'] ) : true;
        $opt_slug       = isset( $_POST['opt_slug'] ) ? boolval( $_POST['opt_slug'] ) : true;
        $opt_excerpt    = isset( $_POST['opt_excerpt'] ) ? boolval( $_POST['opt_excerpt'] ) : false;
        $tag_limit      = isset( $_POST['tag_limit'] ) ? max( 1, min( 20, intval( $_POST['tag_limit'] ) ) ) : 5;
        $excerpt_length = isset( $_POST['excerpt_length'] ) ? max( 50, min( 500, intval( $_POST['excerpt_length'] ) ) ) : 100;
        $excerpt_prompt = isset( $_POST['excerpt_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt_prompt'] ) ) : '';

        // F-05: provider 白名单校验
        $valid_providers = [ 'deepseek', 'kimi', 'openai', 'claude' ];
        if ( ! in_array( $provider, $valid_providers, true ) ) {
            wp_send_json_error( __('Unsupported AI provider, please choose DeepSeek / Kimi / OpenAI / Claude.', 'dreamanual-toolkit' ) );
        }

        // 批量保存，任一失败则报错
        $options = [
            'drea_ai_optimizer_provider'       => $provider,
            'drea_ai_optimizer_model'          => $model,
            'drea_ai_optimizer_opt_tags'       => $opt_tags,
            'drea_ai_optimizer_opt_slug'       => $opt_slug,
            'drea_ai_optimizer_opt_excerpt'    => $opt_excerpt,
            'drea_ai_optimizer_tag_limit'      => $tag_limit,
            'drea_ai_optimizer_excerpt_length' => $excerpt_length,
            'drea_ai_optimizer_excerpt_prompt' => $excerpt_prompt,
        ];

        $save_failed = false;
        foreach ( $options as $key => $value ) {
            $result = update_option( $key, $value );
            // update_option 返回 false 可能是值未变或真正失败，二次验证
            if ( false === $result && get_option( $key ) != $value ) {
                $save_failed = true;
            }
        }

        if ( $save_failed ) {
            wp_send_json_error( __('Save failed, please retry.', 'dreamanual-toolkit' ) );
        }

        // API Key：空值表示清除已存储的 Key
        if ( '' === $api_key ) {
            delete_option( 'drea_ai_optimizer_api_key' );
        } else {
            update_option( 'drea_ai_optimizer_api_key', AI_Client::encrypt( $api_key ) );
        }

        wp_send_json_success( [
            'message' => __('Settings saved.', 'dreamanual-toolkit' ),
        ] );
    }

    /**
     * AJAX: 获取设置
     */
    public function ajax_get_settings(): void {
        check_ajax_referer( 'drea_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __('Insufficient permissions.', 'dreamanual-toolkit' ) );
        }

        $settings = $this->get_settings_array();

        // Bug 5 修复：不下发明文 API Key 到前端，仅返回是否已配置
        $settings['api_key'] = ! empty( $settings['api_key'] );

        wp_send_json_success( $settings );
    }

    // ─── 辅助方法 ──────────────────────────────────────

    /**
     * 获取设置数组（API Key 解密）
     *
     * @return array
     */
    private function get_settings_array(): array {
        $encrypted_key = get_option( 'drea_ai_optimizer_api_key', '' );
        $api_key       = '';

        // 尝试解密；如果是旧版明文 key 则直接使用
        if ( ! empty( $encrypted_key ) ) {
            $decrypted = AI_Client::decrypt( $encrypted_key );
            $api_key   = ! empty( $decrypted ) ? $decrypted : $encrypted_key;
        }

        return [
            'provider'       => get_option( 'drea_ai_optimizer_provider', 'deepseek' ),
            'model'          => get_option( 'drea_ai_optimizer_model', 'deepseek-chat' ),
            'api_key'        => $api_key,
            'opt_tags'       => (bool) get_option( 'drea_ai_optimizer_opt_tags', true ),
            'opt_slug'       => (bool) get_option( 'drea_ai_optimizer_opt_slug', true ),
            'opt_excerpt'    => (bool) get_option( 'drea_ai_optimizer_opt_excerpt', false ),
            'tag_limit'      => (int) get_option( 'drea_ai_optimizer_tag_limit', 5 ),
            'excerpt_length' => (int) get_option( 'drea_ai_optimizer_excerpt_length', 100 ),
            'excerpt_prompt' => get_option( 'drea_ai_optimizer_excerpt_prompt', '' ),
        ];
    }
}

// 注册模块到 Core
Core::get_instance()->register_module( new AI_Optimizer() );
