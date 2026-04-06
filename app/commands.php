<?php
/**
 * Commands Page - Create and manage commands
 */
require_once __DIR__ . '/includes/middleware.php';
requireAuth();
requireModuleAccess('commands');

$user = getCurrentUser();
$layout = new Layout($user, 'commands');
$isAdmin = in_array($user['role'], ['super-admin', 'admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $layout->renderHead('Commands'); ?>
    <style>
        /* ── Commands Grid ── */
        .commands-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .command-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s ease;
            position: relative;
        }
        .command-card:hover { border-color: var(--accent-primary); box-shadow: 0 4px 16px rgba(0,0,0,.15); }
        .command-card.disabled { opacity: .6; }
        .command-header { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
        .command-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; background: var(--bg-tertiary); flex-shrink: 0; }
        .command-info { flex: 1; min-width: 0; }
        .command-name { font-size: 15px; font-weight: 600; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .command-template { font-size: 12px; color: var(--text-muted); }
        .command-description { font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .command-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .command-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 4px; font-size: 11px; background: var(--bg-tertiary); color: var(--text-secondary); }
        .command-actions { display: flex; gap: 8px; padding-top: 12px; border-top: 1px solid var(--border-primary); }
        .command-actions .btn { flex: 1; justify-content: center; padding: 8px 12px; font-size: 13px; }
        .btn-run { background: linear-gradient(135deg, var(--success), #2e9e4e); color: white; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-secondary); }
        .empty-state > i { font-size: 48px; margin-bottom: 16px; opacity: .5; display: block; }
        .empty-state h3 { font-size: 18px; margin-bottom: 8px; color: var(--text-primary); }
        .loading { display: flex; align-items: center; justify-content: center; padding: 60px; color: var(--text-secondary); }
        .loading i { font-size: 24px; animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* ── Builder Overlay ── */
        .builder-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.3s cubic-bezier(.4,0,.2,1);
        }
        .builder-overlay.active { transform: translateX(0); }

        /* ── Builder Header ── */
        .builder-header {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 0 24px;
            height: 64px;
            border-bottom: 1px solid var(--border-primary);
            flex-shrink: 0;
            background: var(--bg-secondary);
        }
        .builder-back {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 6px;
            white-space: nowrap;
        }
        .builder-back:hover { background: var(--bg-hover); color: var(--text-primary); }
        .builder-sep { width: 1px; height: 24px; background: var(--border-primary); flex-shrink: 0; }
        .builder-title { flex: 1; font-size: 14px; color: var(--text-muted); font-weight: 500; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        /* Style button popover */
        .style-btn-wrap { position: relative; }
        .style-btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 7px 12px; border: 1.5px solid var(--border-primary);
            border-radius: 8px; background: var(--bg-tertiary);
            color: var(--text-secondary); font-size: 13px;
            cursor: pointer; font-family: inherit;
            transition: all .12s ease;
        }
        .style-btn:hover { border-color: var(--accent-primary); color: var(--text-primary); }
        .style-btn-icon { font-size: 16px; line-height: 1; }
        .style-popover {
            display: none;
            position: absolute; right: 0; top: calc(100% + 6px); z-index: 200;
            background: var(--bg-secondary); border: 1px solid var(--border-primary);
            border-radius: 10px; padding: 14px; width: 220px;
            box-shadow: 0 8px 24px rgba(0,0,0,.25);
        }
        .style-pop-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
        .style-pop-sep { border-top: 1px solid var(--border-primary); margin: 12px 0; }
        /* Name input inside the form */
        .builder-name-field {
            width: 100%; background: none; border: none; border-bottom: 1.5px solid var(--border-primary);
            color: var(--text-primary); font-size: 20px; font-weight: 600;
            outline: none; font-family: inherit; padding: 4px 0 8px;
            transition: border-color .15s;
        }
        .builder-name-field:focus { border-bottom-color: var(--accent-primary); }
        .builder-name-field::placeholder { color: var(--text-muted); font-weight: 400; }

        /* ── Builder Scroll (new single-column layout) ── */
        .builder-scroll {
            flex: 1;
            overflow-y: auto;
            background: var(--bg-primary);
        }
        .builder-form {
            max-width: 760px;
            margin: 0 auto;
            padding: 24px 24px 60px;
        }

        /* ── Form Section Cards ── */
        .form-section-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .section-num-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-tertiary);
            border-radius: 12px 12px 0 0;
            width: 100%;
            text-align: left;
        }
        button.section-num-header {
            border: none;
            cursor: pointer;
            font-family: inherit;
            transition: background .1s;
        }
        button.section-num-header:hover { background: var(--bg-hover); }
        .section-num-badge {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--accent-primary);
            color: white;
            font-size: 11px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .section-num-title {
            font-size: 12px; font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase; letter-spacing: .5px;
        }
        .section-hint { font-size: 12px; color: var(--text-muted); margin-left: 4px; }
        .section-body { padding: 20px; }
        .section-body-sm { padding: 16px; }

        /* ── Form elements (shared) ── */
        .form-group { margin-bottom: 16px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; color: var(--text-secondary); }
        .form-label .required { color: var(--error); margin-left: 4px; }
        .form-label-muted { font-weight: 400; color: var(--text-muted); font-size: 12px; margin-left: 6px; }
        .info-btn { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0 2px; font-size: 12px; vertical-align: middle; line-height: 1; }
        .info-btn:hover { color: var(--accent-primary); }
        .info-popover-wrap { position: relative; display: inline-block; vertical-align: middle; }
        .info-popover {
            position: absolute; left: 0; top: calc(100% + 5px); z-index: 300;
            background: var(--bg-secondary); border: 1px solid var(--border-primary);
            border-radius: 8px; padding: 12px 14px; width: 280px;
            box-shadow: 0 6px 20px rgba(0,0,0,.25);
            font-size: 12px; font-weight: 400; color: var(--text-secondary); line-height: 1.6;
        }
        .info-popover code { background: var(--bg-tertiary); border-radius: 3px; padding: 1px 5px; font-family: 'JetBrains Mono','Fira Code',monospace; font-size: 11px; color: var(--text-primary); }
        .form-input, .form-select, .form-textarea {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--border-primary); border-radius: 8px;
            background: var(--bg-tertiary); color: var(--text-primary);
            font-size: 14px; font-family: inherit;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px var(--accent-glow);
        }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        .form-inline-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); margin-bottom: 6px; display: block; }
        .form-array-editor {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            background: var(--bg-tertiary);
        }
        .form-array-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .form-array-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-array-row .form-input {
            flex: 1;
        }
        .form-array-actions {
            display: flex;
            justify-content: flex-start;
        }
        .form-array-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .form-array-btn:hover {
            border-color: var(--accent-primary);
            color: var(--text-primary);
            background: var(--bg-hover);
        }

        /* ── Toggle Options ── */
        .toggle-opts { display: flex; gap: 0; }
        .toggle-opt {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            gap: 7px;
            padding: 9px 14px;
            border: 1.5px solid var(--border-primary);
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            transition: all .12s ease;
            font-family: inherit;
            position: relative; z-index: 0;
        }
        .toggle-opts .toggle-opt:not(:first-child) { margin-left: -1.5px; }
        .toggle-opts .toggle-opt:first-child { border-radius: 8px 0 0 8px; }
        .toggle-opts .toggle-opt:last-child  { border-radius: 0 8px 8px 0; }
        .toggle-opt.active { background: var(--accent-glow); border-color: var(--accent-primary); color: var(--accent-primary); font-weight: 600; z-index: 1; }
        .toggle-opt:hover:not(.active) { background: var(--bg-hover); color: var(--text-primary); z-index: 1; }

        /* ── Icon / Color Picker ── */
        .icon-grid { display: flex; flex-wrap: wrap; gap: 4px; max-height: 130px; overflow-y: auto; }
        .icon-opt { font-size: 18px; padding: 5px; border-radius: 6px; cursor: pointer; transition: background .1s; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; }
        .icon-opt:hover { background: var(--bg-hover); }
        .icon-opt.selected { background: var(--accent-glow); }
        .color-swatches { display: flex; gap: 8px; flex-wrap: wrap; }
        .color-swatch { width: 26px; height: 26px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; transition: all .12s; flex-shrink: 0; }
        .color-swatch:hover { transform: scale(1.15); }
        .color-swatch.selected { border-color: white; box-shadow: 0 0 0 2px var(--accent-primary); }

        /* ── Stepper ── */
        .stepper { display: flex; align-items: center; gap: 0; }
        .stepper-btn { width: 36px; height: 38px; border: 1px solid var(--border-primary); background: var(--bg-tertiary); color: var(--text-primary); cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; }
        .stepper-btn:first-child { border-radius: 8px 0 0 8px; }
        .stepper-btn:last-child  { border-radius: 0 8px 8px 0; border-left: none; }
        .stepper-val { width: 52px; text-align: center; border: 1px solid var(--border-primary); border-left: none; border-right: none; padding: 8px 0; font-size: 14px; background: var(--bg-tertiary); color: var(--text-primary); }

        /* ── Tags ── */
        .tags-container { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; border: 1px solid var(--border-primary); border-radius: 8px; background: var(--bg-tertiary); min-height: 44px; }
        .tag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: var(--accent-glow); border-radius: 6px; font-size: 13px; color: var(--accent-primary); }
        .tag-remove { background: none; border: none; color: inherit; cursor: pointer; padding: 0; font-size: 12px; }
        .tag-input { flex: 1; min-width: 100px; border: none; background: transparent; color: var(--text-primary); font-size: 14px; outline: none; }

        /* ── Secret Mappings ── */
        .secret-row { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; }
        .secret-row .form-input, .secret-row .form-select { flex: 1; }
        .secret-arrow { color: var(--text-muted); flex-shrink: 0; font-size: 14px; }

        /* ── Advanced Collapse ── */
        .adv-body { display: none; }
        .adv-body.open { display: block; }

        /* ── Env target fields ── */
        .env-target-fields { display: flex; gap: 8px; align-items: flex-end; }
        .env-target-fields .form-group { flex: 1; margin-bottom: 0; }
        .env-keys-loading { margin-top:8px; color:var(--text-muted); font-size:13px; display:flex; align-items:center; gap:8px; }
        .tpl-type-badge { font-size:10px; font-weight:600; padding:1px 6px; border-radius:999px; background:var(--bg-tertiary); color:var(--text-secondary); margin-left:6px; vertical-align:middle; }
        .tpl-selected .tpl-type-badge { background:var(--accent-glow); color:var(--accent-primary); }
        .tpl-card-type { font-size:10px; color:var(--accent-primary); margin-top:2px; opacity:.85; }

        /* ── Step Cards ── */
        .step-card {
            border: 1.5px solid var(--border-primary);
            border-radius: 10px;
            overflow: hidden;
            background: var(--bg-primary);
            transition: border-color .15s;
        }
        .step-card:hover { border-color: rgba(88,166,255,.25); }
        .step-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
        }
        .step-num-sm {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            color: var(--text-muted);
            font-size: 11px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .step-id-input {
            flex: 1;
            border: none;
            background: none;
            color: var(--text-muted);
            font-size: 12px;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            outline: none;
            min-width: 0;
            padding: 2px 0;
        }
        .step-id-input:hover { color: var(--text-secondary); }
        .step-id-input:focus { color: var(--text-primary); border-bottom: 1px solid var(--accent-primary); }
        .step-card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .step-options-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .step-opt-group { flex: 1; min-width: 180px; }
        .step-target-inline {
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            padding: 12px;
            margin-top: 2px;
        }
        .step-ctrl { display: flex; gap: 4px; }
        .step-ctrl-btn { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 4px 6px; border-radius: 4px; font-size: 12px; }
        .step-ctrl-btn:hover { background: var(--bg-hover); color: var(--text-primary); }
        .step-ctrl-btn:disabled { opacity: .3; cursor: not-allowed; }

        /* ── Step Connector (between steps) ── */
        .step-conn { display: flex; flex-direction: column; align-items: center; padding: 4px 0; position: relative; }
        .step-conn-line { width: 2px; height: 10px; background: var(--border-primary); }
        .connector-pill {
            display: flex; align-items: center; gap: 6px;
            padding: 5px 12px;
            border: 1.5px solid var(--border-primary);
            border-radius: 999px;
            font-size: 11px; font-weight: 600;
            cursor: pointer;
            background: var(--bg-secondary);
            transition: all .15s ease;
            white-space: nowrap;
        }
        .connector-pill:hover { border-color: var(--accent-primary); background: var(--bg-hover); }
        .connector-pill.success { border-color: rgba(63,185,80,.4); color: var(--success); }
        .connector-pill.always  { border-color: rgba(210,153,34,.4); color: var(--warning); }
        .connector-pill.parallel { border-color: var(--border-primary); color: var(--text-muted); }

        /* ── Connector Popover ── */
        .connector-popover {
            position: fixed;
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: 12px;
            padding: 8px;
            min-width: 300px;
            z-index: 3000;
            box-shadow: 0 8px 32px rgba(0,0,0,.4);
            display: none;
        }
        .connector-popover.open { display: block; }
        .cpm-title { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; padding: 6px 10px 8px; }
        .cpm-option {
            display: flex; align-items: flex-start; gap: 12px;
            width: 100%; padding: 10px 12px;
            border: none; background: none; cursor: pointer;
            border-radius: 8px; text-align: left; transition: background .1s;
        }
        .cpm-option:hover { background: var(--bg-hover); }
        .cpm-option.active { background: var(--accent-glow); }
        .cpm-icon { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; margin-top: 1px; }
        .cpm-icon.success { background: rgba(63,185,80,.15); color: var(--success); }
        .cpm-icon.always  { background: rgba(210,153,34,.15); color: var(--warning); }
        .cpm-icon.parallel { background: var(--bg-tertiary); color: var(--text-muted); }
        .cpm-label { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .cpm-desc { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        /* ── Step Add Button ── */
        .step-add-wrap { padding: 8px 0 2px; }
        .pipeline-add-btn {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 20px;
            border: 1.5px dashed var(--border-primary);
            border-radius: 8px;
            background: none; color: var(--text-muted);
            font-size: 13px; cursor: pointer;
            transition: all .15s ease;
            width: 100%; justify-content: center;
        }
        .pipeline-add-btn:hover { border-color: var(--accent-primary); color: var(--accent-primary); background: var(--accent-glow); }

        /* ── Run Modal ── */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 24px; opacity: 0; visibility: hidden; transition: all .2s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal { background: var(--bg-secondary); border: 1px solid var(--border-primary); border-radius: 16px; width: 100%; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; transform: scale(.95); transition: transform .2s ease; }
        .modal-overlay.active .modal { transform: scale(1); }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-primary); display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-size: 18px; font-weight: 600; }
        .modal-close { background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; padding: 4px; }
        .modal-close:hover { color: var(--text-primary); }
        .modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-primary); display: flex; justify-content: flex-end; gap: 12px; }
        .run-output { background: #0d1117; border: 1px solid var(--border-primary); border-radius: 8px; padding: 16px; font-family: 'JetBrains Mono','Fira Code',monospace; font-size: 13px; line-height: 1.5; color: #c9d1d9; max-height: 400px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
        .run-output-wrap { position: relative; }
        .run-output-wrap .copy-btn { position: absolute; top: 8px; right: 8px; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12); border-radius: 6px; color: #8b949e; font-size: 12px; padding: 4px 8px; cursor: pointer; display: flex; align-items: center; gap: 5px; opacity: 0; transition: opacity .15s, background .15s, color .15s; z-index: 1; }
        .run-output-wrap:hover .copy-btn { opacity: 1; }
        .run-output-wrap .copy-btn:hover { background: rgba(255,255,255,.13); color: #c9d1d9; }
        .run-output-wrap .copy-btn.copied { color: var(--success); border-color: var(--success); }
        .run-status { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; margin-left: 12px; }
        .run-status.running { background: rgba(88,166,255,.15); color: var(--accent-primary); animation: pulse 1.5s ease-in-out infinite; }
        .run-status.success { background: rgba(63,185,80,.15); color: var(--success); }
        .run-status.error   { background: rgba(248,81,73,.15);  color: var(--error); }
        .run-status.warning { background: rgba(210,153,34,.15); color: var(--warning); }
        .run-status.info    { background: rgba(88,166,255,.15); color: var(--accent-primary); }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .6; } }
        .run-steps-list { display: flex; flex-direction: column; gap: 8px; }
        .run-step-row { border: 1px solid var(--border-primary); border-radius: 8px; padding: 10px 12px; background: var(--bg-tertiary); }
        .run-step-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 4px; }
        .run-step-name { font-size: 13px; font-weight: 600; }
        .run-step-meta { font-size: 12px; color: var(--text-muted); }
        .step-status { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; font-weight: 600; }
        .step-status.pending   { background: rgba(148,163,184,.15); color: #94a3b8; }
        .step-status.running   { background: rgba(88,166,255,.15); color: var(--accent-primary); }
        .step-status.completed { background: rgba(63,185,80,.15); color: var(--success); }
        .step-status.failed    { background: rgba(248,81,73,.15); color: var(--error); }
        .step-status.cancelled { background: rgba(210,153,34,.15); color: var(--warning); }
        .step-status.skipped   { background: rgba(148,163,184,.12); color: #9ca3af; }
        .step-mini-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background: rgba(88,166,255,.15); color: var(--accent-primary); margin-left: 8px; }
        .inline-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; background: var(--bg-primary); color: var(--text-muted); }
        .form-section { margin-bottom: 20px; }
        .form-section-title { font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px; }

        /* ── Delete modal ── */
        .delete-confirm { text-align: center; padding: 20px 0; }
        .delete-confirm > i { font-size: 48px; color: var(--error); margin-bottom: 16px; display: block; }
        .delete-confirm h3 { margin-bottom: 8px; }
        .delete-confirm p { color: var(--text-secondary); margin-bottom: 24px; }
        .delete-actions { display: flex; justify-content: center; gap: 12px; }

        /* ── Dynamic inputs ── */
        .dynamic-inputs { display: flex; flex-direction: column; gap: 14px; }
        .dynamic-input-group { display: flex; flex-direction: column; gap: 5px; }
        .dynamic-input-label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; }
        .dynamic-input-type { font-size: 11px; color: var(--text-muted); background: var(--bg-primary); padding: 2px 6px; border-radius: 4px; }
        .dynamic-input-hint { font-size: 12px; color: var(--text-muted); }
        .runtime-var-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(88,166,255,.1); border: 1px solid rgba(88,166,255,.3); border-radius: 999px; font-size: 11px; color: var(--accent-primary); font-weight: 600; }
        .runtime-var-toggle { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 11px; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-primary); }
        .runtime-var-toggle:hover { color: var(--accent-primary); border-color: var(--accent-primary); }

        /* ── Template Picker ── */
        .tpl-trigger {
            width: 100%; display: flex; align-items: center; gap: 10px;
            padding: 11px 14px;
            border: 1.5px dashed var(--border-primary); border-radius: 8px;
            background: none; color: var(--text-muted);
            font-size: 14px; font-family: inherit; cursor: pointer;
            transition: all .15s; text-align: left;
        }
        .tpl-trigger:hover { border-color: var(--accent-primary); color: var(--accent-primary); background: var(--accent-glow); }
        .tpl-trigger .tpl-req { margin-left: auto; font-size: 11px; color: var(--error); font-weight: 600; }

        .tpl-picker-open { border: 1.5px solid var(--accent-primary); border-radius: 8px; background: var(--bg-secondary); overflow: hidden; }
        .tpl-search {
            width: 100%; padding: 10px 12px 10px 36px;
            border: none; border-bottom: 1px solid var(--border-primary);
            background: var(--bg-tertiary); color: var(--text-primary);
            font-size: 14px; font-family: inherit; outline: none;
        }
        .tpl-search-wrap { position: relative; }
        .tpl-search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; pointer-events: none; }
        .tpl-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 8px; padding: 12px; max-height: 260px; overflow-y: auto;
        }
        .tpl-card {
            display: flex; flex-direction: column; gap: 3px;
            padding: 10px 12px; border: 1.5px solid var(--border-primary);
            border-radius: 8px; cursor: pointer; transition: all .12s;
            background: var(--bg-tertiary); text-align: left;
        }
        .tpl-card:hover { border-color: var(--accent-primary); background: var(--bg-hover); }
        .tpl-card-icon { font-size: 18px; margin-bottom: 3px; }
        .tpl-card-name { font-size: 12px; font-weight: 600; color: var(--text-primary); line-height: 1.3; }
        .tpl-card-desc { font-size: 11px; color: var(--text-muted); line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .tpl-no-results { padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .tpl-picker-footer { padding: 8px 12px; border-top: 1px solid var(--border-primary); display: flex; justify-content: flex-end; }
        .tpl-cancel { background: none; border: none; color: var(--text-muted); font-size: 12px; cursor: pointer; padding: 5px 10px; border-radius: 4px; font-family: inherit; }
        .tpl-cancel:hover { color: var(--text-primary); background: var(--bg-hover); }

        .tpl-selected {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border: 1.5px solid var(--border-primary);
            border-radius: 8px; background: var(--bg-tertiary);
        }
        .tpl-selected-icon { font-size: 20px; flex-shrink: 0; }
        .tpl-selected-info { flex: 1; min-width: 0; }
        .tpl-selected-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .tpl-selected-desc { font-size: 12px; color: var(--text-muted); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tpl-change-btn { background: none; border: 1px solid var(--border-primary); color: var(--text-muted); font-size: 12px; cursor: pointer; padding: 5px 10px; border-radius: 6px; white-space: nowrap; font-family: inherit; flex-shrink: 0; }
        .tpl-change-btn:hover { border-color: var(--accent-primary); color: var(--accent-primary); }

        /* ── Compact target selector ── */
        .target-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .target-chip {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 12px;
            border: 1.5px solid var(--border-primary); border-radius: 8px;
            cursor: pointer; transition: all .12s;
            background: var(--bg-tertiary);
            font-family: inherit; font-size: 13px;
        }
        .target-chip:hover { border-color: var(--accent-primary); background: var(--bg-hover); }
        .target-chip.selected { border-color: var(--accent-primary); background: var(--accent-glow); }
        .target-chip i { font-size: 12px; color: var(--text-muted); }
        .target-chip.selected i { color: var(--accent-primary); }
        .target-chip-name { font-weight: 500; color: var(--text-primary); }
        .target-chip-type { font-size: 11px; color: var(--text-muted); padding-left: 2px; }
        .target-mode-hint { font-size: 12px; color: var(--text-muted); margin-top: 8px; padding: 6px 0; }

        /* ── Section summary pill ── */
        .section-summary {
            margin-left: auto; font-size: 11px; font-weight: 600;
            color: var(--accent-primary); background: var(--accent-glow);
            padding: 2px 9px; border-radius: 999px; white-space: nowrap;
        }
        .section-summary:empty { display: none; }
    </style>
</head>
<body>
    <?php $layout->renderSidebar(); ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Commands</h1>
                <p class="page-subtitle">Multi-step command chains</p>
            </div>
            <?php if ($isAdmin): ?>
            <button class="btn btn-primary" onclick="openBuilder(null)">
                <i class="fas fa-plus"></i> New Command
            </button>
            <?php endif; ?>
        </div>
        <div class="commands-grid" id="commandsGrid">
            <div class="loading"><i class="fas fa-spinner"></i></div>
        </div>
    </main>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!--  COMMAND BUILDER  (single-column form)                        -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="builder-overlay" id="commandBuilder">

        <!-- Sticky header -->
        <div class="builder-header">
            <button class="builder-back" onclick="closeBuilder()">
                <i class="fas fa-arrow-left"></i> Commands
            </button>
            <div class="builder-sep"></div>
            <div class="builder-title" id="builderHeaderTitle">New command</div>
            <button class="btn btn-primary" onclick="saveCommand()" id="builderSaveBtn">
                <i class="fas fa-save"></i> Save
            </button>
        </div>

        <!-- Scrollable form body -->
        <div class="builder-scroll">
            <div class="builder-form">

                <!-- ① Identity -->
                <div class="form-section-card">
                    <div class="section-num-header">
                        <div class="section-num-badge">1</div>
                        <div class="section-num-title">Identity</div>
                    </div>
                    <div class="section-body">
                        <div class="form-group">
                            <label class="form-label">Name <span class="required">*</span></label>
                            <input type="text" class="builder-name-field" id="builderName" placeholder="My command…" autocomplete="off">
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Command ID <span class="required">*</span></label>
                                <input type="text" class="form-input" id="builderId" placeholder="my-command">
                                <div class="form-hint">Auto-filled from name. Cannot change after creation.</div>
                            </div>
                            <div class="form-group" style="flex:0 0 auto;margin-bottom:0;">
                                <label class="form-label">Style</label>
                                <div class="style-btn-wrap" id="styleBtnWrap">
                                    <button class="style-btn" id="styleBtn" onclick="toggleStylePopover(event)">
                                        <span class="style-btn-icon" id="styleBtnIcon">📦</span>
                                        Style…
                                    </button>
                                    <div class="style-popover" id="stylePopover" style="display:none;">
                                        <div class="style-pop-label">Icon</div>
                                        <div class="icon-grid" id="iconGrid"></div>
                                        <div class="style-pop-sep"></div>
                                        <div class="style-pop-label">Color</div>
                                        <div class="color-swatches" id="colorSwatches"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea class="form-textarea" id="builderDescription" rows="2" placeholder="What does this command do?"></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Tags</label>
                            <div class="tags-container" id="tagsContainer">
                                <input type="text" class="tag-input" id="tagInput" placeholder="Add tag…">
                            </div>
                            <div class="form-hint">Press Enter or comma to add</div>
                        </div>
                    </div>
                </div>

                <!-- ② Default target -->
                <div class="form-section-card">
                    <div class="section-num-header">
                        <div class="section-num-badge">2</div>
                        <div class="section-num-title">Default target</div>
                        <span class="section-hint">Where steps execute unless overridden</span>
                        <span class="section-summary" id="section2Summary"></span>
                    </div>
                    <div class="section-body">
                        <div id="defaultTargetContent"></div>
                    </div>
                </div>

                <!-- ③ Steps -->
                <div class="form-section-card">
                    <div class="section-num-header">
                        <div class="section-num-badge">3</div>
                        <div class="section-num-title">Steps</div>
                        <span class="section-summary" id="section3Summary"></span>
                    </div>
                    <div class="section-body-sm">
                        <div id="stepsContainer"></div>
                    </div>
                </div>

                <!-- ④ Advanced (collapsed) -->
                <div class="form-section-card">
                    <button class="section-num-header" onclick="toggleAdvanced()" style="display:flex;">
                        <div class="section-num-badge" style="background:var(--bg-tertiary);color:var(--text-muted);border:1px solid var(--border-primary);">4</div>
                        <div class="section-num-title">Advanced</div>
                        <i class="fas fa-chevron-right" id="advChevron" style="margin-left:auto;font-size:12px;color:var(--text-muted);transition:transform .2s;"></i>
                    </button>
                    <div class="adv-body" id="advBody">
                        <div class="section-body">
                            <div class="form-group">
                                <label class="form-label">Run mode <span class="form-label-muted">async runs in background, sync waits for result</span></label>
                                <div class="toggle-opts">
                                    <button class="toggle-opt active" id="modeAsync" onclick="setExecMode('async')">
                                        <i class="fas fa-paper-plane"></i> Background job
                                    </button>
                                    <button class="toggle-opt" id="modeSync" onclick="setExecMode('sync')">
                                        <i class="fas fa-hourglass-half"></i> Wait for result
                                    </button>
                                </div>
                                <div class="form-hint">Background jobs return immediately; use History to see results.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max concurrent steps</label>
                                <div class="stepper">
                                    <button class="stepper-btn" onclick="adjustMaxParallel(-1)">−</button>
                                    <div class="stepper-val" id="maxParallelVal">1</div>
                                    <button class="stepper-btn" onclick="adjustMaxParallel(1)">+</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">When a step fails</label>
                                <div class="toggle-opts">
                                    <button class="toggle-opt active" id="policyStop" onclick="setPolicy('fail_if_any_failed')">
                                        <i class="fas fa-stop-circle"></i> Stop everything
                                    </button>
                                    <button class="toggle-opt" id="policyWarn" onclick="setPolicy('completed_with_warnings')">
                                        <i class="fas fa-exclamation-triangle"></i> Continue &amp; warn
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Secrets
                                    <span class="info-popover-wrap">
                                        <button class="info-btn" onclick="toggleInfoPopover('secretsInfoPop',event)"><i class="fas fa-question-circle"></i></button>
                                        <div class="info-popover" id="secretsInfoPop" style="display:none;">
                                            Each secret is injected as an <strong>environment variable</strong> in the step's execution context. The <em>ENV_VAR_NAME</em> you set is the variable name your script uses.<br><br>
                                            <strong>In shell commands:</strong><br>
                                            <code>echo $API_KEY</code><br>
                                            <code>curl -H "Auth: ${API_KEY}" ...</code><br><br>
                                            <strong>In template inputs:</strong><br>
                                            <code>$API_KEY</code> or <code>${API_KEY}</code>
                                        </div>
                                    </span>
                                </label>
                                <div id="secretsContainer"></div>
                                <button class="btn btn-secondary" style="margin-top:8px;font-size:12px;" onclick="addSecretMapping()">
                                    <i class="fas fa-plus"></i> Add secret
                                </button>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Environment scope</label>
                                <select class="form-select" id="builderEnvironment">
                                    <option value="">— None —</option>
                                </select>
                                <div class="form-hint">Used when a step target uses "from environment" resolution.</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /.builder-form -->
        </div><!-- /.builder-scroll -->
    </div><!-- /.builder-overlay -->

    <!-- Connector Popover (global, positioned by JS) -->
    <div class="connector-popover" id="connectorPopover">
        <div class="cpm-title">When does the next step run?</div>
        <button class="cpm-option" data-mode="success">
            <div class="cpm-icon success"><i class="fas fa-check"></i></div>
            <div><div class="cpm-label">After previous succeeds</div><div class="cpm-desc">Only runs if the previous step completed without error</div></div>
        </button>
        <button class="cpm-option" data-mode="always">
            <div class="cpm-icon always"><i class="fas fa-bolt"></i></div>
            <div><div class="cpm-label">Always run next</div><div class="cpm-desc">Runs even if the previous step failed</div></div>
        </button>
        <button class="cpm-option" data-mode="parallel">
            <div class="cpm-icon parallel"><i class="fas fa-code-branch"></i></div>
            <div><div class="cpm-label">Run in parallel</div><div class="cpm-desc">Starts immediately, doesn't wait for the previous step</div></div>
        </button>
    </div>

    <!-- Delete Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width:400px;">
            <div class="modal-body">
                <div class="delete-confirm">
                    <i class="fas fa-trash-alt"></i>
                    <h3>Delete Command?</h3>
                    <p>Are you sure you want to delete "<span id="deleteCommandName"></span>"? This cannot be undone.</p>
                    <div class="delete-actions">
                        <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button class="btn btn-danger" onclick="confirmDelete()"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Run Modal -->
    <div class="modal-overlay" id="runModal">
        <div class="modal" style="max-width:800px;">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="runCommandName">Run Command</h2>
                    <p style="color:var(--text-muted);font-size:13px;margin-top:4px;">
                        <span id="runStepSummary"></span>
                        <span id="runTargetInfo" style="margin-left:12px;"></span>
                    </p>
                </div>
                <button class="modal-close" onclick="closeRunModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-section" id="runInputsSection">
                    <div class="form-section-title">Inputs <span style="font-weight:normal;font-size:12px;color:var(--text-muted);">(modify before running or dry-running)</span></div>
                    <div id="runInputs"></div>
                </div>
                <div class="form-section" id="runStepsSection" style="display:none;">
                    <div class="form-section-title">Step Progress</div>
                    <div id="runSteps"></div>
                </div>
                <div class="form-section" id="runOutputSection" style="display:none;">
                    <div class="form-section-title">Output <span class="run-status" id="runStatus"></span></div>
                    <div class="run-output-wrap">
                        <button class="copy-btn" onclick="copyElText(this,'runOutput')" title="Copy output"><i class="fas fa-copy"></i> Copy</button>
                        <pre class="run-output" id="runOutput"></pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeRunModal()">Close</button>
                <button class="btn btn-danger" id="cancelBtn" onclick="cancelExecution()" style="display:none;"><i class="fas fa-stop"></i> Cancel</button>
                <button class="btn btn-secondary" id="dryRunBtn" onclick="executeCommand(true)">
                    <i class="fas fa-vial"></i> Dry run
                </button>
                <button class="btn btn-primary" id="executeBtn" onclick="executeCommand()" style="background:linear-gradient(135deg,var(--success),#2e9e4e);">
                    <i class="fas fa-play"></i> Execute
                </button>
            </div>
        </div>
    </div>

    <script>
    // ─── Constants ────────────────────────────────────────────────────────────
    const isAdmin = <?= json_encode($isAdmin) ?>;

    const ICONS = ['📦','🚀','⚡','🔧','🛠️','🔄','🖥️','🐳','🌐','📊','📝','🔐','🗂️','📋','✅','🔥','🌍','🔑','📡','🤖','🎯','⚙️','🔗','📤','🛡️'];
    const COLORS = ['#6366f1','#3b82f6','#0ea5e9','#14b8a6','#22c55e','#eab308','#f97316','#ef4444','#ec4899','#8b5cf6','#6b7280'];

    // ─── Shared Data ──────────────────────────────────────────────────────────
    let commands = [], templates = [], templateById = {};
    let targets = [], targetById = {};
    let secrets = [], secretsLocked = false;
    let environments = [], environmentById = {};

    // ─── Builder State ────────────────────────────────────────────────────────
    let editingCommand  = null;
    let builderSteps    = [];
    let builderTags     = [];
    let builderSecretMappings = [];
    let builderInputDraft = {};
    let builderInputContract  = [];

    let builderDefaultTarget = { mode: 'inherit', targetId: '', fromEnvironment: '' };
    let builderExecutionMode  = 'async';
    let builderMaxParallel    = 1;
    let builderPolicy         = 'fail_if_any_failed';
    let builderIcon           = null;
    let builderColor          = null;

    let envTargetsCache = {}; // { envId: { key: targetId, ... } }

    // ─── Run State ────────────────────────────────────────────────────────────
    let runningCommandId = null, runningJobId = null, runningIsDryRun = false, runPollTimer = null;

    // ─── Delete State ─────────────────────────────────────────────────────────
    let deleteCommandId = null;

    // ─── Connector Popover State ──────────────────────────────────────────────
    let connectorStepIndex = -1;

    // ═══════════════════════════════════════════════════════════════════════════
    //  INIT
    // ═══════════════════════════════════════════════════════════════════════════
    document.addEventListener('DOMContentLoaded', async () => {
        await Promise.all([
            loadCommandsData(), loadSecrets(), loadTemplates(),
            loadTargets(), loadEnvironments()
        ]);
        renderCommands();
        buildColorSwatches();
        buildIconGrid();
        document.getElementById('tagInput').addEventListener('keydown', handleTagInput);
        document.getElementById('builderName').addEventListener('input', autoFillId);
        document.addEventListener('click', handleGlobalClick);

        const p = new URLSearchParams(window.location.search);
        if (p.get('action') === 'new') openBuilder(null);
    });

    // ═══════════════════════════════════════════════════════════════════════════
    //  DATA LOADING
    // ═══════════════════════════════════════════════════════════════════════════
    async function loadCommandsData() {
        try {
            const r = await fetch('api/commands.php?action=list');
            const d = await r.json();
            if (d.success) commands = Array.isArray(d.commands) ? d.commands : [];
            else Toast.error('Failed to load commands: ' + (d.error || ''));
        } catch(e) { console.error(e); Toast.error('Failed to load commands'); }
    }
    async function loadTemplates() {
        try {
            const r = await fetch('api/templates.php?action=list');
            const d = await r.json();
            if (d.success) {
                templates = Array.isArray(d.templates) ? d.templates : [];
                templateById = {};
                templates.forEach(t => templateById[t.id] = t);
            }
        } catch(e) { console.error(e); }
    }
    async function loadTargets() {
        try {
            const r = await fetch('api/config.php?action=targets');
            const d = await r.json();
            if (d.success) {
                targets = Array.isArray(d.targets) ? d.targets : [];
                targetById = {};
                targets.forEach(t => targetById[t.id] = t);
            }
        } catch(e) { console.error(e); }
    }
    async function loadSecrets() {
        try {
            const r = await fetch('api/config.php?action=secrets');
            const d = await r.json();
            if (d.locked || r.status === 423) { secretsLocked = true; secrets = []; return; }
            if (d.success) { secretsLocked = false; secrets = Array.isArray(d.secrets) ? d.secrets : []; }
        } catch(e) { console.error(e); }
    }
    async function loadEnvironments() {
        try {
            const r = await fetch('api/config.php?action=environments-list');
            const d = await r.json();
            if (d.success) {
                environments = Array.isArray(d.environments) ? d.environments : [];
                environmentById = {};
                environments.forEach(e => environmentById[e.id] = e);
            }
        } catch(e) { console.error(e); }
    }
    async function loadEnvironmentTargets(envId) {
        if (!envId) return null;
        if (envId in envTargetsCache) return envTargetsCache[envId];
        try {
            const r = await fetch(`api/config.php?action=environment&id=${encodeURIComponent(envId)}`);
            const d = await r.json();
            const data = (d.success && d.environment?.targets) ? d.environment.targets : {};
            envTargetsCache[envId] = data;
            return data;
        } catch(e) {
            console.error(e);
            envTargetsCache[envId] = {};
            return null;
        }
    }

    async function loadCommands() { await loadCommandsData(); renderCommands(); }

    // ═══════════════════════════════════════════════════════════════════════════
    //  COMMANDS GRID
    // ═══════════════════════════════════════════════════════════════════════════
    function renderCommands() {
        const grid = document.getElementById('commandsGrid');
        if (!commands.length) {
            grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">
                <i class="fas fa-terminal"></i>
                <h3>No commands configured</h3>
                <p>${isAdmin ? 'Create your first multi-step command to get started' : 'No commands available'}</p>
                ${isAdmin ? `<button class="btn btn-primary" onclick="openBuilder(null)" style="margin-top:16px;"><i class="fas fa-plus"></i> New Command</button>` : ''}
            </div>`;
            return;
        }
        grid.innerHTML = commands.map(cmd => {
            const steps = Array.isArray(cmd.steps) ? cmd.steps : [];
            const ft    = steps[0] ? templateById[steps[0].templateId] : null;
            const icon  = cmd.icon  || ft?.icon  || '📦';
            const color = cmd.color || ft?.color || '#6b7280';
            const targetLabel = describeTargetRef(cmd.defaultTarget, cmd.environment);
            return `<div class="command-card ${cmd.enabled === false ? 'disabled' : ''}">
                <div class="command-header">
                    <div class="command-icon" style="color:${escapeAttr(color)};font-size:20px;">${renderIconHtml(icon)}</div>
                    <div class="command-info">
                        <div class="command-name">
                            ${escapeHtml(cmd.name)}
                            ${cmd.enabled === false ? '<span class="badge badge-warning" style="font-size:10px;">Disabled</span>' : ''}
                        </div>
                        <div class="command-template">${steps.length} step${steps.length===1?'':'s'} · ${escapeHtml(cmd.executionMode||'async')}</div>
                    </div>
                </div>
                ${cmd.description ? `<div class="command-description">${escapeHtml(cmd.description)}</div>` : ''}
                <div class="command-meta">
                    ${targetLabel && targetLabel !== 'inherit' ? `<span class="command-badge"><i class="fas fa-bullseye"></i> ${escapeHtml(targetLabel)}</span>` : ''}
                    ${(cmd.tags||[]).slice(0,3).map(t => `<span class="command-badge">${escapeHtml(t)}</span>`).join('')}
                </div>
                <div class="command-actions">
                    <button class="btn btn-run" onclick="runCommand('${escapeJs(cmd.id)}')" ${cmd.enabled===false?'disabled':''}>
                        <i class="fas fa-play"></i> Run
                    </button>
                    ${isAdmin ? `
                        <button class="btn btn-secondary" onclick="openBuilder('${escapeJs(cmd.id)}')"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-secondary" onclick="openDeleteModal('${escapeJs(cmd.id)}','${escapeJs(cmd.name)}')"><i class="fas fa-trash"></i></button>
                    ` : ''}
                </div>
            </div>`;
        }).join('');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  BUILDER OPEN / CLOSE
    // ═══════════════════════════════════════════════════════════════════════════
    function openBuilder(commandId) {
        const cmd = commandId ? (commands.find(c => c.id === commandId) || null) : null;
        editingCommand = cmd;

        builderSteps    = cmd ? normalizeSteps(cmd.steps) : [newStep(0)];
        builderTags     = cmd ? [...(cmd.tags||[])] : [];
        builderSecretMappings = [];
        builderInputDraft = isPlainObj(cmd?.inputs) ? {...cmd.inputs} : {};
        builderInputContract  = [];
        builderExecutionMode  = cmd?.executionMode  || 'async';
        builderMaxParallel    = Math.max(1, cmd?.maxParallel||1);
        builderPolicy         = cmd?.finalStatusPolicy || 'fail_if_any_failed';
        builderIcon           = cmd?.icon  || null;
        builderColor          = cmd?.color || null;
        builderDefaultTarget  = parseTargetRef(cmd?.defaultTarget);

        // Populate static fields
        document.getElementById('builderName').value        = cmd?.name        || '';
        document.getElementById('builderId').value          = cmd?.id          || '';
        document.getElementById('builderId').disabled       = !!cmd;
        document.getElementById('builderDescription').value = cmd?.description || '';
        setExecMode(builderExecutionMode, true);
        setPolicy(builderPolicy, true);
        document.getElementById('maxParallelVal').textContent = builderMaxParallel;
        setIconDisplay(document.getElementById('styleBtnIcon'), builderIcon);
        const hTitle = document.getElementById('builderHeaderTitle');
        if (hTitle) hTitle.textContent = cmd ? (cmd.name || 'Edit command') : 'New command';
        updateColorSwatches();
        populateEnvironmentSelect();
        document.getElementById('builderEnvironment').value = cmd?.environment || '';
        if (cmd) loadSecretMappingsFromObject(cmd.secrets||{});
        renderSecretMappings();
        renderTags();

        // Render dynamic sections
        renderDefaultTargetSection();
        renderSteps();
        updateSection2Summary();
        // section3Summary is set inside renderSteps()

        document.getElementById('commandBuilder').classList.add('active');
        document.getElementById('builderName').focus();
    }

    function closeBuilder() {
        document.getElementById('commandBuilder').classList.remove('active');
        closeConnectorPopover();
    }

    function autoFillId() {
        const name = document.getElementById('builderName').value;
        const hTitle = document.getElementById('builderHeaderTitle');
        if (hTitle) hTitle.textContent = name || (editingCommand ? 'Edit command' : 'New command');
        if (editingCommand) return;
        document.getElementById('builderId').value = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  DEFAULT TARGET SECTION (section 2)
    // ═══════════════════════════════════════════════════════════════════════════
    function renderDefaultTargetSection() {
        const cont = document.getElementById('defaultTargetContent');
        if (!cont) return;
        const ref  = builderDefaultTarget;
        const mode = ref?.mode || 'inherit';

        // Mode tabs
        let html = `<div class="toggle-opts">
            <button class="toggle-opt ${mode==='inherit'   ?'active':''}" onclick="selectDefaultTargetMode('inherit')">
                <i class="fas fa-question-circle"></i> Ask each time
            </button>
            <button class="toggle-opt ${mode==='targetId'  ?'active':''}" onclick="selectDefaultTargetMode('targetId')">
                <i class="fas fa-bullseye"></i> Specific target
            </button>
            <button class="toggle-opt ${mode==='fromEnvironment'?'active':''}" onclick="selectDefaultTargetMode('fromEnvironment')">
                <i class="fas fa-cubes"></i> From environment
            </button>
        </div>`;

        if (mode === 'inherit') {
            html += `<div class="target-mode-hint">The user will choose a target when running this command.</div>`;

        } else if (mode === 'targetId') {
            if (!targets.length) {
                html += `<div class="target-mode-hint">No targets configured yet.</div>`;
            } else {
                html += '<div class="target-chips">';
                targets.forEach(t => {
                    const sel  = ref.targetId === t.id;
                    const icon = targetTypeIcon(t.type);
                    html += `<button class="target-chip ${sel?'selected':''}" onclick="selectDefaultTarget('targetId','${escapeJs(t.id)}')">
                        <i class="${escapeAttr(icon)}"></i>
                        <span class="target-chip-name">${escapeHtml(t.name)}</span>
                        ${t.type ? `<span class="target-chip-type">${escapeHtml(t.type)}</span>` : ''}
                    </button>`;
                });
                html += '</div>';
            }

        } else if (mode === 'fromEnvironment') {
            const envOpts = environments.map(e =>
                `<option value="${escapeAttr(e.id)}" ${ref.envId===e.id?'selected':''}>${escapeHtml(e.name)}</option>`
            ).join('');
            html += `<div style="margin-top:12px;">
                <div class="form-group">
                    <label class="form-label">Environment</label>
                    <select class="form-select" id="defaultTargetEnvId" onchange="selectDefaultTargetEnv(this.value)">
                        <option value="">— Select environment —</option>
                        ${envOpts}
                    </select>
                </div>
                <div id="defaultTargetEnvKeys">
                    ${renderDefaultTargetEnvKeysHtml()}
                </div>
            </div>`;
        }

        cont.innerHTML = html;
    }

    function selectDefaultTargetMode(mode) {
        if (mode === 'inherit') {
            builderDefaultTarget = { mode: 'inherit', targetId: '', fromEnvironment: '', envId: '' };
        } else if (mode === 'targetId') {
            builderDefaultTarget = { mode: 'targetId', targetId: builderDefaultTarget.targetId || '', fromEnvironment: '', envId: '' };
        } else {
            builderDefaultTarget = { mode: 'fromEnvironment', targetId: '', fromEnvironment: builderDefaultTarget.fromEnvironment || '', envId: builderDefaultTarget.envId || '' };
        }
        renderDefaultTargetSection();
        updateSection2Summary();
    }

    function selectDefaultTarget(mode, targetId) {
        builderDefaultTarget = { mode, targetId: targetId || '', fromEnvironment: '', envId: '' };
        renderDefaultTargetSection();
        updateSection2Summary();
    }

    function renderDefaultTargetEnvKeysHtml() {
        const ref   = builderDefaultTarget;
        const envId = ref.envId;
        if (!envId) return '';
        if (!(envId in envTargetsCache)) {
            loadEnvironmentTargets(envId).then(() => {
                const el = document.getElementById('defaultTargetEnvKeys');
                if (el) el.innerHTML = renderDefaultTargetEnvKeysHtml();
            });
            return `<div class="env-keys-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading target keys…</div>`;
        }
        const envTargets = envTargetsCache[envId] || {};
        const keys = Object.keys(envTargets);
        if (!keys.length) return `<div class="target-mode-hint" style="margin-top:8px;">No target keys configured for this environment.</div>`;
        return `<div class="target-chips" style="margin-top:8px;">` +
            keys.map(k => {
                const tgt = targetById[envTargets[k]];
                const sel = ref.fromEnvironment === k;
                const icon = tgt ? targetTypeIcon(tgt.type) : 'fas fa-bullseye';
                return `<button class="target-chip ${sel?'selected':''}" onclick="selectDefaultTargetEnvKey('${escapeJs(k)}')">
                    <i class="${escapeAttr(icon)}"></i>
                    <span class="target-chip-name">${escapeHtml(k)}</span>
                    ${tgt?.type ? `<span class="target-chip-type">${escapeHtml(tgt.type)}</span>` : ''}
                </button>`;
            }).join('') + `</div>`;
    }

    function selectDefaultTargetEnv(envId) {
        builderDefaultTarget.envId = envId;
        builderDefaultTarget.fromEnvironment = '';
        const keysDiv = document.getElementById('defaultTargetEnvKeys');
        if (keysDiv) keysDiv.innerHTML = renderDefaultTargetEnvKeysHtml();
        updateSection2Summary();
    }

    function selectDefaultTargetEnvKey(key) {
        builderDefaultTarget.fromEnvironment = key;
        const keysDiv = document.getElementById('defaultTargetEnvKeys');
        if (keysDiv) keysDiv.innerHTML = renderDefaultTargetEnvKeysHtml();
        updateSection2Summary();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  STEPS RENDERING
    // ═══════════════════════════════════════════════════════════════════════════
    function renderSteps() {
        const cont = document.getElementById('stepsContainer');
        let html = '';
        builderSteps.forEach((step, i) => {
            if (i > 0) html += renderConnectorEl(i - 1);
            html += renderStepCardHtml(i);
        });
        html += `<div class="step-add-wrap">
            <button class="pipeline-add-btn" onclick="addStep()">
                <i class="fas fa-plus"></i> Add step
            </button>
        </div>`;
        cont.innerHTML = html;
        updateSection3Summary();
    }

    function renderStepCardHtml(i) {
        const step = builderSteps[i];
        const tpl  = templateById[step.templateId];
        const tplInputs = Array.isArray(tpl?.inputs) ? tpl.inputs : [];
        const isOverride = step.target.mode !== 'inherit';

        return `<div class="step-card" id="step-card-${i}">
            <div class="step-card-header">
                <div class="step-num-sm">${i+1}</div>
                <input class="step-id-input" id="step-id-${i}" value="${escapeAttr(step.id)}"
                    oninput="updateStepId(${i}, this.value)" placeholder="step-id" title="Step ID (used in dependencies)">
                <div class="step-ctrl">
                    <button class="step-ctrl-btn" onclick="moveStep(${i},-1)" ${i===0?'disabled':''} title="Move up"><i class="fas fa-arrow-up"></i></button>
                    <button class="step-ctrl-btn" onclick="moveStep(${i},1)"  ${i===builderSteps.length-1?'disabled':''} title="Move down"><i class="fas fa-arrow-down"></i></button>
                    <button class="step-ctrl-btn" onclick="duplicateStep(${i})" title="Duplicate step"><i class="fas fa-copy"></i></button>
                    <button class="step-ctrl-btn" onclick="removeStep(${i})" title="Remove step"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="step-card-body">
                <div id="step-tpl-picker-${i}">
                    ${renderTemplatePickerHtml(i)}
                </div>
                <div id="step-inputs-${i}" ${tplInputs.length ? '' : 'style="display:none"'}>
                    ${renderStepInputsHtml(i)}
                </div>
                <div class="step-options-row">
                    <div class="step-opt-group" id="step-execon-${i}">
                        <span class="form-inline-label">Execute on</span>
                        <div class="toggle-opts">
                            <button class="toggle-opt ${!isOverride ? 'active' : ''}" onclick="setStepTargetMode(${i},'inherit')">
                                <i class="fas fa-link"></i> Same as command
                            </button>
                            <button class="toggle-opt ${isOverride ? 'active' : ''}" onclick="setStepTargetMode(${i},'override')">
                                <i class="fas fa-bullseye"></i> Different target
                            </button>
                        </div>
                    </div>
                </div>
                <div id="step-target-override-${i}" style="${isOverride ? '' : 'display:none;'}">
                    ${isOverride ? renderStepTargetHtml(i) : ''}
                </div>
            </div>
        </div>`;
    }

    // ─── Template Picker ──────────────────────────────────────────────────────
    function renderTemplatePickerHtml(i) {
        const step = builderSteps[i];
        if (!step) return '';
        return step.templateId ? renderTemplatePickerSelectedHtml(i) : renderTemplatePickerClosedHtml(i);
    }

    function renderTemplatePickerClosedHtml(i) {
        return `<button class="tpl-trigger" onclick="openTemplatePicker(${i})">
            <i class="fas fa-puzzle-piece" style="opacity:.5"></i>
            Pick a template…
            <span class="tpl-req">required</span>
        </button>`;
    }

    function renderTemplatePickerSelectedHtml(i) {
        const step = builderSteps[i];
        const tpl  = step ? templateById[step.templateId] : null;
        if (!tpl) return renderTemplatePickerClosedHtml(i);
        return `<div class="tpl-selected">
            <div class="tpl-selected-icon">${renderIconHtml(tpl.icon)}</div>
            <div class="tpl-selected-info">
                <div class="tpl-selected-name">
                    ${escapeHtml(tpl.name)}
                    ${tpl.targetType ? `<span class="tpl-type-badge"><i class="${escapeAttr(targetTypeIcon(tpl.targetType))}" style="margin-right:3px;"></i>${escapeHtml(tpl.targetType)}</span>` : ''}
                </div>
                ${tpl.description ? `<div class="tpl-selected-desc">${escapeHtml(tpl.description)}</div>` : ''}
            </div>
            <button class="tpl-change-btn" onclick="openTemplatePicker(${i})"><i class="fas fa-exchange-alt" style="font-size:10px;margin-right:4px;"></i>Change</button>
        </div>`;
    }

    function renderTemplatePickerOpenHtml(i, query) {
        return `<div class="tpl-picker-open">
            <div class="tpl-search-wrap">
                <i class="fas fa-search"></i>
                <input class="tpl-search" id="tpl-search-${i}" placeholder="Search templates…"
                    oninput="filterTemplates(${i})" autocomplete="off" value="${escapeAttr(query)}">
            </div>
            <div class="tpl-grid" id="tpl-grid-${i}">
                ${renderTemplateGridItems(i, query)}
            </div>
            ${builderSteps[i]?.templateId ? `<div class="tpl-picker-footer"><button class="tpl-cancel" onclick="closeTemplatePicker(${i})">Cancel</button></div>` : ''}
        </div>`;
    }

    function renderTemplateGridItems(i, query) {
        const q = (query || '').toLowerCase().trim();
        const step = builderSteps[i];
        const filtered = q
            ? templates.filter(t => t.name.toLowerCase().includes(q) || (t.description||'').toLowerCase().includes(q))
            : templates;
        if (!filtered.length) return `<div class="tpl-no-results"><i class="fas fa-search" style="display:block;margin-bottom:8px;font-size:20px;opacity:.3;"></i>No templates match "${escapeHtml(query)}"</div>`;
        return filtered.map(t => `<button class="tpl-card" onclick="selectTemplateFromPicker(${i},'${escapeJs(t.id)}')">
            <div class="tpl-card-icon">${renderIconHtml(t.icon)}</div>
            <div class="tpl-card-name">${escapeHtml(t.name)}</div>
            ${t.targetType ? `<div class="tpl-card-type"><i class="${escapeAttr(targetTypeIcon(t.targetType))}" style="margin-right:3px;"></i>${escapeHtml(t.targetType)}</div>` : ''}
            ${t.description ? `<div class="tpl-card-desc">${escapeHtml(t.description)}</div>` : ''}
        </button>`).join('');
    }

    function openTemplatePicker(i) {
        const pickerDiv = document.getElementById(`step-tpl-picker-${i}`);
        if (!pickerDiv) return;
        pickerDiv.innerHTML = renderTemplatePickerOpenHtml(i, '');
        const searchEl = document.getElementById(`tpl-search-${i}`);
        if (searchEl) searchEl.focus();
    }

    function closeTemplatePicker(i) {
        const pickerDiv = document.getElementById(`step-tpl-picker-${i}`);
        if (!pickerDiv) return;
        pickerDiv.innerHTML = renderTemplatePickerHtml(i);
    }

    function filterTemplates(i) {
        const query = document.getElementById(`tpl-search-${i}`)?.value || '';
        const grid  = document.getElementById(`tpl-grid-${i}`);
        if (grid) grid.innerHTML = renderTemplateGridItems(i, query);
    }

    function selectTemplateFromPicker(i, templateId) {
        const step = builderSteps[i];
        if (!step) return;
        step.templateId = templateId;
        step.inputs = {};
        // Show selected chip
        const pickerDiv = document.getElementById(`step-tpl-picker-${i}`);
        if (pickerDiv) pickerDiv.innerHTML = renderTemplatePickerSelectedHtml(i);
        // Refresh inputs
        const tpl = templateById[templateId];
        const tplInputs = Array.isArray(tpl?.inputs) ? tpl.inputs : [];
        const inputsDiv = document.getElementById(`step-inputs-${i}`);
        if (inputsDiv) {
            inputsDiv.style.display = tplInputs.length ? '' : 'none';
            inputsDiv.innerHTML = renderStepInputsHtml(i);
        }
        // Re-render step target override so type filtering updates
        const overrideDiv = document.getElementById(`step-target-override-${i}`);
        if (overrideDiv && step.target.mode !== 'inherit') overrideDiv.innerHTML = renderStepTargetHtml(i);
        builderInputContract = computeCommandInputContract(builderSteps);
    }

    // ─── Section summaries ────────────────────────────────────────────────────
    function updateSection2Summary() {
        const el  = document.getElementById('section2Summary');
        if (!el) return;
        const ref = builderDefaultTarget;
        if (!ref || ref.mode === 'inherit') { el.textContent = ''; return; }
        if (ref.mode === 'targetId') {
            const t = targetById[ref.targetId];
            el.textContent = t ? t.name : (ref.targetId || '');
        } else if (ref.mode === 'fromEnvironment') {
            el.textContent = ref.fromEnvironment ? `env · ${ref.fromEnvironment}` : 'from environment';
        }
    }

    function updateSection3Summary() {
        const el = document.getElementById('section3Summary');
        if (!el) return;
        const n = builderSteps.length;
        el.textContent = `${n} step${n === 1 ? '' : 's'}`;
    }

    // ─── Duplicate step ───────────────────────────────────────────────────────
    function duplicateStep(idx) {
        const step = builderSteps[idx];
        if (!step) return;
        const clone = JSON.parse(JSON.stringify(step));
        // Auto-generate a unique ID
        const base = step.id.replace(/-copy(-\d+)?$/, '');
        let newId = base + '-copy', n = 2;
        while (builderSteps.some(s => s.id === newId)) newId = base + '-copy-' + n++;
        clone.id = newId;
        clone.dependsOn = step.id ? [step.id] : [];
        builderSteps.splice(idx + 1, 0, clone);
        renderSteps();
    }

    function renderStepInputsHtml(i) {
        const step = builderSteps[i];
        const tpl  = step ? templateById[step.templateId] : null;
        const tplInputs = Array.isArray(tpl?.inputs) ? tpl.inputs : [];
        if (!tpl || tplInputs.length === 0) return '';

        return tplInputs.map(input => {
            const raw = step.inputs[input.name];
            const isRuntime = typeof raw === 'string' && raw.startsWith('{{inputs.');
            const value  = isRuntime ? '' : (raw === undefined ? '' : raw);
            const varName = isRuntime ? raw.replace(/^\{\{inputs\./, '').replace(/\}\}$/, '') : '';
            return `<div class="dynamic-input-group">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <label class="dynamic-input-label" style="margin:0;">
                        ${escapeHtml(input.label||input.name)}
                        <span class="dynamic-input-type">${escapeHtml(input.type||'string')}</span>
                        ${input.required ? '<span style="color:var(--error);">*</span>' : ''}
                    </label>
                    <button class="runtime-var-toggle" onclick="toggleRuntimeVar(${i},'${escapeJs(input.name)}','${escapeJs(input.type||'string')}')" title="${isRuntime?'Switch to fixed value':'Make this a runtime variable'}">
                        ${isRuntime ? '<i class="fas fa-times"></i> fixed' : '<i class="fas fa-bolt"></i> runtime'}
                    </button>
                </div>
                ${isRuntime
                    ? `<div class="runtime-var-pill"><i class="fas fa-bolt"></i> inputs.${escapeHtml(varName||input.name)}</div>
                       <div class="dynamic-input-hint" style="margin-top:6px;">User fills this value when running the command${input.description ? ' · '+escapeHtml(input.description) : ''}</div>`
                    : renderValueField(input, value, `updateStepInput(${i},'${escapeJs(input.name)}',readFormControlValue(this,'${escapeJs(input.type||'string')}'),'${escapeJs(input.type||'string')}')`)
                }
            </div>`;
        }).join('');
    }

    function renderStepTargetHtml(i) {
        const step = builderSteps[i];
        if (!step) return '';
        const ref  = step.target;
        const tpl  = templateById[step.templateId];
        const requiredType = tpl?.targetType || null;
        const envSel = ref.mode === 'fromEnvironment';

        // Filter targets by template's required type if known
        const compatibleTargets = requiredType ? targets.filter(t => t.type === requiredType) : targets;

        let html = '<div class="step-target-inline"><div class="target-chips">';

        if (compatibleTargets.length) {
            compatibleTargets.forEach(t => {
                const sel  = ref.mode === 'targetId' && ref.targetId === t.id;
                const icon = targetTypeIcon(t.type);
                html += `<button class="target-chip ${sel?'selected':''}" onclick="selectStepTarget(${i},'targetId','${escapeJs(t.id)}')">
                    <i class="${escapeAttr(icon)}"></i>
                    <span class="target-chip-name">${escapeHtml(t.name)}</span>
                    ${t.type ? `<span class="target-chip-type">${escapeHtml(t.type)}</span>` : ''}
                </button>`;
            });
        } else {
            html += `<span style="font-size:12px;color:var(--text-muted);padding:4px 0;">No${requiredType ? ` ${requiredType}` : ''} targets configured.</span>`;
        }

        if (environments.length) {
            html += `<button class="target-chip ${envSel?'selected':''}" onclick="selectStepTarget(${i},'fromEnvironment','')">
                <i class="fas fa-cubes"></i>
                <span class="target-chip-name">From environment</span>
            </button>`;
        }

        html += '</div>';

        if (envSel) {
            const envOpts = environments.map(e =>
                `<option value="${escapeAttr(e.id)}" ${step.target.envId===e.id?'selected':''}>${escapeHtml(e.name)}</option>`
            ).join('');
            html += `<div style="margin-top:12px;">
                <div class="form-group">
                    <label class="form-label">Environment</label>
                    <select class="form-select" id="step-env-id-${i}" onchange="selectStepTargetEnv(${i},this.value)">
                        <option value="">— Select environment —</option>
                        ${envOpts}
                    </select>
                </div>
                <div id="step-env-keys-${i}">
                    ${renderStepEnvKeysHtml(i)}
                </div>
            </div>`;
        }

        html += '</div>';
        return html;
    }

    function renderStepEnvKeysHtml(i) {
        const step = builderSteps[i];
        if (!step) return '';
        const envId = step.target.envId;
        if (!envId) return '';
        if (!(envId in envTargetsCache)) {
            loadEnvironmentTargets(envId).then(() => {
                const el = document.getElementById(`step-env-keys-${i}`);
                if (el) el.innerHTML = renderStepEnvKeysHtml(i);
            });
            return `<div class="env-keys-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading target keys…</div>`;
        }
        const envTargets = envTargetsCache[envId] || {};
        const tpl = templateById[step.templateId];
        const requiredType = tpl?.targetType || null;
        const keys = Object.keys(envTargets).filter(k => {
            if (!requiredType) return true;
            const tgt = targetById[envTargets[k]];
            return !tgt || tgt.type === requiredType;
        });
        if (!keys.length) return `<div class="target-mode-hint" style="margin-top:8px;">No${requiredType ? ` ${requiredType}` : ''} target keys in this environment.</div>`;
        return `<div class="target-chips" style="margin-top:8px;">` +
            keys.map(k => {
                const tgt = targetById[envTargets[k]];
                const sel = step.target.fromEnvironment === k;
                const icon = tgt ? targetTypeIcon(tgt.type) : 'fas fa-bullseye';
                return `<button class="target-chip ${sel?'selected':''}" onclick="selectStepEnvKey(${i},'${escapeJs(k)}')">
                    <i class="${escapeAttr(icon)}"></i>
                    <span class="target-chip-name">${escapeHtml(k)}</span>
                    ${tgt?.type ? `<span class="target-chip-type">${escapeHtml(tgt.type)}</span>` : ''}
                </button>`;
            }).join('') + `</div>`;
    }

    function renderConnectorEl(stepIndex) {
        const mode = getConnectorMode(stepIndex);
        const labels = {
            success:  '<i class="fas fa-check"></i> after success',
            always:   '<i class="fas fa-bolt"></i> always',
            parallel: '<i class="fas fa-code-branch"></i> parallel'
        };
        return `<div class="step-conn">
            <div class="step-conn-line"></div>
            <button class="connector-pill ${mode}" id="conn-pill-${stepIndex}" onclick="openConnectorPopover(${stepIndex}, event)">
                ${labels[mode] || labels.success}
            </button>
            <div class="step-conn-line"></div>
        </div>`;
    }

    // ─── Step mutations ───────────────────────────────────────────────────────
    function addStep() {
        builderSteps.push(newStep(builderSteps.length));
        renderSteps();
        // Scroll to the new step
        setTimeout(() => {
            const last = document.getElementById(`step-card-${builderSteps.length - 1}`);
            if (last) last.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);
    }

    function removeStep(idx) {
        if (builderSteps.length <= 1) { Toast.error('A command needs at least one step'); return; }
        const removedId = builderSteps[idx].id;
        builderSteps.splice(idx, 1);
        builderSteps.forEach(s => { s.dependsOn = s.dependsOn.filter(d => d !== removedId); });
        renderSteps();
    }

    function moveStep(idx, dir) {
        const next = idx + dir;
        if (next < 0 || next >= builderSteps.length) return;
        [builderSteps[idx], builderSteps[next]] = [builderSteps[next], builderSteps[idx]];
        renderSteps();
    }

    function updateStepId(idx, val) {
        const step = builderSteps[idx];
        if (!step) return;
        const oldId = step.id;
        step.id = val.trim();
        builderSteps.forEach(s => {
            s.dependsOn = s.dependsOn.map(d => d === oldId ? step.id : d);
        });
        builderInputContract = computeCommandInputContract(builderSteps);
    }

    function updateStepTemplate(idx, templateId) {
        // Delegate to picker flow (which handles both picker chip + inputs)
        selectTemplateFromPicker(idx, templateId);
    }

    function updateStepInput(idx, name, value, type) {
        const step = builderSteps[idx];
        if (!step) return;
        const v = convertInputValue(value, type);
        if (v === '' || v === null || v === undefined) delete step.inputs[name];
        else step.inputs[name] = v;
        builderInputContract = computeCommandInputContract(builderSteps);
    }

    function toggleRuntimeVar(idx, name, type) {
        const step = builderSteps[idx];
        if (!step) return;
        const cur = step.inputs[name];
        const isRuntime = typeof cur === 'string' && cur.startsWith('{{inputs.');
        if (isRuntime) delete step.inputs[name];
        else step.inputs[name] = `{{inputs.${name}}}`;
        const inputsDiv = document.getElementById(`step-inputs-${idx}`);
        if (inputsDiv) inputsDiv.innerHTML = renderStepInputsHtml(idx);
        builderInputContract = computeCommandInputContract(builderSteps);
    }

    function setStepTargetMode(idx, mode) {
        const step = builderSteps[idx];
        if (!step) return;
        if (mode === 'inherit') {
            step.target = { mode: 'inherit', targetId: '', fromEnvironment: '' };
        } else if (step.target.mode === 'inherit') {
            step.target = { mode: 'targetId', targetId: '', fromEnvironment: '' };
        }
        // Update toggle button active states
        const section = document.getElementById(`step-execon-${idx}`);
        if (section) {
            section.querySelectorAll('.toggle-opt').forEach((btn, i) => {
                btn.classList.toggle('active', mode === 'inherit' ? i === 0 : i === 1);
            });
        }
        // Show/hide override target cards
        const overrideDiv = document.getElementById(`step-target-override-${idx}`);
        if (overrideDiv) {
            overrideDiv.style.display = mode === 'inherit' ? 'none' : '';
            if (mode !== 'inherit') overrideDiv.innerHTML = renderStepTargetHtml(idx);
        }
    }

    function selectStepTarget(idx, mode, targetId) {
        const step = builderSteps[idx];
        if (!step) return;
        step.target = { mode, targetId: targetId || '', fromEnvironment: '', envId: '' };
        const overrideDiv = document.getElementById(`step-target-override-${idx}`);
        if (overrideDiv) overrideDiv.innerHTML = renderStepTargetHtml(idx);
    }

    function selectStepTargetEnv(i, envId) {
        const step = builderSteps[i];
        if (!step) return;
        step.target.envId = envId;
        step.target.fromEnvironment = '';
        const keysDiv = document.getElementById(`step-env-keys-${i}`);
        if (keysDiv) keysDiv.innerHTML = renderStepEnvKeysHtml(i);
        // Auto-select key if template's targetType matches exactly one key
        if (envId) {
            const tpl = templateById[step.templateId];
            const requiredType = tpl?.targetType;
            if (requiredType) {
                loadEnvironmentTargets(envId).then(envTargets => {
                    if (!envTargets || step.target.envId !== envId || step.target.fromEnvironment) return;
                    const matchKey = Object.keys(envTargets).find(k => {
                        const tgt = targetById[envTargets[k]];
                        return tgt && tgt.type === requiredType;
                    });
                    if (matchKey) {
                        step.target.fromEnvironment = matchKey;
                        const el = document.getElementById(`step-env-keys-${i}`);
                        if (el) el.innerHTML = renderStepEnvKeysHtml(i);
                    }
                });
            }
        }
    }

    function selectStepEnvKey(i, key) {
        const step = builderSteps[i];
        if (!step) return;
        step.target.fromEnvironment = key;
        const keysDiv = document.getElementById(`step-env-keys-${i}`);
        if (keysDiv) keysDiv.innerHTML = renderStepEnvKeysHtml(i);
    }

    function newStep(idx) {
        const prev = builderSteps[idx - 1];
        return {
            id: `step-${idx + 1}`,
            templateId: '',
            target: { mode: 'inherit', targetId: '', fromEnvironment: '' },
            dependsOn: prev?.id ? [prev.id] : [],
            onFailure: 'stop',
            inputs: {},
            secrets: {},
        };
    }

    function normalizeSteps(raw) {
        if (!Array.isArray(raw)) return [newStep(0)];
        return raw.map((s, i) => {
            const t = parseTargetRef(s.target);
            return {
                id: String(s.id || `step-${i+1}`),
                templateId: String(s.templateId || ''),
                target: t,
                dependsOn: Array.isArray(s.dependsOn) ? s.dependsOn.map(String).filter(Boolean) : [],
                onFailure: s.onFailure === 'continue' ? 'continue' : 'stop',
                inputs: isPlainObj(s.inputs) ? {...s.inputs} : {},
                secrets: isPlainObj(s.secrets) ? {...s.secrets} : {},
            };
        });
    }

    // ─── Connector logic ──────────────────────────────────────────────────────
    function getConnectorMode(stepIndex) {
        const cur  = builderSteps[stepIndex];
        const next = builderSteps[stepIndex + 1];
        if (!cur || !next) return 'success';
        const dependsOnPrev = next.dependsOn.includes(cur.id);
        if (!dependsOnPrev) return 'parallel';
        return cur.onFailure === 'continue' ? 'always' : 'success';
    }

    function setConnectorMode(stepIndex, mode) {
        const cur  = builderSteps[stepIndex];
        const next = builderSteps[stepIndex + 1];
        if (!cur || !next) return;
        if (mode === 'parallel') {
            next.dependsOn = next.dependsOn.filter(d => d !== cur.id);
        } else {
            if (!next.dependsOn.includes(cur.id)) next.dependsOn.push(cur.id);
            cur.onFailure = (mode === 'always') ? 'continue' : 'stop';
        }
        // Update just the connector pill
        const pill = document.getElementById(`conn-pill-${stepIndex}`);
        if (pill) {
            const labels = {
                success:  '<i class="fas fa-check"></i> after success',
                always:   '<i class="fas fa-bolt"></i> always',
                parallel: '<i class="fas fa-code-branch"></i> parallel'
            };
            const newMode = getConnectorMode(stepIndex);
            pill.className = `connector-pill ${newMode}`;
            pill.innerHTML = labels[newMode];
        }
    }

    function openConnectorPopover(stepIndex, event) {
        event.stopPropagation();
        connectorStepIndex = stepIndex;
        const pop = document.getElementById('connectorPopover');
        const mode = getConnectorMode(stepIndex);
        pop.querySelectorAll('.cpm-option').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        const rect = event.currentTarget.getBoundingClientRect();
        pop.style.top  = (rect.bottom + 8) + 'px';
        pop.style.left = Math.max(8, rect.left - 140 + rect.width/2) + 'px';
        pop.classList.add('open');
    }

    function closeConnectorPopover() {
        document.getElementById('connectorPopover').classList.remove('open');
        connectorStepIndex = -1;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  ADVANCED SETTINGS
    // ═══════════════════════════════════════════════════════════════════════════
    function setExecMode(mode, silent) {
        builderExecutionMode = mode;
        document.getElementById('modeAsync').classList.toggle('active', mode === 'async');
        document.getElementById('modeSync').classList.toggle('active',  mode === 'sync');
    }
    function setPolicy(policy, silent) {
        builderPolicy = policy;
        document.getElementById('policyStop').classList.toggle('active', policy === 'fail_if_any_failed');
        document.getElementById('policyWarn').classList.toggle('active', policy === 'completed_with_warnings');
    }
    function adjustMaxParallel(delta) {
        builderMaxParallel = Math.max(1, builderMaxParallel + delta);
        document.getElementById('maxParallelVal').textContent = builderMaxParallel;
    }
    function toggleAdvanced() {
        const body = document.getElementById('advBody');
        const chev = document.getElementById('advChevron');
        const open = body.classList.toggle('open');
        chev.style.transform = open ? 'rotate(90deg)' : '';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  APPEARANCE (icon + color)
    // ═══════════════════════════════════════════════════════════════════════════
    function buildColorSwatches() {
        const cont = document.getElementById('colorSwatches');
        cont.innerHTML = COLORS.map(c =>
            `<div class="color-swatch" style="background:${c}" data-color="${c}" onclick="selectColor('${escapeJs(c)}')" title="${c}"></div>`
        ).join('');
    }
    function buildIconGrid() {
        const cont = document.getElementById('iconGrid');
        cont.innerHTML = ICONS.map(ic =>
            `<span class="icon-opt" data-icon="${escapeAttr(ic)}" onclick="selectIcon('${escapeJs(ic)}')">${renderIconHtml(ic)}</span>`
        ).join('');
    }
    function selectColor(c) {
        builderColor = c;
        updateColorSwatches();
    }
    function updateColorSwatches() {
        document.querySelectorAll('.color-swatch').forEach(el => {
            el.classList.toggle('selected', el.dataset.color === builderColor);
        });
    }
    function selectIcon(ic) {
        builderIcon = ic;
        setIconDisplay(document.getElementById('styleBtnIcon'), ic);
        document.getElementById('stylePopover').style.display = 'none';
        document.querySelectorAll('.icon-opt').forEach(el => el.classList.toggle('selected', el.dataset.icon === ic));
    }
    function toggleStylePopover(e) {
        if (e) e.stopPropagation();
        const pop = document.getElementById('stylePopover');
        pop.style.display = pop.style.display === 'none' ? 'block' : 'none';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  TAGS
    // ═══════════════════════════════════════════════════════════════════════════
    function handleTagInput(e) {
        if (e.key !== 'Enter' && e.key !== ',') return;
        e.preventDefault();
        const tag = e.target.value.trim().toLowerCase().replace(/,/g,'');
        if (tag && !builderTags.includes(tag)) { builderTags.push(tag); renderTags(); }
        e.target.value = '';
    }
    function renderTags() {
        const cont  = document.getElementById('tagsContainer');
        const input = document.getElementById('tagInput');
        cont.innerHTML = builderTags.map(t => `<span class="tag">${escapeHtml(t)}<button type="button" class="tag-remove" onclick="removeTag('${escapeJs(t)}')"><i class="fas fa-times"></i></button></span>`).join('');
        cont.appendChild(input);
    }
    function removeTag(t) { builderTags = builderTags.filter(x => x !== t); renderTags(); }

    // ═══════════════════════════════════════════════════════════════════════════
    //  SECRETS
    // ═══════════════════════════════════════════════════════════════════════════
    function addSecretMapping(envVar='', secretId='') {
        if (secretsLocked) { Toast.error('Secrets are locked'); return; }
        builderSecretMappings.push({envVar, secretId});
        renderSecretMappings();
    }
    function removeSecretMapping(i) { builderSecretMappings.splice(i,1); renderSecretMappings(); }
    function updateSecretMapping(i, field, val) { if (builderSecretMappings[i]) builderSecretMappings[i][field] = val; }
    function loadSecretMappingsFromObject(obj) {
        builderSecretMappings = [];
        if (!obj || typeof obj !== 'object') return;
        Object.entries(obj).forEach(([k,v]) => builderSecretMappings.push({envVar:k, secretId:v}));
    }
    function getSecretMappingsObject() {
        const r = {};
        builderSecretMappings.forEach(m => { if (m.envVar && m.secretId) r[m.envVar] = m.secretId; });
        return r;
    }
    function renderSecretMappings() {
        const cont = document.getElementById('secretsContainer');
        if (secretsLocked) { cont.innerHTML = '<p style="color:var(--error);font-size:13px;">Secrets are locked.</p>'; return; }
        if (!builderSecretMappings.length) { cont.innerHTML = '<p style="color:var(--text-muted);font-size:13px;">No secrets configured</p>'; return; }
        cont.innerHTML = builderSecretMappings.map((m,i) => `
            <div class="secret-row">
                <input type="text" class="form-input" style="text-transform:uppercase;" placeholder="ENV_VAR_NAME" value="${escapeAttr(m.envVar)}"
                    oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_]/g,'_'); updateSecretMapping(${i},'envVar',this.value)">
                <span class="secret-arrow"><i class="fas fa-arrow-right"></i></span>
                <select class="form-select" onchange="updateSecretMapping(${i},'secretId',this.value)">
                    <option value="">— Secret —</option>
                    ${secrets.map(s => `<option value="${escapeAttr(s.id)}" ${m.secretId===s.id?'selected':''}>${escapeHtml(s.id)}</option>`).join('')}
                </select>
                <button class="btn btn-secondary" style="padding:8px;flex-shrink:0;" onclick="removeSecretMapping(${i})"><i class="fas fa-trash"></i></button>
            </div>`).join('');
    }

    function populateEnvironmentSelect() {
        const sel = document.getElementById('builderEnvironment');
        sel.innerHTML = '<option value="">— None —</option>' +
            environments.map(e => `<option value="${escapeAttr(e.id)}">${escapeHtml(e.name)}</option>`).join('');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  SAVE
    // ═══════════════════════════════════════════════════════════════════════════
    async function saveCommand() {
        const id   = document.getElementById('builderId').value.trim();
        const name = document.getElementById('builderName').value.trim();
        if (!id)   { Toast.error('Command ID is required'); document.getElementById('builderId').focus(); return; }
        if (!name) { Toast.error('Command name is required'); document.getElementById('builderName').focus(); return; }
        if (!builderSteps.length) { Toast.error('At least one step is required'); return; }

        const seenIds = new Set();
        for (let i = 0; i < builderSteps.length; i++) {
            const s = builderSteps[i];
            if (!s.id) { Toast.error(`Step ${i+1} needs an ID`); return; }
            if (seenIds.has(s.id)) { Toast.error(`Duplicate step ID: ${s.id}`); return; }
            seenIds.add(s.id);
            if (!s.templateId) { Toast.error(`Step "${s.id}" needs a template`); return; }
        }

        builderInputContract = computeCommandInputContract(builderSteps);
        const inputs = {};
        Object.entries(builderInputDraft).forEach(([k,v]) => { if (v !== '' && v !== null && v !== undefined) inputs[k] = v; });

        const environment = document.getElementById('builderEnvironment').value || null;

        const command = {
            id, name,
            description: document.getElementById('builderDescription').value.trim(),
            executionMode: builderExecutionMode,
            maxParallel: builderMaxParallel,
            finalStatusPolicy: builderPolicy,
            defaultTarget: serializeTargetRef(builderDefaultTarget),
            environment,
            steps: builderSteps.map(s => ({
                id: s.id,
                templateId: s.templateId,
                target: serializeTargetRef(s.target),
                dependsOn: s.dependsOn.filter(Boolean),
                onFailure: s.onFailure === 'continue' ? 'continue' : 'stop',
                inputs: isPlainObj(s.inputs) ? s.inputs : {},
                secrets: isPlainObj(s.secrets) ? s.secrets : {},
            })),
            inputs,
            inputContract: builderInputContract,
            secrets: getSecretMappingsObject(),
            tags: builderTags,
            icon: builderIcon,
            color: builderColor,
            enabled: editingCommand?.enabled ?? true,
        };

        const btn = document.getElementById('builderSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        try {
            const r = await fetch('api/commands.php?action=save', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify(command),
            });
            const d = await r.json();
            if (d.success) {
                Toast.success(editingCommand ? 'Command updated' : 'Command created');
                closeBuilder();
                await loadCommands();
            } else {
                Toast.error('Failed: ' + (d.errors?.join(', ') || d.error || 'Unknown error'));
            }
        } catch(e) {
            console.error(e);
            Toast.error('Failed to save command');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  INPUT CONTRACT COMPUTATION
    // ═══════════════════════════════════════════════════════════════════════════
    function collectInputReferences(value, refs = new Set()) {
        if (typeof value === 'string') {
            const re1 = /\{\{#if ([a-zA-Z0-9_.]+)\}\}/g;
            const re2 = /\{\{([a-zA-Z0-9_.]+)\}\}/g;
            let m;
            while ((m = re1.exec(value)) !== null) refs.add(m[1]);
            while ((m = re2.exec(value)) !== null) refs.add(m[1]);
            return refs;
        }
        if (Array.isArray(value)) { value.forEach(v => collectInputReferences(v, refs)); return refs; }
        if (isPlainObj(value)) { Object.values(value).forEach(v => collectInputReferences(v, refs)); }
        return refs;
    }
    function inputNameFromRef(ref) {
        if (!ref || !ref.startsWith('inputs.')) return '';
        return ref.slice('inputs.'.length).trim();
    }
    function mergeContractField(map, name, sourceStepId, inputDef, required) {
        const n = String(name||'').trim(); if (!n) return;
        const candidate = { name:n, label:inputDef?.label||n, type:inputDef?.type||'string', required:!!required, description:inputDef?.description||'', placeholder:inputDef?.placeholder||'', validation:inputDef?.validation||null, options:Array.isArray(inputDef?.options)?inputDef.options:[], conflict:false, sources:sourceStepId?[sourceStepId]:[] };
        if (!map[n]) { map[n] = candidate; return; }
        const ex = map[n];
        ex.required = ex.required || candidate.required;
        ex.sources  = [...new Set([...ex.sources, ...candidate.sources])];
        if (!ex.description && candidate.description) ex.description = candidate.description;
        if ((ex.type||'string') !== (candidate.type||'string')) ex.conflict = true;
    }
    function computeCommandInputContract(steps) {
        const map = {};
        steps.forEach((step, i) => {
            const sid = step.id || `step-${i+1}`;
            const tpl = templateById[step.templateId];
            if (!tpl) return;
            const tplInputs = Array.isArray(tpl.inputs) ? tpl.inputs : [];
            const stepInputs = isPlainObj(step.inputs) ? step.inputs : {};
            tplInputs.forEach(ti => {
                const sv = stepInputs[ti.name];
                const has = sv !== undefined && sv !== null && sv !== '';
                if (!has) {
                    if (ti.required) mergeContractField(map, ti.name, sid, ti, true);
                    return;
                }
                const refs = Array.from(collectInputReferences(sv)).map(inputNameFromRef).filter(Boolean);
                if (refs.length) refs.forEach(r => mergeContractField(map, r, sid, null, !!ti.required));
            });
            Array.from(collectInputReferences(stepInputs)).map(inputNameFromRef).filter(Boolean)
                .forEach(r => mergeContractField(map, r, sid, null, false));
        });
        return Object.values(map).sort((a,b) => a.name.localeCompare(b.name));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  DELETE
    // ═══════════════════════════════════════════════════════════════════════════
    function openDeleteModal(id, name) {
        deleteCommandId = id;
        document.getElementById('deleteCommandName').textContent = name;
        document.getElementById('deleteModal').classList.add('active');
    }
    function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('active'); deleteCommandId = null; }
    async function confirmDelete() {
        if (!deleteCommandId) return;
        try {
            const r = await fetch(`api/commands.php?action=delete&id=${deleteCommandId}`, {method:'POST'});
            const d = await r.json();
            if (d.success) { Toast.success('Command deleted'); closeDeleteModal(); await loadCommands(); }
            else Toast.error('Failed: ' + (d.error||''));
        } catch(e) { console.error(e); Toast.error('Failed to delete'); }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  RUN COMMAND
    // ═══════════════════════════════════════════════════════════════════════════
    function runCommand(id) {
        const cmd = commands.find(c => c.id === id);
        if (!cmd) return;
        runningCommandId = id; runningJobId = null; runningIsDryRun = false; clearRunPollTimer();
        const steps = Array.isArray(cmd.steps) ? cmd.steps : [];
        const contract = Array.isArray(cmd.inputContract) && cmd.inputContract.length
            ? cmd.inputContract
            : computeCommandInputContract(normalizeSteps(cmd.steps));
        document.getElementById('runCommandName').textContent = cmd.name;
        document.getElementById('runStepSummary').textContent = `${steps.length} steps · ${cmd.executionMode||'async'}`;
        const tl = describeTargetRef(cmd.defaultTarget, cmd.environment);
        document.getElementById('runTargetInfo').textContent = tl && tl !== 'inherit' ? `| ${tl}` : '';
        renderRunInputs(contract, cmd.inputs||{});
        document.getElementById('runSteps').innerHTML = '';
        document.getElementById('runStepsSection').style.display = 'none';
        document.getElementById('runOutput').textContent = '';
        document.getElementById('runOutputSection').style.display = 'none';
        document.getElementById('runStatus').className = 'run-status';
        document.getElementById('runStatus').textContent = '';
        document.getElementById('executeBtn').style.display = 'inline-flex';
        document.getElementById('dryRunBtn').style.display = 'inline-flex';
        document.getElementById('cancelBtn').style.display  = 'none';
        document.getElementById('runModal').classList.add('active');
    }
    function renderRunInputs(contract, defaults) {
        const cont   = document.getElementById('runInputs');
        const fields = Array.isArray(contract) ? contract : [];
        if (!fields.length) { cont.innerHTML = '<p style="color:var(--text-muted);">No dynamic inputs required.</p>'; return; }
        cont.innerHTML = fields.map(f => {
            const val = defaults[f.name] ?? '';
            const helperText = formatRunFieldHelper(f);
            return `<div class="form-group">
                <label class="form-label">${escapeHtml(f.label||f.name)} ${f.required?'<span class="required">*</span>':''} <span class="inline-badge">${escapeHtml(f.type||'string')}</span></label>
                ${renderRunValueField(f, val)}
                ${helperText ? `<div class="form-hint">${escapeHtml(helperText)}</div>` : ''}
            </div>`;
        }).join('');
    }
    function renderRunValueField(f, raw) {
        const val  = raw === undefined || raw === null ? '' : raw;
        const name = escapeAttr(f.name||'');
        const type = normalizeInteractiveFieldType(f);
        if (type === 'select' && Array.isArray(f.options) && f.options.length) {
            return `<select class="form-select" data-run-input="${name}" data-run-type="${escapeAttr(type)}">
                <option value=""></option>
                ${f.options.map(o => { const ov=o.value??o; const ol=o.label??ov; return `<option value="${escapeAttr(ov)}" ${String(val)===String(ov)?'selected':''}>${escapeHtml(ol)}</option>`; }).join('')}
            </select>`;
        }
        if (type === 'multiselect') {
            const selectedValues = Array.isArray(val) ? val.map(v => String(v)) : [];
            if (Array.isArray(f.options) && f.options.length) {
                return `<select class="form-select" multiple data-run-input="${name}" data-run-type="${escapeAttr(type)}">
                    ${f.options.map(o => {
                        const ov = String(o.value ?? o);
                        const ol = o.label ?? ov;
                        return `<option value="${escapeAttr(ov)}" ${selectedValues.includes(ov) ? 'selected' : ''}>${escapeHtml(ol)}</option>`;
                    }).join('')}
                </select>`;
            }
            return renderRunArrayEditor(name, selectedValues, f.placeholder || 'Value');
        }
        if (type === 'boolean') return `<select class="form-select" data-run-input="${name}" data-run-type="${escapeAttr(type)}">
            <option value=""></option>
            <option value="true"  ${val===true||val==='true' ?'selected':''}>true</option>
            <option value="false" ${val===false||val==='false'?'selected':''}>false</option>
        </select>`;
        if (type === 'json' || isPlainObj(val) || (Array.isArray(val) && !isStringArray(val))) {
            return `<textarea class="form-textarea" data-run-input="${name}" data-run-type="json" placeholder="${escapeAttr(f.placeholder||'Enter valid JSON')}">${escapeHtml(prettyJson(val))}</textarea>`;
        }
        if (Array.isArray(val)) {
            return `<textarea class="form-textarea" data-run-input="${name}" data-run-type="multiselect" placeholder="${escapeAttr(f.placeholder||'One value per line')}">${escapeHtml(formatMultivalueText(val))}</textarea>`;
        }
        if (type === 'number') {
            const mn = f.validation?.min !== undefined ? `min="${escapeAttr(f.validation.min)}"` : '';
            const mx = f.validation?.max !== undefined ? `max="${escapeAttr(f.validation.max)}"` : '';
            return `<input type="number" class="form-input" data-run-input="${name}" data-run-type="${escapeAttr(type)}" value="${escapeAttr(val)}" ${mn} ${mx}>`;
        }
        return `<input type="text" class="form-input" data-run-input="${name}" data-run-type="${escapeAttr(type)}" value="${escapeAttr(val)}" placeholder="${escapeAttr(f.placeholder||'')}">`;
    }
    async function executeCommand(dryRun = false) {
        if (!runningCommandId) return;
        runningIsDryRun = !!dryRun;
        const inputs = {};
        document.querySelectorAll('[data-run-input]').forEach(el => {
            const v = readFormControlValue(el, el.dataset.runType||'string');
            if (v !== '' && v !== null && v !== undefined) inputs[el.dataset.runInput] = v;
        });
        document.getElementById('runOutputSection').style.display = 'block';
        document.getElementById('runOutput').textContent = dryRun ? 'Preparing dry run preview…\n' : 'Starting…\n';
        setRunStatus('running', dryRun ? 'Generating dry run…' : 'Running…');
        document.getElementById('executeBtn').style.display = 'none';
        document.getElementById('dryRunBtn').style.display = 'none';
        document.getElementById('cancelBtn').style.display  = 'inline-flex';
        try {
            const r = await fetch('api/execute.php?action=run', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({commandId:runningCommandId, inputs, dryRun}) });
            const d = await r.json();
            runningJobId = d.jobId || null;
            runningIsDryRun = !!d.dryRun || !!dryRun;
            if (!d.success) {
                document.getElementById('runOutput').textContent = 'Error: ' + (d.error||d.errors?.join(', ')||'');
                setRunStatus('error','Failed');
                document.getElementById('executeBtn').style.display = 'inline-flex';
                document.getElementById('dryRunBtn').style.display = 'inline-flex';
                document.getElementById('cancelBtn').style.display  = 'none';
                return;
            }
            if (d.output) document.getElementById('runOutput').textContent = d.output;
            if (d.status === 'pending' || d.status === 'running') { scheduleRunPoll(d.jobId, 700); return; }
            updateRunStatusFromJob({status: d.status||'completed', exit_code: d.exitCode, isDryRun: d.dryRun});
            if (d.jobId) scheduleRunPoll(d.jobId, 250);
            else {
                document.getElementById('executeBtn').style.display = 'inline-flex';
                document.getElementById('dryRunBtn').style.display = 'inline-flex';
                document.getElementById('cancelBtn').style.display = 'none';
            }
        } catch(e) {
            console.error(e);
            document.getElementById('runOutput').textContent = 'Error: ' + e.message;
            setRunStatus('error','Failed');
            document.getElementById('executeBtn').style.display = 'inline-flex';
            document.getElementById('dryRunBtn').style.display = 'inline-flex';
            document.getElementById('cancelBtn').style.display  = 'none';
        }
    }
    function scheduleRunPoll(jobId, ms) { clearRunPollTimer(); runPollTimer = setTimeout(() => pollJobStatus(jobId), ms); }
    function clearRunPollTimer() { if (runPollTimer) { clearTimeout(runPollTimer); runPollTimer = null; } }
    async function pollJobStatus(jobId) {
        if (!jobId) return;
        try {
            const r = await fetch(`api/execute.php?action=job&id=${encodeURIComponent(jobId)}`);
            const d = await r.json();
            if (!(d.success && d.job)) { scheduleRunPoll(jobId, 1000); return; }
            const job = d.job;
            const status = String(job.status||'').toLowerCase();
            document.getElementById('runOutputSection').style.display = 'block';
            const outEl = document.getElementById('runOutput');
            outEl.textContent = job.output || 'No output yet…';
            outEl.scrollTop   = outEl.scrollHeight;
            renderRunSteps(job.steps||[]);
            runningIsDryRun = !!job.isDryRun;
            updateRunStatusFromJob(job);
            if (status === 'pending' || status === 'running') { scheduleRunPoll(jobId, 1000); return; }
            document.getElementById('executeBtn').style.display = 'inline-flex';
            document.getElementById('dryRunBtn').style.display = 'inline-flex';
            document.getElementById('cancelBtn').style.display  = 'none';
        } catch(e) { console.error(e); scheduleRunPoll(jobId, 1000); }
    }
    function renderRunSteps(rawSteps) {
        const steps = Array.isArray(rawSteps) ? rawSteps : [];
        const sect  = document.getElementById('runStepsSection');
        const cont  = document.getElementById('runSteps');
        if (!steps.length) { sect.style.display = 'none'; cont.innerHTML = ''; return; }
        sect.style.display = 'block';
        cont.innerHTML = `<div class="run-steps-list">${steps.map(rs => {
            const sid   = String(rs.step_id||rs.stepId||rs.id||'').trim();
            const tid   = String(rs.template_id||rs.templateId||'').trim();
            const tname = templateById[tid]?.name || tid || 'template';
            const st    = String(rs.status||'pending').toLowerCase();
            const ec    = rs.exit_code ?? rs.exitCode;
            const err   = String(rs.error||'').trim();
            const out   = String(rs.output||'').trim();
            const dryRun = !!rs.isDryRun || !!rs.is_dry_run;
            return `<div class="run-step-row">
                <div class="run-step-head">
                    <div>
                        <div class="run-step-name">
                            ${escapeHtml(sid||'step')}
                            ${dryRun ? '<span class="step-mini-badge"><i class="fas fa-vial"></i> Dry run</span>' : ''}
                        </div>
                        <div class="run-step-meta">${escapeHtml(tname)}</div>
                    </div>
                    <span class="step-status ${escapeAttr(st)}">${escapeHtml(st)}</span>
                </div>
                <div class="run-step-meta">${ec!==undefined&&ec!==null?`Exit: ${escapeHtml(String(ec))}`:''}${err?` · ${escapeHtml(err)}`:''}</div>
                ${out?`<div class="run-output-wrap" style="margin-top:8px;"><button class="copy-btn" onclick="copyElText(this,this.nextElementSibling)" title="Copy"><i class="fas fa-copy"></i> Copy</button><pre class="run-output" style="max-height:180px;">${escapeHtml(out)}</pre></div>`:''}
            </div>`;
        }).join('')}</div>`;
    }
    function updateRunStatusFromJob(job) {
        const st = String(job.status||'').toLowerCase();
        const ec = job.exit_code ?? job.exitCode;
        const dryRun = !!job.isDryRun || !!job.is_dry_run || !!runningIsDryRun;
        if      (st === 'completed' && dryRun)    setRunStatus('info', 'Dry run ready');
        else if (st === 'completed')              setRunStatus('success', ec!==undefined ? `Completed (exit: ${ec})` : 'Completed');
        else if (st === 'completed_with_warnings')  setRunStatus('warning', 'Completed with warnings');
        else if (st === 'failed')                   setRunStatus('error',  ec!==undefined ? `Failed (exit: ${ec})` : 'Failed');
        else if (st === 'cancelled')                setRunStatus('warning', 'Cancelled');
        else if (st === 'running' || st === 'pending') setRunStatus('running', dryRun ? 'Generating dry run…' : 'Running…');
        else setRunStatus('warning', st || 'Unknown');
    }
    function copyElText(btn, target) {
        const el = typeof target === 'string' ? document.getElementById(target) : target;
        if (!el) return;
        const text = el.textContent;
        const done = () => {
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            btn.classList.add('copied');
            setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; btn.classList.remove('copied'); }, 1800);
        };
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
        } else { fallbackCopy(text, done); }
    }
    function fallbackCopy(text, cb) {
        const ta = Object.assign(document.createElement('textarea'), { value: text });
        Object.assign(ta.style, { position: 'fixed', opacity: '0', top: '0', left: '0' });
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        cb();
    }
    function setRunStatus(cls, text) {
        const el = document.getElementById('runStatus');
        el.className = `run-status ${cls}`;
        el.textContent = text;
    }
    async function cancelExecution() {
        if (!runningJobId) return;
        try {
            const r = await fetch(`api/execute.php?action=cancel&id=${encodeURIComponent(runningJobId)}`, {method:'POST'});
            const d = await r.json();
            if (d.success) { setRunStatus('warning','Cancelled'); Toast.warning('Execution cancelled'); }
            else Toast.error(d.error||'Failed to cancel');
        } catch(e) { console.error(e); Toast.error('Failed to cancel'); }
        document.getElementById('executeBtn').style.display = 'inline-flex';
        document.getElementById('dryRunBtn').style.display = 'inline-flex';
        document.getElementById('cancelBtn').style.display  = 'none';
    }
    function closeRunModal() {
        document.getElementById('runModal').classList.remove('active');
        clearRunPollTimer();
        runningCommandId = null; runningJobId = null; runningIsDryRun = false;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  UTILITIES
    // ═══════════════════════════════════════════════════════════════════════════
    function describeTargetRef(ref, envId) {
        if (!ref) return '';
        if (typeof ref === 'string') { if (ref === 'inherit') return ''; const t = targetById[ref]; return t ? t.name : ref; }
        const tid = String(ref.targetId||'').trim();
        if (tid) { const t = targetById[tid]; return t ? t.name : tid; }
        const fe  = String(ref.fromEnvironment||'').trim();
        if (fe) return `${envId ? envId+'.' : ''}${fe}`;
        return '';
    }
    function parseTargetRef(raw) {
        if (!raw) return { mode:'inherit', targetId:'', fromEnvironment:'' };
        if (typeof raw === 'string') {
            if (raw === 'inherit') return { mode:'inherit', targetId:'', fromEnvironment:'' };
            return { mode:'targetId', targetId:raw, fromEnvironment:'' };
        }
        const tid = String(raw.targetId||'').trim();
        if (tid) return { mode:'targetId', targetId:tid, fromEnvironment:'' };
        const fe  = String(raw.fromEnvironment||'').trim();
        if (fe)  return { mode:'fromEnvironment', targetId:'', fromEnvironment:fe };
        return { mode:'inherit', targetId:'', fromEnvironment:'' };
    }
    function serializeTargetRef(ref) {
        if (!ref || ref.mode === 'inherit') return 'inherit';
        if (ref.mode === 'targetId') { const t = String(ref.targetId||'').trim(); return t ? {targetId:t} : 'inherit'; }
        if (ref.mode === 'fromEnvironment') { const fe = String(ref.fromEnvironment||'').trim(); return fe ? {fromEnvironment:fe} : 'inherit'; }
        return 'inherit';
    }
    function targetTypeIcon(type) {
        const m = { ssh:'fas fa-server', local:'fas fa-laptop', docker:'fas fa-cube', 'docker-compose':'fas fa-layer-group', kubernetes:'fas fa-dharmachakra', http:'fas fa-globe', api:'fas fa-plug', webhook:'fas fa-bolt' };
        return m[type] || 'fas fa-bullseye';
    }
    function renderValueField(field, raw, onChangeExpr) {
        const val  = raw === undefined || raw === null ? '' : raw;
        const type = normalizeInteractiveFieldType(field);
        if (type === 'select' && Array.isArray(field.options) && field.options.length) {
            return `<select class="form-select" onchange="${onChangeExpr}"><option value=""></option>
                ${field.options.map(o => { const ov=o.value??o; const ol=o.label??ov; return `<option value="${escapeAttr(ov)}" ${String(val)===String(ov)?'selected':''}>${escapeHtml(ol)}</option>`; }).join('')}
            </select>`;
        }
        if (type === 'multiselect') {
            const selectedValues = Array.isArray(val) ? val.map(v => String(v)) : [];
            if (Array.isArray(field.options) && field.options.length) {
                return `<select class="form-select" multiple onchange="${onChangeExpr}">
                    ${field.options.map(o => {
                        const ov = String(o.value ?? o);
                        const ol = o.label ?? ov;
                        return `<option value="${escapeAttr(ov)}" ${selectedValues.includes(ov) ? 'selected' : ''}>${escapeHtml(ol)}</option>`;
                    }).join('')}
                </select>`;
            }
            return `<textarea class="form-textarea" placeholder="${escapeAttr(field.placeholder||'One value per line')}" oninput="${onChangeExpr}">${escapeHtml(formatMultivalueText(val))}</textarea>`;
        }
        if (type === 'boolean') return `<select class="form-select" onchange="${onChangeExpr}">
            <option value=""></option>
            <option value="true"  ${val===true||val==='true' ?'selected':''}>true</option>
            <option value="false" ${val===false||val==='false'?'selected':''}>false</option>
        </select>`;
        if (type === 'json' || isPlainObj(val) || (Array.isArray(val) && !isStringArray(val))) {
            return `<textarea class="form-textarea" placeholder="${escapeAttr(field.placeholder||'Enter valid JSON')}" oninput="${onChangeExpr}">${escapeHtml(prettyJson(val))}</textarea>`;
        }
        if (Array.isArray(val)) {
            return `<textarea class="form-textarea" placeholder="${escapeAttr(field.placeholder||'One value per line')}" oninput="${onChangeExpr}">${escapeHtml(formatMultivalueText(val))}</textarea>`;
        }
        if (type === 'number') {
            const mn = field.validation?.min !== undefined ? `min="${escapeAttr(field.validation.min)}"` : '';
            const mx = field.validation?.max !== undefined ? `max="${escapeAttr(field.validation.max)}"` : '';
            return `<input type="number" class="form-input" value="${escapeAttr(val)}" ${mn} ${mx} oninput="${onChangeExpr}">`;
        }
        return `<input type="text" class="form-input" value="${escapeAttr(val)}" placeholder="${escapeAttr(field.placeholder||'')}" oninput="${onChangeExpr}">`;
    }
    function convertInputValue(raw, type) {
        if (raw === '' || raw === null || raw === undefined) return '';
        if (type === 'multiselect') {
            if (Array.isArray(raw)) return raw.map(v => String(v)).filter(Boolean);
            return String(raw).split(/\r?\n/).map(v => v.trim()).filter(Boolean);
        }
        if (type === 'json') {
            if (typeof raw === 'object') return raw;
            try { return JSON.parse(String(raw)); } catch (e) { return raw; }
        }
        if (type === 'number') { const n = Number(raw); return Number.isNaN(n) ? '' : n; }
        if (type === 'boolean') { if (raw === true || raw === 'true') return true; if (raw === false || raw === 'false') return false; return ''; }
        return raw;
    }
    function readFormControlValue(el, type) {
        if (type === 'multiselect' && el instanceof HTMLSelectElement) {
            return Array.from(el.selectedOptions).map(option => option.value).filter(Boolean);
        }
        if (type === 'multiselect-list') {
            return Array.from(el.querySelectorAll('[data-array-item]'))
                .map((field) => String(field.value || '').trim())
                .filter(Boolean);
        }
        return convertInputValue(el.value, type);
    }
    function renderRunArrayEditor(name, values, placeholder) {
        const items = Array.isArray(values) && values.length ? values : [''];
        return `<div class="form-array-editor" data-run-input="${name}" data-run-type="multiselect-list" data-item-placeholder="${escapeAttr(placeholder)}">
            <div class="form-array-list">
                ${renderRunArrayRows(items, placeholder)}
            </div>
            <div class="form-array-actions">
                <button type="button" class="form-array-btn" onclick="addRunArrayItem(this)">
                    <i class="fas fa-plus"></i>
                    Add item
                </button>
            </div>
        </div>`;
    }
    function renderRunArrayRows(values, placeholder) {
        const items = Array.isArray(values) && values.length ? values : [''];
        return items.map((item, index) => `
            <div class="form-array-row">
                <input type="text" class="form-input" data-array-item value="${escapeAttr(item)}" placeholder="${escapeAttr(placeholder || 'Value')}">
                <button type="button" class="form-array-btn" onclick="removeRunArrayItem(this)" aria-label="Remove item ${index + 1}">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        `).join('');
    }
    function addRunArrayItem(button) {
        const container = button.closest('[data-run-type="multiselect-list"]');
        if (!container) return;
        const values = readFormControlValue(container, 'multiselect-list');
        values.push('');
        refreshRunArrayEditor(container, values);
        const rows = container.querySelectorAll('[data-array-item]');
        const last = rows[rows.length - 1];
        if (last) last.focus();
    }
    function removeRunArrayItem(button) {
        const container = button.closest('[data-run-type="multiselect-list"]');
        const row = button.closest('.form-array-row');
        if (!container || !row) return;
        const rows = Array.from(container.querySelectorAll('.form-array-row'));
        const index = rows.indexOf(row);
        const values = Array.from(container.querySelectorAll('[data-array-item]')).map((field) => field.value);
        if (index >= 0) values.splice(index, 1);
        refreshRunArrayEditor(container, values);
    }
    function refreshRunArrayEditor(container, values) {
        const list = container.querySelector('.form-array-list');
        if (!list) return;
        const placeholder = container.dataset.itemPlaceholder || 'Value';
        list.innerHTML = renderRunArrayRows(values, placeholder);
    }
    function isPlainObj(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); }
    function isStringArray(v) { return Array.isArray(v) && v.every(item => typeof item === 'string'); }
    function formatMultivalueText(v) {
        if (!Array.isArray(v)) return '';
        return v.map(item => String(item ?? '')).join('\n');
    }
    function prettyJson(v) {
        try { return JSON.stringify(v ?? {}, null, 2); }
        catch (e) { return String(v ?? ''); }
    }
    function formatRunFieldHelper(field) {
        const description = String(field?.description || '').trim();
        if (!description) return '';
        if (normalizeInteractiveFieldType(field) !== 'multiselect' || (Array.isArray(field?.options) && field.options.length)) {
            return description;
        }
        return description.replace(/^newline-separated/i, 'Add one item per row');
    }
    function normalizeInteractiveFieldType(field) {
        const type = String(field?.type || 'string').trim().toLowerCase();
        if (type === 'string' && looksLikeStringListField(field)) return 'multiselect';
        if (type === 'integer') return 'number';
        if (type === 'object') return 'json';
        if (type === 'array') {
            const itemType = String(field?.items?.type || 'string').trim().toLowerCase();
            return itemType === 'string' ? 'multiselect' : 'json';
        }
        if (type === 'string' && String(field?.format || '').trim().toLowerCase() === 'json') return 'json';
        return type;
    }
    function looksLikeStringListField(field) {
        const haystack = [
            field?.title,
            field?.label,
            field?.description,
            field?.placeholder,
        ].map((value) => String(value || '').trim().toLowerCase()).filter(Boolean).join(' ');
        if (!haystack) return false;
        return haystack.includes('newline-separated')
            || haystack.includes('list of')
            || haystack.includes('one per line')
            || haystack.includes('multiple values');
    }
    function escapeHtml(v) {
        if (v === undefined || v === null) return '';
        const d = document.createElement('div');
        d.textContent = String(v);
        return d.innerHTML;
    }
    function escapeAttr(v) {
        if (v === undefined || v === null) return '';
        return String(v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function escapeJs(v) {
        if (v === undefined || v === null) return '';
        return String(v).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/\n/g,'\\n').replace(/\r/g,'\\r');
    }
    // Render an icon value that may be an emoji string OR a Font Awesome class (e.g. "fas fa-globe")
    function renderIconHtml(icon, fallback) {
        const v = icon || fallback || '📦';
        return /^fa[bsrld]?\s+fa-/.test(v) ? `<i class="${escapeAttr(v)}" aria-hidden="true"></i>` : escapeHtml(v);
    }
    function setIconDisplay(el, icon) {
        if (!el) return;
        const v = icon || '📦';
        if (/^fa[bsrld]?\s+fa-/.test(v)) { el.innerHTML = `<i class="${escapeAttr(v)}" aria-hidden="true"></i>`; }
        else { el.textContent = v; }
    }

    function toggleInfoPopover(id, e) {
        if (e) e.stopPropagation();
        const pop = document.getElementById(id);
        if (!pop) return;
        pop.style.display = pop.style.display === 'none' ? 'block' : 'none';
    }

    // ─── Global click / keyboard handlers ────────────────────────────────────
    function handleGlobalClick(e) {
        const cp = document.getElementById('connectorPopover');
        if (cp.classList.contains('open') && !cp.contains(e.target) && !e.target.closest('.connector-pill')) {
            closeConnectorPopover();
        }
        const stylePop = document.getElementById('stylePopover');
        const styleWrap = document.getElementById('styleBtnWrap');
        if (stylePop && stylePop.style.display !== 'none' && styleWrap && !styleWrap.contains(e.target)) {
            stylePop.style.display = 'none';
        }
        // Close any open info popovers
        document.querySelectorAll('.info-popover').forEach(pop => {
            if (pop.style.display !== 'none' && !pop.closest('.info-popover-wrap').contains(e.target)) {
                pop.style.display = 'none';
            }
        });
    }

    document.getElementById('connectorPopover').addEventListener('click', e => {
        const btn = e.target.closest('[data-mode]');
        if (!btn || connectorStepIndex < 0) return;
        setConnectorMode(connectorStepIndex, btn.dataset.mode);
        closeConnectorPopover();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (document.getElementById('runModal').classList.contains('active'))    closeRunModal();
            if (document.getElementById('deleteModal').classList.contains('active')) closeDeleteModal();
            if (document.getElementById('connectorPopover').classList.contains('open')) closeConnectorPopover();
        }
    });

    document.querySelectorAll('.modal-overlay').forEach(ov => {
        ov.addEventListener('click', e => {
            if (e.target !== ov) return;
            if (ov.id === 'runModal')    closeRunModal();
            if (ov.id === 'deleteModal') closeDeleteModal();
        });
    });
    </script>
</body>
</html>
