/**
 * api.js — Cliente HTTP SCI-TSS v2.1
 * =====================================
 * CORRECCIÓN:
 *  - login(): ahora lee res.data (objeto anidado) en lugar de res directamente.
 *    El backend auth.php devuelve: { success, message, data: { id, username, ... } }
 *  - OFFLINE_MODE = false por defecto (producción).
 *  - Sin hardcoding de URLs.
 *
 * @version 2.1.0
 */
'use strict';

// ── CONSTANTES INSTITUCIONALES ────────────────────────────────
const MOCK_LOGO = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI0NSIgZmlsbD0iIzAwMzM2NiIgc3Ryb2tlPSIjZmFjYzE1IiBzdHJva2Utd2lkdGg9IjUiLz48dGV4dCB4PSI1MCIgeT0iNDUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyMiIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+VFNTPC90ZXh0Pjx0ZXh0IHg9IjUwIiB5PSI2MiIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjkiIGZpbGw9IiM5NGEzYjgiIHRleHQtYW5jaG9yPSJtaWRkbGUiPlNFR1VSSUVEQUQ8L3RleHQ+PHRleHQgeD0iNTAiIHk9Ijc0IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iOSIgZmlsbD0iIzk0YTNiOCIgdGV4dC1hbmNob3I9Im1pZGRsZSI+U09DSUFMIDwvdGV4dD48cmVjdCB4PSIxNSIgeT0iODMiIHdpZHRoPSIyMiIgaGVpZ2h0PSI2IiByeD0iMyIgZmlsbD0iI2ZhY2MxNSIvPjxyZWN0IHg9IjM5IiB5PSI4MyIgd2lkdGg9IjIyIiBoZWlnaHQ9IjYiIHJ4PSIzIiBmaWxsPSIjMjU2M2ViIi8+PHJlY3QgeD0iNjMiIHk9IjgzIiB3aWR0aD0iMjIiIGhlaWdodD0iNiIgcng9IjMiIGZpbGw9IiNkYzI2MjYiLz48L3N2Zz4=';
const VALIDATION_BASE_URL = 'https://carnetizacion.tss.gob.ve/validar';

// ── CONFIGURACIÓN ─────────────────────────────────────────────
const OFFLINE_MODE = false;
const API_BASE = '';   // Relativa al servidor actual

// ── NORMALIZACIÓN DE EMPLEADOS ────────────────────────────────
function normalizarEmpleado(emp) {
    if (!emp) return emp;

    const primerNombre = (emp.primer_nombre || '').trim();
    const segundoNombre = (emp.segundo_nombre || '').trim();
    const primerApellido = (emp.primer_apellido || '').trim();
    const segundoApellido = (emp.segundo_apellido || '').trim();

    const nombresCompletos = [primerNombre, segundoNombre].filter(Boolean).join(' ');
    const apellidosCompletos = [primerApellido, segundoApellido].filter(Boolean).join(' ');

    return {
        ...emp,
        nombres: nombresCompletos || emp.nombres || '',
        apellidos: apellidosCompletos || emp.apellidos || '',
        primer_nombre: primerNombre,
        segundo_nombre: segundoNombre,
        primer_apellido: primerApellido,
        segundo_apellido: segundoApellido,
        status: emp.status || emp.estado_carnet || 'Pendiente por Imprimir',
        estado_carnet: emp.estado_carnet || emp.status || 'Pendiente por Imprimir',
        photo_url: emp.photo_url || emp.foto_url || '',
        foto_url: emp.foto_url || emp.photo_url || '',
        gerencia: emp.gerencia || '',
        forma_entrega: emp.forma_entrega || '',
        nivel_permiso: emp.nivel_permiso || 'Nivel 1',
    };
}

// ── UTILIDAD FETCH ────────────────────────────────────────────
async function request(url, method = 'GET', body = null) {
    if (OFFLINE_MODE) {
        return mockRequest(url, method, body);
    }

    const options = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        credentials: 'same-origin',
    };

    // Inyectar CSRF token en peticiones mutantes
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method.toUpperCase())) {
        try {
            const u = api.getCurrentUser();
            if (u && u.csrf_token) {
                options.headers['X-CSRF-Token'] = u.csrf_token;
            }
        } catch (_) { /* Sin token CSRF disponible */ }
    }

    if (body !== null) {
        options.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(API_BASE + url, options);
    } catch (networkErr) {
        throw new Error(
            'Error de conexión: No se pudo alcanzar el servidor. ' +
            'Verifique que Apache y MySQL estén activos en XAMPP.'
        );
    }

    const text = await response.text();

    // Detectar respuesta HTML inesperada (error PHP, 404, etc.)
    if (text.trim().startsWith('<')) {
        console.error('[SCI-TSS API] Respuesta HTML inesperada:', text.substring(0, 300));
        throw new Error(
            `El servidor devolvió HTML en lugar de JSON (HTTP ${response.status}). ` +
            'Revise los logs de Apache en XAMPP Control Panel.'
        );
    }

    let result;
    try {
        result = JSON.parse(text);
    } catch (parseErr) {
        console.error('[SCI-TSS API] JSON inválido recibido:', text.substring(0, 300));
        throw new Error('Respuesta del servidor inválida. Contacte al administrador.');
    }

    if (!result.success) {
        throw new Error(result.message || 'Error en la petición al servidor.');
    }

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
              fill="white" text-anchor="middle" font-weight="700">${initials}</text>
    </svg>`;

    return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));
}

// ── API BRIDGE ────────────────────────────────────────────────
const api = {

    initCsrf: async () => {
        // CSRF se maneja automáticamente via sesión PHP.
        // El token llega en la respuesta de login y se persiste en sessionStorage.
        return true;
    },

    // ── AUTH ──────────────────────────────────────────────────
    /**
     * CORRECCIÓN v2.1:
     * auth.php devuelve: { success, message, data: { id, username, full_name, ... } }
     * Guardamos res.data (no res) en sessionStorage.
     */
    login: async (username, password) => {
        const res = await request('api/auth.php', 'POST', { username, password });

        // El backend devuelve los datos del usuario en res.data
        const userData = res.data || res;
        sessionStorage.setItem('current_user', JSON.stringify(userData));
        return res;
    },

    logout: async () => {
        try {
            await request('api/auth/logout.php', 'POST');
        } catch (_) {
            // Limpieza local aunque falle el servidor
        } finally {
            sessionStorage.removeItem('current_user');
        }
        return { success: true };
    },

    getCurrentUser: () => {
        try {
            return JSON.parse(sessionStorage.getItem('current_user') || '{}');
        } catch (_) {
            return {};
        }
    },

    isAdmin: () => {
        const u = api.getCurrentUser();
        const role = u.effective_role || u.temporary_role || u.role;
        return ['ADMIN', 'COORD'].includes((role || '').toUpperCase());
    },

    // ── USUARIOS / DELEGACIÓN ─────────────────────────────────
    getUsers: async () => request('api/users.php'),

    delegateRole: async (username, tempRole, delegatedBy) =>
        request('api/users.php', 'POST', { action: 'delegate', username, tempRole, delegatedBy }),

    revokeDelegate: async (username) =>
        request('api/users.php', 'POST', { action: 'revoke', username }),

    // ── GESTIÓN DE USUARIOS ───────────────────────────────────────
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
        if (params.page) qs.set('page', params.page);
        if (params.limit) qs.set('limit', params.limit);
        if (params.search) qs.set('search', params.search);
        if (params.status) qs.set('status', params.status);

        if ([...qs].length > 0) url += '?' + qs.toString();

        const res = await request(url);

        if (Array.isArray(res.data)) {
            res.data = res.data.map(normalizarEmpleado);
        } else if (res.data) {
            res.data = normalizarEmpleado(res.data);
        }

        return res;
    },

    createEmployee: async (data) => {
        const payload = { ...data };

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
        const id = formData.get ? formData.get('employee_id') : formData.employee_id;
        const photoBase64 = formData.get ? formData.get('photo_base64') : formData.photo_base64;
        return request('api/employees.php', 'POST', { id, photo_url: photoBase64, foto_url: photoBase64 });
    },

    removePhoto: async (id) =>
        request('api/employees.php', 'POST', { id, photo_url: '', foto_url: '' }),

    autoMatch: async () => request('api/employees.php', 'POST', { action: 'auto_match' }),
    uploadPayroll: async (rows) => request('api/employees.php', 'POST', { action: 'upload_payroll', rows }),
    smartExtraction: async () => request('api/employees.php', 'POST', { action: 'smart_extraction' }),

    // ── ESTADÍSTICAS ──────────────────────────────────────────
    getStats: async () => {
        try {
            return await request('api/stats.php');
        } catch (_) {
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
};

// ── UTILIDADES DE UI ──────────────────────────────────────────
const ui = {
    showAlert(containerId, message, type = 'danger') {
        const el = document.getElementById(containerId);
        if (!el) return;
        el.innerHTML = `<div class="alert alert-${type}"
            style="padding:10px 14px;border-radius:8px;font-size:.85rem;font-weight:500;
                   background:${type === 'danger' ? '#fee2e2' : '#dbeafe'};
                   color:${type === 'danger' ? '#991b1b' : '#1e40af'};
                   border:1px solid ${type === 'danger' ? '#fca5a5' : '#93c5fd'};
                   margin-bottom:10px;">${message}</div>`;
        setTimeout(() => { if (el) el.innerHTML = ''; }, 7000);
    },

    setLoading(btn, loading, text = 'Cargando...') {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn.dataset.original = btn.innerHTML;
            btn.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px;"></span>${text}`;
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
            const date = new Date(d);
            if (isNaN(date.getTime())) return d;
            return date.toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        } catch (_) {
            return d;
        }
    },
};

// ── MODO DEMO / OFFLINE ───────────────────────────────────────
// Solo activo si OFFLINE_MODE = true arriba
let _savedGerencias = null;
let _savedEmployees = null;
try {
    _savedGerencias = JSON.parse(localStorage.getItem('tss_mock_gerencias'));
    _savedEmployees = JSON.parse(localStorage.getItem('tss_mock_employees'));
} catch (_) { /* LocalStorage no disponible */ }

let MOCK_GERENCIAS = _savedGerencias || [
    { id: 1, nombre: 'OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION' },
    { id: 2, nombre: 'DESPACHO' },
    { id: 3, nombre: 'AUDITORIA INTERNA' },
    { id: 4, nombre: 'CONSULTORIA JURIDICA' },
    { id: 5, nombre: 'OFICINA DE PLANIFICACION, ORGANIZACION Y PRESUPUESTO' },
    { id: 6, nombre: 'OFICINA DE ADMINISTRACION Y GESTION INTERNA' },
    { id: 7, nombre: 'GERENCIA GENERAL DE REGISTRO Y AFILIACION' },
    { id: 8, nombre: 'GERENCIA GENERAL DE ESTUDIOS ACTUARIALES Y ECONOMICOS' },
    { id: 9, nombre: 'GERENCIA GENERAL DE INVERSIONES Y GESTION FINANCIERA' },
    { id: 10, nombre: 'OFICINA DE COMUNICACION Y RELACIONES INSTITUCIONALES' },
];
let nextGerenciaId = MOCK_GERENCIAS.length > 0 ? Math.max(...MOCK_GERENCIAS.map(g => g.id)) + 1 : 1;

let MOCK_EMPLOYEES = _savedEmployees || [
    {
        id: 1, cedula: '27798979', nacionalidad: 'V',
        primer_nombre: 'Nohely', segundo_nombre: 'Alexandra',
        primer_apellido: 'Aponte', segundo_apellido: 'Contreras',
        cargo: 'Apoyo Técnico',
        gerencia: 'OFICINA DE COMUNICACION Y RELACIONES INSTITUCIONALES',
        estado_carnet: 'Carnet Impreso', photo_url: '', fecha_ingreso: '2022-03-15',
    },
    {
        id: 2, cedula: '12345678', nacionalidad: 'V',
        primer_nombre: 'Juan', segundo_nombre: 'Alejandro',
        primer_apellido: 'Pérez', segundo_apellido: null,
        cargo: 'Analista de Sistemas',
        gerencia: 'OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION',
        estado_carnet: 'Pendiente por Imprimir', photo_url: '', fecha_ingreso: '2020-01-15',
    },
];
let nextEmpId = MOCK_EMPLOYEES.length > 0 ? Math.max(...MOCK_EMPLOYEES.map(e => e.id)) + 1 : 1;

function saveDB() {
    try {
        localStorage.setItem('tss_mock_gerencias', JSON.stringify(MOCK_GERENCIAS));
        localStorage.setItem('tss_mock_employees', JSON.stringify(MOCK_EMPLOYEES));
    } catch (_) { console.warn('[SCI-TSS] LocalStorage no disponible.'); }
}

async function mockRequest(url, method, body) {
    console.warn('[SCI-TSS] MODO DEMO ACTIVO — datos simulados.');
    await new Promise(r => setTimeout(r, 300));

    if (url.includes('auth.php')) {
        const userData = {
            id: 1, username: body?.username || 'admin',
            full_name: 'Usuario Demo', role: 'ADMIN',
            temporary_role: null, effective_role: 'ADMIN',
            csrf_token: 'demo_csrf_token',
        };
        return { success: true, message: 'Login exitoso (Demo).', data: userData };
    }

    if (url.includes('gerencias.php')) {
        if (method === 'GET') return { success: true, data: [...MOCK_GERENCIAS] };
        if (method === 'POST') {
            if (body?.id) { const g = MOCK_GERENCIAS.find(x => x.id == body.id); if (g) g.nombre = body.nombre; }
            else if (body?.nombre) MOCK_GERENCIAS.push({ id: nextGerenciaId++, nombre: body.nombre });
            saveDB();
            return { success: true, message: 'Operación completada (Demo).' };
        }
        if (method === 'DELETE') {
            const id = new URLSearchParams(url.split('?')[1]).get('id');
            MOCK_GERENCIAS = MOCK_GERENCIAS.filter(g => g.id != id);
            saveDB();
            return { success: true, message: 'Gerencia eliminada (Demo).' };
        }
    }

    if (url.includes('employees.php')) {
        if (method === 'GET') {
            const params = new URLSearchParams(url.split('?')[1] || '');
            const search = (params.get('search') || '').toLowerCase();
            const status = params.get('status') || '';
            const page = parseInt(params.get('page') || '1');
            const limit = parseInt(params.get('limit') || '50');
            const idFilter = params.get('id');

            let lista = MOCK_EMPLOYEES.map(normalizarEmpleado);
            if (idFilter) lista = lista.filter(e => String(e.id) === String(idFilter));
            if (search) lista = lista.filter(e =>
                (e.nombres || '').toLowerCase().includes(search) ||
                (e.apellidos || '').toLowerCase().includes(search) ||
                (e.cedula || '').includes(search)
            );
            if (status) lista = lista.filter(e => e.estado_carnet === status);

            const total = lista.length;
            const totalPages = Math.ceil(total / limit);
            const paginada = lista.slice((page - 1) * limit, page * limit);

            return { success: true, data: paginada, meta: { totalRecords: total, currentPage: page, totalPages, limit } };
        }

        if (method === 'POST') {
            if (body?.action === 'upload_payroll' && body?.rows) {
                let added = 0;
                body.rows.forEach(r => {
                    const ced = String(r['Cédula'] || r['cedula'] || '').replace(/[^0-9]/g, '');
                    if (!ced) return;
                    MOCK_EMPLOYEES.unshift({
                        id: nextEmpId++, cedula: ced, nacionalidad: 'V',
                        primer_nombre: String(r['Primer Nombre'] || r['nombres'] || '').trim(),
                        segundo_nombre: String(r['Segundo Nombre'] || '').trim() || null,
                        primer_apellido: String(r['Primer Apellido'] || r['apellidos'] || '').trim(),
                        segundo_apellido: String(r['Segundo Apellido'] || '').trim() || null,
                        cargo: String(r['Cargo'] || r['cargo'] || '').trim(),
                        gerencia: String(r['Gerencia'] || r['gerencia'] || '').trim(),
                        estado_carnet: 'Pendiente por Imprimir', photo_url: '',
                    });
                    added++;
                });
                if (added > 0) saveDB();
                return { success: true, message: `Nómina importada: ${added} empleado(s) (Demo).` };
            }

            if (body?.action === 'auto_match') return { success: true, message: 'Auto-Match completado (Demo).' };

            if (body?.id) {
                const emp = MOCK_EMPLOYEES.find(e => e.id == body.id);
                if (emp) {
                    Object.keys(body).forEach(k => { if (k !== 'id') emp[k] = body[k]; });
                    saveDB();
                }
                return { success: true, message: 'Empleado actualizado (Demo).' };
            }

            const cedulaLimpia = String(body?.cedula || '').replace(/[^0-9]/g, '');
            MOCK_EMPLOYEES.unshift({
                id: nextEmpId++, cedula: cedulaLimpia, nacionalidad: body?.nacionalidad || 'V',
                primer_nombre: body?.primer_nombre || '', segundo_nombre: body?.segundo_nombre || null,
                primer_apellido: body?.primer_apellido || '', segundo_apellido: body?.segundo_apellido || null,
                cargo: body?.cargo || '', gerencia: body?.gerencia || '',
                estado_carnet: 'Pendiente por Imprimir',
                fecha_ingreso: body?.fecha_ingreso || new Date().toISOString().split('T')[0],
                photo_url: '', nivel_permiso: body?.nivel_permiso || 'Nivel 1',
            });
            saveDB();
            return { success: true, message: 'Empleado registrado (Demo).', data: { id: nextEmpId - 1 } };
        }

        if (method === 'DELETE') {
            const id = new URLSearchParams(url.split('?')[1]).get('id');
            MOCK_EMPLOYEES = MOCK_EMPLOYEES.filter(e => e.id != id);
            saveDB();
            return { success: true, message: 'Empleado eliminado (Demo).' };
        }
    }

    if (url.includes('users.php')) {
        return {
            success: true, data: [
                { id: 1, username: 'admin', full_name: 'Administrador Principal', role: 'ADMIN', temporary_role: null, delegated_by: null, is_locked: false, failed_attempts: 0 },
            ]
        };
    }

    return { success: true, message: 'Respuesta simulada (Demo).' };
}