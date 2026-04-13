/**
 * rbac.js — Módulo de Control de Acceso Basado en Roles (RBAC) para UI
 * ======================================================================
 * Sistema de Carnetización Inteligente (SCI-TSS)
 * Capa Frontend del modelo Zero Trust.
 *
 * ROLES SISTEMA (jerarquía descendente de privilegios):
 *  ADMIN    → Acceso total. Gestión de usuarios, gerencias, delegación.
 *  COORD    → Acceso operativo completo (sin gestión de usuarios del sistema).
 *  ANALISTA → CRUD de empleados + subida de fotos. Sin delegación de roles.
 *  USUARIO  → Operaciones básicas: ver y actualizar estado de carnet.
 *  CONSULTA → Solo lectura. Ningún botón de escritura visible.
 *
 * PRINCIPIO ZERO TRUST:
 *  El rol efectivo (effective_role) calcula la precedencia
 *  rol_temporal > rol_base. Esta lógica espeja el middleware auth_check.php.
 *  NUNCA confiar solo en la capa frontend: el backend valida independientemente.
 *
 * USO:
 *   1. Incluir este script después de api.js en el HTML.
 *   2. Llamar applyRolePermissions() al inicio de init() en cada vista.
 *
 * @version 1.0.0
 */
'use strict';

// ── HELPERS DE ROL ────────────────────────────────────────────────────────────

/**
 * getRolEfectivo() — Devuelve el rol efectivo del usuario autenticado.
 * Espeja la lógica del middleware PHP: rol_temporal tiene precedencia.
 * @returns {string} Rol efectivo en mayúsculas. Default: 'CONSULTA' (más restrictivo).
 */
function getRolEfectivo() {
    const u = api.getCurrentUser();
    return (u.effective_role || u.temporary_role || u.role || 'CONSULTA').toUpperCase();
}

/**
 * puedeEscribir(rol) — Determina si el rol puede realizar operaciones de escritura.
 * @param {string} rol
 * @returns {boolean}
 */
function puedeEscribir(rol) {
    return ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO'].includes(rol);
}

/**
 * esAdmin(rol) — Determina si el rol es ADMIN o COORD (gestores del sistema).
 * @param {string} rol
 * @returns {boolean}
 */
function esAdmin(rol) {
    return ['ADMIN', 'COORD'].includes(rol);
}


// ── FUNCIÓN PRINCIPAL DE PERMISOS ─────────────────────────────────────────────

/**
 * applyRolePermissions(vista) — Aplica restricciones de UI según el rol efectivo.
 *
 * Esta función centraliza TODA la lógica de permisos de interfaz.
 * Debe llamarse UNA VEZ al inicio de init() en cada vista (dashboard, editor).
 *
 * Estrategia de aplicación:
 *  - Ocultar (display:none):   elementos que el rol no debe ver ni interactuar.
 *  - Deshabilitar (disabled):  elementos visibles pero que el rol no puede usar.
 *  - Texto de contexto:        badge de rol visible en la barra de usuario.
 *
 * @param {'dashboard'|'editor'} vista - Nombre de la vista actual.
 * @returns {{ rol: string, puedeEscribir: boolean, esAdmin: boolean }}
 *          Objeto con el contexto de permisos para uso posterior en la vista.
 */
function applyRolePermissions(vista = 'dashboard') {
    const rol    = getRolEfectivo();
    const escribe = puedeEscribir(rol);
    const admin   = esAdmin(rol);

    // ──────────────────────────────────────────────────────────────────────────
    // SECCIÓN 1: Elemento de badge de rol en la barra superior
    // Muestra el rol efectivo con indicador visual si hay delegación temporal
    // ──────────────────────────────────────────────────────────────────────────
    const u = api.getCurrentUser();
    const elRolUI = document.getElementById('user-role');
    if (elRolUI) {
        const LABELS_ROL = {
            ADMIN:    'Administrador',
            COORD:    'Coordinador',
            ANALISTA: 'Analista',
            USUARIO:  'Operador',
            CONSULTA: 'Solo Consulta',
        };
        const label = LABELS_ROL[rol] || rol;
        const tempBadge = u.temporary_role
            ? ` <span style="font-size:.65rem;background:#f59e0b;color:#fff;padding:1px 6px;border-radius:3px;font-weight:700;">⚡TEMP</span>`
            : '';
        elRolUI.innerHTML = label + tempBadge;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SECCIÓN 2: Navegación lateral — solo ADMIN/COORD ven "Configuración"
    // ──────────────────────────────────────────────────────────────────────────
    _toggleEl('nav-config', admin);
    _toggleEl('nav-users', admin);

    // ──────────────────────────────────────────────────────────────────────────
    // SECCIÓN 3: Restricciones específicas por VISTA
    // ──────────────────────────────────────────────────────────────────────────

    if (vista === 'dashboard') {
        _applyDashboardPermissions(rol, escribe, admin);
    } else if (vista === 'editor') {
        _applyEditorPermissions(rol, escribe, admin);
    }

    // Retornar contexto para uso en la vista si necesita tomar decisiones adicionales
    return { rol, puedeEscribir: escribe, esAdmin: admin };
}


// ── PERMISOS POR VISTA ────────────────────────────────────────────────────────

/**
 * _applyDashboardPermissions() — Restricciones para dashboard.html
 *
 * Mapa de permisos por elemento:
 * ┌─────────────────────────────┬─────────┬───────┬─────────┬─────────┬──────────┐
 * │ Elemento                    │ ADMIN   │ COORD │ ANALISTA│ USUARIO │ CONSULTA │
 * ├─────────────────────────────┼─────────┼───────┼─────────┼─────────┼──────────┤
 * │ btn-new-employee            │ ✅      │ ✅    │ ✅      │ ❌      │ ❌       │
 * │ btn-import-excel            │ ✅      │ ✅    │ ✅      │ ❌      │ ❌       │
 * │ btn-auto-match              │ ✅      │ ✅    │ ❌      │ ❌      │ ❌       │
 * │ btn-manage-gerencias        │ ✅      │ ✅    │ ❌      │ ❌      │ ❌       │
 * │ btn-delegate-perms          │ ✅      │ ❌    │ ❌      │ ❌      │ ❌       │
 * │ select estado/entrega       │ ✅(all) │ ✅    │ ✅      │ ✅      │ ❌ disab.│
 * │ btn Editar / Eliminar       │ ✅      │ ✅    │ ✅      │ ❌      │ ❌       │
 * └─────────────────────────────┴─────────┴───────┴─────────┴─────────┴──────────┘
 */
function _applyDashboardPermissions(rol, escribe, admin) {
    const puedeCrear  = ['ADMIN', 'COORD', 'ANALISTA'].includes(rol);
    const puedeImport = ['ADMIN', 'COORD', 'ANALISTA'].includes(rol);
    const esAdminOnly = rol === 'ADMIN';

    // Botones de creación / importación
    _toggleEl('btn-new-employee',   puedeCrear);
    _toggleEl('btn-import-excel',   puedeImport);

    // Auto-Match y gestión: solo ADMIN/COORD
    _toggleEl('btn-auto-match',       admin);
    _toggleEl('btn-manage-gerencias', admin);

    // Delegación: SOLO ADMIN
    _toggleEl('btn-delegate-perms', esAdminOnly);

    // Si CONSULTA: deshabilitar los selects de estado y entrega en la tabla
    // (se maneja dinámicamente en renderTable() con el flag `escribe`)
    // Guardamos el contexto para que renderTable() lo use
    if (typeof window !== 'undefined') {
        window._rbacPuedeEscribir = escribe;
    }

    // Toast informativo para CONSULTA
    if (rol === 'CONSULTA' && typeof showFloatingToast === 'function') {
        showFloatingToast(
            '🔒 Modo Solo Consulta — Las acciones de edición están deshabilitadas.',
            'info'
        );
    }
}

/**
 * _applyEditorPermissions() — Restricciones para editor.html
 *
 * ┌────────────────────────────────┬───────┬───────┬─────────┬─────────┬──────────┐
 * │ Elemento                       │ ADMIN │ COORD │ ANALISTA│ USUARIO │ CONSULTA │
 * ├────────────────────────────────┼───────┼───────┼─────────┼─────────┼──────────┤
 * │ form-edit-employee (inputs)    │ ✅    │ ✅    │ ✅      │ ❌ RO   │ ❌ RO    │
 * │ btn-save-fields                │ ✅    │ ✅    │ ✅      │ ❌      │ ❌       │
 * │ btn-delete-employee            │ ✅    │ ✅    │ ❌      │ ❌      │ ❌       │
 * │ btn-upload-photo / remove-photo│ ✅    │ ✅    │ ✅      │ ❌      │ ❌       │
 * │ btn-smart-extract              │ ✅    │ ✅    │ ✅      │ ❌      │ ❌       │
 * │ btn-upload-bg / reset-bg       │ ✅    │ ✅    │ ❌      │ ❌      │ ❌       │
 * │ btn-upload-front / back        │ ✅    │ ✅    │ ❌      │ ❌      │ ❌       │
 * │ card-photo-module              │ ✅    │ ✅    │ ✅      │ ❌      │ ❌       │
 * └────────────────────────────────┴───────┴───────┴─────────┴─────────┴──────────┘
 */
function _applyEditorPermissions(rol, escribe, admin) {
    const puedeEditar  = ['ADMIN', 'COORD', 'ANALISTA'].includes(rol);
    const puedeEliminar = ['ADMIN', 'COORD'].includes(rol);
    const puedeFotos   = ['ADMIN', 'COORD', 'ANALISTA'].includes(rol);
    const puedeFondos  = ['ADMIN', 'COORD'].includes(rol);

    // Formulario de datos: solo lectura si no puede editar
    const form = document.getElementById('form-edit-employee');
    if (form) {
        form.querySelectorAll('input:not([id="edit-cedula"]), textarea').forEach(el => {
            if (puedeEditar) el.removeAttribute('readonly');
            else el.setAttribute('readonly', 'readonly');
        });
        form.querySelectorAll('select').forEach(el => {
            el.disabled = !puedeEditar;
        });
    }

    // Botón guardar campos
    _toggleEl('btn-save-fields', puedeEditar);

    // Botón eliminar empleado
    _toggleEl('btn-delete-employee', puedeEliminar);

    // Módulo de foto
    _toggleEl('card-photo-module', puedeFotos);
    _toggleEl('btn-upload-photo',  puedeFotos);
    _toggleEl('btn-remove-photo',  puedeFotos);
    _toggleEl('btn-smart-extract', puedeFotos);

    // Fondos e imágenes de carnet (solo ADMIN/COORD)
    ['btn-upload-bg', 'btn-reset-bg', 'btn-upload-front', 'btn-reset-front',
     'btn-upload-back', 'btn-reset-back'].forEach(id => _toggleEl(id, puedeFondos));

    // Toast informativo
    if (!puedeEditar && typeof showEditorToast === 'function') {
        const msg = rol === 'CONSULTA'
            ? '🔒 Modo Solo Consulta — Edición deshabilitada.'
            : '👁️ Modo Vista — Solo puede consultar el carnet.';
        showEditorToast(msg, 'info');
    }
}


// ── HELPER INTERNO ────────────────────────────────────────────────────────────

/**
 * _toggleEl(id, visible) — Muestra u oculta un elemento del DOM por ID.
 * @param {string} id
 * @param {boolean} visible
 */
function _toggleEl(id, visible) {
    const el = document.getElementById(id);
    if (el) el.style.display = visible ? '' : 'none';
}
