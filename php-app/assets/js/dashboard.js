/**
 * dashboard.js — Gestión de Personal SCI-TSS
 * ============================================
 * REMEDIACIÓN INTEGRAL v3.0:
 *
 * BUGS CRÍTICOS CORREGIDOS:
 *  1. renderTable() contenía código muerto con `data.forEach()` (variable no definida)
 *     y funciones anidadas ilegalmente (goToPage, setupControls, changeStatus, etc.)
 *     que eran inaccesibles desde el scope global → La tabla nunca renderizaba.
 *  2. setupGerenciasManager() nunca se llamaba en init() → Modal gerencias roto.
 *  3. setupDelegation() era un stub vacío → Delegación no funcional.
 *  4. Dropdown toggle usaba position:fixed sin cleanup en scroll correcto.
 *  5. Doble llamada a setupLogout() (en init() + setupGlobalLogout en api.js).
 *
 * ARQUITECTURA: Todas las funciones son de scope global (window-level),
 * declaradas correctamente con `function` o asignadas a `window.*`.
 * El patrón de init() solo orquesta — no define funciones.
 *
 * @version 3.0.0
 */
'use strict';

// ── Estado del módulo ─────────────────────────────────────────
let employees = [];
let currentMeta = { totalRecords: 0, currentPage: 1, totalPages: 1, limit: 50 };
let searchTimer = null;

// ══════════════════════════════════════════════════════════════
// INIT — Orquestador principal
// ══════════════════════════════════════════════════════════════
async function init() {
    if (typeof api.initCsrf === 'function') await api.initCsrf();

    setupUserInfo();
    applyConsultaRestrictions();

    await Promise.all([
        loadEmployees(),
        populateGerenciasSelects(),
    ]);

    setupControls();
    setupModal();
    setupAutoMatch();
    setupPayrollImport();
    setupGerenciasManager();
    setupDelegation();

    // Cerrar dropdowns al hacer clic fuera
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.action-dropdown-btn') && !e.target.closest('.action-dropdown')) {
            closeAllDropdowns();
        }
    });

    // Cerrar dropdowns al hacer scroll
    document.addEventListener('scroll', closeAllDropdowns, true);
}

// ══════════════════════════════════════════════════════════════
// UI — Información del usuario en sidebar
// ══════════════════════════════════════════════════════════════
function setupUserInfo() {
    const user = api.getCurrentUser();
    if (!user || !user.username) return;

    const displayName = user.full_name
        ? `${user.full_name} (${user.username})`
        : user.username.charAt(0).toUpperCase() + user.username.slice(1);

    const elName = document.getElementById('user-name');
    const elRole = document.getElementById('user-role');
    const elAvatar = document.getElementById('user-avatar');

    if (elName) elName.textContent = displayName;

    if (elRole) {
        const effRole = (user.effective_role || user.role || '').toUpperCase();
        const roleLabels = {
            ADMIN: 'Administrador',
            COORD: 'Coordinador',
            ANALISTA: 'Analista',
            USUARIO: 'Operador',
            CONSULTA: 'Solo Consulta',
        };
        elRole.textContent = (roleLabels[effRole] || effRole) + (user.temporary_role ? ' ⚡Temp.' : '');
    }

    if (elAvatar) {
        const initials = user.full_name
            ? user.full_name.split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase()
            : user.username[0].toUpperCase();
        elAvatar.textContent = initials;
    }

    const navUsuarios = document.getElementById('nav-usuarios');
    if (navUsuarios) navUsuarios.style.display = api.isAdmin() ? 'flex' : 'none';
}

// ══════════════════════════════════════════════════════════════
// RBAC — Restricciones por rol
// ══════════════════════════════════════════════════════════════
function applyConsultaRestrictions() {
    const isAdminCoord = api.isAdminCoord();
    const canCreate = ['ADMIN', 'COORD', 'ANALISTA'].includes(
        (api.getCurrentUser().effective_role || api.getCurrentUser().role || '').toUpperCase()
    );

    const showIf = (id, show) => {
        const el = document.getElementById(id);
        if (el) el.style.display = show ? 'inline-flex' : 'none';
    };

    showIf('btn-new-employee', canCreate);
    showIf('btn-import-excel', canCreate);
    showIf('btn-auto-match', isAdminCoord);
    showIf('btn-manage-gerencias', isAdminCoord);
    showIf('btn-delegate-perms', api.isAdmin());
}

// ══════════════════════════════════════════════════════════════
// DATA LOADING
// ══════════════════════════════════════════════════════════════
async function loadEmployees(params = {}) {
    const defaults = { page: currentMeta.currentPage, limit: currentMeta.limit };
    const merged = Object.assign({}, defaults, params);

    // Limpiar parámetros vacíos
    Object.keys(merged).forEach(k => {
        if (merged[k] === '' || merged[k] === undefined || merged[k] === null) delete merged[k];
    });

    const tbody = document.getElementById('employees-tbody');
    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--color-muted);">
            <span style="display:inline-flex;align-items:center;gap:8px;">
            <span class="spinner" style="border-top-color:var(--color-primary);border-color:var(--color-border);"></span>
            Cargando registros...</span></td></tr>`;
    }

    try {
        const res = await api.getEmployees(merged);
        employees = res.data || [];
        currentMeta = res.meta || currentMeta;
        renderTable(employees);
        renderStats(employees, currentMeta);
        renderPagination(currentMeta);
    } catch (err) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--color-danger);">
                ⚠️ ${err.message}</td></tr>`;
        }
    }
}

// ══════════════════════════════════════════════════════════════
// RENDERING — Stats
// ══════════════════════════════════════════════════════════════
function renderStats(list, meta) {
    const getEstado = e => e.estado_carnet || e.status || '';
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

    set('stat-total', meta.totalRecords ?? list.length);
    set('stat-pending', list.filter(e => getEstado(e) === 'Pendiente por Imprimir').length);
    set('stat-printed', list.filter(e => getEstado(e) === 'Carnet Impreso').length);
    set('stat-verified', list.filter(e => getEstado(e) === 'Carnet Entregado').length);
}

// ══════════════════════════════════════════════════════════════
// RENDERING — Tabla de empleados
// CORRECCIÓN CRÍTICA: Esta función SOLO renderiza HTML.
// No contiene ninguna otra función anidada (era el bug principal).
// ══════════════════════════════════════════════════════════════
function renderTable(list) {
    const tbody = document.getElementById('employees-tbody');
    const isAdmin = api.isAdmin();

    if (!list || !list.length) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--color-muted);">
            No se encontraron registros.</td></tr>`;
        return;
    }

    const STATUS_OPTIONS = ['Pendiente por Imprimir', 'Carnet Impreso', 'Carnet Entregado'];
    const ENTREGA_OPTIONS = [
        { value: '', label: 'Sin asignar' },
        { value: 'Manual', label: 'Manual' },
        { value: 'Digital', label: 'Digital' },
    ];

    const rows = list.map(emp => {
        // Nombre completo desde campos disgregados (con fallback legacy)
        const primerNombre = (emp.primer_nombre || '').trim();
        const segundoNombre = (emp.segundo_nombre || '').trim();
        const primerApellido = (emp.primer_apellido || '').trim();
        const segundoApellido = (emp.segundo_apellido || '').trim();

        const nombresDisplay = [primerNombre, segundoNombre].filter(Boolean).join(' ') || emp.nombres || '';
        const apellidosDisplay = [primerApellido, segundoApellido].filter(Boolean).join(' ') || emp.apellidos || '';
        const fullName = apellidosDisplay ? `${apellidosDisplay}, ${nombresDisplay}` : nombresDisplay;

        const nac = emp.nacionalidad || 'V';
        const cedulaRaw = (emp.cedula || '').replace(/[^0-9]/g, '');
        const cedulaDisplay = `${nac}-${cedulaRaw}`;
        const estadoCarnet = emp.estado_carnet || emp.status || 'Pendiente por Imprimir';
        const avatarName = [primerNombre, primerApellido].filter(Boolean).join(' ') || fullName;
        const photoSrc = emp.photo_url || emp.foto_url || makeAvatar(avatarName);

        const statusOpts = STATUS_OPTIONS.map(s =>
            `<option value="${s}" ${estadoCarnet === s ? 'selected' : ''}>${s}</option>`
        ).join('');

        const entregaOpts = ENTREGA_OPTIONS.map(o =>
            `<option value="${o.value}" ${(emp.forma_entrega || '') === o.value ? 'selected' : ''}>${o.label}</option>`
        ).join('');

        const adminActions = isAdmin ? `
            <hr style="margin:4px 0;border:none;border-top:1px solid #f1f5f9;"/>
            <button onclick="event.stopPropagation();openEditor(${emp.id})"
                style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:8px 16px;
                background:none;border:none;cursor:pointer;color:#0284c7;font-size:.8rem;">
                ✏️ Editar
            </button>
            <button onclick="event.stopPropagation();deleteEmployee(${emp.id})"
                style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:8px 16px;
                background:none;border:none;cursor:pointer;color:#dc2626;font-size:.8rem;">
                🗑️ Eliminar
            </button>` : '';

        return `
        <tr data-id="${emp.id}" onclick="viewEmployee(${emp.id})" style="cursor:pointer;">
          <td>
            <div class="emp-info">
              <img src="${photoSrc}" class="avatar" alt="${fullName}"
                   onerror="this.src='${makeAvatar(avatarName)}'" />
              <div>
                <div class="emp-name">${fullName}</div>
                <div class="emp-id">#${emp.id} · ${cedulaDisplay}</div>
              </div>
            </div>
          </td>
          <td style="font-family:monospace;font-size:.82rem;">${cedulaDisplay}</td>
          <td>
            <div style="font-weight:500;">${emp.cargo || '—'}</div>
            <div style="font-size:.75rem;color:var(--color-muted);">${emp.gerencia || '—'}</div>
          </td>
          <td>
            <select class="badge ${ui.getBadgeClass(estadoCarnet)}"
                    onchange="changeStatus(event, ${emp.id})"
                    onclick="event.stopPropagation()"
                    style="border:none;background:transparent;cursor:pointer;font-weight:600;font-size:.72rem;"
                    ${!isAdmin ? 'disabled' : ''}>
              ${statusOpts}
            </select>
          </td>
          <td onclick="event.stopPropagation()">
            <select onchange="changeEntrega(event, ${emp.id})"
                    style="padding:4px 6px;font-size:.75rem;border:1px solid var(--color-border);
                           border-radius:6px;width:110px;cursor:pointer;"
                    ${!isAdmin ? 'disabled' : ''}>
              ${entregaOpts}
            </select>
          </td>
          <td style="color:var(--color-muted);font-size:.8rem;">${ui.formatDate(emp.fecha_ingreso)}</td>
          <td style="text-align:right;">
            <div style="position:relative;display:inline-block;">
              <button class="action-dropdown-btn"
                      onclick="event.stopPropagation(); toggleDropdown(${emp.id});"
                      title="Opciones"
                      style="background:transparent;color:var(--color-muted);border:none;
                             font-size:1.2rem;cursor:pointer;padding:4px 8px;border-radius:4px;">
                ⋮
              </button>
              <div id="dropdown-${emp.id}" class="action-dropdown"
                   style="display:none;position:fixed;background:#fff;border:1px solid var(--color-border);
                          border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,.15);z-index:1000;
                          min-width:140px;padding:6px 0;font-size:.8rem;">
                <button onclick="event.stopPropagation();viewEmployee(${emp.id})"
                    style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;
                    padding:8px 16px;background:none;border:none;cursor:pointer;color:var(--color-text);font-size:.8rem;">
                  👁️ Consultar
                </button>
                ${adminActions}
              </div>
            </div>
          </td>
        </tr>`;
    });

    if (tbody) tbody.innerHTML = rows.join('');
}

// ══════════════════════════════════════════════════════════════
// RENDERING — Paginación
// ══════════════════════════════════════════════════════════════
function renderPagination(meta) {
    const container = document.getElementById('pagination-container');
    if (!container) return;

    const { currentPage, totalPages, totalRecords, limit } = meta;
    const from = ((currentPage - 1) * limit) + 1;
    const to = Math.min(currentPage * limit, totalRecords);

    if (totalPages <= 1) { container.innerHTML = ''; return; }

    const delta = 2;
    const start = Math.max(1, currentPage - delta);
    const end = Math.min(totalPages, currentPage + delta);
    const range = [];

    if (start > 1) range.push(1, '...');
    for (let i = start; i <= end; i++) range.push(i);
    if (end < totalPages) range.push('...', totalPages);

    const pageButtons = range.map(p => p === '...'
        ? `<span style="padding:0 4px;color:var(--color-muted);">…</span>`
        : `<button class="btn ${p === currentPage ? 'btn-primary' : 'btn-secondary'}"
                   style="padding:6px 10px;font-size:.75rem;min-width:34px;"
                   onclick="goToPage(${p})">${p}</button>`
    ).join('');

    container.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:12px 20px;border-top:1px solid var(--color-border);
                font-size:.8rem;color:var(--color-muted);">
      <span>Mostrando <strong>${from}–${to}</strong> de <strong>${totalRecords}</strong> registros</span>
      <div style="display:flex;gap:4px;align-items:center;">
        <button class="btn btn-secondary" style="padding:6px 10px;font-size:.75rem;"
                ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">← Ant.</button>
        ${pageButtons}
        <button class="btn btn-secondary" style="padding:6px 10px;font-size:.75rem;"
                ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">Sig. →</button>
      </div>
    </div>`;
}

// ══════════════════════════════════════════════════════════════
// DROPDOWN
// ══════════════════════════════════════════════════════════════
function closeAllDropdowns() {
    document.querySelectorAll('.action-dropdown').forEach(d => { d.style.display = 'none'; });
}

window.toggleDropdown = function (id) {
    const curr = document.getElementById(`dropdown-${id}`);
    if (!curr) return;

    const isOpen = curr.style.display === 'block';
    closeAllDropdowns();

    if (!isOpen) {
        // Posición calculada con getBoundingClientRect para evitar overflow
        const btn = curr.previousElementSibling;
        const rect = btn.getBoundingClientRect();

        curr.style.display = 'block';
        const dropH = curr.offsetHeight || 120;
        const spaceDown = window.innerHeight - rect.bottom;
        const top = spaceDown >= dropH + 10 ? rect.bottom + 5 : rect.top - dropH - 5;

        curr.style.top = Math.max(10, top) + 'px';
        curr.style.right = (window.innerWidth - rect.right) + 'px';
        curr.style.left = 'auto';
    }
};

// ══════════════════════════════════════════════════════════════
// NAVEGACIÓN
// ══════════════════════════════════════════════════════════════
function goToPage(page) {
    currentMeta.currentPage = page;
    const search = document.getElementById('search-input')?.value.trim() || '';
    const status = document.getElementById('filter-status')?.value || '';
    loadEmployees({ page, search, status });
}

function openEditor(id) {
    localStorage.setItem('selected_employee_id', id);
    window.location.href = `editor.html?id=${encodeURIComponent(id)}`;
}

function viewEmployee(id) {
    localStorage.setItem('selected_employee_id', id);
    window.location.href = `editor.html?id=${encodeURIComponent(id)}&mode=view`;
}

// ══════════════════════════════════════════════════════════════
// CONTROLES — Búsqueda, filtros, paginación
// ══════════════════════════════════════════════════════════════
function setupControls() {
    const searchInput = document.getElementById('search-input');
    const filterStatus = document.getElementById('filter-status');
    const limitSelect = document.getElementById('limit-select');
    const btnRefresh = document.getElementById('btn-refresh');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                currentMeta.currentPage = 1;
                loadEmployees({
                    page: 1,
                    search: searchInput.value.trim(),
                    status: filterStatus?.value || '',
                    limit: parseInt(limitSelect?.value) || currentMeta.limit,
                });
            }, 400);
        });
    }

    if (filterStatus) {
        filterStatus.addEventListener('change', () => {
            currentMeta.currentPage = 1;
            loadEmployees({
                page: 1,
                search: searchInput?.value.trim() || '',
                status: filterStatus.value,
                limit: parseInt(limitSelect?.value) || currentMeta.limit,
            });
        });
    }

    if (limitSelect) {
        limitSelect.addEventListener('change', () => {
            currentMeta.limit = parseInt(limitSelect.value);
            currentMeta.currentPage = 1;
            loadEmployees({ page: 1, limit: currentMeta.limit });
        });
    }

    if (btnRefresh) {
        btnRefresh.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (filterStatus) filterStatus.value = '';
            currentMeta.currentPage = 1;
            loadEmployees({ page: 1 });
        });
    }
}

// ══════════════════════════════════════════════════════════════
// ACCIONES DE FILA — Estado y Entrega
// ══════════════════════════════════════════════════════════════
async function changeStatus(e, id) {
    e.stopPropagation();
    const status = e.target.value;
    const emp = employees.find(x => String(x.id) === String(id));
    try {
        await api.updateStatus(id, status);
        if (emp) {
            emp.status = status;
            emp.estado_carnet = status;
            e.target.className = `badge ${ui.getBadgeClass(status)}`;
            renderStats(employees, currentMeta);
        }
    } catch (err) {
        showFloatingToast(err.message, 'danger');
        if (emp) e.target.value = emp.estado_carnet || emp.status;
    }
}

async function changeEntrega(e, id) {
    e.stopPropagation();
    const forma_entrega = e.target.value;
    const emp = employees.find(x => String(x.id) === String(id));
    try {
        await api.updateStatus(id, emp?.estado_carnet || emp?.status, forma_entrega);
        if (emp) emp.forma_entrega = forma_entrega;
    } catch (err) {
        showFloatingToast(err.message, 'danger');
    }
}

async function deleteEmployee(id) {
    const emp = employees.find(x => String(x.id) === String(id));
    if (!emp) return;

    const apellidos = [emp.primer_apellido, emp.segundo_apellido].filter(Boolean).join(' ') || emp.apellidos || '';
    const nombres = [emp.primer_nombre, emp.segundo_nombre].filter(Boolean).join(' ') || emp.nombres || '';
    const nombreCompleto = apellidos ? `${apellidos}, ${nombres}` : nombres;

    let confirmed = false;
    if (typeof Swal !== 'undefined') {
        const result = await Swal.fire({
            title: '¿Confirmar eliminación?',
            text: `Funcionario: "${nombreCompleto}". Esta acción no se puede deshacer.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
        });
        confirmed = result.isConfirmed;
    } else {
        confirmed = confirm(`¿Eliminar a "${nombreCompleto}"? Esta acción no se puede deshacer.`);
    }

    if (!confirmed) return;

    try {
        await api.deleteEmployee(id);
        employees = employees.filter(x => String(x.id) !== String(id));
        currentMeta.totalRecords = Math.max(0, (currentMeta.totalRecords || 0) - 1);
        renderTable(employees);
        renderStats(employees, currentMeta);
        showFloatingToast('Funcionario eliminado correctamente.', 'success');
    } catch (err) {
        showFloatingToast(err.message, 'danger');
    }
}

// ══════════════════════════════════════════════════════════════
// MODAL — Nuevo Empleado
// ══════════════════════════════════════════════════════════════
function setupModal() {
    const modal = document.getElementById('modal-new');
    const btnNew = document.getElementById('btn-new-employee');
    const btnCancel = document.getElementById('btn-modal-cancel');
    const form = document.getElementById('form-new-employee');

    if (!modal || !form) return;

    if (btnNew) {
        btnNew.addEventListener('click', async () => {
            await populateGerenciasSelects();
            modal.style.display = 'flex';
        });
    }

    if (btnCancel) {
        btnCancel.addEventListener('click', () => {
            modal.style.display = 'none';
            form.reset();
            const alertEl = document.getElementById('modal-alert');
            if (alertEl) alertEl.innerHTML = '';
        });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-modal-save');
        const data = Object.fromEntries(new FormData(e.target).entries());

        // Validación de campos obligatorios
        if (!data.cedula || !data.primer_nombre || !data.primer_apellido || !data.cargo || !data.gerencia) {
            ui.showAlert('modal-alert', 'Los campos marcados con * son obligatorios.');
            return;
        }

        // Validación estricta de cédula: solo dígitos
        const cedulaLimpia = (data.cedula || '').trim().replace(/[^0-9]/g, '');
        if (!cedulaLimpia) {
            ui.showAlert('modal-alert', 'La cédula debe contener solo números (sin prefijos V- o E-).');
            return;
        }
        if (cedulaLimpia.length < 5 || cedulaLimpia.length > 10) {
            ui.showAlert('modal-alert', 'La cédula debe tener entre 5 y 10 dígitos.');
            return;
        }
        data.cedula = cedulaLimpia;

        ui.setLoading(btn, true, 'Guardando...');
        try {
            await api.createEmployee(data);
            modal.style.display = 'none';
            form.reset();
            currentMeta.currentPage = 1;
            await loadEmployees({ page: 1 });
            showFloatingToast('Empleado registrado correctamente.', 'success');
        } catch (err) {
            ui.showAlert('modal-alert', err.message);
        } finally {
            ui.setLoading(btn, false);
        }
    });
}

// ══════════════════════════════════════════════════════════════
// GERENCIAS — Gestor de gerencias
// ══════════════════════════════════════════════════════════════
async function populateGerenciasSelects() {
    try {
        const res = await api.getGerencias();
        const gerencias = res.data || [];
        const options = gerencias.map(g => `<option value="${g.nombre}">${g.nombre}</option>`).join('');
        document.querySelectorAll('.gerencia-select').forEach(sel => {
            const curr = sel.value;
            sel.innerHTML = `<option value="">Seleccionar gerencia...</option>` + options;
            if (curr) sel.value = curr;
        });
    } catch (err) {
        console.warn('[SCI-TSS] No se pudo cargar gerencias:', err.message);
    }
}

function setupGerenciasManager() {
    const btn = document.getElementById('btn-manage-gerencias');
    const modal = document.getElementById('modal-gerencias');
    const close = document.getElementById('btn-close-gerencias');
    const addBtn = document.getElementById('btn-add-gerencia');

    if (!btn || !modal) return;

    async function renderGerenciasList() {
        try {
            const res = await api.getGerencias();
            const list = document.getElementById('gerencias-list');
            if (!list) return;
            list.innerHTML = (res.data || []).map(g => `
                <div id="ger-item-${g.id}"
                     style="display:flex;align-items:center;gap:8px;padding:8px 10px;
                            background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                    <span style="flex:1;font-size:.875rem;font-weight:500;" id="ger-label-${g.id}">${g.nombre}</span>
                    <input type="text" id="ger-edit-${g.id}" value="${g.nombre}"
                           style="flex:1;display:none;padding:4px 8px;border:1px solid var(--color-border);
                                  border-radius:6px;font-size:.875rem;" />
                    <button id="ger-btn-edit-${g.id}" onclick="editGerencia(${g.id})"
                            style="padding:4px 8px;font-size:.75rem;background:#eff6ff;color:#0284c7;
                                   border:1px solid #bae6fd;border-radius:6px;cursor:pointer;">✏️</button>
                    <button id="ger-btn-save-${g.id}" onclick="saveGerencia(${g.id})"
                            style="display:none;padding:4px 8px;font-size:.75rem;background:#d1fae5;
                                   color:#065f46;border:1px solid #6ee7b7;border-radius:6px;cursor:pointer;">✔</button>
                    <button onclick="removeGerencia(${g.id})"
                            style="padding:4px 8px;font-size:.75rem;background:#fee2e2;color:#dc2626;
                                   border:1px solid #fca5a5;border-radius:6px;cursor:pointer;">🗑</button>
                </div>`).join('');
        } catch (err) {
            showFloatingToast(err.message, 'danger');
        }
    }

    btn.addEventListener('click', async () => {
        modal.style.display = 'flex';
        await renderGerenciasList();
    });

    if (close) close.addEventListener('click', () => {
        modal.style.display = 'none';
        populateGerenciasSelects();
    });

    if (addBtn) {
        addBtn.addEventListener('click', async () => {
            const input = document.getElementById('new-gerencia-input');
            const name = (input?.value || '').trim();
            if (!name) return;
            try {
                await api.createGerencia(name);
                if (input) input.value = '';
                await renderGerenciasList();
                populateGerenciasSelects();
                showFloatingToast(`Gerencia "${name}" creada.`, 'success');
            } catch (err) {
                showFloatingToast(err.message, 'danger');
            }
        });
    }

    window.editGerencia = (id) => {
        document.getElementById(`ger-label-${id}`).style.display = 'none';
        document.getElementById(`ger-edit-${id}`).style.display = 'block';
        document.getElementById(`ger-btn-edit-${id}`).style.display = 'none';
        document.getElementById(`ger-btn-save-${id}`).style.display = 'inline-block';
        document.getElementById(`ger-edit-${id}`)?.focus();
    };

    window.saveGerencia = async (id) => {
        const nombre = document.getElementById(`ger-edit-${id}`)?.value?.trim();
        if (!nombre) return;
        try {
            await api.updateGerencia(id, nombre);
            await renderGerenciasList();
            populateGerenciasSelects();
            showFloatingToast('Gerencia actualizada.', 'success');
        } catch (err) {
            showFloatingToast(err.message, 'danger');
        }
    };

    window.removeGerencia = async (id) => {
        if (!confirm('¿Eliminar esta gerencia? Solo se puede si no tiene empleados asociados.')) return;
        try {
            await api.deleteGerencia(id);
            await renderGerenciasList();
            populateGerenciasSelects();
            showFloatingToast('Gerencia eliminada.', 'success');
        } catch (err) {
            showFloatingToast(err.message, 'danger');
        }
    };
}

// ══════════════════════════════════════════════════════════════
// AUTO-MATCH
// ══════════════════════════════════════════════════════════════
function setupAutoMatch() {
    const btn = document.getElementById('btn-auto-match');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.style.opacity = '.7';
        const orig = btn.innerHTML;
        btn.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);
                            border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;
                            vertical-align:middle;margin-right:6px;"></span>Analizando...`;
        try {
            const res = await api.autoMatch();
            await loadEmployees({ page: 1 });
            showFloatingToast(res.message || 'Auto-Match completado.', 'success');
        } catch (err) {
            showFloatingToast(err.message || 'Error en Auto-Match.', 'danger');
        } finally {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerHTML = orig;
        }
    });
}

// ══════════════════════════════════════════════════════════════
// IMPORTAR NÓMINA
// ══════════════════════════════════════════════════════════════
function setupPayrollImport() {
    const btn = document.getElementById('btn-import-excel');
    const fileInput = document.getElementById('excel-upload');
    if (!btn || !fileInput) return;

    btn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'csv'].includes(ext)) {
            showFloatingToast('Solo se aceptan archivos .xlsx o .csv', 'danger');
            fileInput.value = '';
            return;
        }

        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span style="display:inline-block;width:12px;height:12px;border:2px solid #94a3b8;
                         border-top-color:var(--color-primary);border-radius:50%;animation:spin .6s linear infinite;
                         vertical-align:middle;margin-right:6px;"></span>Leyendo...`;
        try {
            if (typeof XLSX === 'undefined') throw new Error('SheetJS no cargado. Intente recargando la página.');

            const buffer = await file.arrayBuffer();
            const workbook = XLSX.read(buffer, { type: 'array' });
            const sheet = workbook.Sheets[workbook.SheetNames[0]];
            if (!sheet) throw new Error('El archivo no contiene hojas de cálculo.');
            const rows = XLSX.utils.sheet_to_json(sheet, { defval: '' });
            if (!rows.length) throw new Error('La hoja está vacía.');

            const res = await api.uploadPayroll(rows);
            await loadEmployees({ page: 1 });
            showFloatingToast(res.message || 'Nómina importada correctamente.', 'success');
        } catch (err) {
            showFloatingToast(err.message || 'Error al procesar el archivo.', 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
            fileInput.value = '';
        }
    });
}

// ══════════════════════════════════════════════════════════════
// DELEGACIÓN DE PERMISOS
// ══════════════════════════════════════════════════════════════
function setupDelegation() {
    const btn = document.getElementById('btn-delegate-perms');
    const modal = document.getElementById('modal-delegation');
    const close = document.getElementById('btn-close-delegation');
    const form = document.getElementById('form-delegation');
    const btnRevoke = document.getElementById('btn-revoke-delegation');

    if (!btn || !modal) return;

    const currentUser = api.getCurrentUser();

    async function renderDelegationModal() {
        try {
            const res = await api.getUsers();
            const users = (res.data || []).filter(u => u.username !== currentUser.username);

            const select = document.getElementById('delegation-user');
            if (select) {
                select.innerHTML = users.map(u => {
                    const tempLabel = u.temporary_role ? ` [⚡ ${u.temporary_role}]` : '';
                    return `<option value="${u.username}">${u.full_name} (@${u.username}) — ${u.role}${tempLabel}</option>`;
                }).join('') || '<option value="">No hay otros usuarios</option>';
            }

            const delegated = users.filter(u => u.temporary_role);
            const container = document.getElementById('current-delegations');
            const delegList = document.getElementById('delegations-list');

            if (container && delegList) {
                if (delegated.length) {
                    container.style.display = 'block';
                    delegList.innerHTML = delegated.map(u => `
                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    padding:8px 12px;background:#fef9c3;border:1px solid #fde68a;
                                    border-radius:8px;margin-bottom:6px;font-size:.82rem;">
                            <span>
                                <strong>${u.full_name}</strong> (@${u.username})
                                → <span style="background:#f59e0b;color:#fff;padding:1px 8px;
                                              border-radius:3px;font-weight:700;">${u.temporary_role}</span>
                            </span>
                            <button onclick="revokeDelegation('${u.username}')"
                                style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;
                                       padding:3px 10px;border-radius:5px;font-size:.72rem;cursor:pointer;font-weight:600;">
                                Revocar
                            </button>
                        </div>`).join('');
                } else {
                    container.style.display = 'none';
                }
            }
        } catch (err) {
            console.error('[Delegation]', err.message);
        }
    }

    btn.addEventListener('click', async () => {
        modal.style.display = 'flex';
        await renderDelegationModal();
    });

    if (close) close.addEventListener('click', () => { modal.style.display = 'none'; });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const targetUsername = document.getElementById('delegation-user')?.value;
            const temporaryRole = document.getElementById('delegation-role')?.value;
            if (!targetUsername || !temporaryRole) return;
            try {
                const res = await api.delegateRole(targetUsername, temporaryRole, currentUser.username);
                showFloatingToast(res.message, 'success');
                await renderDelegationModal();
            } catch (err) {
                ui.showAlert('delegation-alert', err.message);
            }
        });
    }

    if (btnRevoke) {
        btnRevoke.addEventListener('click', async () => {
            const targetUsername = document.getElementById('delegation-user')?.value;
            if (!targetUsername) return;
            try {
                const res = await api.revokeDelegate(targetUsername);
                showFloatingToast(res.message, 'success');
                await renderDelegationModal();
            } catch (err) {
                ui.showAlert('delegation-alert', err.message);
            }
        });
    }

    window.revokeDelegation = async (username) => {
        try {
            const res = await api.revokeDelegate(username);
            showFloatingToast(res.message, 'success');
            await renderDelegationModal();
        } catch (err) {
            showFloatingToast(err.message, 'danger');
        }
    };
}

// ══════════════════════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ══════════════════════════════════════════════════════════════
function showFloatingToast(message, type = 'success') {
    const colors = {
        success: { bg: '#d1fae5', color: '#065f46', border: '#6ee7b7' },
        danger: { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' },
        info: { bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' },
    };
    const c = colors[type] || colors.info;
    const toast = document.createElement('div');
    toast.style.cssText = `
        position:fixed;bottom:24px;right:24px;z-index:99999;
        background:${c.bg};color:${c.color};border:1px solid ${c.border};
        padding:12px 20px;border-radius:10px;font-size:.875rem;font-weight:500;
        max-width:400px;box-shadow:0 4px 20px rgba(0,0,0,.12);
        animation:slideInRight .3s ease;`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'opacity .4s ease';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 400);
    }, 4500);
}

// ── Estilos dinámicos necesarios ─────────────────────────────
if (!document.getElementById('dashboard-dynamic-styles')) {
    const style = document.createElement('style');
    style.id = 'dashboard-dynamic-styles';
    style.textContent = `
        @keyframes slideInRight { from { transform:translateX(100%);opacity:0; } to { transform:translateX(0);opacity:1; } }
        @keyframes spin { 100% { transform:rotate(360deg); } }
    `;
    document.head.appendChild(style);
}

// ── Arrancar ──────────────────────────────────────────────────
init();