<?php
/**
 * Plugin Name: Dreamanual Toolkit
 * Plugin URI:  https://github.com/ShitFool/dreamanual-toolkit
 * Description: Modular WordPress toolbox consolidating AI optimization, content visibility, role management, site enhancement, and more into independent modules.
 * Version:     1.4.2
 * Author:      Dreamanual
 * Author URI:  https://dreamanual.com
 * License:     GPL-2.0+
 * Text Domain: dreamanual-toolkit
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

// 插件常量
define( 'DREA_VERSION', '1.4.2' );
define( 'DREA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DREA_URL', plugin_dir_url( __FILE__ ) );
define( 'DREA_BASENAME', plugin_basename( __FILE__ ) );

// 加载核心类
require_once DREA_PATH . 'includes/class-module.php';
require_once DREA_PATH . 'includes/class-crypto.php';
require_once DREA_PATH . 'includes/class-ai-client.php';
require_once DREA_PATH . 'includes/class-core.php';

// 初始化
Core::get_instance();
