/**
 * AI 优化模块 — 批量处理页脚本 (Vanilla JS, no jQuery)
 */
(function () {
    'use strict';

    if (typeof dreaAi === 'undefined') return;

    var i18n    = dreaAi.i18n;
    var ajaxUrl = dreaAi.ajaxUrl;
    var nonce   = dreaAi.nonce;

    var selectAll = null;
    var selectAllHeader = null;

    var state = {
        posts: [],
        suggestions: {},
        existingTags: [],
        currentPage: 1,
        totalPages: 1,
        perPage: 20,
        isGenerating: false,
        settings: {},
        categoryFilter: ''
    };

    var modelMap = {
        kimi: [
            { value: 'kimi-k2.6', label: 'Kimi K2.6 (通用)' },
            { value: 'kimi-k2.7-code', label: 'Kimi K2.7 Code (编程)' },
            { value: 'kimi-k2.7-code-highspeed', label: 'Kimi K2.7 Code Highspeed' },
            { value: 'kimi-k3', label: 'Kimi K3 (旗舰)' }
        ],
        openai: [
            { value: 'gpt-4o-mini', label: 'gpt-4o-mini' },
            { value: 'gpt-4o', label: 'gpt-4o' },
            { value: 'gpt-3.5-turbo', label: 'gpt-3.5-turbo' }
        ],
        claude: [
            { value: 'claude-3-haiku-20240307', label: 'claude-3-haiku' },
            { value: 'claude-3-sonnet-20240229', label: 'claude-3-sonnet' },
            { value: 'claude-3-opus-20240229', label: 'claude-3-opus' }
        ],
        deepseek: [
            { value: 'deepseek-chat', label: 'DeepSeek-V3' },
            { value: 'deepseek-reasoner', label: 'DeepSeek-R1' }
        ]
    };

    // ─── 工具函数 ───

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function $(sel) { return document.querySelector(sel); }
    function $$(sel) { return document.querySelectorAll(sel); }

    function showToast(message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;
        DreaToast.show(message, type, 'drea-ai-toast-container');
    }

    function postAjax(data) {
        var action = data.action || 'unknown';
        data.nonce = nonce;
        var body = new FormData();
        for (var key in data) {
            if (Array.isArray(data[key])) {
                data[key].forEach(function (v) { body.append(key + '[]', v); });
            } else {
                body.append(key, data[key]);
            }
        }
        return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (text) {
                        console.error('[DREA] ' + action + ' HTTP ' + r.status, text.substring(0, 500));
                        throw new Error('HTTP ' + r.status);
                    });
                }
                return r.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('[DREA] ' + action + ' JSON parse error, raw:', text.substring(0, 500));
                        throw e;
                    }
                });
            });
    }

    // ─── 初始化 ───

    function init() {
        var catFilter = $('#category-filter');
        selectAll = $('#select-all');
        selectAllHeader = $('#select-all-header');
        var genBtn = $('#generate-selected-btn');
        var applyAllBtn = $('#apply-all-btn');

        if (catFilter) catFilter.addEventListener('change', function () {
            state.categoryFilter = this.value;
            loadPosts(1);
        });

        if (selectAll) selectAll.addEventListener('change', function () {
            toggleSelectAll(this.checked);
        });
        if (selectAllHeader) selectAllHeader.addEventListener('change', function () {
            toggleSelectAll(this.checked);
        });

        if (genBtn) genBtn.addEventListener('click', generateForSelected);
        if (applyAllBtn) applyAllBtn.addEventListener('click', applyAll);

        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('post-checkbox')) updateGenerateButton();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.generate-single-btn');
            if (btn) generateSingle(parseInt(btn.dataset.postId));

            btn = e.target.closest('.apply-single-btn');
            if (btn) applySingle(parseInt(btn.dataset.postId));

            btn = e.target.closest('.pagination-btn');
            if (btn) { e.preventDefault(); loadPosts(parseInt(btn.dataset.page)); }
        });

        document.addEventListener('input', function (e) {
            if (e.target.classList.contains('drea-ai-excerpt-edit')) {
                var postId = parseInt(e.target.dataset.postId);
                if (state.suggestions[postId]) {
                    state.suggestions[postId].excerpt = e.target.value;
                    renderApplyPanel();
                }
            }
        });

        initToggleHandlers();
        loadSettings();
    }

    // ─── 加载设置 ───

    function loadSettings() {
        postAjax({ action: 'drea_ai_get_settings' })
            .then(function (res) {
                if (res.success) {
                    state.settings = res.data;
                    syncToggleUI();
                    // Bug 5 修复：api_key 现在返回布尔值（是否已配置），不再返回明文
                    if (!state.settings.api_key) {
                        showToast(i18n.pleaseConfigureApiKey, 'error', 5000);
                    } else {
                        loadPosts(1);
                    }
                } else {
                    showToast(i18n.loadSettingsFailed, 'error');
                }
            })
            .catch(function (err) {
                console.error('[DREA] loadSettings failed:', err);
                showToast(i18n.loadSettingsFailed, 'error');
            });
    }

    // ─── 快捷开关同步 ───

    function syncToggleUI() {
        var cbTags = $('#drea-toggle-tags');
        var cbSlug = $('#drea-toggle-slug');
        var cbExcerpt = $('#drea-toggle-excerpt');
        if (cbTags) cbTags.checked = !!state.settings.opt_tags;
        if (cbSlug) cbSlug.checked = !!state.settings.opt_slug;
        if (cbExcerpt) cbExcerpt.checked = !!state.settings.opt_excerpt;
    }

    function initToggleHandlers() {
        document.addEventListener('change', function (e) {
            if (e.target.id === 'drea-toggle-tags') {
                state.settings.opt_tags = e.target.checked;
            } else if (e.target.id === 'drea-toggle-slug') {
                state.settings.opt_slug = e.target.checked;
            } else if (e.target.id === 'drea-toggle-excerpt') {
                state.settings.opt_excerpt = e.target.checked;
            }
        });
    }

    // ─── 加载文章 ───

    function loadPosts(page) {
        // Bug 5 修复：api_key 现在返回布尔值，不再检查明文
        if (!state.settings.api_key) {
            showToast(i18n.pleaseConfigureApiKey, 'error');
            return;
        }

        var spinner = document.querySelector('.drea-ai-posts-actions .spinner');
        if (spinner) spinner.classList.add('is-active');

        var data = { action: 'drea_ai_get_posts', page: page, per_page: state.perPage, post_type: 'post' };
        if (state.categoryFilter) data.category = state.categoryFilter;

        postAjax(data)
            .then(function (res) {
                if (spinner) spinner.classList.remove('is-active');
                if (res.success) {
                    state.posts = res.data.posts;
                    state.currentPage = page;
                    state.totalPages = res.data.total_pages;
                    renderPosts();
                    renderPagination();
                    loadExistingTags();
                    if (res.data.posts.length > 0) {
                        showToast(res.data.posts.length + i18n.postsLoaded, 'success');
                    } else {
                        showToast(i18n.noPostsInCategory, 'info');
                    }
                } else {
                    showToast(i18n.loadFailed + (res.data || i18n.unknownError), 'error');
                }
            })
            .catch(function (err) {
                if (spinner) spinner.classList.remove('is-active');
                console.error('[DREA] loadPosts failed:', err);
                showToast(i18n.networkError, 'error');
            });
    }

    function loadExistingTags() {
        postAjax({ action: 'drea_ai_get_existing_tags' })
            .then(function (res) {
                if (res.success) {
                    state.existingTags = res.data.tags || [];
                } else {
                    showToast(i18n.loadTagsFailed, 'info');
                }
            })
            .catch(function (err) {
                console.error('[DREA] loadExistingTags failed:', err);
                showToast(i18n.loadTagsFailed, 'info');
            });
    }

    // ─── 渲染 ───

    function renderPosts() {
        var tbody = $('#drea-ai-posts-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (state.posts.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="drea-ai-no-posts">' + i18n.noPosts + '</td></tr>';
            return;
        }

        state.posts.forEach(function (post) {
            var currentTags = post.tags.map(function (t) {
                return '<span class="drea-ai-tag-badge">' + escapeHtml(t) + '</span>';
            }).join('');
            var s = state.suggestions[post.id];
            var has = !!s;

            var suggestionTags = has ? s.tags.map(function (t) {
                return '<span class="drea-ai-tag-badge drea-ai-tag-badge--new">' + escapeHtml(t) + '</span>';
            }).join('') : '';
            var suggestionSlug = has ? '<span class="drea-ai-new-slug">' + escapeHtml(s.slug) + '</span>' : '';
            var excerptHtml = has
                ? '<textarea class="drea-ai-excerpt-edit" data-post-id="' + post.id + '" rows="2">' + escapeHtml(s.excerpt || '') + '</textarea>'
                : '';

            var tr = document.createElement('tr');
            tr.dataset.postId = post.id;
            tr.innerHTML =
                '<td class="column-cb"><input type="checkbox" class="post-checkbox" data-post-id="' + post.id + '"></td>' +
                '<td><strong>' + escapeHtml(post.title) + '</strong><div class="drea-ai-post-excerpt">' + escapeHtml(post.excerpt.substring(0, 60)) + (post.excerpt.length > 60 ? '...' : '') + '</div></td>' +
                '<td>' + (currentTags || '<span class="drea-ai-tag-badge">' + i18n.noTags + '</span>') + '</td>' +
                '<td><span class="drea-ai-current-slug">' + escapeHtml(post.slug) + '</span></td>' +
                '<td>' + (has ? suggestionTags : '<span class="drea-ai-tag-badge">' + i18n.notGenerated + '</span>') + '</td>' +
                '<td>' + (has ? suggestionSlug : '<span class="drea-ai-current-slug">' + i18n.notGenerated + '</span>') + '</td>' +
                '<td>' + (has ? excerptHtml : '<span class="drea-ai-tag-badge">' + i18n.notGenerated + '</span>') + '</td>' +
                '<td><button type="button" class="button button-small generate-single-btn" data-post-id="' + post.id + '">' + (has ? i18n.regenerate : i18n.generate) + '</button>' +
                (has ? ' <button type="button" class="button button-small apply-single-btn" data-post-id="' + post.id + '">' + i18n.apply + '</button>' : '') + '</td>';
            tbody.appendChild(tr);
        });

        updateGenerateButton();
        // sync select-all
        var total = $$('.post-checkbox').length;
        var checked = $$('.post-checkbox:checked').length;
        var allChecked = total > 0 && checked === total;
        if (selectAll) selectAll.checked = allChecked;
        if (selectAllHeader) selectAllHeader.checked = allChecked;
    }

    function renderPagination() {
        var html = buildPaginationHtml();
        var top = $('#drea-ai-pagination-top');
        var bot = $('#drea-ai-pagination');
        if (top) top.innerHTML = html;
        if (bot) bot.innerHTML = html;
    }

    function buildPaginationHtml() {
        if (state.totalPages <= 1) return '';
        var html = '';
        if (state.currentPage > 1) {
            html += '<button class="button pagination-btn" data-page="' + (state.currentPage - 1) + '">\u2039 ' + i18n.prev + '</button>';
        }
        var start = Math.max(1, state.currentPage - 2);
        var end = Math.min(state.totalPages, start + 4);
        if (end - start < 4) start = Math.max(1, end - 4);
        for (var i = start; i <= end; i++) {
            html += '<button class="button pagination-btn' + (i === state.currentPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        if (state.currentPage < state.totalPages) {
            html += '<button class="button pagination-btn" data-page="' + (state.currentPage + 1) + '">' + i18n.next + ' \u203A</button>';
        }
        html += '<span class="page-info">' + i18n.page + state.currentPage + ' / ' + state.totalPages + '</span>';
        return html;
    }

    function renderApplyPanel() {
        var tbody = $('#drea-ai-apply-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        var withSuggestions = state.posts.filter(function (p) { return state.suggestions[p.id]; });
        var countEl = $('#pending-count');
        if (countEl) countEl.textContent = withSuggestions.length;

        var panel = document.querySelector('.drea-ai-apply-panel');
        if (withSuggestions.length === 0) {
            if (panel) panel.style.display = 'none';
            return;
        }

        withSuggestions.forEach(function (post) {
            var s = state.suggestions[post.id];
            var oldTags = post.tags.map(function (t) { return '<span class="drea-ai-tag-badge">' + escapeHtml(t) + '</span>'; }).join('');
            var newTags = s.tags ? s.tags.map(function (t) { return '<span class="drea-ai-tag-badge drea-ai-tag-badge--new">' + escapeHtml(t) + '</span>'; }).join('') : '-';

            var tr = document.createElement('tr');
            tr.dataset.postId = post.id;
            tr.innerHTML =
                '<td>' + escapeHtml(post.title) + '</td>' +
                '<td>' + (oldTags || '-') + '</td>' +
                '<td>' + newTags + '</td>' +
                '<td><span class="drea-ai-current-slug">' + escapeHtml(post.slug) + '</span></td>' +
                '<td><span class="drea-ai-new-slug">' + escapeHtml(s.slug || '') + '</span></td>' +
                '<td class="drea-ai-excerpt-cell">' + escapeHtml(post.excerpt || '(无)') + '</td>' +
                '<td class="drea-ai-excerpt-cell drea-ai-excerpt-cell--new">' + escapeHtml(s.excerpt || '(无)') + '</td>' +
                '<td><button type="button" class="button button-small apply-single-btn" data-post-id="' + post.id + '">' + i18n.apply + '</button></td>';
            tbody.appendChild(tr);
        });

        if (panel) panel.style.display = '';
    }

    // ─── 选择和按钮状态 ───

    function toggleSelectAll(checked) {
        $$('.post-checkbox').forEach(function (cb) { cb.checked = checked; });
        updateGenerateButton();
    }

    function updateGenerateButton() {
        var btn = $('#generate-selected-btn');
        if (!btn) return;
        var count = $$('.post-checkbox:checked').length;
        btn.disabled = count === 0;
        btn.textContent = i18n.generateForSelected + count + i18n.selected;
    }

    // ─── 生成 ───

    function generateForSelected() {
        if (state.isGenerating) return;
        var ids = Array.from($$('.post-checkbox:checked')).map(function (cb) {
            return parseInt(cb.dataset.postId);
        });
        if (ids.length === 0) return;

        state.isGenerating = true;
        var btn = $('#generate-selected-btn');
        btn.disabled = true;
        var spinner = btn.parentElement.querySelector('.spinner');
        if (spinner) spinner.classList.add('is-active');
        var panel = document.querySelector('.drea-ai-progress-panel');
        if (panel) panel.style.display = '';

        var i = 0;
        function next() {
            if (i >= ids.length) {
                var progressText = document.querySelector('.drea-ai-progress-text');
                if (progressText) progressText.textContent = i18n.done;
                state.isGenerating = false;
                btn.disabled = false;
                if (spinner) spinner.classList.remove('is-active');
                renderApplyPanel();
                renderPosts();
                return;
            }
            var progress = ((i + 1) / ids.length) * 100;
            var fill = document.querySelector('.drea-ai-progress-fill');
            if (fill) fill.style.width = progress + '%';
            var pt = document.querySelector('.drea-ai-progress-text');
            if (pt) pt.textContent = i18n.processing + (i + 1) + ' / ' + ids.length + '...';

            generateSingle(ids[i], false).then(function () {
                i++;
                if (i < ids.length) {
                    setTimeout(next, 1000);
                } else {
                    next();
                }
            });
        }
        next();
    }

    function generateSingle(postId, render) {
        if (render === undefined) render = true;
        var post = state.posts.find(function (p) { return p.id === postId; });
        if (!post) return Promise.resolve();

        var btn = document.querySelector('.generate-single-btn[data-post-id="' + postId + '"]');
        if (btn) { btn.disabled = true; btn.textContent = i18n.generating; }

        return postAjax({
            action: 'drea_ai_generate',
            post_id: postId,
            // Bug 5 修复：不再传 provider/model/api_key，后端从设置读取
            existing_tags: state.existingTags,
            opt_tags: state.settings.opt_tags ? 1 : 0,
            opt_slug: state.settings.opt_slug ? 1 : 0,
            opt_excerpt: state.settings.opt_excerpt ? 1 : 0,
            tag_limit: state.settings.tag_limit || 5,
            excerpt_length: state.settings.excerpt_length || 100,
            excerpt_prompt: state.settings.excerpt_prompt || ''
        }).then(function (res) {
            if (res.success) {
                state.suggestions[postId] = res.data;
            } else {
                showToast('"' + post.title + '"' + i18n.failed + (res.data || i18n.unknownError), 'error');
            }
            if (btn) { btn.disabled = false; btn.textContent = res.success ? i18n.regenerate : i18n.retry; }
            if (render) { renderPosts(); renderApplyPanel(); }
        }).catch(function () {
            showToast('"' + post.title + '" ' + i18n.networkError, 'error');
            if (btn) { btn.disabled = false; btn.textContent = i18n.retry; }
        });
    }

    // ─── 应用 ───

    function applyAll() {
        var withSuggestions = state.posts.filter(function (p) { return state.suggestions[p.id]; });
        if (withSuggestions.length === 0) { showToast(i18n.noPendingChanges, 'error'); return; }

        if (!confirm(i18n.applyAiSuggestionsTo + withSuggestions.length + i18n.postsQuestion + '\n\n\u26A0\uFE0F ' + i18n.slugChangeNote + '\n\n\u26A0\uFE0F ' + i18n.excerptOverwriteNote)) return;

        var btn = $('#apply-all-btn');
        btn.disabled = true;
        var spinner = btn.parentElement.querySelector('.spinner');
        if (spinner) spinner.classList.add('is-active');
        var panel = document.querySelector('.drea-ai-progress-panel');
        if (panel) panel.style.display = '';

        var i = 0;
        var successCount = 0;
        var failCount = 0;
        function next() {
            if (i >= withSuggestions.length) {
                var pt = document.querySelector('.drea-ai-progress-text');
                if (pt) pt.textContent = i18n.done;
                btn.disabled = false;
                if (spinner) spinner.classList.remove('is-active');
                // F-06: 根据成功/失败数给差异化汇总提示
                if (failCount === 0) {
                    showToast(i18n.allChangesApplied, 'success', 5000);
                } else {
                    var summary = i18n.batchSummary.replace('{0}', successCount).replace('{1}', failCount);
                    showToast(summary + ' ' + i18n.batchPartialFail, 'error', 6000);
                }
                return;
            }
            var p = withSuggestions[i];
            var progress = ((i + 1) / withSuggestions.length) * 100;
            var fill = document.querySelector('.drea-ai-progress-fill');
            if (fill) fill.style.width = progress + '%';
            var pt = document.querySelector('.drea-ai-progress-text');
            if (pt) pt.textContent = i18n.applying + (i + 1) + ' / ' + withSuggestions.length + '...';

            applySingle(p.id, false).then(function (ok) {
                if (ok) successCount++; else failCount++;
                i++; next();
            });
        }
        next();
    }

    function applySingle(postId, confirmApply) {
        if (confirmApply === undefined) confirmApply = true;
        var s = state.suggestions[postId];
        if (!s) return Promise.resolve();
        var post = state.posts.find(function (p) { return p.id === postId; });

        if (confirmApply) {
            var detail = i18n.applyConfirmTags + (s.tags || []).join(', ') + '\n' + i18n.applyConfirmSlug + (s.slug || '');
            if (s.excerpt) detail += '\n\n' + i18n.applyConfirmExcerpt + s.excerpt.substring(0, 50) + '...';
            if (!window.confirm(i18n.applyConfirmTitle.replace('{title}', post.title) + '\n\n' + detail)) {
                return Promise.resolve();
            }
        }

        return postAjax({
            action: 'drea_ai_apply',
            post_id: postId,
            tags: s.tags || [],
            slug: s.slug || '',
            excerpt: s.excerpt || ''
        }).then(function (res) {
            if (res.success) {
                showToast('"' + post.title + '"' + i18n.changesApplied, 'success', 3000);
                delete state.suggestions[postId];
                renderPosts();
                renderApplyPanel();
                return true;
            } else {
                showToast('"' + post.title + '"' + i18n.applyFailed + (res.data || i18n.unknownError), 'error');
                return false;
            }
        }).catch(function () {
            showToast('"' + post.title + '"' + i18n.networkErrorShort, 'error');
            return false;
        });
    }

    // ─── 启动 ───
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
