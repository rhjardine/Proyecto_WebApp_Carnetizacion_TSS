/**
 * dashboard.js — Gestión de Personal con paginación, búsqueda, filtros,
 *               CRUD de gerencias, restricciones CONSULTA e importación de nómina.
 *
 * REFACTORIZACIÓN v2.0 (Pre-Producción):
 *  - Eliminados todos los datos estáticos/mock de prueba.
 *  - Integración completa con api.js → backend PHP/MySQL real.
 *  - Renderizado de nombre completo con campos disgregados del nuevo esquema:
 *      primer_nombre + segundo_nombre + primer_apellido + segundo_apellido
 *  - Status usa campo 'estado_carnet' (alias 'status' mantenido por compatibilidad).
 *  - Validación de cédula en tiempo real: solo numérico (sin prefijo V/E).
 *
 * @version 2.0.0-preproduccion
 */
'use strict';

let employees = [];
let currentMeta = { totalRecords: 0, currentPage: 1, totalPages: 1, limit: 50 };
let searchTimer = null;

// ── INIT ──────────────────────────────────────────────────────────────────────
async function init() {
    if (typeof api.initCsrf === 'function') await api.initCsrf();
    setupLogout();
    setupUserInfo();
    applyConsultaRestrictions();
    await Promise.all([loadEmployees(), populateGerenciasSelects()]);
    setupControls();
    setupModal();
    setupAutoMatch();
    setupPayrollImport();
}

function setupDelegation() {
    // Keep exact content
}

// Global click listener to close dropdowns
document.addEventListener('click', function (e) {
    if (!e.target.closest('.action-dropdown-btn') && !e.target.closest('.action-dropdown')) {
        document.querySelectorAll('.action-dropdown').forEach(d => {
            d.style.display = 'none';
        });
    }
});

function setupUserInfo() {
    const user = api.getCurrentUser();
    if (!user.username) return;

    const displayName = user.full_name
        ? `${user.username} (${user.full_name})`
        : user.username.charAt(0).toUpperCase() + user.username.slice(1);

    const el = document.getElementById('user-name');
    if (el) el.textContent = displayName;

    const ra = document.getElementById('user-role');
    if (ra) {
        const effRole = (user.effective_role || user.role || '').toUpperCase();
        let roleText = 'Solo Consulta';
        if (effRole === 'ADMIN') roleText = 'Administrador';
        if (effRole === 'COORD') roleText = 'Coordinador';
        if (effRole === 'ANALISTA') roleText = 'Analista';

        if (user.temporary_role) roleText += ' ⚡Temp.';
        ra.textContent = roleText;
    }

    const av = document.getElementById('user-avatar');
    if (av) {
        const initials = user.full_name
            ? user.full_name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
            : user.username[0].toUpperCase();
        av.textContent = initials;
    }

    const navUsuarios = document.getElementById('nav-usuarios');
    if (navUsuarios) navUsuarios.style.display = api.isAdmin() ? 'flex' : 'none';
}

// ── TAREA 3: RESTRICCIONES CONSULTA ──────────────────────────────────────────
function applyConsultaRestrictions() {
    const isAdminCoord = api.isAdminCoord();

    if (!isAdminCoord) {
        ['btn-new-employee', 'btn-import-excel', 'btn-auto-match'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    } else {
        // Show buttons for authorized users
        ['btn-new-employee', 'btn-import-excel', 'btn-auto-match', 'btn-manage-gerencias', 'btn-delegate-perms'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'inline-flex';
        });
    }
}

// ── DATA LOADING ──────────────────────────────────────────────────────────────
async function loadEmployees(params = {}) {
    const defaults = { page: currentMeta.currentPage, limit: currentMeta.limit };
    const merged = Object.assign({}, defaults, params);
    Object.keys(merged).forEach(k => { if (merged[k] === '' || merged[k] === undefined) delete merged[k]; });

    document.getElementById('employees-tbody').innerHTML =
        `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--color-muted);">
         <span style="display:inline-flex;align-items:center;gap:8px;">
         <span class="spinner" style="border-top-color:var(--color-primary);border-color:var(--color-border);"></span>
         Cargando...</span></td></tr>`;

    try {
        const res = await api.getEmployees(merged);
        employees = res.data || [];
        currentMeta = res.meta || currentMeta;
        renderTable(employees);
        renderStats(employees, currentMeta);
        renderPagination(currentMeta);
    } catch (err) {
        document.getElementById('employees-tbody').innerHTML =
            `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--color-danger);">${err.message}</td></tr>`;
    }
}

// ── RENDERING ─────────────────────────────────────────────────────────────────
function renderStats(list, meta) {
    document.getElementById('stat-total').textContent = meta.totalRecords ?? list.length;
    // Compatibilidad: verificar tanto estado_carnet (nuevo) como status (legado)
    const getEstado = e => e.estado_carnet || e.status || '';
    document.getElementById('stat-pending').textContent = list.filter(e => getEstado(e) === 'Pendiente por Imprimir').length;
    document.getElementById('stat-verified').textContent = list.filter(e => getEstado(e) === 'Carnet Entregado').length;
    document.getElementById('stat-printed').textContent = list.filter(e => getEstado(e) === 'Carnet Impreso').length;
}

function renderTable(list) {
    const tbody = document.getElementById('employees-tbody');
    const isAdmin = api.isAdmin();
    if (!list.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--color-muted);">No se encontraron registros.</td></tr>`;
        return;
    }
    const STATUS_OPTIONS = ['Pendiente por Imprimir', 'Carnet Impreso', 'Carnet Entregado'];
    const ENTREGA_OPTIONS = ['', 'Manual', 'Digital'];

    tbody.innerHTML = list.map(emp => {
        // ── Construcción del nombre completo con campos disgregados (esquema MySQL) ──
        // Prioridad 1: campos disgregados del nuevo esquema
        // Prioridad 2: campos compuestos del esquema legado (normalizado por api.js)
        const primerNombre = (emp.primer_nombre || '').trim();
        const segundoNombre = (emp.segundo_nombre || '').trim();
        const primerApellido = (emp.primer_apellido || '').trim();
        const segundoApellido = (emp.segundo_apellido || '').trim();

        // Nombres: "Juan Alejandro" | Apellidos: "Aponte Contreras"
        const nombresDisplay = [primerNombre, segundoNombre].filter(Boolean).join(' ')
            || emp.nombres || '';
        const apellidosDisplay = [primerApellido, segundoApellido].filter(Boolean).join(' ')
            || emp.apellidos || '';

        // Formato de presentación en tabla: "APELLIDOS, Nombres"

        data.forEach(emp => {
            const tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.dataset.id = emp.id;

            // FIX CRÍTICO: Event Listener robusto para evitar burbujeo e intermitencias
            tr.addEventListener('click', (e) => {
                // No navegar si el clic fue en un select, botón o elemento interactivo
                if (e.target.closest('select') || e.target.closest('button') || e.target.closest('.action-dropdown')) {
                    return;
                }
                viewEmployee(emp.id);
            });

            const photoSrc = emp.photo_url || emp.foto_url || makeAvatar(`${emp.primer_nombre} ${emp.primer_apellido}`);
            const fullName = `${emp.primer_nombre || ''} ${emp.primer_apellido || ''}`;

            tr.innerHTML = `
            <td>
                <div class="emp-info">
                    <img src="${photoSrc}" class="avatar" alt="${fullName}" onerror="this.onerror=null; this.src='${makeAvatar(fullName)}'" />
                    <div>
                        <div class="emp-name">${fullName}</div>
                        <div class="emp-id">#${emp.id} · V-${emp.cedula || ''}</div>
                    </div>
                </div>
            </td>
            <td>V-${emp.cedula || ''}</td>
            <td>
                <div style="font-weight:600;color:var(--color-text);">${emp.cargo || '—'}</div>
                <div style="font-size:11px;color:var(--color-muted);">${emp.gerencia || 'No asignada'}</div>
            </td>
            <td>
                <select class="badge ${ui.getBadgeClass(emp.estado_carnet)}" onchange="changeStatus(event, ${emp.id})">
                    <option value="Pendiente por Imprimir" ${emp.estado_carnet === 'Pendiente por Imprimir' ? 'selected' : ''}>Pendiente</option>
                    <option value="Carnet Impreso" ${emp.estado_carnet === 'Carnet Impreso' ? 'selected' : ''}>Impreso</option>
                    <option value="Carnet Entregado" ${emp.estado_carnet === 'Carnet Entregado' ? 'selected' : ''}>Entregado</option>
                </select>
            </td>
            <td>
                <select class="form-select-sm" style="font-size:12px;width:100px;" onchange="changeEntrega(event, ${emp.id})">
                    <option value="">No def.</option>
                    <option value="Manual" ${emp.forma_entrega === 'Manual' ? 'selected' : ''}>Manual</option>
                    <option value="Digital" ${emp.forma_entrega === 'Digital' ? 'selected' : ''}>Digital</option>
                </select>
            </td>
            <td style="font-size:12px;color:var(--color-muted);">${ui.formatDate(emp.fecha_ingreso)}</td>
            <td style="text-align:center;">
                <button onclick="editEmployee(${emp.id})" class="btn-action" title="Editar Funcionario" style="background:var(--color-primary-light);color:var(--color-primary);border:1px solid var(--color-primary-border);border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer;font-weight:600;">
                    Abrir
                </button>
            </td>
        `;
            tbody.appendChild(tr);
        });
    }

// Garantizamos que la función de edición exista globalmente (3era Remediación)
window.editEmployee = function (id) {
            if (api.isAdmin()) {
                openEditor(id);
            } else {
                viewEmployee(id);
            }
        };

    function renderPagination(meta) {
        const container = document.getElementById('pagination-container');
        if (!container) return;
        const { currentPage, totalPages, totalRecords, limit } = meta;
        const from = ((currentPage - 1) * limit) + 1;
        const to = Math.min(currentPage * limit, totalRecords);
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        const range = [];
        const delta = 2;
        const start = Math.max(1, currentPage - delta);
        const end = Math.min(totalPages, currentPage + delta);
        if (start > 1) range.push(1, '...');
        for (let i = start; i <= end; i++) range.push(i);
        if (end < totalPages) range.push('...', totalPages);
        container.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid var(--color-border);font-size:.8rem;color:var(--color-muted);">
      <span>Mostrando <strong>${from}–${to}</strong> de <strong>${totalRecords}</strong> registros</span>
      <div style="display:flex;gap:4px;align-items:center;">
        <button class="btn btn-secondary" style="padding:6px 10px;font-size:.75rem;" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">← Ant.</button>
        ${range.map(p => p === '...'
            ? `<span style="padding:0 4px;color:var(--color-muted);">…</span>`
            : `<button class="btn ${p === currentPage ? 'btn-primary' : 'btn-secondary'}" style="padding:6px 10px;font-size:.75rem;min-width:34px;" onclick="goToPage(${p})">${p}</button>`
        ).join('')}
        <button class="btn btn-secondary" style="padding:6px 10px;font-size:.75rem;" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">Sig. →</button>
      </div>
    </div>`;
    }

    // ── DROPDOWN TOGGLE ────────────────────────────────────────────────────────
    window.toggleDropdown = function (id) {
        const all = document.querySelectorAll('.action-dropdown');
        const curr = document.getElementById(`dropdown-${id}`);
        if (!curr) return;

        const btn = curr.previousElementSibling;
        const isOpening = curr.style.display === 'none' || !curr.style.display;

        // Cerrar todos los demás
        all.forEach(d => { if (d.id !== `dropdown-${id}`) d.style.display = 'none'; });

        if (isOpening) {
            curr.style.display = 'block';

            // Calcular posición fija para evitar recortes de overflow
            const btnRect = btn.getBoundingClientRect();
            curr.style.position = 'fixed';
            curr.style.margin = '0';

            // Asegurar que el elemento esté renderizado para obtener su altura final
            const dropdownHeight = curr.offsetHeight || 100;
            const spaceBelow = window.innerHeight - btnRect.bottom;

            if (spaceBelow < dropdownHeight + 15 && btnRect.top > dropdownHeight + 15) {
                // Abrir hacia arriba (drop-up)
                curr.style.top = (btnRect.top - dropdownHeight - 5) + 'px';
            } else {
                // Abrir hacia abajo (drop-down)
                curr.style.top = (btnRect.bottom + 5) + 'px';
            }

            curr.style.left = 'auto';
            curr.style.right = (window.innerWidth - btnRect.right) + 'px';
            curr.style.zIndex = '9999';
        } else {
            curr.style.display = 'none';
        }
    };

    // Cerrar dropdowns si se hace scroll para que no floten en position: fixed
    document.addEventListener('scroll', function (e) {
        if (!e.target.closest || !e.target.closest('.action-dropdown')) {
            document.querySelectorAll('.action-dropdown').forEach(d => {
                d.style.display = 'none';
            });
        }
    }, true);


    // ── PAGINATION & FILTERS ──────────────────────────────────────────────────────
    function goToPage(page) {
        currentMeta.currentPage = page;
        const search = document.getElementById('search-input').value.trim();
        const status = document.getElementById('filter-status').value;
        loadEmployees({ page, search, status });
    }

    function setupControls() {
        const searchInput = document.getElementById('search-input');
        const filterStatus = document.getElementById('filter-status');
        const limitSelect = document.getElementById('limit-select');

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                currentMeta.currentPage = 1;
                loadEmployees({ page: 1, search: searchInput.value.trim(), status: filterStatus.value, limit: limitSelect?.value || currentMeta.limit });
            }, 400);
        });

        filterStatus.addEventListener('change', () => {
            currentMeta.currentPage = 1;
            loadEmployees({ page: 1, search: searchInput.value.trim(), status: filterStatus.value, limit: limitSelect?.value || currentMeta.limit });
        });

        if (limitSelect) {
            limitSelect.addEventListener('change', () => {
                currentMeta.limit = parseInt(limitSelect.value);
                currentMeta.currentPage = 1;
                loadEmployees({ page: 1, limit: currentMeta.limit });
            });
        }

        document.getElementById('btn-refresh').addEventListener('click', () => {
            searchInput.value = '';
            filterStatus.value = '';
            currentMeta.currentPage = 1;
            loadEmployees({ page: 1 });
        });
    }

    // ── STATUS / ENTREGA ──────────────────────────────────────────────────────────
    async function changeStatus(e, id) {
        const status = e.target.value;
        try {
            const emp = employees.find(x => String(x.id) === String(id));
            await api.updateStatus(id, status);
            if (emp) { emp.status = status; e.target.className = `badge ${ui.getBadgeClass(status)}`; renderStats(employees, currentMeta); }
        } catch (err) {
            alert(err.message);
            e.target.value = employees.find(x => String(x.id) === String(id))?.status;
        }
    }

    async function changeEntrega(e, id) {
        const forma_entrega = e.target.value;
        try {
            const emp = employees.find(x => String(x.id) === String(id));
            await api.updateStatus(id, emp?.status, forma_entrega);
            if (emp) emp.forma_entrega = forma_entrega;
        } catch (err) { alert(err.message); }
    }

    async function deleteEmployee(id) {
        const emp = employees.find(x => String(x.id) === String(id));
        if (!emp) return;
        // Construir nombre para el mensaje de confirmación
        const apellidos = [emp.primer_apellido, emp.segundo_apellido].filter(Boolean).join(' ') || emp.apellidos || '';
        const nombres = [emp.primer_nombre, emp.segundo_nombre].filter(Boolean).join(' ') || emp.nombres || '';
        const nombreCompleto = apellidos ? `${apellidos}, ${nombres}` : nombres;

        if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
                title: '¿Confirmar eliminación?',
                text: `Registro del funcionario "${nombreCompleto}". Esta acción no se puede deshacer.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            });
            if (!result.isConfirmed) return;
        } else {
            if (!confirm(`¿Eliminar al funcionario "${nombreCompleto}"? Esta acción no se puede deshacer.`)) return;
        }

        try {
            await api.deleteEmployee(id);
            employees = employees.filter(x => String(x.id) !== String(id));
            currentMeta.totalRecords = Math.max(0, (currentMeta.totalRecords || 0) - 1);
            renderTable(employees);
            renderStats(employees, currentMeta);
            showFloatingToast('Funcionario eliminado correctamente.', 'success');
        } catch (err) { showFloatingToast(err.message, 'danger'); }
    }

    function openEditor(id) {
        localStorage.setItem('selected_employee_id', id);
        window.location.href = `editor.html?id=${encodeURIComponent(id)}`;
    }

    function viewEmployee(id) {
        localStorage.setItem('selected_employee_id', id);
        window.location.href = `editor.html?id=${encodeURIComponent(id)}&mode=view`;
    }

    // ── TAREA 4: GERENCIAS ────────────────────────────────────────────────────────
    async function populateGerenciasSelects() {
        const res = await api.getGerencias();
        const gerencias = res.data || [];
        const options = gerencias.map(g => `<option value="${g.nombre}">${g.nombre}</option>`).join('');
        document.querySelectorAll('.gerencia-select').forEach(sel => {
            const currentVal = sel.value;
            sel.innerHTML = `<option value="">Seleccionar gerencia...</option>` + options;
            if (currentVal) sel.value = currentVal;
        });
    }

    function setupGerenciasManager() {
        const btn = document.getElementById('btn-manage-gerencias');
        const modal = document.getElementById('modal-gerencias');
        const close = document.getElementById('btn-close-gerencias');
        if (!btn || !modal) return;

        async function renderGerenciasList() {
            const res = await api.getGerencias();
            const list = document.getElementById('gerencias-list');
            list.innerHTML = (res.data || []).map(g => `
            <div id="ger-item-${g.id}" style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <span style="flex:1;font-size:.875rem;font-weight:500;" id="ger-label-${g.id}">${g.nombre}</span>
                <input type="text" id="ger-edit-${g.id}" value="${g.nombre}"
                       style="flex:1;display:none;padding:4px 8px;border:1px solid var(--color-border);border-radius:6px;font-size:.875rem;" />
                <button onclick="editGerencia(${g.id})" id="ger-btn-edit-${g.id}" title="Editar"
                        style="padding:4px 8px;font-size:.75rem;background:#eff6ff;color:#0284c7;border:1px solid #bae6fd;border-radius:6px;cursor:pointer;">✏️</button>
                <button onclick="saveGerencia(${g.id})" id="ger-btn-save-${g.id}" title="Guardar"
                        style="display:none;padding:4px 8px;font-size:.75rem;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;cursor:pointer;">✔</button>
                <button onclick="removeGerencia(${g.id})" title="Eliminar"
                        style="padding:4px 8px;font-size:.75rem;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;">🗑</button>
            </div>`).join('');
        }

        btn.addEventListener('click', async () => { modal.style.display = 'flex'; await renderGerenciasList(); });
        if (close) close.addEventListener('click', () => { modal.style.display = 'none'; populateGerenciasSelects(); });

        document.getElementById('btn-add-gerencia').addEventListener('click', async () => {
            const input = document.getElementById('new-gerencia-input');
            const name = (input?.value || '').trim();
            if (!name) return;
            try {
                await api.createGerencia(name);
                input.value = '';
                await renderGerenciasList();
                populateGerenciasSelects();
                showFloatingToast(`Gerencia "${name}" creada.`, 'success');
            } catch (err) { showFloatingToast(err.message, 'danger'); }
        });

        window.editGerencia = (id) => {
            document.getElementById(`ger-label-${id}`).style.display = 'none';
            document.getElementById(`ger-edit-${id}`).style.display = 'block';
            document.getElementById(`ger-btn-edit-${id}`).style.display = 'none';
            document.getElementById(`ger-btn-save-${id}`).style.display = 'inline-block';
        };

        window.saveGerencia = async (id) => {
            const nombre = document.getElementById(`ger-edit-${id}`)?.value?.trim();
            if (!nombre) return;
            try {
                await api.updateGerencia(id, nombre);
                await renderGerenciasList();
                populateGerenciasSelects();
                showFloatingToast('Gerencia actualizada.', 'success');
            } catch (err) { showFloatingToast(err.message, 'danger'); }
        };

        window.removeGerencia = async (id) => {
            if (!confirm('¿Eliminar esta gerencia?')) return;
            try {
                await api.deleteGerencia(id);
                await renderGerenciasList();
                populateGerenciasSelects();
                showFloatingToast('Gerencia eliminada.', 'success');
            } catch (err) { showFloatingToast(err.message, 'danger'); }
        };
    }

    // ── MODAL NUEVO EMPLEADO ──────────────────────────────────────────────────────
    function setupModal() {
        const modal = document.getElementById('modal-new');
        document.getElementById('btn-new-employee').addEventListener('click', async () => {
            await populateGerenciasSelects();
            modal.style.display = 'flex';
        });
        document.getElementById('btn-modal-cancel').addEventListener('click', () => {
            modal.style.display = 'none';
            document.getElementById('form-new-employee').reset();
        });
        document.getElementById('form-new-employee').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btn-modal-save');
            const data = Object.fromEntries(new FormData(e.target).entries());

            // ── Validación de campos obligatorios ──────────────────────────────────
            if (!data.cedula || !data.primer_nombre || !data.primer_apellido || !data.cargo || !data.gerencia) {
                ui.showAlert('modal-alert', 'Los campos marcados con * son obligatorios.');
                return;
            }

            // ── TAREA 3: Validación estricta de cédula (solo dígitos) ──────────────
            // La cédula en el nuevo esquema MySQL almacena SOLO el valor numérico.
            // El prefijo V/E proviene del campo 'nacionalidad'.
            const cedulaLimpia = (data.cedula || '').trim().replace(/[^0-9]/g, '');

            if (!cedulaLimpia) {
                ui.showAlert('modal-alert', 'La cédula debe contener solo números (sin prefijos V- o E-).');
                return;
            }
            if (cedulaLimpia.length < 5 || cedulaLimpia.length > 10) {
                ui.showAlert('modal-alert', 'La cédula debe tener entre 5 y 10 dígitos.');
                return;
            }

            // Asignar cédula numérica limpia al payload
            data.cedula = cedulaLimpia;

            ui.setLoading(btn, true, 'Guardando...');
            try {
                await api.createEmployee(data);
                modal.style.display = 'none';
                e.target.reset();
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

    function setupLogout() {
        document.getElementById('btn-logout').addEventListener('click', async () => {
            await api.logout();
            window.location.href = 'login.html';
        });
    }

    // ── AUTO-MATCH AI ─────────────────────────────────────────────────────────────
    function setupAutoMatch() {
        const btn = document.getElementById('btn-auto-match');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            const originalBg = btn.style.background;
            btn.disabled = true; btn.style.opacity = '0.7';
            btn.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px;"></span>Analizando BD...`;
            try {
                const res = await api.autoMatch();
                await loadEmployees({ page: 1 });
                showFloatingToast(res.message || 'Auto-Match completado.', 'success');
            } catch (err) {
                showFloatingToast(err.message || 'Error en Auto-Match.', 'danger');
            } finally {
                btn.disabled = false; btn.style.opacity = '1';
                btn.style.background = originalBg; btn.innerHTML = '✨ Auto-Match AI';
            }
        });
    }

    // ── IMPORTAR NÓMINA (SheetJS) ─────────────────────────────────────────────────
    function setupPayrollImport() {
        const btn = document.getElementById('btn-import-excel');
        const fileInput = document.getElementById('excel-upload');
        if (!btn || !fileInput) return;
        btn.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['xlsx', 'csv'].includes(ext)) { showFloatingToast('Solo .xlsx o .csv', 'danger'); fileInput.value = ''; return; }

            if (typeof XLSX === 'undefined') {
                showFloatingToast('SheetJS no disponible. Usando modo demostración.', 'info');
                try { const r = await api.uploadPayroll(file); await loadEmployees({ page: 1 }); showFloatingToast(r.message, 'success'); }
                catch (err) { showFloatingToast(err.message, 'danger'); }
                finally { fileInput.value = ''; }
                return;
            }

            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span style="display:inline-block;width:12px;height:12px;border:2px solid #94a3b8;border-top-color:var(--color-primary);border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px;"></span>Leyendo...`;
            try {
                const buffer = await file.arrayBuffer();
                const workbook = XLSX.read(buffer, { type: 'array' });
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                if (!sheet) throw new Error('El archivo no contiene hojas.');
                const rows = XLSX.utils.sheet_to_json(sheet, { defval: '' });
                if (!rows.length) throw new Error('La hoja está vacía.');

                const res = await api.uploadPayroll(rows);
                await loadEmployees({ page: 1 });
                showFloatingToast(res.message || 'Procesamiento de nómina completado.', 'success');
            } catch (err) {
                showFloatingToast(err.message || 'Error al procesar el archivo.', 'danger');
            } finally {
                btn.disabled = false; btn.innerHTML = origText; fileInput.value = '';
            }
        });
    }

    // ── TAREA 8: DELEGACIÓN DE PERMISOS ──────────────────────────────────────────
    function setupDelegation() {
        const btn = document.getElementById('btn-delegate-perms');
        const modal = document.getElementById('modal-delegation');
        const close = document.getElementById('btn-close-delegation');
        if (!btn || !modal) return;

        const currentUser = api.getCurrentUser();

        async function renderDelegationModal() {
            const res = await api.getUsers();
            const users = (res.data || []).filter(u => u.username !== currentUser.username);
            const select = document.getElementById('delegation-user');
            if (select) {
                select.innerHTML = users.map(u => {
                    const tempLabel = u.temporary_role ? ` [⚡ Temp: ${u.temporary_role}]` : '';
                    return `<option value="${u.username}">${u.full_name} (@${u.username}) — ${u.role}${tempLabel}</option>`;
                }).join('');
            }
            // Delegaciones activas
            const delegated = users.filter(u => u.temporary_role);
            const container = document.getElementById('current-delegations');
            const list = document.getElementById('delegations-list');
            if (container && list) {
                if (delegated.length) {
                    container.style.display = 'block';
                    list.innerHTML = delegated.map(u => `
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;margin-bottom:6px;font-size:.82rem;">
                    <span><strong>${u.full_name}</strong> (@${u.username}) → <span style="background:#f59e0b;color:#fff;padding:1px 8px;border-radius:3px;font-weight:700;">${u.temporary_role}</span>
                    <span style="color:#94a3b8;font-size:.72rem;"> delegado por ${u.delegated_by}</span></span>
                    <button onclick="revokeDelegation('${u.username}')" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:3px 10px;border-radius:5px;font-size:.72rem;cursor:pointer;font-weight:600;">Revocar</button>
                </div>`).join('');
                } else {
                    container.style.display = 'none';
                }
            }
        }

        btn.addEventListener('click', async () => { modal.style.display = 'flex'; await renderDelegationModal(); });
        if (close) close.addEventListener('click', () => { modal.style.display = 'none'; });

        document.getElementById('form-delegation')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const targetUsername = document.getElementById('delegation-user')?.value;
            const temporaryRole = document.getElementById('delegation-role')?.value;
            if (!targetUsername || !temporaryRole) return;
            try {
                const res = await api.delegateRole(targetUsername, temporaryRole, currentUser.username);
                showFloatingToast(res.message, 'success');
                await renderDelegationModal();
            } catch (err) { ui.showAlert('delegation-alert', err.message); }
        });

        document.getElementById('btn-revoke-delegation')?.addEventListener('click', async () => {
            const targetUsername = document.getElementById('delegation-user')?.value;
            if (!targetUsername) return;
            try {
                const res = await api.revokeDelegate(targetUsername);
                showFloatingToast(res.message, 'success');
                await renderDelegationModal();
            } catch (err) { ui.showAlert('delegation-alert', err.message); }
        });

        window.revokeDelegation = async (username) => {
            try {
                const res = await api.revokeDelegate(username);
                showFloatingToast(res.message, 'success');
                await renderDelegationModal();
            } catch (err) { showFloatingToast(err.message, 'danger'); }
        };
    }


    function showFloatingToast(message, type = 'success') {
        const colors = {
            success: { bg: '#d1fae5', color: '#065f46', border: '#6ee7b7' },
            danger: { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' },
            info: { bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' },
        };
        const c = colors[type] || colors.info;
        const toast = document.createElement('div');
        toast.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;background:${c.bg};color:${c.color};border:1px solid ${c.border};padding:12px 20px;border-radius:10px;font-size:.875rem;font-weight:500;max-width:400px;box-shadow:0 4px 20px rgba(0,0,0,.12);animation:slideInRight .3s ease;`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.transition = 'opacity .4s ease'; toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 4500);
    }

    if (!document.getElementById('dynamic-styles')) {
        const style = document.createElement('style');
        style.id = 'dynamic-styles';
        style.textContent = `
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    `;
        document.head.appendChild(style);
    }

    init();