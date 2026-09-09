<?php
/**
 * AI API 客户端 —— 共享组件，供各模块调用
 *
 * 从 DREAAITA_API 迁移重构，支持 Kimi / OpenAI / Claude / DeepSeek。
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class AI_Client {

    /** @var string AI 提供商 */
    private $provider;

    /** @var string API 密钥 */
    private $api_key;

    /** @var string 模型名称 */
    private $model;

    /** @var array 提供商 API 地址 */
    // phpcs:disable PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Multi-provider support is a core design decision; WP 7.0 AI Client is too new and lacks multi-provider support.
    private $api_urls = [
        'kimi'     => 'https://api.moonshot.cn/v1/chat/completions',
        'openai'   => 'https://api.openai.com/v1/chat/completions',
        'claude'   => 'https://api.anthropic.com/v1/messages',
        'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
    ];
    // phpcs:enable

    /** @var array 提供商默认模型 */
    private $default_models = [
        'kimi'     => 'kimi-k2.6',
        'openai'   => 'gpt-4o-mini',
        'claude'   => 'claude-3-haiku-20240307',
        'deepseek' => 'deepseek-chat',
    ];

    /**
     * 构造函数
     *
     * @param string $provider 提供商。
     * @param string $api_key  API 密钥。
     * @param string $model    模型名（为空则使用默认）。
     */
    public function __construct( string $provider, string $api_key, string $model = '' ) {
        $this->provider = $provider;
        $this->api_key  = $api_key;
        $this->model    = $model ?: ( $this->default_models[ $provider ] ?? '' );
    }

    /**
     * 生成标签、Slug 和摘要
     *
     * @param string   $title          文章标题。
     * @param string   $content        文章内容摘要。
     * @param string[] $current_tags   当前标签。
     * @param string[] $existing_tags  博客已有标签。
     * @param bool     $opt_tags       是否生成标签。
     * @param bool     $opt_slug       是否生成 Slug。
     * @param bool     $opt_excerpt    是否生成摘要。
     * @param int      $excerpt_length 摘要长度。
     * @param string   $excerpt_prompt 自定义摘要提示词。
     * @return array|WP_Error
     */
    public function generate_tags_and_slug(
        string $title,
        string $content,
        array $current_tags = [],
        array $existing_tags = [],
        bool $opt_tags = true,
        bool $opt_slug = true,
        bool $opt_excerpt = false,
        int $excerpt_length = 100,
        string $excerpt_prompt = '',
        int $tag_limit = 5
    ) {
        $prompt = $this->build_prompt( $title, $content, $current_tags, $existing_tags, $opt_tags, $opt_slug, $opt_excerpt, $excerpt_length, $excerpt_prompt, $tag_limit );

        switch ( $this->provider ) {
            case 'kimi':
            case 'openai':
            case 'deepseek':
                return $this->call_openai_compatible( $prompt );
            case 'claude':
                return $this->call_claude( $prompt );
            default:
                return new \WP_Error( 'invalid_provider', __('Unsupported AI provider.', 'dreamanual-toolkit' ) );
        }
    }

    /**
     * 获取提供商的可用模型列表
     *
     * @param string $provider 提供商。
     * @return array [{value, label}]
     */
    public static function get_model_options( string $provider ): array {
        $map = [
            // Bug 6 修复：moonshot-v1 系列已于 2026-08-31 下线，更新为当前可用模型
            'kimi' => [
                [ 'value' => 'kimi-k2.6', 'label' => 'Kimi K2.6 (通用)' ],
                [ 'value' => 'kimi-k2.7-code', 'label' => 'Kimi K2.7 Code (编程)' ],
                [ 'value' => 'kimi-k2.7-code-highspeed', 'label' => 'Kimi K2.7 Code Highspeed' ],
                [ 'value' => 'kimi-k3', 'label' => 'Kimi K3 (旗舰)' ],
            ],
            'openai' => [
                [ 'value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini' ],
                [ 'value' => 'gpt-4o', 'label' => 'gpt-4o' ],
                [ 'value' => 'gpt-3.5-turbo', 'label' => 'gpt-3.5-turbo' ],
            ],
            'claude' => [
                [ 'value' => 'claude-3-haiku-20240307', 'label' => 'claude-3-haiku' ],
                [ 'value' => 'claude-3-sonnet-20240229', 'label' => 'claude-3-sonnet' ],
                [ 'value' => 'claude-3-opus-20240229', 'label' => 'claude-3-opus' ],
            ],
            'deepseek' => [
                [ 'value' => 'deepseek-chat', 'label' => 'DeepSeek-V3' ],
                [ 'value' => 'deepseek-reasoner', 'label' => 'DeepSeek-R1' ],
            ],
        ];
        return $map[ $provider ] ?? [];
    }

    // ─── 加密工具 ──────────────────────────────────────

    /**
     * 加密 API 密钥（代理到 Crypto 工具类）
     *
     * 优化 2：加密逻辑已抽取到独立 Crypto 类，此处保留静态代理以维持向后兼容
     *
     * @param string $plain_text 明文。
     * @return string 加密后的 base64 字符串。
     */
    public static function encrypt( string $plain_text ): string {
        return Crypto::encrypt( $plain_text );
    }

    /**
     * 解密 API 密钥（代理到 Crypto 工具类）
     *
     * @param string $cipher_text 加密的 base64 字符串。
     * @return string 明文，失败返回空字符串。
     */
    public static function decrypt( string $cipher_text ): string {
        return Crypto::decrypt( $cipher_text );
    }

    // ─── 内部方法 ──────────────────────────────────────

    /**
     * 构建 AI 提示词
     */
    private function build_prompt(
        string $title, string $content, array $current_tags, array $existing_tags,
        bool $opt_tags = true, bool $opt_slug = true, bool $opt_excerpt = false,
        int $excerpt_length = 100, string $excerpt_prompt = '', int $tag_limit = 5
    ): string {
        $existing_tags_str = ! empty( $existing_tags ) ? implode( '、', $existing_tags ) : '无';
        $current_tags_str  = ! empty( $current_tags ) ? implode( '、', $current_tags ) : '无';

        $task_desc   = '';
        $json_fields = '';
        $sections    = [];

        if ( $opt_tags ) {
            $task_desc   .= '提取精准的文章标签';
            $json_fields .= "\n  \"tags\": [\"标签1\", \"标签2\", \"标签3\"],";
            $sections[]  = "\n\n### 标签要求\n1. 从文章已发布的内容中提炼 {$tag_limit} 个精准、具体的标签，避免泛泛而谈的词汇（如\"技术\"、\"生活\"等过于宽泛的词）。\n2. 优先从博客已有标签列表中挑选合适的标签，必要时可以创建新标签。\n3. 标签应该反映文章的核心主题、关键技术、涉及的工具或概念。\n4. 每个标签应该是 1-4 个汉字或 1-3 个英文单词，简洁明了。\n5. 标签之间要有区分度，避免重复或近义。";
        }

        if ( $opt_slug ) {
            if ( $task_desc ) {
                $task_desc .= '、';
            }
            $task_desc   .= '优化的文章别名（slug）';
            $json_fields .= "\n  \"slug\": \"optimized-post-slug\",";
            $sections[]  = "\n\n### 文章别名（slug）要求\n1. 基于文章已有标题和内容提炼一个简洁、语义化的英文 slug，用于 URL。\n2. 使用小写字母和连字符，如：my-awesome-post-title。\n3. slug 应该包含文章的核心关键词，长度控制在 3-6 个单词。\n4. 避免无意义的数字和停用词（如 a, an, the, of, in 等）。";
        }

        if ( $opt_excerpt ) {
            if ( $task_desc ) {
                $task_desc .= '、';
            }
            $task_desc   .= '精炼的文章简介（excerpt）';
            $json_fields .= "\n  \"excerpt\": \"精炼的文章简介...\",";

            if ( ! empty( $excerpt_prompt ) ) {
                $custom_prompt = str_replace(
                    [ '{title}', '{content}', '{excerpt_length}', '{current_tags}', '{existing_tags}' ],
                    [ $title, $content, $excerpt_length, $current_tags_str, $existing_tags_str ],
                    $excerpt_prompt
                );
                $sections[] = "\n### 文章简介（excerpt）要求（请严格按照以下用户自定义提示词执行，覆盖其他通用规则）\n" . $custom_prompt;
            } else {
                $sections[] = "\n\n### 文章简介（excerpt）要求\n1. 如果文章是小说/故事类，直接从文中选取最有吸引力的原句作为简介，不要重新组织语言。\n2. 如果是个人评论/随笔类且原文用第一人称写作，简介也请保持第一人称（用\"我\"而非\"作者\"），优先引用原文精彩观点或句子。\n3. 其他类型文章提炼核心观点。\n4. 控制在 {$excerpt_length} 字以内，不要硬凑字数。\n5. 语言风格与原文保持一致，不要出现过度营销化的夸张用语。";
            }
        }

        $sections_str = implode( '', $sections );

        return "你是一位专业的内容分析和 SEO 优化专家。请根据以下已发布的文章信息，{$task_desc}。\n\n## 文章标题\n{$title}\n\n## 文章内容摘要\n{$content}\n\n## 当前标签\n{$current_tags_str}\n\n## 博客已有标签列表\n{$existing_tags_str}\n\n## 任务要求{$sections_str}\n\n## 输出格式\n请严格按照以下 JSON 格式输出，不添加任何额外内容：\n\n```json\n{{$json_fields}\n  \"reasoning\": \"简要说明为什么这样推荐（1-2句话）\"\n}\n```";
    }

    /**
     * 调用 OpenAI 兼容 API（Kimi / OpenAI / DeepSeek）
     */
    private function call_openai_compatible( string $prompt ) {
        $url = $this->api_urls[ $this->provider ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
        ];

        $body = [
            'model'    => $this->model,
            'messages' => [
                [ 'role' => 'system', 'content' => '你是一个专业的内容分析助手。对于标签和URL别名，请严格基于现有文本提取，不生成新内容；对于文章简介，则允许根据用户自定义提示词进行适当改写、选取原句或调整人称视角，以生成最佳简介。请严格遵守用户自定义的简介要求。' ],
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            'max_tokens' => 500,
        ];

        // Kimi models do not support custom temperature
        if ( 'kimi' !== $this->provider ) {
            $body['temperature'] = 0.3;
        }

        $response = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->parse_wp_error( $response );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            $body   = wp_remote_retrieve_body( $response );
            $data   = json_decode( $body, true );
            $error  = $data['error']['message'] ?? 'API request failed (HTTP ' . $status . ')';
            return new \WP_Error( 'api_error', $this->classify_api_error( $status, $error ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            return new \WP_Error( 'invalid_response', __('Invalid API response format.', 'dreamanual-toolkit' ) );
        }

        return $this->parse_ai_response( $data['choices'][0]['message']['content'] );
    }

    /**
     * 调用 Claude API
     */
    private function call_claude( string $prompt ) {
        $url = $this->api_urls['claude'];

        $headers = [
            'x-api-key'         => $this->api_key,
            'Content-Type'      => 'application/json',
            'anthropic-version' => '2023-06-01',
        ];

        $body = [
            'model'      => $this->model,
            'max_tokens' => 500,
            'messages'   => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ];

        $response = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->parse_wp_error( $response );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            $body  = wp_remote_retrieve_body( $response );
            $data  = json_decode( $body, true );
            $error = $data['error']['message'] ?? 'API request failed (HTTP ' . $status . ')';
            return new \WP_Error( 'api_error', $this->classify_api_error( $status, $error ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data['content'][0]['text'] ) ) {
            return new \WP_Error( 'invalid_response', __('Invalid API response format.', 'dreamanual-toolkit' ) );
        }

        return $this->parse_ai_response( $data['content'][0]['text'] );
    }

    /**
     * 解析 AI 响应为结构化数据
     */
    private function parse_ai_response( string $content ): array {
        $content = trim( $content );

        // 提取 JSON 块
        if ( preg_match( '/```json\s*(\{.*?\})\s*```/s', $content, $m ) ) {
            $content = $m[1];
        } elseif ( preg_match( '/```\s*(\{.*?\})\s*```/s', $content, $m ) ) {
            $content = $m[1];
        }

        $data = json_decode( $content, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $cleaned = preg_replace( '/^[^{]*/', '', $content );
            $cleaned = preg_replace( '/[^}]*$/', '', $cleaned );
            $data    = json_decode( $cleaned, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                return new \WP_Error( 'parse_error', __('AI response format abnormal, cannot parse. Please retry or switch AI model.', 'dreamanual-toolkit' ) );
            }
        }

        if ( ! isset( $data['tags'] ) && ! isset( $data['slug'] ) && ! isset( $data['excerpt'] ) ) {
            return new \WP_Error( 'invalid_data', __('No valid fields in AI response.', 'dreamanual-toolkit' ) );
        }

        // 规范化
        if ( ! isset( $data['tags'] ) ) {
            $data['tags'] = [];
        }
        if ( ! is_array( $data['tags'] ) ) {
            $data['tags'] = [ $data['tags'] ];
        }
        $data['tags']     = array_map( 'sanitize_text_field', array_map( 'trim', $data['tags'] ) );
        $data['slug']     = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';
        $data['excerpt']  = isset( $data['excerpt'] ) ? sanitize_textarea_field( trim( $data['excerpt'] ) ) : '';
        $data['reasoning'] = isset( $data['reasoning'] ) ? sanitize_text_field( $data['reasoning'] ) : '';

        return $data;
    }

    /**
     * 分类 API 错误为友好中文提示
     */
    private function classify_api_error( int $status, string $error_message ): string {
        $lower = strtolower( $error_message );

        if ( 401 === $status || false !== stripos( $lower, 'authentication' ) || false !== stripos( $lower, 'invalid api key' ) || false !== stripos( $lower, 'incorrect api key' ) ) {
            return __('API Key invalid or expired, please check AI Optimizer settings.', 'dreamanual-toolkit' );
        }

        if ( 429 === $status || false !== stripos( $lower, 'quota' ) || false !== stripos( $lower, 'insufficient' ) || false !== stripos( $lower, 'rate limit' ) || false !== stripos( $lower, 'too many requests' ) || false !== stripos( $lower, '余额' ) || false !== stripos( $lower, '额度' ) ) {
            return __('AI API quota insufficient, balance too low, or rate limited. Please check your account or retry later.', 'dreamanual-toolkit' );
        }

        if ( preg_match( '/content.?filter|safety|moderation|policy|blocked|violates|harm|敏感|审核|content_policy/i', $lower ) ) {
            return __('Content blocked by AI provider\'s safety policy, please modify content or switch provider.', 'dreamanual-toolkit' );
        }

        if ( $status >= 500 ) {
            return __('AI service temporarily unavailable, please retry later.', 'dreamanual-toolkit' );
        }

        if ( 400 === $status ) {
            return __('AI request parameters invalid, please check model name and request settings.', 'dreamanual-toolkit' );
        }

        /* translators: %d: HTTP status code */
        return sprintf( __('AI request failed (HTTP %1$d), please retry later.', 'dreamanual-toolkit' ), $status );
    }

    /**
     * 解析 WP_Error 网络错误
     */
    private function parse_wp_error( $response ): \WP_Error {
        $code = $response->get_error_code();
        $msg  = $response->get_error_message();

        if ( 'http_request_failed' === $code ) {
            // 优化 3：复用公共网络错误翻译器
            if ( false !== stripos( $msg, 'cURL error 28' ) || false !== stripos( $msg, 'timed out' ) ) {
                return new \WP_Error( 'request_timeout', __( 'AI request timed out, please retry later.', 'dreamanual-toolkit' ) );
            }
            if ( false !== stripos( $msg, 'Could not resolve host' ) || false !== stripos( $msg, 'Connection refused' ) || false !== stripos( $msg, 'Network is unreachable' ) ) {
                return new \WP_Error( 'connection_failed', __( 'Cannot connect to AI service, please check network.', 'dreamanual-toolkit' ) );
            }
            return new \WP_Error( 'network_error', __( 'Network error, please check connection.', 'dreamanual-toolkit' ) );
        }

        return new \WP_Error( 'request_failed', __( 'Request failed, please check network connection or retry later.', 'dreamanual-toolkit' ) );
    }
}
