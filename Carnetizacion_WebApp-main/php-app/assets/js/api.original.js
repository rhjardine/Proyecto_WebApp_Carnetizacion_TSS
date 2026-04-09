/**
 * api.js — Centralized HTTP Communication Layer
 * All fetch() calls go through this module.
 *
 * SECURITY:
 * - Sends session cookie on every request (credentials: 'include')
 * - Injects X-CSRF-Token header on all mutating requests (POST/PATCH/PUT/DELETE)
 * - CSRF token is loaded once per page session via api.initCsrf()
 * - On 401, user is redirected to login automatically
 */

const API_BASE = '/api';

// CSRF token stored in JS memory (never in localStorage → XSS safe)
let _csrfToken = null;

const api = {
    /**
     * Call this once on every protected page (dashboard, editor, upload).
     * Fetches the CSRF token from the server session.
     */
    async initCsrf() {
        try {
            const res = await fetch(`${API_BASE}/auth/csrf.php`, { credentials: 'include' });
            const data = await res.json();
            if (data.success && data.csrf_token) {
                _csrfToken = data.csrf_token;
            }
        } catch (e) {
            console.warn('No se pudo obtener el CSRF token. Verifica la sesión.');
        }
    },

    async _request(method, endpoint, body = null, isFormData = false) {
        const upperMethod = method.toUpperCase();
        const mutating = ['POST', 'PATCH', 'PUT', 'DELETE'].includes(upperMethod);

        const opts = {
            method,
            credentials: 'include', // envía cookie de sesión en cada petición
            headers: {},
        };

        // --- Inyectar CSRF token en peticiones que mutan estado ---
        if (mutating) {
            if (!_csrfToken) {
                console.error('CSRF token no disponible. Llama api.initCsrf() al inicio de la página.');
            }
            opts.headers['X-CSRF-Token'] = _csrfToken || '';
        }

        if (body) {
            if (isFormData) {
                opts.body = body; // FormData establece su propio Content-Type (multipart)
            } else {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
        }

        const res = await fetch(`${API_BASE}${endpoint}`, opts);

        // Sesión expirada → redirigir al login
        if (res.status === 401) {
            window.location.href = '/login.html';
            return;
        }

        // CSRF inválido → no redirigir, mostrar el error al usuario
        if (res.status === 403) {
            const data = await res.json();
            throw new Error(data.message || 'Acceso denegado (CSRF).');
        }

        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Error desconocido');
        }
        return data;
    },

    get: (endpoint) => api._request('GET', endpoint),
    post: (endpoint, body) => api._request('POST', endpoint, body),
    patch: (endpoint, body) => api._request('PATCH', endpoint, body),
    upload: (endpoint, form) => api._request('POST', endpoint, form, true),

    // Auth
    async login(username, password) {
        const res = await api._request('POST', '/auth/login.php', { username, password });
        // Almacenar el token CSRF que llega en la respuesta del login
        if (res && res.csrf_token) {
            _csrfToken = res.csrf_token;
        }
        return res;
    },
    logout: () => api.post('/auth/logout.php'),

    // Employees — params: { page, limit, search, status }
    getEmployees: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        const endpoint = '/employees/index.php' + (qs ? '?' + qs : '');
        return api.get(endpoint);
    },
    createEmployee: (data) => api.post('/employees/index.php', data),
    updateStatus: (id, status) => api.patch('/employees/update/index.php', { id, status }),
    uploadPhoto: (form) => api.upload('/employees/upload.php', form),
};

// ─────────────────────────────────────────────────────────────
// UI Utilities
// ─────────────────────────────────────────────────────────────
const ui = {
    showAlert(containerId, message, type = 'danger') {
        const el = document.getElementById(containerId);
        if (!el) return;
        el.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
        setTimeout(() => { el.innerHTML = ''; }, 4000);
    },

    setLoading(btn, loading, text = 'Cargando...') {
        if (loading) {
            btn.disabled = true;
            btn.dataset.original = btn.innerHTML;
            btn.innerHTML = `<span class="spinner"></span> ${text}`;
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.original || text;
        }
    },

    getBadgeClass(status) {
        const map = {
            'Pendiente': 'badge-yellow',
            'Verificado': 'badge-green',
            'Impreso': 'badge-blue',
            'Rechazado': 'badge-red',
        };
        return map[status] || 'badge-gray';
    },

    formatDate(dateStr) {
        if (!dateStr) return '—';
        return new Date(dateStr).toLocaleDateString('es-VE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
        });
    },
};
