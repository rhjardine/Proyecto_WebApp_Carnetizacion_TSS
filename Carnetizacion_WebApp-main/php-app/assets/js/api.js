/**
 * api.js — Conexión con Backend PHP & PostgreSQL
 */
'use strict';

const MOCK_LOGO = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI0NSIgZmlsbD0iIzAwMzM2NiIgc3Ryb2tlPSIjZmFjYzE1IiBzdHJva2Utd2lkdGg9IjUiLz48dGV4dCB4PSI1MCIgeT0iNDUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyMiIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+VFNTPC90ZXh0Pjx0ZXh0IHg9IjUwIiB5PSI2MiIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjkiIGZpbGw9IiM5NGEzYjgiIHRleHQtYW5jaG9yPSJtaWRkbGUiPlNFR1VSSUVEQUQ8L3RleHQ+PHRleHQgeD0iNTAiIHk9Ijc0IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iOSIgZmlsbD0iIzk0YTNiOCIgdGV4dC1hbmNob3I9Im1pZGRsZSI+U09DSUFMIDwvdGV4dD48cmVjdCB4PSIxNSIgeT0iODMiIHdpZHRoPSIyMiIgaGVpZ2h0PSI2IiByeD0iMyIgZmlsbD0iI2ZhY2MxNSIvPjxyZWN0IHg9IjM5IiB5PSI4MyIgd2lkdGg9IjIyIiBoZWlnaHQ9IjYiIHJ4PSIzIiBmaWxsPSIjMjU2M2ViIi8+PHJlY3QgeD0iNjMiIHk9IjgzIiB3aWR0aD0iMjIiIGhlaWdodD0iNiIgcng9IjMiIGZpbGw9IiNkYzI2MjYiLz48L3N2Zz4=';
const VALIDATION_BASE_URL = 'https://carnetizacion.tss.gob.ve/validar';

// ── MODO DEMO / OFFLINE ───────────────────────────────────────────────────────
// Cambie a 'true' si el servidor PHP no está disponible para la presentación
const OFFLINE_MODE = true;

// ── UTILIDAD FETCH ───────────────────────────────────────────────────────────
async function request(url, method = 'GET', body = null) {
    if (OFFLINE_MODE) {
        return mockRequest(url, method, body);
    }
    const options = {
        method,
        headers: { 'Content-Type': 'application/json' }
    };
    if (body) options.body = JSON.stringify(body);

    const response = await fetch(url, options);
    const text = await response.text();

    let result;
    try {
        result = JSON.parse(text);
    } catch (e) {
        console.error("Error al parsear JSON. Respuesta recibida:", text);
        if (text.trim().startsWith('<')) {
            throw new Error('El servidor devolvió una página HTML en lugar de datos. Esto suele ocurrir si el archivo PHP no existe (404) o hay un error de servidor (500). Verifique que Apache/PHP estén corriendo.');
        }
        throw new Error('Error de formato en la respuesta del servidor: ' + e.message);
    }

    if (!result.success) throw new Error(result.message || 'Error en la petición');
    return result;
}


// ── GENERADOR DE AVATARES ────────────────────────────────────────────────────
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

// ── API BRIDGE ───────────────────────────────────────────────────────────────
const api = {
    initCsrf: async () => true,

    // ── AUTH ─────────────────
    login: async (username, password) => {
        const res = await request('api/auth.php', 'POST', { username, password });
        sessionStorage.setItem('current_user', JSON.stringify(res.data));
        return res;
    },

    logout: async () => {
        sessionStorage.removeItem('current_user');
        return { success: true };
    },

    getCurrentUser: () => {
        try { return JSON.parse(sessionStorage.getItem('current_user') || '{}'); }
        catch (_) { return {}; }
    },

    isAdmin: () => {
        const u = api.getCurrentUser();
        const role = u.effective_role || u.role;
        return role === 'ADMIN';
    },

    // ── USUARIOS / ROLES ─────
    getUsers: async () => request('api/users.php'),

    delegateRole: async (username, tempRole, delegatedBy) =>
        request('api/users.php', 'POST', { action: 'delegate', username, tempRole, delegatedBy }),

    revokeDelegate: async (username) =>
        request('api/users.php', 'POST', { action: 'revoke', username }),

    // ── GERENCIAS ────────────
    getGerencias: async () => request('api/gerencias.php'),

    createGerencia: async (nombre) => request('api/gerencias.php', 'POST', { nombre }),

    updateGerencia: async (id, nombre) => request('api/gerencias.php', 'POST', { id, nombre }),

    deleteGerencia: async (id) => request(`api/gerencias.php?id=${id}`, 'DELETE'),

    // ── EMPLEADOS ───────────
    getEmployees: async (params = {}) => {
        // Adaptación sencilla de filtros para el backend real
        let url = 'api/employees.php';
        if (params.id) url += `?id=${params.id}`;
        return request(url);
    },

    createEmployee: async (data) => request('api/employees.php', 'POST', data),

    updateEmployee: async (id, fields) => request('api/employees.php', 'POST', { id, ...fields }),

    deleteEmployee: async (id) => request(`api/employees.php?id=${id}`, 'DELETE'),

    updateStatus: async (id, status, forma_entrega) =>
        request('api/employees.php', 'POST', { id, status, forma_entrega }),

    uploadPhoto: async (form) => {
        const id = form.get('employee_id');
        const photoBase64 = form.get('photo_base64');
        const emp = MOCK_EMPLOYEES.find(e => String(e.id) === String(id));
        if (emp) {
            if (photoBase64) {
                emp.photo_url = photoBase64;
            } else {
                // Fallback si no envian foto pero llaman al endpoint
                emp.photo_url = makeAvatar((emp.nombres || emp.nombre) + ' ' + (emp.apellidos || ''), '10b981');
            }
            // En API real hace POST api/employees.php. Aquí simulamos offline y guardamos.
            if (typeof saveDB === 'function') saveDB();
        }
        return { success: true, message: 'Foto procesada exitosamente.' };
    },

    removePhoto: async (id) => request('api/employees.php', 'POST', { id, photo_url: '' }),

    // ── IA ─────────────────
    autoMatch: async () => {
        // Implementación futura en PHP. Por ahora simulamos éxito inmediato.
        return { success: true, message: 'Análisis completado (Simulado)' };
    },

    uploadPayroll: async (rows) => request('api/employees.php', 'POST', { action: 'upload_payroll', rows }),

    smartExtraction: async (file) => {
        return { success: true, data: { nombres: 'Extraído', apellidos: 'IA' }, message: 'Datos recuperados' };
    },

    getStats: async () => {
        // En prod real llamar a un api/stats.php, por ahora enviamos valores base
        const res = await api.getEmployees();
        const list = res.data || [];
        return {
            success: true,
            data: {
                total: list.length,
                pendientes: list.filter(e => e.status === 'Pendiente por Imprimir').length,
                impresos: list.filter(e => e.status === 'Carnet Impreso').length,
                entregados: list.filter(e => e.status === 'Carnet Entregado').length,
            }
        };
    }
};

// ── UTILIDADES DE UI ─────────────────────────────────────────────────────────
const ui = {
    showAlert(containerId, message, type = 'danger') {
        const el = document.getElementById(containerId);
        if (!el) return;
        el.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
        setTimeout(() => { el.innerHTML = ''; }, 6000);
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
            'Pendiente por Imprimir': 'badge-yellow',
            'Carnet Impreso': 'badge-blue',
            'Carnet Entregado': 'badge-green',
        };
        return map[status] || 'badge-gray';
    },

    formatDate(d) {
        if (!d) return '—';
        return new Date(d).toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }
};

// ── LÓGICA MOCK PARA PRESENTACIÓN SIN BACKEND ────────────────────────────────
let _savedGerencias = null;
let _savedEmployees = null;
try {
    _savedGerencias = JSON.parse(localStorage.getItem('tss_mock_gerencias'));
    _savedEmployees = JSON.parse(localStorage.getItem('tss_mock_employees'));
} catch (e) { }

let MOCK_GERENCIAS = _savedGerencias || [
    { id: 1, nombre: "OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION" },
    { id: 2, nombre: "DESPACHO" },
    { id: 3, nombre: "AUDITORIA INTERNA" },
    { id: 4, nombre: "CONSULTORIA JURIDICA" },
    { id: 5, nombre: "OFICINA DE PLANIFICACION, ORGANIZACION Y PRESUPUESTO" },
    { id: 6, nombre: "OFICINA DE ADMINISTRACION Y GESTION INTERNA" },
    { id: 7, nombre: "GERENCIA GENERAL DE REGISTRO Y AFILIACIÓN" },
    { id: 8, nombre: "GERENCIA GENERAL DE ESTUDIOS ACTUARIALES Y ECONOMICOS" },
    { id: 9, nombre: "GERENCIA GENERAL DE INVERSIONES Y GESTION FINANCIERA" },
    { id: 10, nombre: "OFICINA DE RELACIONES INTERINSTITUCIONALES" }
];
let nextGerenciaId = MOCK_GERENCIAS.length > 0 ? Math.max(...MOCK_GERENCIAS.map(g => g.id)) + 1 : 1;

let MOCK_EMPLOYEES = _savedEmployees || [
    { id: 1, cedula: 'V-12345678', nombres: 'Juan Alejandro', apellidos: 'Pérez', cargo: 'Analista de Sistemas', gerencia: 'OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION', status: 'Pendiente por Imprimir', photo_url: '' },
    { id: 2, cedula: 'V-87654321', nombres: 'María Victoria', apellidos: 'Gómez', cargo: 'Coordinador', gerencia: 'FINANZAS', status: 'Carnet Impreso', photo_url: '' }
];
let nextEmpId = MOCK_EMPLOYEES.length > 0 ? Math.max(...MOCK_EMPLOYEES.map(e => e.id)) + 1 : 1;

function saveDB() {
    try {
        localStorage.setItem('tss_mock_gerencias', JSON.stringify(MOCK_GERENCIAS));
        localStorage.setItem('tss_mock_employees', JSON.stringify(MOCK_EMPLOYEES));
    } catch (e) { console.warn('LocalStorage no disponible'); }
}

async function mockRequest(url, method, body) {
    console.warn("MODO DEMO ACTIVO: Usando datos simulados.");
    await new Promise(r => setTimeout(r, 600)); // Simular latencia

    if (url.includes('auth.php')) {
        return { success: true, data: { username: body.username, role: 'ADMIN', full_name: 'Usuario Demo' } };
    }
    if (url.includes('gerencias.php')) {
        if (method === 'GET') {
            return { success: true, data: [...MOCK_GERENCIAS] };
        } else if (method === 'POST') {
            if (body && body.id) {
                const g = MOCK_GERENCIAS.find(x => x.id == body.id);
                if (g) g.nombre = body.nombre;
            } else if (body && body.nombre) {
                MOCK_GERENCIAS.push({ id: nextGerenciaId++, nombre: body.nombre });
            }
            saveDB();
            return { success: true, message: 'Operación simulada con éxito' };
        } else if (method === 'DELETE') {
            const id = new URLSearchParams(url.split('?')[1]).get('id');
            MOCK_GERENCIAS = MOCK_GERENCIAS.filter(g => g.id != id);
            saveDB();
            return { success: true, message: 'Gerencia eliminada' };
        }
    }
    if (url.includes('employees.php')) {
        if (method === 'GET') {
            return {
                success: true,
                data: [...MOCK_EMPLOYEES],
                meta: { totalRecords: MOCK_EMPLOYEES.length, currentPage: 1, totalPages: 1, limit: 50 }
            };
        }
        if (method === 'POST') {
            if (body && body.action === 'upload_payroll' && body.rows) {
                let added = 0;
                body.rows.forEach(r => {
                    const ced = r['Cédula'] || r['cedula'] || r['CI'] || '';
                    if (!ced) return;
                    MOCK_EMPLOYEES.unshift({
                        id: nextEmpId++,
                        cedula: String(ced).trim().toUpperCase(),
                        nombres: String(r['Nombres'] || r['nombres'] || '').trim(),
                        apellidos: String(r['Apellidos'] || r['apellidos'] || '').trim(),
                        cargo: String(r['Cargo'] || r['cargo'] || '').trim(),
                        gerencia: String(r['Gerencia'] || r['gerencia'] || '').trim(),
                        status: 'Pendiente por Imprimir'
                    });
                    added++;
                });
                if (added > 0) saveDB();
                return { success: true, message: added > 0 ? `Nómina importada: ${added} empleados.` : 'No se importaron empleados.' };
            }
            if (body && !body.id) { // Create
                MOCK_EMPLOYEES.unshift({
                    id: nextEmpId++,
                    ...body,
                    status: 'Pendiente por Imprimir'
                });
                saveDB();
            } else if (body && body.id) { // Update
                const emp = MOCK_EMPLOYEES.find(e => e.id == body.id);
                if (emp) {
                    Object.assign(emp, body);
                    saveDB();
                }
            }
        }
        if (method === 'DELETE') {
            const id = new URLSearchParams(url.split('?')[1]).get('id');
            MOCK_EMPLOYEES = MOCK_EMPLOYEES.filter(e => e.id != id);
            saveDB();
            return { success: true, message: 'Empleado eliminado' };
        }
        return { success: true, message: 'Operación simulada con éxito' };
    }
    if (url.includes('users.php')) {
        return {
            success: true, data: [
                { id: 1, username: 'admin', full_name: 'Admin SCI-TSS', role: 'ADMIN', is_locked: false }
            ]
        };
    }
    return { success: true, message: 'Respuesta simulada' };
}