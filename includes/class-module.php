<?php
/**
 * 模块基类 —— 所有模块必须继承此类
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

abstract class Module_Base {

    /**
     * 模块唯一 ID（如 'ai-optimizer'）
     *
     * @return string
     */
    abstract public function get_id(): string;

    /**
     * 模块显示名（如 'AI 优化'）
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * 模块描述
     *
     * @return string
     */
    abstract public function get_description(): string;

    /**
     * 模块版本号
     *
     * @return string
     */
    public function get_version(): string {
        return DREA_VERSION;
    }

    /**
     * 模块主文件路径（相对于插件根目录）
     *
     * @return string
     */
    public function get_path(): string {
        return 'modules/' . $this->get_id() . '/' . $this->get_id() . '.php';
    }

    /**
     * 注册 WordPress hooks —— 模块启用时由 Core 调用
     *
     * @return void
     */
    abstract public function register_hooks(): void;

    /**
     * 模块启用时执行 —— 初始化默认配置、创建数据表等
     *
     * @return void
     */
    public function on_activate(): void {
        // 默认空实现，子类按需覆盖
    }

    /**
     * 模块停用时执行 —— 清理 hook、可选保留数据
     *
     * @return void
     */
    public function on_deactivate(): void {
        // 默认空实现，子类按需覆盖
    }

    /**
     * 模块卸载时执行 —— 删除该模块所有 option 和数据表
     *
     * @return void
     */
    public function uninstall(): void {
        // 默认空实现，子类按需覆盖
    }

    /**
     * 获取模块 option 值的快捷方法
     *
     * @param string $key     option 名（不含前缀）。
     * @param mixed  $default 默认值。
     * @return mixed
     */
    protected function get_option( string $key, $default = false ) {
        return get_option( 'drea_' . str_replace( '-', '_', $this->get_id() ) . '_' . $key, $default );
    }

    /**
     * 更新模块 option 的快捷方法
     *
     * @param string $key   option 名（不含前缀）。
     * @param mixed  $value 值。
     * @return bool
     */
    protected function update_option( string $key, $value ): bool {
        return update_option( 'drea_' . str_replace( '-', '_', $this->get_id() ) . '_' . $key, $value );
    }

    /**
     * 删除模块 option 的快捷方法
     *
     * @param string $key option 名（不含前缀）。
     * @return bool
     */
    protected function delete_option( string $key ): bool {
        return delete_option( 'drea_' . str_replace( '-', '_', $this->get_id() ) . '_' . $key );
    }

    /**
     * 模块是否已启用
     *
     * @return bool
     */
    public function is_active(): bool {
        return Core::get_instance()->is_module_active( $this->get_id() );
    }

/**
     * 获取资源版本号（filemtime 失败时回退到插件版本号）
     *
     * 低危 2 修复：各模块统一复用此方法替代直接调用 filemtime()，避免文件缺失时返回 false
     *
     * @param string $path 资源文件路径。
     * @return string|int 版本号。
     */
    protected function asset_version( string $path ) {
        $mtime = @filemtime( $path );
        return false === $mtime ? DREA_VERSION : $mtime;
    }

    /**
     * 已注册的 hook 列表（供 unregister_hooks 反向移除）
     *
     * 低危 4 修复：子类在 register_hooks 中调用 on()/filter() 自动记录，
     * unregister_hooks 基类默认实现据此反向 remove。
     *
     * @var array<int, array{type:string, hook:string, callback:callable, priority:int}>
     */
    protected array $hooks = [];

    /**
     * 已被本模块移除的核心 WP hook 列表（供 unregister_hooks 反向恢复）
     *
     * 低危 4 修复：site-optimize 等模块通过 remove() 反注册核心功能，
     * 停用模块时应把这些核心 hook 恢复，避免插件停用后站点功能仍被屏蔽。
     *
     * @var array<int, array{type:string, hook:string, callback:callable, priority:int}>
     */
    protected array $removed_hooks = [];

    /**
     * 替代 add_action，自动追踪以便注销
     *
     * @param string   $hook         hook 名。
     * @param callable $callback     回调。
     * @param int      $priority     优先级，默认 10。
     * @param int      $accepted_args 接受的参数个数，默认 1。
     * @return void
     */
    protected function on( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        add_action( $hook, $callback, $priority, $accepted_args );
        $this->hooks[] = [ 'type' => 'action', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
    }

    /**
     * 替代 add_filter，自动记录以便注销
     *
     * @param string   $hook         hook 名。
     * @param callable $callback     回调。
     * @param int      $priority     优先级，默认 10。
     * @param int      $accepted_args 接受的参数个数，默认 1。
     * @return void
     */
    protected function filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        add_filter( $hook, $callback, $priority, $accepted_args );
        $this->hooks[] = [ 'type' => 'filter', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
    }

    /**
     * 替代 remove_action/remove_filter，自动记录以便注销时恢复
     *
     * 低危 4 修复：site-optimize 模块需要反注册核心 WP hook（如 emoji、feed 等），
     * 停用模块时应将这些 hook 恢复，避免功能残留。
     *
     * @param string          $hook     hook 名。
     * @param callable|string $callback 被移除的回调。
     * @param int             $priority 优先级。
     * @return void
     */
    protected function remove( string $hook, $callback, int $priority = 10 ): void {
        remove_action( $hook, $callback, $priority );
        remove_filter( $hook, $callback, $priority );
        $this->removed_hooks[] = [ 'type' => 'action', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
    }

    /**
     * 默认注销实现：反向移除所有已注册 hook，并恢复被移除的核心 hook
     *
     * 子类若使用 on()/filter()/remove() 注册 hook 则无需覆盖本方法；
     * 若子类直接调用 add_action/add_filter 或包含 remove_action/remove_filter 反注册逻辑，
     * 则应覆盖本方法并在其中完成对称注销。
     *
     * @return void
     */
    public function unregister_hooks(): void {
        // 1. 移除本模块注册的 hooks
        foreach ( $this->hooks as $h ) {
            if ( 'action' === $h['type'] ) {
                remove_action( $h['hook'], $h['callback'], $h['priority'] );
            } else {
                remove_filter( $h['hook'], $h['callback'], $h['priority'] );
            }
        }
        $this->hooks = [];

        // 2. 恢复被本模块移除的核心 hook
        foreach ( $this->removed_hooks as $h ) {
            add_action( $h['hook'], $h['callback'], $h['priority'] );
        }
        $this->removed_hooks = [];
    }
}
