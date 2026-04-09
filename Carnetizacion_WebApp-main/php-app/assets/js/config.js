/**
 * config.js — Lógica de la página de Configuración
 * ================================================
 * Maneja la creación de campos personalizados y ajustes institucionales.
 * ENTORNO: Air-gapped / LocalStorage para persistencia de la demo.
 */

'use strict';

let selectedIconType = 'text';
let customFields = [];

// ============================================================================
// INIT
// ============================================================================
async function init() {
    // Sidebar — usuario actual
    const user = api.getCurrentUser();
    if (user.username) {
        document.getElementById('user-name').textContent = user.username.charAt(0).toUpperCase() + user.username.slice(1);
        document.getElementById('user-role').textContent = user.role === 'ADMIN' ? 'Administrador' : 'Operador';
        document.getElementById('user-avatar').textContent = user.username[0].toUpperCase();
    }

    document.getElementById('btn-logout').addEventListener('click', async () => {
        sessionStorage.removeItem('current_user');
        window.location.href = 'login.html';
    });

    // Cargar campos existentes
    loadFields();

    // Cargar datos institucionales
    loadInstitution();
}

// ============================================================================
// GESTIÓN DE CAMPOS PERSONALIZADOS
// ============================================================================

function selectType(btn, type) {
    selectedIconType = type;

    // UI: Marcar activo
    document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Mostrar/ocultar opciones de selección
    const optionsBlock = document.getElementById('select-options-block');
    if (type === 'select') {
        optionsBlock.style.display = 'block';
    } else {
        optionsBlock.style.display = 'none';
    }
}

function saveField() {
    const label = document.getElementById('field-label').value.trim();
    const isRequired = document.getElementById('field-required').checked;
    const optionsStr = document.getElementById('select-options').value;

    if (!label) {
        showFormAlert('Por favor, ingrese un nombre para la etiqueta.', 'danger');
        return;
    }

    const newField = {
        id: Date.now(),
        label: label,
        type: selectedIconType,
        required: isRequired,
        options: selectedIconType === 'select' ? optionsStr.split('\n').filter(o => o.trim()) : []
    };

    customFields.push(newField);
    persistFields();
    renderFields();

    // Limpiar form
    document.getElementById('field-label').value = '';
    document.getElementById('field-required').checked = false;
    document.getElementById('select-options').value = '';

    showToast('Campo guardado correctamente');
}

function removeField(id) {
    customFields = customFields.filter(f => f.id !== id);
    persistFields();
    renderFields();
    showToast('Campo eliminado');
}

function renderFields() {
    const list = document.getElementById('field-list');
    const empty = document.getElementById('fields-empty');
    const count = document.getElementById('field-count');

    count.textContent = customFields.length;

    if (customFields.length === 0) {
        list.style.display = 'none';
        empty.style.display = 'flex';
        return;
    }

    list.style.display = 'flex';
    empty.style.display = 'none';

    list.innerHTML = '';
    customFields.forEach(f => {
        const item = document.createElement('div');
        item.className = 'field-item';

        const typeIcons = {
            text: { char: 'T', color: '#eff6ff', txt: '#003366' },
            number: { char: '#', color: '#f0fdf4', txt: '#166534' },
            date: { char: '📅', color: '#fff7ed', txt: '#9a3412' },
            select: { char: '≡', color: '#f5f3ff', txt: '#5b21b6' }
        };

        const t = typeIcons[f.type] || typeIcons.text;

        item.innerHTML = `
            <div class="field-item-left">
                <div class="field-type-badge" style="background:${t.color}; color:${t.txt}">${t.char}</div>
                <div class="field-item-info">
                    <div class="name">${f.label} ${f.required ? '<span style="color:#dc2626">*</span>' : ''}</div>
                    <div class="meta">${f.type === 'select' ? `${f.options.length} opciones` : f.type}</div>
                </div>
            </div>
            <div class="field-actions">
                <button onclick="removeField(${f.id})" class="del" title="Eliminar">🗑️</button>
            </div>
        `;
        list.appendChild(item);
    });
}

function persistFields() {
    localStorage.setItem('tss_custom_fields', JSON.stringify(customFields));
}

function loadFields() {
    const saved = localStorage.getItem('tss_custom_fields');
    if (saved) {
        try { customFields = JSON.parse(saved); } catch (e) { customFields = []; }
    }
    renderFields();
}

// ============================================================================
// AJUSTES INSTITUCIONALES
// ============================================================================

function saveInstitution() {
    const settings = {
        name: document.getElementById('inst-name').value,
        abbr: document.getElementById('inst-abbr').value,
        country: document.getElementById('inst-country').value,
        version: document.getElementById('inst-version').value,
        url: document.getElementById('inst-url').value
    };

    localStorage.setItem('tss_settings', JSON.stringify(settings));
    showToast('Configuración institucional guardada');
}

function loadInstitution() {
    const saved = localStorage.getItem('tss_settings');
    if (!saved) return;

    const s = JSON.parse(saved);
    document.getElementById('inst-name').value = s.name || '';
    document.getElementById('inst-abbr').value = s.abbr || '';
    document.getElementById('inst-country').value = s.country || '';
    document.getElementById('inst-version').value = s.version || '2025';
    document.getElementById('inst-url').value = s.url || '';
}

function resetInstitution() {
    if (!confirm('¿Restablecer los ajustes predeterminados?')) return;
    localStorage.removeItem('tss_settings');
    location.reload();
}

// ============================================================================
// ZONA DE PELIGRO
// ============================================================================

function clearCustomFields() {
    if (!confirm('¿Eliminar todos los campos personalizados? Esta acción no se puede deshacer.')) return;
    customFields = [];
    persistFields();
    renderFields();
    showToast('Todos los campos eliminados');
}

function resetDemoData() {
    if (!confirm('¿Restablecer todos los datos de la demo? Se borrarán empleados y configuraciones locales.')) return;
    localStorage.clear();
    sessionStorage.clear();
    window.location.href = 'login.html';
}

// ============================================================================
// UI HELPERS
// ============================================================================

function showFormAlert(msg, type) {
    const el = document.getElementById('form-alert');
    el.innerHTML = `<div class="alert alert-${type}" style="padding:10px; font-size:.8rem; margin:0;">${msg}</div>`;
    setTimeout(() => el.innerHTML = '', 3000);
}

function showToast(msg) {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
        background: #1e293b; color: #fff; padding: 12px 24px; border-radius: 8px;
        font-size: .875rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10000;
        animation: toastFadeIn 0.3s ease forwards;
    `;
    toast.textContent = msg;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastFadeOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

// Inyectar animaciones de toast al vuelo
const style = document.createElement('style');
style.innerHTML = `
    @keyframes toastFadeIn { from { opacity: 0; bottom: 20px; } to { opacity: 1; bottom: 30px; } }
    @keyframes toastFadeOut { from { opacity: 1; bottom: 30px; } to { opacity: 0; bottom: 20px; } }
`;
document.head.appendChild(style);

window.selectType = selectType;
window.saveField = saveField;
window.removeField = removeField;
window.saveInstitution = saveInstitution;
window.resetInstitution = resetInstitution;
window.clearCustomFields = clearCustomFields;
window.resetDemoData = resetDemoData;

init();
