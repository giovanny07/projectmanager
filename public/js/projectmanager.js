/**
 * Project Manager — projectmanager.js
 * Global namespace and UI logic for task dependencies.
 *
 * @license GPL-3.0-or-later
 */

/* global CFG_GLPI */

'use strict';

window.ProjectManager = window.ProjectManager || {};

/**
 * Reads the CSRF token GLPI exposes for JS via the page's meta tag.
 * There is no `window.glpiCsrfToken` global in GLPI 11 — using it always
 * sends an empty token and gets rejected with 403 by the CSRF listener.
 */
function _pmCsrfToken() {
    const meta = document.querySelector('meta[property="glpi:csrf_token"]');
    return meta ? meta.content : '';
}

/**
 * Loads the project's tasks into the predecessor <select>.
 *
 * @param {object} opts
 * @param {number} opts.projectId
 * @param {number} opts.currentTaskId  Task to exclude (the current one)
 * @param {string} opts.selectId       ID of the <select> element
 * @param {string} [opts.ajaxUrl]      Endpoint URL (computed in PHP via Plugin::getWebDir)
 */
window.ProjectManager.loadPredecessorSelect = function (opts) {
    const sel = document.getElementById(opts.selectId);
    if (!sel) return;

    sel.disabled = true;

    const body = new URLSearchParams({
        projects_id:      opts.projectId,
        exclude_task_id:  opts.currentTaskId,
        _glpi_csrf_token: _pmCsrfToken(),
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
 * Minimal translation helper: uses the strings injected by PHP if present,
 * otherwise returns the string unchanged.
 */
function _pm(str) {
    return (window.ProjectManagerI18n || {})[str] || str;
}
