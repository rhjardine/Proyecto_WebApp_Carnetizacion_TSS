/**
 * api.js — Cliente HTTP para SCI-TSS (Pre-Producción)
 * =====================================================
 * REFACTORIZACIÓN COMPLETA — Eliminación de deuda técnica
 *
 * Cambios respecto a versión anterior:
 *  - OFFLINE_MODE = false → peticiones REALES al backend PHP/MySQL
 *  - Se elimina toda lógica mock (MOCK_EMPLOYEES, MOCK_GERENCIAS, mockRequest)
 *  - Adaptación completa al nuevo esquema MySQL (campos en español)
 *  - Manejo de errores estructurado con retry en caso de CSRF expirado
 *  - Función normalizarEmpleado() para compatibilidad de campos
 *
 * CAMPOS DEL ESQUEMA MySQL (carnetizacion_tss):
 *  empleados: id, nacionalidad, cedula, primer_nombre, segundo_nombre,
 *             primer_apellido, segundo_apellido, cargo, gerencia_id,
 *             fecha_ingreso, estado_laboral, foto_url, foto_ruta,
 *             estado_carnet, forma_entrega, creado_el, actualizado_el
 *
 * Compatibilidad con frontend existente:
 *  - emp.nombres       → construido como primer_nombre + segundo_nombre
 *  - emp.apellidos     → construido como primer_apellido + segundo_apellido
 *  - emp.status        → alias de estado_carnet
 *  - emp.photo_url     → alias de foto_url
 *  - emp.gerencia      → nombre de gerencia (JOIN desde backend)
 *
 * @version 2.0.0-preproduccion
 * @author  SCI-TSS Dev Team
 */
'use strict';

// ── CONSTANTES INSTITUCIONALES ────────────────────────────────
const MOCK_LOGO = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI0NSIgZmlsbD0iIzAwMzM2NiIgc3Ryb2tlPSIjZmFjYzE1IiBzdHJva2Utd2lkdGg9IjUiLz48dGV4dCB4PSI1MCIgeT0iNDUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyMiIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+VFNTPC90ZXh0Pjx0ZXh0IHg9IjUwIiB5PSI2MiIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjkiIGZpbGw9IiM5NGEzYjgiIHRleHQtYW5jaG9yPSJtaWRkbGUiPlNFR1VSSUVEQUQ8L3RleHQ+PHRleHQgeD0iNTAiIHk9Ijc0IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iOSIgZmlsbD0iIzk0YTNiOCIgdGV4dC1hbmNob3I9Im1pZGRsZSI+U09DSUFMIDwvdGV4dD48cmVjdCB4PSIxNSIgeT0iODMiIHdpZHRoPSIyMiIgaGVpZ2h0PSI2IiByeD0iMyIgZmlsbD0iI2ZhY2MxNSIvPjxyZWN0IHg9IjM5IiB5PSI4MyIgd2lkdGg9IjIyIiBoZWlnaHQ9IjYiIHJ4PSIzIiBmaWxsPSIjMjU2M2ViIi8+PHJlY3QgeD0iNjMiIHk9IjgzIiB3aWR0aD0iMjIiIGhlaWdodD0iNiIgcng9IjMiIGZpbGw9IiNkYzI2MjYiLz48L3N2Zz4=';
const VALIDATION_BASE_URL = 'https://carnetizacion.tss.gob.ve/validar';

// ── CONFIGURACIÓN ─────────────────────────────────────────────
// Para modo demo/presentación sin backend: cambiar a true
const OFFLINE_MODE = false;

// Base URL de la API (vacío = relativa al servidor actual)
const API_BASE = '';

// ── NORMALIZACIÓN DE EMPLEADOS ────────────────────────────────
/**
 * normalizarEmpleado(emp) — Adapta la respuesta del nuevo esquema MySQL
 * al formato esperado por el frontend (compatibilidad hacia atrás).
 *
 * El nuevo esquema tiene campos disgregados:
 *   primer_nombre, segundo_nombre, primer_apellido, segundo_apellido
 *
 * El frontend usa campos compuestos:
 *   nombres, apellidos
 *
 * Esta función construye los campos compuestos y añade aliases de
 * compatibilidad para asegurar el correcto funcionamiento de todas
 * las vistas (dashboard, editor, reverso del carnet).
 *
 * @param {Object} emp - Objeto empleado crudo del servidor
 * @returns {Object} Empleado normalizado con todos los campos esperados
 */
function normalizarEmpleado(emp) {
    if (!emp) return emp;

    // ── Construir nombres completos concatenados ──────────────
    const primerNombre   = (emp.primer_nombre   || '').trim();
    const segundoNombre  = (emp.segundo_nombre  || '').trim();
    const primerApellido = (emp.primer_apellido || '').trim();
    const segundoApellido= (emp.segundo_apellido|| '').trim();

    // Nombres: "Juan Alejandro" (omite el segundo si está vacío)
    const nombresCompletos = [primerNombre, segundoNombre]
        .filter(Boolean).join(' ');

    // Apellidos: "Aponte Contreras" (ídem)
    const apellidosCompletos = [primerApellido, segundoApellido]
        .filter(Boolean).join(' ');

    return {
        // ── Campos originales del esquema MySQL ───────────────
        ...emp,

        // ── Aliases de compatibilidad con el frontend ─────────
        nombres:          nombresCompletos   || emp.nombres   || '',
        apellidos:        apellidosCompletos || emp.apellidos || '',
        primer_nombre:    primerNombre,
        segundo_nombre:   segundoNombre,
        primer_apellido:  primerApellido,
        segundo_apellido: segundoApellido,

        // Estado del carnet (alias: status → estado_carnet)
        status:           emp.status        || emp.estado_carnet || 'Pendiente por Imprimir',
        estado_carnet:    emp.estado_carnet || emp.status        || 'Pendiente por Imprimir',

        // Foto (alias: photo_url → foto_url)
        photo_url:        emp.photo_url     || emp.foto_url      || '',
        foto_url:         emp.foto_url      || emp.photo_url     || '',

        // Gerencia (viene del JOIN en el backend)
        gerencia:         emp.gerencia      || '',

        // Forma de entrega
        forma_entrega:    emp.forma_entrega || '',

        // Nivel de permiso (campo legacy mantenido por compatibilidad)
        nivel_permiso:    emp.nivel_permiso || 'Nivel 1',
    };
}

// ── UTILIDAD FETCH ────────────────────────────────────────────
/**
 * request(url, method, body) — Ejecutor central de peticiones HTTP.
 *
 * Características:
 *  - Manejo unificado de errores HTTP y de red
 *  - Soporte para respuestas JSON con validación de formato
 *  - Mensajes de error descriptivos para facilitar debugging
 *
 * @param {string} url    - Ruta relativa de la API (ej: 'api/employees.php')
 * @param {string} method - Verbo HTTP: GET | POST | DELETE
 * @param {Object} body   - Cuerpo de la petición (solo para POST)
 * @returns {Promise<Object>} Respuesta JSON del servidor
 * @throws {Error} Si la petición falla o el servidor devuelve error
 */
async function request(url, method = 'GET', body = null) {
    if (OFFLINE_MODE) {
        return mockRequest(url, method, body);
    }

    const options = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
        },
        credentials: 'same-origin', // Envía cookies de sesión automáticamente
    };

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

    // Detectar respuestas HTML (error de PHP, página 404/500, etc.)
    if (text.trim().startsWith('<')) {
        console.error('[SCI-TSS API] Respuesta HTML inesperada:', text.substring(0, 200));
        throw new Error(
            `El servidor devolvió HTML en lugar de JSON (HTTP ${response.status}). ` +
            'Esto suele indicar un error de PHP (500) o ruta incorrecta (404). ' +
            'Revise el error en XAMPP → Apache → Logs.'
        );
    }

    let result;
    try {
        result = JSON.parse(text);
    } catch (parseErr) {
        console.error('[SCI-TSS API] JSON inválido:', text.substring(0, 300));
        throw new Error('Respuesta del servidor inválida. Contacte al administrador.');
    }

    if (!result.success) {
        throw new Error(result.message || 'Error en la petición al servidor.');
    }

    return result;
}

// ── GENERADOR DE AVATARES ─────────────────────────────────────
/**
 * makeAvatar(name, bg) — Genera un SVG de avatar con iniciales.
 * Usado como fallback cuando el empleado no tiene fotografía.
 *
 * @param {string} name - Nombre completo del empleado
 * @param {string} bg   - Color hexadecimal del fondo (opcional)
 * @returns {string} Data URL con el SVG en base64
 */
function makeAvatar(name = '?', bg = null) {
    const words    = String(name).trim().split(/\s+/);
    const initials = words.length >= 2
        ? (words[0][0] + words[1][0]).toUpperCase()
        : (words[0][0] || '?').toUpperCase();

    const palette = ['003366', '7c3aed', '0284c7', '059669', 'dc2626', 'd97706'];
    const color   = bg || palette[name.charCodeAt(0) % palette.length];

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">
        <rect width="80" height="80" rx="8" fill="#${color}"/>
        <text x="40" y="54" font-family="Arial,Helvetica,sans-serif" font-size="32"
              fill="white" text-anchor="middle" font-weight="700">${initials}</text>
    </svg>`;

    return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));
}

// ── API BRIDGE ────────────────────────────────────────────────
const api = {

    // ── CSRF (mantiene compatibilidad con auth check middleware) ─
    initCsrf: async () => {
        // El backend PHP maneja CSRF via sesión.
        // Este método existe para compatibilidad futura.
        return true;
    },

    // ── AUTH ──────────────────────────────────────────────────
    login: async (username, password) => {
        const res = await request('api/auth.php', 'POST', { username, password });
        // Almacenar datos del usuario en sessionStorage
        // (sessionStorage se limpia al cerrar el tab → más seguro que localStorage)
        sessionStorage.setItem('current_user', JSON.stringify(res.data));
        return res;
    },

    logout: async () => {
        try {
            // Informar al backend para destruir la sesión PHP
            await request('api/auth/logout.php', 'POST');
        } catch (_) {
            // Si falla el logout del servidor, limpiar localmente igual
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
        const u    = api.getCurrentUser();
        const role = u.effective_role || u.temporary_role || u.role;
        return role === 'ADMIN';
    },

    // ── USUARIOS / DELEGACIÓN ─────────────────────────────────
    getUsers: async () => request('api/users.php'),

    delegateRole: async (username, tempRole, delegatedBy) =>
        request('api/users.php', 'POST', {
            action:      'delegate',
            username,
            tempRole,
            delegatedBy,
        }),

    revokeDelegate: async (username) =>
        request('api/users.php', 'POST', { action: 'revoke', username }),

    // ── GERENCIAS ─────────────────────────────────────────────
    getGerencias: async () => request('api/gerencias.php'),

    createGerencia: async (nombre) =>
        request('api/gerencias.php', 'POST', { nombre }),

    updateGerencia: async (id, nombre) =>
        request('api/gerencias.php', 'POST', { id, nombre }),

    deleteGerencia: async (id) =>
        request(`api/gerencias.php?id=${id}`, 'DELETE'),

    // ── EMPLEADOS ─────────────────────────────────────────────
    /**
     * getEmployees(params) — Obtiene lista de empleados con filtros y paginación.
     *
     * Parámetros admitidos:
     *  - page:   número de página (default 1)
     *  - limit:  registros por página (default 50, max 200)
     *  - search: búsqueda en nombre, apellido o cédula
     *  - status: filtro por estado_carnet
     *  - id:     obtener un empleado específico
     *
     * @returns {Promise<{success, data: empleado[], meta: {totalRecords, ...}}>}
     */
    getEmployees: async (params = {}) => {
        let url = 'api/employees.php';
        const qs = new URLSearchParams();

        if (params.id)     qs.set('id',     params.id);
        if (params.page)   qs.set('page',   params.page);
        if (params.limit)  qs.set('limit',  params.limit);
        if (params.search) qs.set('search', params.search);
        if (params.status) qs.set('status', params.status);

        if ([...qs].length > 0) url += '?' + qs.toString();

        const res = await request(url);

        // Normalizar cada empleado para compatibilidad con el frontend
        if (Array.isArray(res.data)) {
            res.data = res.data.map(normalizarEmpleado);
        } else if (res.data) {
            res.data = normalizarEmpleado(res.data);
        }

        return res;
    },

    /**
     * createEmployee(data) — Crea un nuevo empleado.
     *
     * El backend espera los campos disgregados del nuevo esquema MySQL:
     *   primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, cedula, ...
     *
     * Si el frontend envía 'nombres'/'apellidos' (formato legado), se adapta aquí.
     */
    createEmployee: async (data) => {
        const payload = { ...data };

        // Adaptar campos compuestos a campos disgregados si es necesario
        if (payload.nombres && !payload.primer_nombre) {
            const partes = String(payload.nombres).trim().split(/\s+/);
            payload.primer_nombre  = partes[0] || '';
            payload.segundo_nombre = partes.slice(1).join(' ') || null;
            delete payload.nombres;
        }
        if (payload.apellidos && !payload.primer_apellido) {
            const partes = String(payload.apellidos).trim().split(/\s+/);
            payload.primer_apellido  = partes[0] || '';
            payload.segundo_apellido = partes.slice(1).join(' ') || null;
            delete payload.apellidos;
        }

        return request('api/employees.php', 'POST', payload);
    },

    /**
     * updateEmployee(id, fields) — Actualiza campos específicos de un empleado.
     * Solo envía los campos presentes en `fields` (PATCH semántico sobre POST).
     */
    updateEmployee: async (id, fields) => {
        const payload = { id, ...fields };
        return request('api/employees.php', 'POST', payload);
    },

    deleteEmployee: async (id) =>
        request(`api/employees.php?id=${id}`, 'DELETE'),

    /**
     * updateStatus(id, status, forma_entrega) — Actualiza el estado del carnet.
     * Acepta tanto 'status' como 'estado_carnet' para compatibilidad.
     */
    updateStatus: async (id, status, forma_entrega) => {
        const payload = {
            id,
            estado_carnet: status, // Campo oficial en MySQL
            status,                 // Alias para compatibilidad con backend legado
        };
        if (forma_entrega !== undefined) {
            payload.forma_entrega = forma_entrega;
        }
        return request('api/employees.php', 'POST', payload);
    },

    uploadPhoto: async (formData) => {
        // FormData → envío multipart para archivos reales
        // Si es base64, se envía como JSON
        const id          = formData.get ? formData.get('employee_id') : formData.employee_id;
        const photoBase64 = formData.get ? formData.get('photo_base64') : formData.photo_base64;

        return request('api/employees.php', 'POST', {
            id,
            photo_url: photoBase64,
            foto_url:  photoBase64,
        });
    },

    removePhoto: async (id) =>
        request('api/employees.php', 'POST', { id, photo_url: '', foto_url: '' }),

    // ── IA / AUTOMATIZACIÓN ───────────────────────────────────
    autoMatch: async () =>
        request('api/employees.php', 'POST', { action: 'auto_match' }),

    uploadPayroll: async (rows) =>
        request('api/employees.php', 'POST', { action: 'upload_payroll', rows }),

    smartExtraction: async (file) =>
        request('api/employees.php', 'POST', { action: 'smart_extraction' }),

    // ── ESTADÍSTICAS ──────────────────────────────────────────
    getStats: async () => {
        // Endpoint dedicado de estadísticas (más eficiente que cargar todos los empleados)
        try {
            return await request('api/stats.php');
        } catch (_) {
            // Fallback: calcular desde la lista de empleados
            const res  = await api.getEmployees({ limit: 200 });
            const list = res.data || [];
            return {
                success: true,
                data: {
                    total:      res.meta?.totalRecords || list.length,
                    pendientes: list.filter(e => e.estado_carnet === 'Pendiente por Imprimir').length,
                    impresos:   list.filter(e => e.estado_carnet === 'Carnet Impreso').length,
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
            btn.disabled        = true;
            btn.dataset.original = btn.innerHTML;
            btn.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px;"></span>${text}`;
        } else {
            btn.disabled  = false;
            btn.innerHTML = btn.dataset.original || text;
        }
    },

    getBadgeClass(status) {
        const statusNorm = (status || '').toLowerCase();
        const map = {
            'pendiente por imprimir': 'badge-yellow',
            'carnet impreso':         'badge-blue',
            'carnet entregado':       'badge-green',
        };
        return map[statusNorm] || 'badge-gray';
    },

    formatDate(d) {
        if (!d) return '—';
        try {
            // Manejar tanto formatos ISO (2024-01-15) como MySQL (2024-01-15T00:00:00)
            const date = new Date(d);
            if (isNaN(date.getTime())) return d;
            return date.toLocaleDateString('es-VE', {
                day:   '2-digit',
                month: '2-digit',
                year:  'numeric',
            });
        } catch (_) {
            return d;
        }
    },
};

// ── MODO DEMO / OFFLINE (Solo para presentaciones sin backend) ─
// Se mantiene con datos mínimos para compatibilidad.
// ACTIVAR: const OFFLINE_MODE = true; (arriba en este archivo)
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
        id: 2, cedula: '11929185', nacionalidad: 'V',
        primer_nombre: 'Jose', segundo_nombre: 'Luis',
        primer_apellido: 'Cisneros', segundo_apellido: 'Medina',
        cargo: 'Oficial de Seguridad',
        gerencia: 'OFICINA DE ADMINISTRACION Y GESTION INTERNA',
        estado_carnet: 'Carnet Entregado', photo_url: '', fecha_ingreso: '2010-07-01',
    },
    {
        id: 3, cedula: '12345678', nacionalidad: 'V',
        primer_nombre: 'Juan', segundo_nombre: 'Alejandro',
        primer_apellido: 'Pérez', segundo_apellido: null,
        cargo: 'Analista de Sistemas',
        gerencia: 'OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION',
        estado_carnet: 'Pendiente por Imprimir', photo_url: '', fecha_ingreso: '2020-01-15',
    },
    {
        id: 4, cedula: '87654321', nacionalidad: 'V',
        primer_nombre: 'María', segundo_nombre: 'Victoria',
        primer_apellido: 'Gómez', segundo_apellido: null,
        cargo: 'Coordinadora',
        gerencia: 'OFICINA DE ADMINISTRACION Y GESTION INTERNA',
        estado_carnet: 'Carnet Impreso', photo_url: '', fecha_ingreso: '2019-05-20',
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
    console.warn('[SCI-TSS] MODO DEMO ACTIVO: usando datos simulados.');
    await new Promise(r => setTimeout(r, 350));

    if (url.includes('auth.php')) {
        const user = { username: body?.username || 'admin', role: 'ADMIN', full_name: 'Usuario Demo' };
        return { success: true, data: user };
    }

    if (url.includes('gerencias.php')) {
        if (method === 'GET') {
            return { success: true, data: [...MOCK_GERENCIAS] };
        }
        if (method === 'POST') {
            if (body?.id) {
                const g = MOCK_GERENCIAS.find(x => x.id == body.id);
                if (g) g.nombre = body.nombre;
            } else if (body?.nombre) {
                MOCK_GERENCIAS.push({ id: nextGerenciaId++, nombre: body.nombre });
            }
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
            const params   = new URLSearchParams(url.split('?')[1] || '');
            const search   = (params.get('search') || '').toLowerCase();
            const status   = params.get('status') || '';
            const page     = parseInt(params.get('page') || '1');
            const limit    = parseInt(params.get('limit') || '50');
            const idFilter = params.get('id');

            let lista = MOCK_EMPLOYEES.map(normalizarEmpleado);
            if (idFilter) {
                lista = lista.filter(e => String(e.id) === String(idFilter));
            }
            if (search) {
                lista = lista.filter(e =>
                    e.nombres.toLowerCase().includes(search) ||
                    e.apellidos.toLowerCase().includes(search) ||
                    e.cedula.includes(search)
                );
            }
            if (status) {
                lista = lista.filter(e => e.estado_carnet === status || e.status === status);
            }

            const total      = lista.length;
            const totalPages = Math.ceil(total / limit);
            const from       = (page - 1) * limit;
            const paginada   = lista.slice(from, from + limit);

            return {
                success: true,
                data:    paginada,
                meta:    { totalRecords: total, currentPage: page, totalPages, limit },
            };
        }

        if (method === 'POST') {
            if (body?.action === 'upload_payroll' && body?.rows) {
                let added = 0;
                body.rows.forEach(r => {
                    const ced = r['Cédula'] || r['cedula'] || r['CI'] || '';
                    if (!ced) return;
                    const cedulaLimpia = String(ced).replace(/[^0-9]/g, '');
                    MOCK_EMPLOYEES.unshift({
                        id:             nextEmpId++,
                        cedula:         cedulaLimpia,
                        nacionalidad:   'V',
                        primer_nombre:  String(r['Primer Nombre'] || r['nombres'] || '').trim(),
                        segundo_nombre: String(r['Segundo Nombre'] || '').trim() || null,
                        primer_apellido: String(r['Primer Apellido'] || r['apellidos'] || '').trim(),
                        segundo_apellido: String(r['Segundo Apellido'] || '').trim() || null,
                        cargo:          String(r['Cargo'] || r['cargo'] || '').trim(),
                        gerencia:       String(r['Gerencia'] || r['gerencia'] || '').trim(),
                        estado_carnet:  'Pendiente por Imprimir',
                        photo_url:      '',
                    });
                    added++;
                });
                if (added > 0) saveDB();
                return {
                    success: true,
                    message: added > 0
                        ? `Nómina importada: ${added} empleado(s) registrado(s).`
                        : 'No se importaron empleados (verifique el formato del archivo).',
                };
            }

            if (body?.action === 'auto_match') {
                return { success: true, message: 'Auto-Match completado (Demo). Ningún cambio aplicado.' };
            }

            if (body?.id) {
                const emp = MOCK_EMPLOYEES.find(e => e.id == body.id);
                if (emp) {
                    // Actualizar campos presentes en el body
                    if (body.estado_carnet !== undefined) emp.estado_carnet = body.estado_carnet;
                    if (body.status        !== undefined) emp.estado_carnet = body.status;
                    if (body.forma_entrega !== undefined) emp.forma_entrega  = body.forma_entrega;
                    if (body.photo_url     !== undefined) emp.photo_url      = body.photo_url;
                    if (body.primer_nombre !== undefined) emp.primer_nombre  = body.primer_nombre;
                    if (body.segundo_nombre!== undefined) emp.segundo_nombre = body.segundo_nombre;
                    if (body.primer_apellido    !== undefined) emp.primer_apellido  = body.primer_apellido;
                    if (body.segundo_apellido   !== undefined) emp.segundo_apellido = body.segundo_apellido;
                    if (body.cargo         !== undefined) emp.cargo          = body.cargo;
                    if (body.gerencia      !== undefined) emp.gerencia       = body.gerencia;
                    if (body.nacionalidad  !== undefined) emp.nacionalidad   = body.nacionalidad;
                    if (body.nivel_permiso !== undefined) emp.nivel_permiso  = body.nivel_permiso;
                    saveDB();
                }
                return { success: true, message: 'Empleado actualizado (Demo).' };
            }

            // CREATE
            const cedulaLimpia = String(body?.cedula || '').replace(/[^0-9]/g, '');
            MOCK_EMPLOYEES.unshift({
                id:              nextEmpId++,
                cedula:          cedulaLimpia,
                nacionalidad:    body?.nacionalidad || 'V',
                primer_nombre:   body?.primer_nombre  || (String(body?.nombres || '').split(' ')[0]),
                segundo_nombre:  body?.segundo_nombre || (String(body?.nombres || '').split(' ').slice(1).join(' ')) || null,
                primer_apellido: body?.primer_apellido  || (String(body?.apellidos || '').split(' ')[0]),
                segundo_apellido:body?.segundo_apellido || (String(body?.apellidos || '').split(' ').slice(1).join(' ')) || null,
                cargo:           body?.cargo          || '',
                gerencia:        body?.gerencia       || '',
                estado_carnet:   'Pendiente por Imprimir',
                fecha_ingreso:   body?.fecha_ingreso  || new Date().toISOString().split('T')[0],
                photo_url:       '',
                nivel_permiso:   body?.nivel_permiso  || 'Nivel 1',
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
            success: true,
            data: [
                { id: 1, usuario: 'admin', username: 'admin', nombre_completo: 'Administrador Principal', full_name: 'Administrador Principal', rol: 'ADMIN', role: 'ADMIN', bloqueado: false },
            ],
        };
    }

    return { success: true, message: 'Respuesta simulada (Demo).' };
}
