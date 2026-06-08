<?php
/**
 * Logger App - Group viewer shell
 */

require_once __DIR__ . '/../../includes/AppContext.php';
require_once __DIR__ . '/../../includes/LoggerManager.php';

$app = new AppContext('logger');
$user = $app->requireAuth();
$layout = $app->getLayout();
$isAdmin = $app->hasRole('admin');
$manager = new LoggerManager();
$identifier = trim((string)($_GET['slug'] ?? $_GET['id'] ?? ''));
$group = $identifier !== '' ? $manager->getGroup($identifier) : null;

function logger_group_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead($group ? 'Logger - ' . $group['name'] : 'Logger Group'); ?>
    <style>
        .logger-page {
            display: grid;
            gap: 18px;
        }

        .logger-panel {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            overflow: hidden;
        }

        .logger-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .logger-panel-title {
            margin: 0;
            font-size: 18px;
        }

        .logger-panel-body {
            padding: 16px;
        }

        .logger-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .logger-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border: 1px solid var(--border-color);
            border-radius: 999px;
            background: var(--bg-primary);
            color: var(--text-secondary);
            font-size: 12px;
        }

        .saved-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 34px;
            padding: 7px 10px;
            border: 1px solid var(--border-color);
            border-radius: 999px;
            background: var(--bg-primary);
            color: var(--text-secondary);
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .filter-chip.active {
            border-color: #0f766e;
            background: rgba(20, 184, 166, 0.12);
            color: #14b8a6;
        }

        .filter-chip:disabled {
            cursor: not-allowed;
            opacity: 0.58;
        }

        .runtime-filter-builder {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .runtime-filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .runtime-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            max-width: 100%;
            min-height: 32px;
            padding: 6px 9px;
            border: 1px solid var(--border-color);
            border-radius: 999px;
            background: var(--bg-primary);
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 700;
        }

        .runtime-filter-chip strong {
            color: var(--text-primary);
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .runtime-color-input {
            width: 44px;
            min-width: 44px;
            height: 38px;
            padding: 3px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            cursor: pointer;
        }

        .runtime-color-input[hidden] {
            display: none;
        }

        .runtime-chip-color-input {
            width: 18px;
            min-width: 18px;
            height: 18px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.48);
            border-radius: 999px;
            background: transparent;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.28);
            cursor: pointer;
            flex: 0 0 auto;
            overflow: hidden;
            appearance: none;
        }

        .runtime-chip-color-input::-webkit-color-swatch-wrapper {
            padding: 0;
        }

        .runtime-chip-color-input::-webkit-color-swatch {
            border: 0;
            border-radius: 999px;
        }

        .runtime-chip-color-input::-moz-color-swatch {
            border: 0;
            border-radius: 999px;
        }

        .runtime-filter-chip button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border: 0;
            border-radius: 999px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
        }

        .runtime-filter-chip button:hover {
            color: #ef4444;
        }

        .logger-entry-list {
            display: grid;
            gap: 10px;
        }

        .logger-entry {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
        }

        .logger-entry:hover {
            border-color: #0f766e;
        }

        .logger-entry-name {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
        }

        .logger-console {
            display: grid;
            align-content: start;
            gap: 2px;
            min-height: 360px;
            max-height: 620px;
            padding: 16px;
            border-radius: 8px;
            background: #0b1020;
            color: #d1d5db;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            line-height: 1.45;
            overflow: auto;
        }

        .logger-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .logger-toolbar-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .logger-input,
        .logger-select {
            min-height: 38px;
            padding: 9px 11px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font: inherit;
            font-size: 13px;
        }

        .logger-input {
            min-width: 260px;
        }

        .logger-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            padding: 9px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .logger-button.primary {
            border-color: #0f766e;
            background: #0f766e;
            color: white;
        }

        .logger-button:hover {
            border-color: rgba(20, 184, 166, 0.5);
            color: #14b8a6;
        }

        .logger-button.primary:hover {
            background: #14b8a6;
            color: white;
        }

        .logger-toggle {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 38px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .logger-status {
            min-height: 18px;
            margin-bottom: 12px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .logger-status.error {
            color: #ef4444;
        }

        .source-health {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .source-health[hidden] {
            display: none;
        }

        .source-health-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            max-width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-secondary);
            font-size: 12px;
        }

        .source-health-item.error {
            border-color: rgba(239, 68, 68, 0.45);
            background: rgba(239, 68, 68, 0.09);
            color: #fca5a5;
        }

        .source-health-name {
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-primary);
            font-weight: 700;
        }

        .log-row {
            display: grid;
            grid-template-columns: minmax(150px, 230px) minmax(0, 1fr);
            gap: 12px;
            padding: 2px 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.07);
        }

        .log-prefix {
            color: #5eead4;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .log-text {
            min-width: 0;
            color: #d1d5db;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .log-highlight {
            padding: 1px 4px;
            border-radius: 4px;
            background: var(--highlight-color, #facc15);
            color: var(--highlight-foreground, #111827);
            font-weight: 900;
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.7),
                0 0 0 3px var(--highlight-glow, rgba(250, 204, 21, 0.24)),
                0 0 18px var(--highlight-glow, rgba(250, 204, 21, 0.38));
        }

        .log-line-number {
            color: #94a3b8;
        }

        .log-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 260px;
            color: #94a3b8;
            text-align: center;
        }

        .logger-empty {
            padding: 28px;
            border: 1px dashed var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            background: var(--bg-secondary);
            text-align: center;
        }

        .logger-link {
            color: #14b8a6;
            text-decoration: none;
        }

        @media (max-width: 760px) {
            .logger-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .logger-toolbar-group,
            .logger-input,
            .logger-select,
            .logger-button,
            .runtime-filter-builder {
                width: 100%;
            }

            .log-row {
                grid-template-columns: 1fr;
                gap: 2px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $layout->renderSidebar(); ?>

        <main class="main-content">
            <div class="content-area">
                <?php $app->renderAppHeader(); ?>

                <?php if ($group === null): ?>
                    <div class="logger-empty">
                        <i class="fas fa-circle-exclamation"></i>
                        <div>Logger group not found</div>
                        <p><a class="logger-link" href="index.php">Back to Logger</a></p>
                    </div>
                <?php else: ?>
                    <div class="logger-page">
                        <section class="logger-panel">
                            <div class="logger-panel-header">
                                <h2 class="logger-panel-title"><?= logger_group_e($group['name']) ?></h2>
                                <div class="logger-pills">
                                    <span class="logger-pill"><i class="fas fa-list"></i><?= (int)$group['entry_count'] ?> entries</span>
                                    <span class="logger-pill"><i class="fas fa-layer-group"></i><?= (int)$group['source_count'] ?> sources</span>
                                    <span class="logger-pill"><i class="fas fa-filter"></i><?= (int)$group['filter_count'] ?> filters</span>
                                </div>
                            </div>
                            <div class="logger-panel-body">
                                <?php if (!empty($group['description'])): ?>
                                <p><?= logger_group_e($group['description']) ?></p>
                                <?php endif; ?>

                                <?php if ($group['entries'] === []): ?>
                                    <div class="logger-empty">No entries in this group</div>
                                <?php else: ?>
                                    <div class="logger-entry-list">
                                        <?php foreach ($group['entries'] as $entry): ?>
                                        <a class="logger-entry" href="entry.php?slug=<?= rawurlencode((string)$entry['slug']) ?>">
                                            <span class="logger-entry-name">
                                                <i class="fas fa-file-lines"></i>
                                                <?= logger_group_e($entry['name']) ?>
                                            </span>
                                            <span class="logger-pill"><i class="fas fa-layer-group"></i><?= (int)$entry['source_count'] ?> sources</span>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="logger-panel">
                            <div class="logger-panel-header">
                                <h2 class="logger-panel-title">Merged Output</h2>
                                <div class="logger-pills">
                                    <span class="logger-pill" id="loggerModePill"><i class="fas fa-satellite-dish"></i>latest</span>
                                    <span class="logger-pill" id="loggerCountPill"><i class="fas fa-align-left"></i>0 lines</span>
                                </div>
                            </div>
                            <div class="logger-panel-body">
                                <?php if (!empty($group['filters'])): ?>
                                <div class="saved-filters" id="savedFilters">
                                    <?php foreach ($group['filters'] as $filter): ?>
                                    <button class="filter-chip" type="button" data-filter-id="<?= logger_group_e($filter['id']) ?>" <?= $filter['enabled'] ? '' : 'disabled' ?>>
                                        <i class="fas fa-filter"></i>
                                        <?= logger_group_e($filter['name']) ?>
                                    </button>
                                    <?php endforeach; ?>
                                    <button class="filter-chip" id="clearSavedFilter" type="button" hidden>
                                        <i class="fas fa-xmark"></i>
                                        Clear
                                    </button>
                                </div>
                                <?php endif; ?>
                                <div class="runtime-filter-builder">
                                    <input class="logger-input" id="runtimeFilterText" type="search" placeholder="Add grep, grepv, or highlight">
                                    <select class="logger-select" id="runtimeFilterMode" aria-label="Runtime filter mode">
                                        <option value="grep">grep</option>
                                        <option value="grepv">grepv</option>
                                        <option value="highlight">highlight</option>
                                    </select>
                                    <input class="runtime-color-input" id="runtimeFilterColor" type="color" value="#facc15" title="Highlight color" aria-label="Highlight color" hidden>
                                    <button class="logger-button" id="addRuntimeFilter" type="button" title="Add runtime filter">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                                <div class="runtime-filter-chips" id="runtimeFilterChips" hidden></div>
                                <div class="logger-toolbar">
                                    <div class="logger-toolbar-group">
                                        <input class="logger-input" id="lineFilter" type="search" placeholder="Filter loaded lines">
                                        <button class="logger-button" id="remoteSearch" type="button">
                                            <i class="fas fa-magnifying-glass"></i>
                                            Search targets
                                        </button>
                                        <label class="logger-toggle">
                                            <input id="caseSensitive" type="checkbox">
                                            Case
                                        </label>
                                    </div>
                                    <div class="logger-toolbar-group">
                                        <select class="logger-select" id="lineLimit">
                                            <option value="100">100 lines</option>
                                            <option value="200" selected>200 lines</option>
                                            <option value="500">500 lines</option>
                                            <option value="1000">1000 lines</option>
                                        </select>
                                        <button class="logger-button" id="refreshLogs" type="button">
                                            <i class="fas fa-rotate"></i>
                                            Refresh
                                        </button>
                                        <button class="logger-button primary" id="followToggle" type="button">
                                            <i class="fas fa-pause"></i>
                                            Pause
                                        </button>
                                    </div>
                                </div>
                                <div class="logger-status" id="loggerStatus"></div>
                                <div class="source-health" id="sourceHealth" hidden></div>
                                <div class="logger-console" id="loggerConsole" aria-live="polite">
                                    <div class="log-empty">Loading latest lines...</div>
                                </div>
                            </div>
                        </section>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <?php if ($group !== null): ?>
    <script>
        const loggerGroup = <?= json_encode([
            'id' => $group['id'],
            'slug' => $group['slug'],
            'name' => $group['name'],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const loggerFilters = <?= json_encode($group['filters'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const loggerIsAdmin = <?= json_encode($isAdmin) ?>;

        const loggerConsole = document.getElementById('loggerConsole');
        const loggerStatus = document.getElementById('loggerStatus');
        const sourceHealth = document.getElementById('sourceHealth');
        const loggerModePill = document.getElementById('loggerModePill');
        const loggerCountPill = document.getElementById('loggerCountPill');
        const lineFilter = document.getElementById('lineFilter');
        const lineLimit = document.getElementById('lineLimit');
        const refreshLogs = document.getElementById('refreshLogs');
        const followToggle = document.getElementById('followToggle');
        const remoteSearch = document.getElementById('remoteSearch');
        const caseSensitive = document.getElementById('caseSensitive');
        const clearSavedFilter = document.getElementById('clearSavedFilter');
        const runtimeFilterText = document.getElementById('runtimeFilterText');
        const runtimeFilterMode = document.getElementById('runtimeFilterMode');
        const runtimeFilterColor = document.getElementById('runtimeFilterColor');
        const addRuntimeFilter = document.getElementById('addRuntimeFilter');
        const runtimeFilterChips = document.getElementById('runtimeFilterChips');

        let loadedLines = [];
        let following = true;
        let pollTimer = null;
        let currentMode = 'latest';
        let activeSavedFilter = null;
        let runtimeFilters = [];

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function lineHaystack(line) {
            return `${line.entryName || ''} ${line.sourceName || ''} ${line.path || ''} ${line.line || ''}`;
        }

        function runtimeComparable(value) {
            const stringValue = String(value ?? '');
            return caseSensitive.checked ? stringValue : stringValue.toLowerCase();
        }

        function runtimeFilterContains(filter, line) {
            const needle = runtimeComparable(filter.query);
            if (!needle) return true;
            return runtimeComparable(lineHaystack(line)).includes(needle);
        }

        function runtimeFilterIcon(mode) {
            if (mode === 'grepv') return 'fa-filter-circle-xmark';
            if (mode === 'highlight') return 'fa-highlighter';
            return 'fa-filter';
        }

        function normalizeHighlightColor(value) {
            const color = String(value || '').trim();
            return /^#[0-9a-fA-F]{6}$/.test(color) ? color.toLowerCase() : '#facc15';
        }

        function highlightForegroundColor(color) {
            const normalized = normalizeHighlightColor(color).slice(1);
            const r = parseInt(normalized.slice(0, 2), 16);
            const g = parseInt(normalized.slice(2, 4), 16);
            const b = parseInt(normalized.slice(4, 6), 16);
            const luminance = (0.299 * r) + (0.587 * g) + (0.114 * b);
            return luminance > 150 ? '#111827' : '#ffffff';
        }

        function highlightGlowColor(color) {
            const normalized = normalizeHighlightColor(color).slice(1);
            const r = parseInt(normalized.slice(0, 2), 16);
            const g = parseInt(normalized.slice(2, 4), 16);
            const b = parseInt(normalized.slice(4, 6), 16);
            return `rgba(${r}, ${g}, ${b}, 0.42)`;
        }

        function syncRuntimeColorPicker() {
            if (!runtimeFilterColor) return;
            runtimeFilterColor.hidden = runtimeFilterMode.value !== 'highlight';
        }

        function renderRuntimeFilters() {
            if (!runtimeFilterChips) return;
            runtimeFilterChips.hidden = runtimeFilters.length === 0;
            runtimeFilterChips.innerHTML = runtimeFilters.map((filter) => `
                <span class="runtime-filter-chip">
                    <i class="fas ${runtimeFilterIcon(filter.mode)}"></i>
                    ${filter.mode === 'highlight' ? `<input class="runtime-chip-color-input" type="color" value="${escapeHtml(normalizeHighlightColor(filter.color))}" data-runtime-filter-color="${escapeHtml(filter.id)}" title="Edit highlight color" aria-label="Edit highlight color for ${escapeHtml(filter.query)}">` : ''}
                    <span>${escapeHtml(filter.mode)}</span>
                    <strong>${escapeHtml(filter.query)}</strong>
                    <button type="button" data-runtime-filter-remove="${escapeHtml(filter.id)}" title="Remove filter">
                        <i class="fas fa-xmark"></i>
                    </button>
                </span>
            `).join('');

            runtimeFilterChips.querySelectorAll('[data-runtime-filter-remove]').forEach((button) => {
                button.addEventListener('click', () => {
                    const id = button.getAttribute('data-runtime-filter-remove') || '';
                    runtimeFilters = runtimeFilters.filter((filter) => filter.id !== id);
                    renderRuntimeFilters();
                    renderLines();
                });
            });

            runtimeFilterChips.querySelectorAll('[data-runtime-filter-color]').forEach((input) => {
                const updateColor = () => {
                    const id = input.getAttribute('data-runtime-filter-color') || '';
                    const color = normalizeHighlightColor(input.value);
                    runtimeFilters = runtimeFilters.map((filter) => filter.id === id ? { ...filter, color } : filter);
                    renderLines();
                };
                input.addEventListener('input', updateColor);
                input.addEventListener('change', updateColor);
            });
        }

        function addViewerRuntimeFilter() {
            const query = runtimeFilterText.value.trim();
            if (!query) {
                runtimeFilterText.focus();
                return;
            }

            const mode = ['grep', 'grepv', 'highlight'].includes(runtimeFilterMode.value)
                ? runtimeFilterMode.value
                : 'grep';
            runtimeFilters.push({
                id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
                mode,
                query,
                color: mode === 'highlight' ? normalizeHighlightColor(runtimeFilterColor?.value) : '',
            });
            runtimeFilterText.value = '';
            renderRuntimeFilters();
            renderLines();
        }

        function highlightText(value) {
            const text = String(value ?? '');
            const highlightFilters = runtimeFilters.filter((filter) => filter.mode === 'highlight' && filter.query !== '');
            if (!highlightFilters.length || text === '') {
                return escapeHtml(text);
            }

            const haystack = runtimeComparable(text);
            const ranges = [];
            for (const filter of highlightFilters) {
                const needle = runtimeComparable(filter.query);
                if (!needle) continue;

                let index = 0;
                while ((index = haystack.indexOf(needle, index)) !== -1) {
                    const color = normalizeHighlightColor(filter.color);
                    ranges.push({
                        start: index,
                        end: index + needle.length,
                        color,
                        foreground: highlightForegroundColor(color),
                        glow: highlightGlowColor(color),
                    });
                    index += Math.max(needle.length, 1);
                }
            }

            if (!ranges.length) {
                return escapeHtml(text);
            }

            ranges.sort((a, b) => a.start - b.start || b.end - a.end);
            const selected = [];
            let coveredUntil = 0;
            for (const range of ranges) {
                const start = Math.max(range.start, coveredUntil);
                if (start >= range.end) {
                    continue;
                }
                selected.push({ ...range, start });
                coveredUntil = range.end;
            }

            let output = '';
            let cursor = 0;
            for (const range of selected) {
                output += escapeHtml(text.slice(cursor, range.start));
                output += `<mark class="log-highlight" style="--highlight-color:${escapeHtml(range.color)};--highlight-foreground:${escapeHtml(range.foreground)};--highlight-glow:${escapeHtml(range.glow)}">${escapeHtml(text.slice(range.start, range.end))}</mark>`;
                cursor = range.end;
            }
            output += escapeHtml(text.slice(cursor));
            return output;
        }

        function setStatus(message, type = '') {
            loggerStatus.textContent = message;
            loggerStatus.className = `logger-status ${type}`;
        }

        function setMode(mode) {
            currentMode = mode;
            loggerModePill.innerHTML = `<i class="fas fa-satellite-dish"></i>${escapeHtml(mode)}`;
        }

        function termsForPattern(pattern) {
            const matches = String(pattern || '').match(/"([^"]+)"|'([^']+)'|[^\s+,;|]+/g) || [];
            return matches.map((term) => term.replace(/^["']|["']$/g, '').trim()).filter(Boolean);
        }

        function filterMatchesLine(filter, line) {
            if (!filter || !filter.enabled) return true;
            const terms = termsForPattern(filter.pattern);
            if (!terms.length) return true;
            const caseSensitiveFilter = !!filter.case_sensitive;
            const mode = filter.mode === 'contains_any' ? 'contains_any' : 'contains_all';
            const haystackRaw = `${line.entryName || ''} ${line.sourceName || ''} ${line.path || ''} ${line.line || ''}`;
            const haystack = caseSensitiveFilter ? haystackRaw : haystackRaw.toLowerCase();
            let matched = 0;
            for (const term of terms) {
                const needle = caseSensitiveFilter ? term : term.toLowerCase();
                const contains = haystack.includes(needle);
                if (mode === 'contains_any' && contains) return true;
                if (mode === 'contains_all' && contains) matched += 1;
            }
            return mode === 'contains_all' && matched === terms.length;
        }

        function updateFilterChips() {
            document.querySelectorAll('[data-filter-id]').forEach((button) => {
                button.classList.toggle('active', !!activeSavedFilter && button.getAttribute('data-filter-id') === activeSavedFilter.id);
            });
            if (clearSavedFilter) {
                clearSavedFilter.hidden = !activeSavedFilter;
            }
        }

        function lineMatches(line) {
            if (activeSavedFilter && !filterMatchesLine(activeSavedFilter, line)) {
                return false;
            }
            for (const filter of runtimeFilters) {
                const contains = runtimeFilterContains(filter, line);
                if (filter.mode === 'grep' && !contains) return false;
                if (filter.mode === 'grepv' && contains) return false;
            }
            const query = lineFilter.value.trim();
            if (!query) return true;
            const haystack = lineHaystack(line);
            return caseSensitive.checked
                ? haystack.includes(query)
                : haystack.toLowerCase().includes(query.toLowerCase());
        }

        function emptyLineMessage() {
            if (loadedLines.length > 0) {
                return 'No matching loaded lines.';
            }
            if (activeSavedFilter) {
                return 'No lines returned for this saved filter.';
            }
            if (currentMode === 'search') {
                return 'No search results returned.';
            }
            return 'No lines returned.';
        }

        function renderSourceHealth(sources = []) {
            if (!sourceHealth) return;
            if (!Array.isArray(sources) || sources.length === 0) {
                sourceHealth.hidden = true;
                sourceHealth.innerHTML = '';
                return;
            }

            sourceHealth.hidden = false;
            sourceHealth.innerHTML = sources.map((source) => {
                const hasError = !!source.error;
                const label = source.entryName ? `${source.entryName} / ${source.name || 'source'}` : (source.name || 'source');
                const details = [];
                if (source.transport) details.push(source.transport);
                if (loggerIsAdmin && source.targetType) details.push(source.targetType);
                details.push(`${Number(source.lineCount || 0)} lines`);
                const summary = hasError ? 'needs attention' : details.join(' · ');
                const title = hasError ? `${label}: ${source.error}` : `${label}: ${summary}`;
                return `
                    <div class="source-health-item ${hasError ? 'error' : ''}" title="${escapeHtml(title)}">
                        <i class="fas ${hasError ? 'fa-triangle-exclamation' : 'fa-file-lines'}"></i>
                        <span class="source-health-name">${escapeHtml(label)}</span>
                        <span>${escapeHtml(summary)}</span>
                    </div>
                `;
            }).join('');
        }

        function linePrefix(line) {
            if (line.entryName) {
                return `${line.entryName} / ${line.sourceName}`;
            }
            return line.sourceName || 'source';
        }

        function renderLines() {
            const visible = loadedLines.filter(lineMatches);
            loggerCountPill.innerHTML = `<i class="fas fa-align-left"></i>${visible.length} lines`;

            if (visible.length === 0) {
                loggerConsole.innerHTML = `<div class="log-empty">${escapeHtml(emptyLineMessage())}</div>`;
                return;
            }

            loggerConsole.innerHTML = visible.map((line) => {
                const lineNumber = line.lineNumber ? `<span class="log-line-number">:${escapeHtml(line.lineNumber)}</span>` : '';
                const prefix = linePrefix(line);
                return `
                    <div class="log-row" data-line-id="${escapeHtml(line.id)}">
                        <div class="log-prefix" title="${escapeHtml(line.path)}">${escapeHtml(prefix)}${lineNumber}</div>
                        <div class="log-text">${highlightText(line.line)}</div>
                    </div>
                `;
            }).join('');

            if (following && currentMode === 'latest') {
                loggerConsole.scrollTop = loggerConsole.scrollHeight;
            }
        }

        function mergeLines(newLines, replace = false) {
            if (replace) {
                loadedLines = newLines;
                renderLines();
                return;
            }

            const seen = new Set(loadedLines.map((line) => line.id));
            for (const line of newLines) {
                if (!seen.has(line.id)) {
                    loadedLines.push(line);
                    seen.add(line.id);
                }
            }

            const maxLines = 3000;
            if (loadedLines.length > maxLines) {
                loadedLines = loadedLines.slice(loadedLines.length - maxLines);
            }
            renderLines();
        }

        async function fetchLatest(replace = true) {
            setMode('latest');
            setStatus(replace ? 'Loading latest lines...' : 'Checking for new lines...');
            const params = new URLSearchParams({
                action: 'group-tail',
                id: loggerGroup.id,
                lines: lineLimit.value || '200',
            });
            if (activeSavedFilter) {
                params.set('filterId', activeSavedFilter.id);
            }
            if (!replace) {
                params.set('poll', '1');
            }

            try {
                const response = await fetch(`/api/logger.php?${params.toString()}`);
                const data = await response.json();
                if (!data.success) {
                    setStatus(data.error || 'Group tail failed.', 'error');
                    return;
                }
                mergeLines(data.lines || [], replace);
                const errors = (data.sources || []).filter((source) => source.error);
                renderSourceHealth(data.sources || []);
                setStatus(errors.length ? 'Some sources need attention.' : `Updated ${new Date().toLocaleTimeString()}`, errors.length ? 'error' : '');
            } catch (error) {
                renderSourceHealth([]);
                setStatus(`Group tail failed: ${error.message}`, 'error');
            }
        }

        async function runRemoteSearch() {
            const query = lineFilter.value.trim();
            if (!query && !activeSavedFilter) {
                lineFilter.focus();
                return;
            }

            stopFollow();
            setMode('search');
            setStatus('Searching target files...');
            const params = new URLSearchParams({
                action: 'group-search',
                id: loggerGroup.id,
                q: query,
                limit: lineLimit.value || '200',
                caseSensitive: caseSensitive.checked ? '1' : '0',
            });
            if (activeSavedFilter) {
                params.set('filterId', activeSavedFilter.id);
            }

            try {
                const response = await fetch(`/api/logger.php?${params.toString()}`);
                const data = await response.json();
                if (!data.success) {
                    setStatus(data.error || 'Group search failed.', 'error');
                    return;
                }
                mergeLines(data.lines || [], true);
                const errors = (data.sources || []).filter((source) => source.error);
                renderSourceHealth(data.sources || []);
                setStatus(errors.length ? 'Some sources need attention.' : `Found ${(data.lines || []).length} lines`, errors.length ? 'error' : '');
            } catch (error) {
                renderSourceHealth([]);
                setStatus(`Group search failed: ${error.message}`, 'error');
            }
        }

        function startFollow() {
            following = true;
            followToggle.innerHTML = '<i class="fas fa-pause"></i>Pause';
            followToggle.classList.add('primary');
            if (pollTimer) window.clearInterval(pollTimer);
            pollTimer = window.setInterval(() => {
                if (following && currentMode === 'latest') {
                    fetchLatest(false);
                }
            }, 5000);
        }

        function stopFollow() {
            following = false;
            followToggle.innerHTML = '<i class="fas fa-play"></i>Follow';
            followToggle.classList.remove('primary');
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        refreshLogs?.addEventListener('click', () => {
            loadedLines = [];
            fetchLatest(true);
            startFollow();
        });
        lineLimit?.addEventListener('change', () => fetchLatest(true));
        lineFilter?.addEventListener('input', renderLines);
        caseSensitive?.addEventListener('change', () => {
            renderRuntimeFilters();
            renderLines();
        });
        addRuntimeFilter?.addEventListener('click', addViewerRuntimeFilter);
        runtimeFilterMode?.addEventListener('change', syncRuntimeColorPicker);
        runtimeFilterText?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                addViewerRuntimeFilter();
            }
        });
        remoteSearch?.addEventListener('click', runRemoteSearch);
        document.querySelectorAll('[data-filter-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const filterId = button.getAttribute('data-filter-id') || '';
                activeSavedFilter = loggerFilters.find((filter) => filter.id === filterId) || null;
                updateFilterChips();
                renderLines();
                if (currentMode === 'latest') {
                    fetchLatest(true);
                }
            });
        });
        clearSavedFilter?.addEventListener('click', () => {
            activeSavedFilter = null;
            updateFilterChips();
            fetchLatest(true);
        });
        lineFilter?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                runRemoteSearch();
            }
        });
        followToggle?.addEventListener('click', () => {
            if (following) {
                stopFollow();
            } else {
                setMode('latest');
                fetchLatest(false);
                startFollow();
            }
        });

        fetchLatest(true);
        startFollow();
        updateFilterChips();
        syncRuntimeColorPicker();
    </script>
    <?php endif; ?>
</body>
</html>
