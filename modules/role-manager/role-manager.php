<?php
/**
 * 角色管理模块 —— 迁移自"用户角色编辑"插件
 *
 * 提供角色和能力的可视化管理：查看角色能力矩阵、新建/复制/删除角色、
 * 编辑角色能力、用户角色快速变更。
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class Role_Manager extends Module_Base {

    /** @var string 模块 ID */
    const MODULE_ID = 'role-manager';

    /** @var array 禁止删除的角色 */
    const PROTECTED_ROLES = [ 'administrator' ];

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
        return __('Role Manager', 'dreamanual-toolkit' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Visually manage WordPress user roles and capabilities, with role duplication and bulk editing support.', 'dreamanual-toolkit' );
    }

    /**
     * 获取设置页 URL
     */
    public function get_settings_url(): string {
        return admin_url( 'admin.php?page=drea-rm' );
    }

    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        $this->on( 'admin_menu', [ $this, 'add_admin_menu' ] );
        $this->on( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX
        $this->on( 'wp_ajax_drea_rm_get_roles', [ $this, 'ajax_get_roles' ] );
        $this->on( 'wp_ajax_drea_rm_get_role', [ $this, 'ajax_get_role' ] );
        $this->on( 'wp_ajax_drea_rm_add_role', [ $this, 'ajax_add_role' ] );
        $this->on( 'wp_ajax_drea_rm_copy_role', [ $this, 'ajax_copy_role' ] );
        $this->on( 'wp_ajax_drea_rm_delete_role', [ $this, 'ajax_delete_role' ] );
        $this->on( 'wp_ajax_drea_rm_update_role', [ $this, 'ajax_update_role' ] );
        $this->on( 'wp_ajax_drea_rm_change_user_role', [ $this, 'ajax_change_user_role' ] );
    }

    /**
     * {@inheritdoc}
     * WP 原生角色存储在 wp_options 中，无需迁移
     */
    public function on_activate(): void {
        // 角色数据由 WP 原生管理，无需额外初始化
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(): void {
        // 不删除角色数据——角色属于 WP 核心，不应随插件卸载而清除
    }

    // ─── 管理菜单 ─────────────────────────────────────

    /**
     * 注册子菜单
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'dreamanual-toolkit',
            __('Role Manager', 'dreamanual-toolkit' ),
            __('Role Manager', 'dreamanual-toolkit' ),
            'manage_options',
            'drea-rm',
            [ $this, 'render_page' ]
        );
    }

    /**
     * 渲染页面
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__('You do not have permission to access this page.', 'dreamanual-toolkit' ) );
        }
        include __DIR__ . '/admin/roles-page.php';
    }

    /**
     * 加载管理资源
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( false === strpos( $hook, 'drea-rm' ) ) return;

        $module_url  = DREA_URL . 'modules/role-manager';
        $module_path = DREA_PATH . 'modules/role-manager';

        wp_enqueue_style(
            'drea-rm-admin',
            $module_url . '/assets/css/admin.css',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/css/admin.css' )
        );

        wp_enqueue_script(
            'drea-rm-admin',
            $module_url . '/assets/js/admin.js',
            [ 'drea-toolkit-common' ],
            $this->asset_version( $module_path . '/assets/js/admin.js' ),
            true
        );

        wp_localize_script( 'drea-rm-admin', 'dreaRm', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'drea_rm_nonce' ),
            'i18n'    => [
                /* translators: %s: Role name */
                'confirmDelete'       => __('Delete role "%s"? This action cannot be undone.', 'dreamanual-toolkit' ),
                /* translators: %d: Number of users with this role */
                'deleteRoleWithUsers' => __('Warning: This role has %d users. After deletion, these users will lose the role and may be unable to access the admin. Please assign them new roles before deleting.', 'dreamanual-toolkit' ),
                'cannotDelete'        => __('Cannot delete built-in roles.', 'dreamanual-toolkit' ),
                'roleAdded'           => __('Role added.', 'dreamanual-toolkit' ),
                'roleCopied'          => __('Role duplicated.', 'dreamanual-toolkit' ),
                'roleDeleted'         => __('Role deleted.', 'dreamanual-toolkit' ),
                'roleUpdated'         => __('Role capabilities updated.', 'dreamanual-toolkit' ),
                'userRoleChanged'     => __('User role changed.', 'dreamanual-toolkit' ),
                'failed'              => __('Operation failed, please retry.', 'dreamanual-toolkit' ),
                'error'               => __('Request failed, please retry later.', 'dreamanual-toolkit' ),
                'loadRolesFailed'     => __('Failed to load role list, possibly due to network or permission issues. Please refresh and retry.', 'dreamanual-toolkit' ),
                'networkError'        => __('Network error, please check connection and retry.', 'dreamanual-toolkit' ),
                'invalidSlug'         => __('Role key can only use lowercase letters, numbers, and underscores.', 'dreamanual-toolkit' ),
                'noRoles'             => __('No roles yet', 'dreamanual-toolkit' ),
                'builtIn'             => __('Built-in', 'dreamanual-toolkit' ),
                'edit'                => __('Edit', 'dreamanual-toolkit' ),
                'copy'                => __('Duplicate', 'dreamanual-toolkit' ),
                'delete'              => __('Delete', 'dreamanual-toolkit' ),
                'fillRequired'        => __('Please fill in role name and key', 'dreamanual-toolkit' ),
                'addRole'             => __('Add Role', 'dreamanual-toolkit' ),
                'copyRole'            => __('Duplicate Role', 'dreamanual-toolkit' ),
            ],
        ] );
    }

    // ─── AJAX 处理器 ──────────────────────────────────

    /**
     * AJAX: 获取所有角色
     */
    public function ajax_get_roles(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $wp_roles = wp_roles();
        $roles    = [];

        // 低危 3 优化：用 count_users() 一次取全部用户角色计数，避免逐角色 N 次查询
        $user_counts = count_users();
        $role_counts = isset( $user_counts['avail_roles'] ) ? $user_counts['avail_roles'] : [];

        foreach ( $wp_roles->roles as $name => $info ) {
            $user_count = isset( $role_counts[ $name ] ) ? (int) $role_counts[ $name ] : 0;
            $roles[]    = [
                'name'        => $name,
                'display_name' => translate_user_role( $info['name'] ),
                'capabilities' => array_keys( $info['capabilities'] ),
                'user_count'  => $user_count,
                'is_protected' => in_array( $name, self::PROTECTED_ROLES, true ),
            ];
        }

        wp_send_json_success( [ 'roles' => $roles ] );
    }

    /**
     * AJAX: 获取单个角色详情
     */
    public function ajax_get_role(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $role_name = sanitize_text_field( wp_unslash( $_POST['role'] ?? '' ) );
        $role      = get_role( $role_name );

        if ( ! $role ) {
            wp_send_json_error( [ 'message' => __('Role does not exist', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [
            'name'         => $role_name,
            'display_name' => translate_user_role( wp_roles()->roles[ $role_name ]['name'] ),
            'capabilities' => array_keys( $role->capabilities ),
        ] );
    }

    /**
     * AJAX: 添加新角色
     */
    public function ajax_add_role(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $role_slug    = sanitize_key( $_POST['role_slug'] ?? '' );

        if ( ! $display_name || ! $role_slug ) {
            wp_send_json_error( [ 'message' => __('Role name and key cannot be empty', 'dreamanual-toolkit' ) ] );
        }

        if ( get_role( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __('Role key already exists', 'dreamanual-toolkit' ) ] );
        }

        $result = add_role( $role_slug, $display_name, [ 'read' => true ] );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __('Failed to add role, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('Role added.', 'dreamanual-toolkit' ) ] );
    }

    /**
     * AJAX: 复制角色
     */
    public function ajax_copy_role(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $source  = sanitize_text_field( wp_unslash( $_POST['source_role'] ?? '' ) );
        $new_slug = sanitize_key( $_POST['new_role_slug'] ?? '' );
        $new_name = sanitize_text_field( wp_unslash( $_POST['new_role_name'] ?? '' ) );

        if ( ! $new_slug || ! $new_name ) {
            wp_send_json_error( [ 'message' => __('Role name and key cannot be empty', 'dreamanual-toolkit' ) ] );
        }

        $source_role = get_role( $source );
        if ( ! $source_role ) {
            wp_send_json_error( [ 'message' => __('Source role does not exist', 'dreamanual-toolkit' ) ] );
        }

        if ( get_role( $new_slug ) ) {
            wp_send_json_error( [ 'message' => __('Role key already exists', 'dreamanual-toolkit' ) ] );
        }

        $result = add_role( $new_slug, $new_name, $source_role->capabilities );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __('Failed to duplicate role, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('Role duplicated.', 'dreamanual-toolkit' ) ] );
    }

    /**
     * AJAX: 删除角色
     */
    public function ajax_delete_role(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $role_name = sanitize_text_field( wp_unslash( $_POST['role'] ?? '' ) );

        if ( in_array( $role_name, self::PROTECTED_ROLES, true ) ) {
            wp_send_json_error( [ 'message' => __('Cannot delete built-in protected role', 'dreamanual-toolkit' ) ] );
        }

        remove_role( $role_name );

        // 验证角色确实已被移除
        if ( get_role( $role_name ) ) {
            wp_send_json_error( [ 'message' => __('Failed to delete role, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('Role deleted.', 'dreamanual-toolkit' ) ] );
    }

    /**
     * AJAX: 更新角色能力
     */
    public function ajax_update_role(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $role_name = sanitize_text_field( wp_unslash( $_POST['role'] ?? '' ) );
        $caps_raw  = isset( $_POST['capabilities'] ) ? sanitize_text_field( wp_unslash( $_POST['capabilities'] ) ) : '[]';
        $caps      = json_decode( $caps_raw, true );

        if ( ! is_array( $caps ) ) {
            wp_send_json_error( [ 'message' => __('Invalid capability data format', 'dreamanual-toolkit' ) ] );
        }

        $role = get_role( $role_name );
        if ( ! $role ) {
            wp_send_json_error( [ 'message' => __('Role does not exist', 'dreamanual-toolkit' ) ] );
        }

        // Bug 2 修复：保护 administrator 角色不被移除 manage_options 能力，防止全站锁死
        if ( in_array( $role_name, self::PROTECTED_ROLES, true ) ) {
            if ( ! in_array( 'manage_options', $caps, true ) ) {
                wp_send_json_error( [
                    'message' => __( 'Cannot remove "manage_options" from a protected role (e.g. administrator).', 'dreamanual-toolkit' ),
                ] );
            }
        }

        // F-18: 先备份旧能力，更新失败时回滚
        $old_caps = $role->capabilities;

        // 先移除所有能力
        foreach ( $role->capabilities as $cap => $val ) {
            $role->remove_cap( $cap );
        }

        // 添加新能力
        foreach ( $caps as $cap ) {
            $role->add_cap( sanitize_text_field( $cap ) );
        }

        // 验证更新结果：重新获取角色确认能力已写入
        $check_role = get_role( $role_name );
        if ( ! $check_role || count( array_keys( $check_role->capabilities ) ) === 0 && count( $caps ) > 0 ) {
            // 回滚
            foreach ( $check_role ? $check_role->capabilities : [] as $cap => $val ) {
                $check_role->remove_cap( $cap );
            }
            foreach ( $old_caps as $cap => $val ) {
                $role->add_cap( $cap );
            }
            wp_send_json_error( [ 'message' => __('Failed to update role capabilities, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('Role capabilities updated.', 'dreamanual-toolkit' ) ] );
    }

    /**
     * AJAX: 更改用户角色
     */
    public function ajax_change_user_role(): void {
        check_ajax_referer( 'drea_rm_nonce', 'nonce' );
        if ( ! current_user_can( 'promote_users' ) ) {
            wp_send_json_error( [ 'message' => __('Insufficient permissions', 'dreamanual-toolkit' ) ] );
        }

        $user_id   = intval( $_POST['user_id'] ?? 0 );
        $new_role  = sanitize_text_field( wp_unslash( $_POST['new_role'] ?? '' ) );

        if ( ! $user_id || ! $new_role ) {
            wp_send_json_error( [ 'message' => __('Invalid parameter', 'dreamanual-toolkit' ) ] );
        }

        // F-03: 校验目标角色确实存在，防止用户失去所有角色
        if ( ! get_role( $new_role ) ) {
            wp_send_json_error( [ 'message' => __('Target role does not exist', 'dreamanual-toolkit' ) ] );
        }

        // 不允许修改自己的角色（防止锁死）
        if ( $user_id === get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __('Cannot modify your own role', 'dreamanual-toolkit' ) ] );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( [ 'message' => __('User does not exist', 'dreamanual-toolkit' ) ] );
        }

        $user->set_role( $new_role );

        // 验证角色确实已变更
        $updated_user = get_userdata( $user_id );
        if ( ! $updated_user || ! in_array( $new_role, $updated_user->roles, true ) ) {
            wp_send_json_error( [ 'message' => __('Failed to change user role, please retry.', 'dreamanual-toolkit' ) ] );
        }

        wp_send_json_success( [ 'message' => __('User role changed.', 'dreamanual-toolkit' ) ] );
    }
}

// 注册模块
Core::get_instance()->register_module( new Role_Manager() );
