/**
 * Project Manager — projectmanager.js + dependency_ui.js
 * Namespace global y lógica de UI para dependencias.
 *
 * @license GPL-3.0-or-later
 */

/* global CFG_GLPI */

'use strict';

window.ProjectManager = window.ProjectManager || {};

/**
 * Carga las tareas del proyecto en el <select> de predecesoras.
 *
 * @param {object} opts
 * @param {number} opts.projectId
 * @param {number} opts.currentTaskId  Tarea a excluir (la actual)
 * @param {string} opts.selectId       ID del elemento <select>
 * @param {string} [opts.ajaxUrl]      URL del endpoint (calculada en PHP vía Plugin::getWebDir)
 */
window.ProjectManager.loadPredecessorSelect = function (opts) {
    const sel = document.getElementById(opts.selectId);
    if (!sel) return;

    sel.disabled = true;

    const body = new URLSearchParams({
        projects_id:      opts.projectId,
        exclude_task_id:  opts.currentTaskId,
        _glpi_csrf_token: window.glpiCsrfToken || '',
    });

    const url = opts.ajaxUrl || `${CFG_GLPI.root_doc}/plugins/projectmanager/ajax/get_project_tasks.php`;

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
        sel.innerHTML = `<option value="">${_pm('— Select task —')}</option>`;

        if (!data.tasks?.length) {
            sel.innerHTML += `<option disabled>${_pm('No other tasks in this project')}</option>`;
        } else {
            data.tasks.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = t.name + (t.percent_done < 100 ? ` (${t.percent_done}%)` : ' ✓');
                sel.appendChild(opt);
            });
        }

        sel.disabled = false;
    })
    .catch(err => {
        console.error('ProjectManager: failed to load tasks', err);
        sel.innerHTML = `<option value="">${_pm('Error loading tasks')}</option>`;
        sel.disabled = false;
    });
};

/**
 * Dispara replanificación en cascada vía AJAX (sin recargar la página).
 *
 * @param {number}   projectId
 * @param {number}   [changedTaskId=0]
 * @param {Function} [onDone]   callback(result)
 */
window.ProjectManager.rescheduleProject = function (projectId, changedTaskId, onDone) {
    if (!projectId) return;

    const btn = document.querySelector('[data-pm-reschedule]');
    _pmSetBtnLoading(btn, true);

    fetch(`${CFG_GLPI.root_doc}/plugins/projectmanager/ajax/reschedule.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            projects_id:      projectId,
            changed_task_id:  changedTaskId || 0,
            _glpi_csrf_token: window.glpiCsrfToken || '',
        }),
    })
    .then(r => r.json())
    .then(data => {
        _pmSetBtnLoading(btn, false);
        _pmToast(
            data.message || (data.success ? _pm('Done') : _pm('Error')),
            data.success ? 'success' : 'danger'
        );
        if (typeof onDone === 'function') onDone(data);
    })
    .catch(err => {
        console.error('ProjectManager: reschedule error', err);
        _pmSetBtnLoading(btn, false);
        _pmToast(_pm('Server communication error.'), 'danger');
    });
};

// ── Helpers privados ────────────────────────────────────────────────

function _pmSetBtnLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle('pm-btn-loading', loading);
    if (loading) {
        btn.dataset.originalHtml = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"
                               role="status" aria-hidden="true"></span>${_pm('Recalculating...')}`;
    } else if (btn.dataset.originalHtml) {
        btn.innerHTML = btn.dataset.originalHtml;
    }
}

function _pmToast(message, type = 'info') {
    // Reusar sistema de mensajes de GLPI si está disponible
    const glpiMessages = document.getElementById('messages_after_redirect');

    const wrapper = glpiMessages || (() => {
        const el = document.createElement('div');
        el.className = 'pm-cascade-toast';
        document.body.appendChild(el);
        return el;
    })();

    const icons = { success: 'check', danger: 'x', warning: 'alert-triangle', info: 'info-circle' };
    const icon  = icons[type] || 'info-circle';

    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show shadow-sm mb-2`;
    alert.innerHTML = `
        <i class="ti ti-${icon} me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    wrapper.prepend(alert);

    // Auto-dismiss a los 5 segundos
    setTimeout(() => {
        alert.classList.remove('show');
        setTimeout(() => alert.remove(), 300);
    }, 5000);
}

/**
 * Traducción mínima: usa las traducciones inyectadas por PHP si existen,
 * de lo contrario retorna el string sin cambios.
 */
function _pm(str) {
    return (window.ProjectManagerI18n || {})[str] || str;
}
