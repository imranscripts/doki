<?php
/**
 * Text Diff App - Compare two text blocks side-by-side with a live visual diff.
 * Sandboxed: text only, no uploads.
 */

require_once __DIR__ . '/../../includes/AppContext.php';

$app = new AppContext('text-diff');
$user = $app->requireAuth();
$layout = $app->getLayout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Text Diff'); ?>
    <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>
    <style>
        .diff-shell {
            --diff-accent: #38bdf8;
            --diff-accent-strong: #0ea5e9;
            --diff-add: #2ea043;
            --diff-remove: #f85149;
            --diff-mod: #d29922;

            position: relative;
            border-radius: 18px;
            border: 1px solid var(--border-color, #30363d);
            padding: 18px;
            background:
                radial-gradient(900px 400px at -10% -20%, rgba(56, 189, 248, 0.15), transparent 55%),
                radial-gradient(800px 500px at 110% 0%, rgba(14, 165, 233, 0.12), transparent 60%),
                linear-gradient(180deg, rgba(22, 27, 34, 0.98), rgba(13, 17, 23, 0.98));
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
            overflow: hidden;
        }

        .diff-shell::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.15;
            pointer-events: none;
        }

        .diff-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .control-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            align-items: center;
        }

        .action-group,
        .toggle-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color, #30363d);
            background: rgba(22, 27, 34, 0.9);
            color: var(--text-primary, #f0f6fc);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .action-btn i {
            color: var(--diff-accent);
        }

        .action-btn:hover {
            border-color: var(--diff-accent);
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
            transform: translateY(-1px);
        }

        .action-btn.ghost {
            background: transparent;
            color: var(--text-muted, #94a3b8);
        }

        .toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(22, 27, 34, 0.8);
            border: 1px solid var(--border-color, #30363d);
            font-size: 11px;
            color: var(--text-secondary, #cbd5f5);
            cursor: pointer;
            user-select: none;
        }

        .toggle input {
            accent-color: var(--diff-accent-strong);
            width: 14px;
            height: 14px;
        }

        .diff-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .stat {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(22, 27, 34, 0.9);
            color: var(--text-primary, #f0f6fc);
        }

        .stat.insert { color: #7ee787; border-color: rgba(46, 160, 67, 0.4); background: rgba(46, 160, 67, 0.15); }
        .stat.delete { color: #ff9b8a; border-color: rgba(248, 81, 73, 0.4); background: rgba(248, 81, 73, 0.12); }
        .stat.modify { color: #f4c76a; border-color: rgba(210, 153, 34, 0.4); background: rgba(210, 153, 34, 0.12); }
        .stat.match { color: #7dd3fc; border-color: rgba(56, 189, 248, 0.4); background: rgba(56, 189, 248, 0.12); }

        .editor-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .editor-panel {
            display: flex;
            flex-direction: column;
            border-radius: 14px;
            border: 1px solid var(--border-color, #30363d);
            background: rgba(13, 17, 23, 0.9);
            overflow: hidden;
            min-height: 360px;
        }

        .editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 14px;
            background: rgba(22, 27, 34, 0.95);
            border-bottom: 1px solid var(--border-color, #30363d);
        }

        .editor-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary, #f0f6fc);
        }

        .editor-title .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--diff-accent);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
        }

        .editor-title .dot.right {
            background: #34d399;
            box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.2);
        }

        .editor-meta {
            font-size: 11px;
            color: var(--text-muted, #94a3b8);
            font-weight: 500;
        }

        .editor-actions {
            display: flex;
            gap: 6px;
        }

        .mini-btn {
            padding: 5px 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color, #30363d);
            background: transparent;
            color: var(--text-muted, #94a3b8);
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .mini-btn:hover {
            color: var(--text-primary, #f0f6fc);
            border-color: var(--diff-accent);
        }

        .editor-body {
            flex: 1;
            min-height: 320px;
            height: clamp(280px, 40vh, 520px);
        }

        .diff-panel {
            position: relative;
            z-index: 1;
            border-radius: 14px;
            border: 1px solid var(--border-color, #30363d);
            background: rgba(13, 17, 23, 0.92);
            overflow: hidden;
        }

        .diff-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 14px;
            background: rgba(22, 27, 34, 0.95);
            border-bottom: 1px solid var(--border-color, #30363d);
        }

        .diff-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary, #f0f6fc);
        }

        .diff-subtitle {
            font-size: 11px;
            color: var(--text-muted, #94a3b8);
            font-weight: 500;
        }

        .view-toggle {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--border-color, #30363d);
            border-radius: 999px;
            overflow: hidden;
        }

        .view-toggle button {
            padding: 6px 10px;
            font-size: 11px;
            background: transparent;
            color: var(--text-muted, #94a3b8);
            border: none;
            cursor: pointer;
        }

        .view-toggle button.active {
            background: rgba(56, 189, 248, 0.18);
            color: #7dd3fc;
        }

        .diff-body {
            position: relative;
            min-height: 300px;
        }

        .diff-body.is-empty #diffEditor {
            opacity: 0.3;
            filter: grayscale(0.6);
        }

        .diff-empty {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
            color: var(--text-muted, #94a3b8);
            font-size: 13px;
            z-index: 2;
            backdrop-filter: blur(2px);
        }

        #diffEditor {
            height: clamp(260px, 36vh, 480px);
        }

        #editorLeft,
        #editorRight {
            height: 100%;
        }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: rgba(22, 27, 34, 0.95);
            border: 1px solid var(--border-color, #30363d);
            color: var(--text-primary, #f0f6fc);
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 12px;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.2s ease;
            pointer-events: none;
            z-index: 999;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .monaco-editor .editor-diff-line-added { background: rgba(46, 160, 67, 0.18); }
        .monaco-editor .editor-diff-line-removed { background: rgba(248, 81, 73, 0.18); }
        .monaco-editor .editor-diff-line-modified { background: rgba(210, 153, 34, 0.2); }

        .monaco-editor .editor-diff-gutter-added { border-left: 3px solid #2ea043; }
        .monaco-editor .editor-diff-gutter-removed { border-left: 3px solid #f85149; }
        .monaco-editor .editor-diff-gutter-modified { border-left: 3px solid #d29922; }

        @media (max-width: 1200px) {
            .diff-controls {
                grid-template-columns: 1fr;
            }

            .diff-stats {
                justify-content: flex-start;
            }
        }

        @media (max-width: 900px) {
            .editor-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .diff-shell {
                padding: 14px;
            }

            .diff-controls {
                gap: 12px;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
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

                <div class="app-content">
                    <div class="diff-shell">
                        <div class="diff-controls">
                            <div class="control-stack">
                                <div class="action-group">
                                    <button class="action-btn" id="swapBtn" type="button">
                                        <i class="fas fa-right-left"></i>
                                        Swap Sides
                                    </button>
                                    <button class="action-btn" id="clearBtn" type="button">
                                        <i class="fas fa-trash"></i>
                                        Clear Both
                                    </button>
                                    <button class="action-btn ghost" id="exampleBtn" type="button">
                                        <i class="fas fa-wand-magic-sparkles"></i>
                                        Load Example
                                    </button>
                                </div>
                                <div class="toggle-group">
                                    <label class="toggle">
                                        <input type="checkbox" id="ignoreWhitespace" />
                                        Ignore whitespace
                                    </label>
                                    <label class="toggle">
                                        <input type="checkbox" id="wrapLines" checked />
                                        Wrap lines
                                    </label>
                                    <label class="toggle">
                                        <input type="checkbox" id="syncScroll" checked />
                                        Sync scroll
                                    </label>
                                    <label class="toggle">
                                        <input type="checkbox" id="highlightChanges" checked />
                                        Highlight changes
                                    </label>
                                </div>
                            </div>
                            <div class="diff-stats">
                                <div class="stat insert" id="statInsert">+0</div>
                                <div class="stat delete" id="statDelete">-0</div>
                                <div class="stat modify" id="statModify">~0</div>
                                <div class="stat match" id="statMatch">100% match</div>
                            </div>
                        </div>

                        <div class="editor-grid">
                            <div class="editor-panel">
                                <div class="editor-header">
                                    <div class="editor-title">
                                        <span class="dot"></span>
                                        Text A
                                        <span class="editor-meta" id="leftMeta">0 lines • 0 chars</span>
                                    </div>
                                    <div class="editor-actions">
                                        <button class="mini-btn" id="copyLeft" type="button">Copy</button>
                                    </div>
                                </div>
                                <div class="editor-body" id="editorLeft"></div>
                            </div>

                            <div class="editor-panel">
                                <div class="editor-header">
                                    <div class="editor-title">
                                        <span class="dot right"></span>
                                        Text B
                                        <span class="editor-meta" id="rightMeta">0 lines • 0 chars</span>
                                    </div>
                                    <div class="editor-actions">
                                        <button class="mini-btn" id="copyRight" type="button">Copy</button>
                                    </div>
                                </div>
                                <div class="editor-body" id="editorRight"></div>
                            </div>
                        </div>

                        <div class="diff-panel">
                            <div class="diff-header">
                                <div class="diff-title">
                                    <i class="fas fa-code-compare"></i>
                                    Differences
                                    <span class="diff-subtitle" id="diffSubtitle">Paste text in both editors to compare.</span>
                                </div>
                                <div class="view-toggle">
                                    <button type="button" id="viewUnified" class="active">Unified</button>
                                    <button type="button" id="viewSplit">Split</button>
                                </div>
                            </div>
                            <div class="diff-body is-empty">
                                <div class="diff-empty" id="diffEmpty">Drop text into both editors to reveal a live diff.</div>
                                <div id="diffEditor"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        let leftEditor;
        let rightEditor;
        let diffEditor;
        let leftDecorations = [];
        let rightDecorations = [];
        let syncScrollEnabled = true;
        let highlightEnabled = true;
        let isSyncing = false;

        const leftMeta = document.getElementById('leftMeta');
        const rightMeta = document.getElementById('rightMeta');
        const diffSubtitle = document.getElementById('diffSubtitle');
        const diffEmpty = document.getElementById('diffEmpty');
        const diffBody = document.querySelector('.diff-body');
        const toast = document.getElementById('toast');

        const statInsert = document.getElementById('statInsert');
        const statDelete = document.getElementById('statDelete');
        const statModify = document.getElementById('statModify');
        const statMatch = document.getElementById('statMatch');

        const ignoreWhitespace = document.getElementById('ignoreWhitespace');
        const wrapLines = document.getElementById('wrapLines');
        const syncScroll = document.getElementById('syncScroll');
        const highlightChanges = document.getElementById('highlightChanges');

        const swapBtn = document.getElementById('swapBtn');
        const clearBtn = document.getElementById('clearBtn');
        const exampleBtn = document.getElementById('exampleBtn');
        const copyLeft = document.getElementById('copyLeft');
        const copyRight = document.getElementById('copyRight');
        const viewUnified = document.getElementById('viewUnified');
        const viewSplit = document.getElementById('viewSplit');

        const sampleLeft = `Product Plan\n\n- Launch window: March 2026\n- Target markets: US, UK, AU\n- Pricing: $12/user/month\n- SLA: 99.9% uptime\n\nNotes:\nFocus on onboarding speed and in-app tips.\n`;

        const sampleRight = `Product Plan\n\n- Launch window: April 2026\n- Target markets: US, UK, AU, CA\n- Pricing: $15/user/month\n- SLA: 99.95% uptime\n\nNotes:\nFocus on onboarding speed, in-app tips, and templates.\n`;

        function showToast(message) {
            toast.textContent = message;
            toast.classList.add('show');
            clearTimeout(showToast._timer);
            showToast._timer = setTimeout(() => {
                toast.classList.remove('show');
            }, 1800);
        }

        function debounce(fn, wait = 150) {
            let timer;
            return (...args) => {
                clearTimeout(timer);
                timer = setTimeout(() => fn(...args), wait);
            };
        }

        function pluralize(count, label) {
            return `${count} ${label}${count === 1 ? '' : 's'}`;
        }

        function getStats(text) {
            if (!text || !text.trim()) {
                return { lines: 0, chars: 0 };
            }
            const lines = text.split(/\r\n|\r|\n/).length;
            const chars = text.length;
            return { lines, chars };
        }

        function updateEditorMeta(originalModel, modifiedModel) {
            const leftText = originalModel.getValue();
            const rightText = modifiedModel.getValue();
            const leftStats = getStats(leftText);
            const rightStats = getStats(rightText);

            leftMeta.textContent = `${pluralize(leftStats.lines, 'line')} • ${pluralize(leftStats.chars, 'char')}`;
            rightMeta.textContent = `${pluralize(rightStats.lines, 'line')} • ${pluralize(rightStats.chars, 'char')}`;
        }

        function isReadyToCompare(originalModel, modifiedModel) {
            return originalModel.getValue().trim().length > 0 && modifiedModel.getValue().trim().length > 0;
        }

        function countLines(start, end) {
            if (!start || !end || start === 0 || end === 0) {
                return 0;
            }
            return Math.max(0, end - start + 1);
        }

        function updateDiffSummary(originalModel, modifiedModel) {
            const ready = isReadyToCompare(originalModel, modifiedModel);
            diffBody.classList.toggle('is-empty', !ready);
            diffEmpty.style.display = ready ? 'none' : 'flex';

            if (!ready) {
                diffSubtitle.textContent = 'Paste text in both editors to compare.';
                statInsert.textContent = '+0';
                statDelete.textContent = '-0';
                statModify.textContent = '~0';
                statMatch.textContent = '100% match';
                return;
            }

            const lineChanges = diffEditor.getLineChanges() || [];
            let inserted = 0;
            let deleted = 0;
            let modified = 0;

            lineChanges.forEach(change => {
                const originalLines = countLines(change.originalStartLineNumber, change.originalEndLineNumber);
                const modifiedLines = countLines(change.modifiedStartLineNumber, change.modifiedEndLineNumber);

                if (originalLines === 0 && modifiedLines > 0) {
                    inserted += modifiedLines;
                } else if (modifiedLines === 0 && originalLines > 0) {
                    deleted += originalLines;
                } else if (originalLines > 0 && modifiedLines > 0) {
                    modified += Math.max(originalLines, modifiedLines);
                }
            });

            const leftLines = getStats(originalModel.getValue()).lines;
            const rightLines = getStats(modifiedModel.getValue()).lines;
            const baseLines = Math.max(1, leftLines, rightLines);
            const totalChanges = Math.min(baseLines, inserted + deleted + modified);
            const match = Math.max(0, Math.round(100 * (1 - totalChanges / baseLines)));

            statInsert.textContent = `+${inserted}`;
            statDelete.textContent = `-${deleted}`;
            statModify.textContent = `~${modified}`;
            statMatch.textContent = `${match}% match`;

            if (lineChanges.length === 0) {
                diffSubtitle.textContent = 'No differences detected.';
            } else {
                diffSubtitle.textContent = `${lineChanges.length} change${lineChanges.length === 1 ? '' : 's'} highlighted.`;
            }
        }

        function clearDecorations() {
            if (!leftEditor || !rightEditor) return;
            leftDecorations = leftEditor.deltaDecorations(leftDecorations, []);
            rightDecorations = rightEditor.deltaDecorations(rightDecorations, []);
        }

        function updateDecorations(originalModel, modifiedModel) {
            if (!highlightEnabled) {
                clearDecorations();
                return;
            }

            const ready = isReadyToCompare(originalModel, modifiedModel);
            if (!ready) {
                clearDecorations();
                return;
            }

            const lineChanges = diffEditor.getLineChanges() || [];
            const leftNew = [];
            const rightNew = [];

            lineChanges.forEach(change => {
                const originalLines = countLines(change.originalStartLineNumber, change.originalEndLineNumber);
                const modifiedLines = countLines(change.modifiedStartLineNumber, change.modifiedEndLineNumber);

                if (originalLines > 0) {
                    const className = modifiedLines > 0 ? 'editor-diff-line-modified' : 'editor-diff-line-removed';
                    const gutterClass = modifiedLines > 0 ? 'editor-diff-gutter-modified' : 'editor-diff-gutter-removed';
                    leftNew.push({
                        range: new monaco.Range(change.originalStartLineNumber, 1, change.originalEndLineNumber, 1),
                        options: { isWholeLine: true, className, linesDecorationsClassName: gutterClass }
                    });
                }

                if (modifiedLines > 0) {
                    const className = originalLines > 0 ? 'editor-diff-line-modified' : 'editor-diff-line-added';
                    const gutterClass = originalLines > 0 ? 'editor-diff-gutter-modified' : 'editor-diff-gutter-added';
                    rightNew.push({
                        range: new monaco.Range(change.modifiedStartLineNumber, 1, change.modifiedEndLineNumber, 1),
                        options: { isWholeLine: true, className, linesDecorationsClassName: gutterClass }
                    });
                }
            });

            leftDecorations = leftEditor.deltaDecorations(leftDecorations, leftNew);
            rightDecorations = rightEditor.deltaDecorations(rightDecorations, rightNew);
        }

        function setWordWrap(enabled) {
            const wrap = enabled ? 'on' : 'off';
            leftEditor.updateOptions({ wordWrap: wrap });
            rightEditor.updateOptions({ wordWrap: wrap });
            diffEditor.updateOptions({ wordWrap: wrap });
        }

        function setViewMode(mode) {
            const sideBySide = mode === 'split';
            diffEditor.updateOptions({ renderSideBySide: sideBySide });
            viewUnified.classList.toggle('active', !sideBySide);
            viewSplit.classList.toggle('active', sideBySide);
        }

        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => showToast('Copied to clipboard'));
                return;
            }

            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showToast('Copied to clipboard');
            } catch (err) {
                showToast('Copy failed');
            }
            document.body.removeChild(textarea);
        }

        function syncEditors(left, right) {
            left.onDidScrollChange(e => {
                if (!syncScrollEnabled || isSyncing) return;
                isSyncing = true;
                right.setScrollTop(e.scrollTop);
                isSyncing = false;
            });

            right.onDidScrollChange(e => {
                if (!syncScrollEnabled || isSyncing) return;
                isSyncing = true;
                left.setScrollTop(e.scrollTop);
                isSyncing = false;
            });
        }

        require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
        require(['vs/editor/editor.main'], function() {
            const originalModel = monaco.editor.createModel('', 'plaintext');
            const modifiedModel = monaco.editor.createModel('', 'plaintext');

            const editorOptions = {
                theme: 'vs-dark',
                automaticLayout: true,
                minimap: { enabled: false },
                scrollBeyondLastLine: false,
                fontSize: 14,
                fontFamily: "'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace",
                lineNumbers: 'on',
                renderWhitespace: 'selection',
                wordWrap: 'on',
                smoothScrolling: true,
                padding: { top: 12, bottom: 12 }
            };

            leftEditor = monaco.editor.create(document.getElementById('editorLeft'), {
                ...editorOptions,
                model: originalModel
            });

            rightEditor = monaco.editor.create(document.getElementById('editorRight'), {
                ...editorOptions,
                model: modifiedModel
            });

            diffEditor = monaco.editor.createDiffEditor(document.getElementById('diffEditor'), {
                theme: 'vs-dark',
                automaticLayout: true,
                readOnly: true,
                renderSideBySide: false,
                ignoreTrimWhitespace: false,
                renderIndicators: true,
                minimap: { enabled: false },
                scrollBeyondLastLine: false,
                wordWrap: 'on',
                originalEditable: false
            });

            diffEditor.setModel({ original: originalModel, modified: modifiedModel });

            const scheduleUpdate = debounce(() => {
                updateEditorMeta(originalModel, modifiedModel);
                updateDiffSummary(originalModel, modifiedModel);
                if (!isReadyToCompare(originalModel, modifiedModel)) {
                    clearDecorations();
                }
            }, 120);

            originalModel.onDidChangeContent(scheduleUpdate);
            modifiedModel.onDidChangeContent(scheduleUpdate);

            diffEditor.onDidUpdateDiff(() => {
                updateDiffSummary(originalModel, modifiedModel);
                updateDecorations(originalModel, modifiedModel);
            });

            updateEditorMeta(originalModel, modifiedModel);
            updateDiffSummary(originalModel, modifiedModel);

            syncEditors(leftEditor, rightEditor);

            ignoreWhitespace.addEventListener('change', (e) => {
                diffEditor.updateOptions({ ignoreTrimWhitespace: e.target.checked });
            });

            wrapLines.addEventListener('change', (e) => {
                setWordWrap(e.target.checked);
            });

            syncScroll.addEventListener('change', (e) => {
                syncScrollEnabled = e.target.checked;
            });

            highlightChanges.addEventListener('change', (e) => {
                highlightEnabled = e.target.checked;
                updateDecorations(originalModel, modifiedModel);
            });

            swapBtn.addEventListener('click', () => {
                const leftValue = originalModel.getValue();
                const rightValue = modifiedModel.getValue();
                originalModel.setValue(rightValue);
                modifiedModel.setValue(leftValue);
                showToast('Swapped text panels');
            });

            clearBtn.addEventListener('click', () => {
                originalModel.setValue('');
                modifiedModel.setValue('');
                showToast('Cleared both panels');
            });

            exampleBtn.addEventListener('click', () => {
                originalModel.setValue(sampleLeft);
                modifiedModel.setValue(sampleRight);
                showToast('Example loaded');
            });

            copyLeft.addEventListener('click', () => {
                copyText(originalModel.getValue());
            });

            copyRight.addEventListener('click', () => {
                copyText(modifiedModel.getValue());
            });

            viewUnified.addEventListener('click', () => setViewMode('unified'));
            viewSplit.addEventListener('click', () => setViewMode('split'));
        });
    </script>
</body>
</html>
