/**
 * api.js — Cliente HTTP SCI-TSS v3.0
 * =====================================
 * REMEDIACIÓN INTEGRAL:
 *
 * BUGS CORREGIDOS:
 *  1. login(): guardaba res completo en sessionStorage en lugar de res.data
 *     → getCurrentUser() devolvía { success, message, ... } sin username/role
 *     → TODO el sistema de roles/navegación fallaba silenciosamente
 *
 *  2. isAdmin/isCoord/isAdminCoord() buscaban user.role pero tras el fix del
 *     login ahora res.data.role está correctamente disponible.
 *
 *  3. getEmployees() con params.id: el backend puede devolver un objeto o array.
 *     Normalización unificada en normalizarEmpleado().
 *
 * @version 3.0.0
 */
'use strict';

// ── CONSTANTES INSTITUCIONALES ────────────────────────────────
const MOCK_LOGO = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI0NSIgZmlsbD0iIzAwMzM2NiIgc3Ryb2tlPSIjZmFjYzE1IiBzdHJva2Utd2lkdGg9IjUiLz48dGV4dCB4PSI1MCIgeT0iNDUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyMiIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+VFNTPC90ZXh0Pjx0ZXh0IHg9IjUwIiB5PSI2MiIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjkiIGZpbGw9IiM5NGEzYjgiIHRleHQtYW5jaG9yPSJtaWRkbGUiPlNFR1VSSUVEQUQ8L3RleHQ+PHRleHQgeD0iNTAiIHk9Ijc0IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iOSIgZmlsbD0iIzk0YTNiOCIgdGV4dC1hbmNob3I9Im1pZGRsZSI+U09DSUFMIDwvdGV4dD48cmVjdCB4PSIxNSIgeT0iODMiIHdpZHRoPSIyMiIgaGVpZ2h0PSI2IiByeD0iMyIgZmlsbD0iI2ZhY2MxNSIvPjxyZWN0IHg9IjM5IiB5PSI4MyIgd2lkdGg9IjIyIiBoZWlnaHQ9IjYiIHJ4PSIzIiBmaWxsPSIjMjU2M2ViIi8+PHJlY3QgeD0iNjMiIHk9IjgzIiB3aWR0aD0iMjIiIGhlaWdodD0iNiIgcng9IjMiIGZpbGw9IiNkYzI2MjYiLz48L3N2Zz4=';
const VALIDATION_BASE_URL = 'https://carnetizacion.tss.gob.ve/validar';
const API_BASE = '';
const APP_BASE_PATH = window.location.pathname.replace(/\/[^/]*$/, '');

// ── HELPERS de URL de fotos ───────────────────────────────────
function resolvePhotoUrl(rawUrl) {
    if (!rawUrl) return '';
    const value = String(rawUrl).trim();
    if (!value) return '';
    if (/^(data:|blob:|https?:)/i.test(value)) return value;
    if (value.startsWith('/uploads/')) return `${APP_BASE_PATH}${value}`;
    if (value.startsWith('uploads/')) return `${APP_BASE_PATH}/${value}`;
    return value;
}

// ── NORMALIZACIÓN DE EMPLEADOS ────────────────────────────────
function normalizarEmpleado(emp) {
    if (!emp) return emp;
    const pn = (emp.primer_nombre || '').trim();
    const sn = (emp.segundo_nombre || '').trim();
    const pa = (emp.primer_apellido || '').trim();
    const sa = (emp.segundo_apellido || '').trim();
    return {
        ...emp,
        nombres: [pn, sn].filter(Boolean).join(' ') || emp.nombres || '',
        apellidos: [pa, sa].filter(Boolean).join(' ') || emp.apellidos || '',
        primer_nombre: pn,
        segundo_nombre: sn,
        primer_apellido: pa,
        segundo_apellido: sa,
        status: emp.status || emp.estado_carnet || 'Pendiente por Imprimir',
        estado_carnet: emp.estado_carnet || emp.status || 'Pendiente por Imprimir',
        photo_url: resolvePhotoUrl(emp.photo_url || emp.foto_url || ''),
        foto_url: resolvePhotoUrl(emp.foto_url || emp.photo_url || ''),
        gerencia: emp.gerencia || '',
        forma_entrega: emp.forma_entrega || '',
        nivel_permiso: emp.nivel_permiso || 'Nivel 1',
    };
}

// ── UTILIDAD FETCH ────────────────────────────────────────────
async function request(url, method = 'GET', body = null) {
    const options = {
        method,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
    };

    // Inyectar CSRF token en peticiones mutantes
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method.toUpperCase())) {
        try {
            const u = api.getCurrentUser();
            if (u && u.csrf_token) options.headers['X-CSRF-Token'] = u.csrf_token;
        } catch (_) { }
    }

    if (body !== null) options.body = JSON.stringify(body);

    let response;
    try {
        response = await fetch(API_BASE + url, options);
    } catch (networkErr) {
        throw new Error('Error de conexión: verifique que Apache y MySQL estén activos en XAMPP.');
    }

    const text = await response.text();

    // Respuesta HTML inesperada (error PHP, 404 de Apache, etc.)
    if (text.trim().startsWith('<')) {
        console.error('[SCI-TSS] Respuesta HTML inesperada:', text.substring(0, 300));
        throw new Error(`El servidor devolvió HTML en lugar de JSON (HTTP ${response.status}). Revise los logs de Apache.`);
    }

    let result;
    try {
        result = JSON.parse(text);
    } catch (_) {
        console.error('[SCI-TSS] JSON inválido:', text.substring(0, 300));
        throw new Error('Respuesta del servidor inválida. Contacte al administrador.');
    }

    if (!result.success) {
        throw new Error(result.message || 'Error en la petición al servidor.');
    }

    return result;
}

async function requestFormData(url, method = 'POST', formData) {
    const options = {
        method,
        headers: { 'Accept': 'application/json' },
        body: formData,
        credentials: 'same-origin',
    };
    try {
        const u = api.getCurrentUser();
        if (u && u.csrf_token) options.headers['X-CSRF-Token'] = u.csrf_token;
    } catch (_) { }

    let response;
    try {
        response = await fetch(API_BASE + url, options);
    } catch (_) {
        throw new Error('Error de conexión: verifique que Apache y MySQL estén activos en XAMPP.');
    }

    const text = await response.text();
    if (text.trim().startsWith('<')) throw new Error(`El servidor devolvió HTML (HTTP ${response.status}).`);

    let result;
    try { result = JSON.parse(text); } catch (_) { throw new Error('Respuesta del servidor inválida.'); }
    if (!result.success) throw new Error(result.message || 'Error en la petición al servidor.');
    return result;
}

// ── GENERADOR DE AVATARES ─────────────────────────────────────
function makeAvatar(name = '?', bg = null) {
    const words = String(name).trim().split(/\s+/);
    const initials = words.length >= 2
        ? (words[0][0] + words[1][0]).toUpperCase()
        : (words[0][0] || '?').toUpperCase();
    const palette = ['003366', '7c3aed', '0284c7', '059669', 'dc2626', 'd97706'];
    const color = bg || palette[name.charCodeAt(0) % palette.length];
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">
        <rect width="80" height="80" rx="8" fill="#${color}"/>
        <text x="40" y="54" font-family="Arial,Helvetica,sans-serif" font-size="32"
              fill="white" text-anchor="middle" font-weight="700">${initials}</text></svg>`;
    return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));
}

// ══════════════════════════════════════════════════════════════
// API BRIDGE
// ══════════════════════════════════════════════════════════════
const api = {

    initCsrf: async () => true,  // CSRF se gestiona via sesión PHP + respuesta de login

    // ── AUTH ──────────────────────────────────────────────────
    /**
     * CORRECCIÓN CRÍTICA v3.0:
     * El backend retorna { success, message, csrf_token, data: { id, username, ... } }
     * Guardamos res.data (los datos del usuario) en sessionStorage.
     * ANTES se guardaba res completo → getCurrentUser() devolvía campos incorrectos.
     */
    login: async (username, password) => {
        const res = await request('api/auth/login.php', 'POST', { username, password });
        const userData = res.data || {};
        // Asegurar csrf_token disponible en userData para peticiones posteriores
        if (!userData.csrf_token && res.csrf_token) {
            userData.csrf_token = res.csrf_token;
        }
        sessionStorage.setItem('current_user', JSON.stringify(userData));
        return res;
    },

    logout: async () => {
        try { await request('api/auth/logout.php', 'POST'); } catch (_) { }
        finally { sessionStorage.removeItem('current_user'); }
        return { success: true };
    },

    getCurrentUser: () => {
        try { return JSON.parse(sessionStorage.getItem('current_user') || '{}'); }
        catch (_) { return {}; }
    },

    // Helpers de rol — leen effective_role primero (soporta SUDO temporal)
    isAdmin: () => {
        const u = api.getCurrentUser();
        return (u.effective_role || u.role || '').toUpperCase() === 'ADMIN';
    },
    isCoord: () => {
        const u = api.getCurrentUser();
        return (u.effective_role || u.role || '').toUpperCase() === 'COORD';
    },
    isAnalyst: () => {
        const u = api.getCurrentUser();
        return (u.effective_role || u.role || '').toUpperCase() === 'ANALISTA';
    },
    isAdminCoord: () => {
        const u = api.getCurrentUser();
        const role = (u.effective_role || u.role || '').toUpperCase();
        return ['ADMIN', 'COORD'].includes(role);
    },

    // ── USUARIOS ──────────────────────────────────────────────
    getUsers: async () => request('api/users.php'),
    delegateRole: async (username, tempRole, delegatedBy) =>
        request('api/users.php', 'POST', { action: 'delegate', username, tempRole, delegatedBy }),
    revokeDelegate: async (username) =>
        request('api/users.php', 'POST', { action: 'revoke', username }),
    createUser: async (username, password, fullName, role) =>
        request('api/users.php', 'POST', { action: 'create', username, password, full_name: fullName, role }),
    editUser: async (id, fullName, role) =>
        request('api/users.php', 'POST', { action: 'edit', id, full_name: fullName, role }),
    changeUserPassword: async (id, newPassword) =>
        request('api/users.php', 'POST', { action: 'change_password', id, new_password: newPassword }),
    unlockUser: async (id) =>
        request('api/users.php', 'POST', { action: 'unlock', id }),
    deleteUser: async (id) =>
        request('api/users.php', 'POST', { action: 'delete', id }),

    // ── SUDO ──────────────────────────────────────────────────
    grantSudo: async (userId, permissionId, minutes) =>
        request('api/auth/sudo.php', 'POST', { action: 'grant', user_id: userId, permission_id: permissionId, minutes }),
    revokeSudo: async (userId, permissionId) =>
        request('api/auth/sudo.php', 'POST', { action: 'revoke', user_id: userId, permission_id: permissionId }),

    // ── GERENCIAS ─────────────────────────────────────────────
    getGerencias: async () => request('api/gerencias.php'),
    createGerencia: async (nombre) => request('api/gerencias.php', 'POST', { nombre }),
    updateGerencia: async (id, nombre) => request('api/gerencias.php', 'POST', { id, nombre }),
    deleteGerencia: async (id) => request(`api/gerencias.php?id=${id}`, 'DELETE'),

    // ── EMPLEADOS ─────────────────────────────────────────────
    getEmployees: async (params = {}) => {
        let url = 'api/employees.php';
        const qs = new URLSearchParams();
        if (params.id) qs.set('id', params.id);
        if (params.cedula) qs.set('cedula', params.cedula);
        if (params.page) qs.set('page', params.page);
        if (params.limit) qs.set('limit', params.limit);
        if (params.search) qs.set('search', params.search);
        if (params.status) qs.set('status', params.status);
        if ([...qs].length > 0) url += '?' + qs.toString();

        const res = await request(url);

        // Normalizar: puede venir como array (lista) u objeto (individual por id)
        if (Array.isArray(res.data)) {
            res.data = res.data.map(normalizarEmpleado);
        } else if (res.data && typeof res.data === 'object') {
            // Objeto individual → envolver en array para consistencia
            res.data = [normalizarEmpleado(res.data)];
        }

        return res;
    },

    createEmployee: async (data) => {
        const payload = { ...data };
        // Normalizar nombres/apellidos si vienen como campos compuestos
        if (payload.nombres && !payload.primer_nombre) {
            const partes = String(payload.nombres).trim().split(/\s+/);
            payload.primer_nombre = partes[0] || '';
            payload.segundo_nombre = partes.slice(1).join(' ') || null;
            delete payload.nombres;
        }
        if (payload.apellidos && !payload.primer_apellido) {
            const partes = String(payload.apellidos).trim().split(/\s+/);
            payload.primer_apellido = partes[0] || '';
            payload.segundo_apellido = partes.slice(1).join(' ') || null;
            delete payload.apellidos;
        }
        return request('api/employees.php', 'POST', payload);
    },

    updateEmployee: async (id, fields) => request('api/employees.php', 'POST', { id, ...fields }),
    deleteEmployee: async (id) => request(`api/employees.php?id=${id}`, 'DELETE'),

    updateStatus: async (id, status, forma_entrega) => {
        const payload = { id, estado_carnet: status, status };
        if (forma_entrega !== undefined) payload.forma_entrega = forma_entrega;
        return request('api/employees.php', 'POST', payload);
    },

    uploadPhoto: async (formData) => {
        const photoFile = formData.get ? formData.get('photo') : null;
        if (photoFile instanceof File) {
            return requestFormData('api/employees/upload.php', 'POST', formData);
        }
        const id = formData.get ? formData.get('employee_id') : formData.employee_id;
        const photoB64 = formData.get ? formData.get('photo_base64') : formData.photo_base64;
        return request('api/employees.php', 'POST', { id, photo_url: photoB64, foto_url: photoB64 });
    },

    removePhoto: async (id) => request('api/employees.php', 'POST', { id, photo_url: '', foto_url: '' }),
    autoMatch: async () => request('api/employees.php', 'POST', { action: 'auto_match' }),
    uploadPayroll: async (rows) => request('api/employees.php', 'POST', { action: 'upload_payroll', rows }),
    smartExtraction: async () => request('api/employees.php', 'POST', { action: 'smart_extraction' }),

    // ── ESTADÍSTICAS ──────────────────────────────────────────
    getStats: async () => {
        try {
            return await request('api/stats.php');
        } catch (_) {
            // Fallback: calcular desde lista de empleados
            const res = await api.getEmployees({ limit: 200 });
            const list = res.data || [];
            return {
                success: true,
                data: {
                    total: res.meta?.totalRecords || list.length,
                    pendientes: list.filter(e => e.estado_carnet === 'Pendiente por Imprimir').length,
                    impresos: list.filter(e => e.estado_carnet === 'Carnet Impreso').length,
                    entregados: list.filter(e => e.estado_carnet === 'Carnet Entregado').length,
                },
            };
        }
    },

    // ── CONFIGURACIÓN ─────────────────────────────────────────
    getSettings: async () => request('api/settings.php'),
    updateSetting: async (clave, valor, seccion = 'global') =>
        request('api/settings.php', 'POST', { seccion, clave, valor }),
};

// ══════════════════════════════════════════════════════════════
// GLOBAL UI — Navegación y logout
// ══════════════════════════════════════════════════════════════
function initGlobalUI() {
    const user = api.getCurrentUser();
    const role = (user.effective_role || user.role || '').toUpperCase();
    const isAdminCoord = ['ADMIN', 'COORD'].includes(role);
    const isAdmin = role === 'ADMIN';

    // Control de visibilidad de nav-config
    const navConfig = document.getElementById('nav-config');
    if (navConfig) {
        if (isAdminCoord) {
            navConfig.style.opacity = '1';
            navConfig.style.pointerEvents = 'auto';
            if (navConfig.tagName === 'A') navConfig.href = 'config.html';
            navConfig.removeAttribute('title');
        } else {
            navConfig.style.opacity = '0.4';
            navConfig.style.pointerEvents = 'none';
            navConfig.setAttribute('title', 'Acceso denegado: requiere rol Administrador o Coordinador.');
        }
    }

    // Control de visibilidad de nav-editor
    const navEditor = document.getElementById('nav-editor');
    if (navEditor) {
        if (isAdminCoord) {
            navEditor.style.opacity = '1';
            navEditor.style.pointerEvents = 'auto';
            if (navEditor.tagName === 'A') navEditor.href = 'editor.html';
            navEditor.removeAttribute('title');
        } else {
            navEditor.style.opacity = '0.4';
            navEditor.style.pointerEvents = 'none';
            navEditor.setAttribute('title', 'Acceso denegado: solo Administradores y Coordinadores.');
        }
    }

    // Control de visibilidad de nav-usuarios
    const navUsuarios = document.getElementById('nav-usuarios');
    if (navUsuarios) navUsuarios.style.display = isAdmin ? 'flex' : 'none';

    setupGlobalLogout();
}

function setupGlobalLogout() {
    const btnLogout = document.getElementById('btn-logout');
    if (!btnLogout) return;

    // Clonar para eliminar event listeners previos
    const newBtn = btnLogout.cloneNode(true);
    btnLogout.parentNode.replaceChild(newBtn, btnLogout);

    newBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();

        let confirmed = true;
        if (typeof Swal !== 'undefined') {
            const res = await Swal.fire({
                title: '¿Desea cerrar sesión?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#003366',
                cancelButtonColor: '#dc2626',
                confirmButtonText: 'Sí, salir',
                cancelButtonText: 'Cancelar',
            });
            confirmed = res.isConfirmed;
        }

        if (!confirmed) return;
        newBtn.textContent = '...';
        await api.logout();
        window.location.href = 'login.html';
    });
}

// Ejecutar después del DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGlobalUI);
} else {
    initGlobalUI();
}

// ══════════════════════════════════════════════════════════════
// UI UTILITIES
// ══════════════════════════════════════════════════════════════
const ui = {
    showAlert(containerId, message, type = 'danger') {
        const el = document.getElementById(containerId);
        if (!el) return;
        const colors = {
            danger: { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' },
            success: { bg: '#d1fae5', color: '#065f46', border: '#6ee7b7' },
            info: { bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' },
        };
        const c = colors[type] || colors.danger;
        el.innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:.85rem;font-weight:500;
                background:${c.bg};color:${c.color};border:1px solid ${c.border};margin-bottom:10px;">
                ${message}</div>`;
        setTimeout(() => { if (el) el.innerHTML = ''; }, 7000);
    },

    setLoading(btn, loading, text = 'Cargando...') {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn.dataset.original = btn.innerHTML;
            btn.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid
                rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;
                vertical-align:middle;margin-right:6px;"></span>${text}`;
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.original || text;
        }
    },

    getBadgeClass(status) {
        const map = {
            'pendiente por imprimir': 'badge-yellow',
            'carnet impreso': 'badge-blue',
            'carnet entregado': 'badge-green',
        };
        return map[(status || '').toLowerCase()] || 'badge-gray';
    },

    formatDate(d) {
        if (!d) return '—';
        try {
            const date = new Date(d + 'T00:00:00'); // Evitar desfase de zona horaria
            if (isNaN(date.getTime())) return d;
            return date.toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        } catch (_) { return d; }
    },
};