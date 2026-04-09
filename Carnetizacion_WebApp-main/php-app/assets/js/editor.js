/**
 * editor.js — Editor de Carnet | Offline / Air-gapped
 * =====================================================
 * - Tareas 3, 5, 6, 7: CONSULTA restrictions, custom background, new card templates, full_name.
 * - Campos: nombres, apellidos, cedula, cargo, gerencia, nacionalidad, nivel_permiso.
 * - Sin referencias a tipo_sangre ni nss.
 */
'use strict';

// ── CONSTANTES: MOCK_LOGO y VALIDATION_BASE_URL están definidas en api.js
// (api.js se carga antes que este script via <script src> — NO redeclarar aquí)

let employee = null;
let currentTemplate = '2025';
let currentOrientation = 'horizontal';
let currentFace = 'anverso';
let qrDataUrl = null;
let customBackground = null;   // Tarea 5: fondo personalizado (Base64)
let cardFrontImage = null;   // Imagen anverso personalizada (Base64)
let cardBackImage = null;   // Imagen reverso personalizada (Base64)
let cropperInstance = null;   // Cropper.js

// ── INIT ──────────────────────────────────────────────────────────────────────
async function init() {
  if (typeof api.initCsrf === 'function') await api.initCsrf();

  const user = api.getCurrentUser();
  if (user.username) {
    const displayName = user.full_name
      ? `${user.username} (${user.full_name})`
      : user.username.charAt(0).toUpperCase() + user.username.slice(1);
    const el = document.getElementById('user-name'); if (el) el.textContent = displayName;
    const effRole = user.effective_role || user.role;
    const ra = document.getElementById('user-role');
    if (ra) {
      let rt = effRole === 'ADMIN' ? 'Administrador' : 'Solo Consulta';
      if (user.temporary_role) rt += ' ⚡Temp.';
      ra.textContent = rt;
    }
    const av = document.getElementById('user-avatar');
    if (av) {
      const initials = user.full_name
        ? user.full_name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
        : user.username[0].toUpperCase();
      av.textContent = initials;
    }

    // Role-based sidebar link visibility
    const navConfig = document.getElementById('nav-config');
    if (navConfig) {
      navConfig.style.display = api.isAdmin() ? 'flex' : 'none';
    }
  }


  document.getElementById('btn-logout').addEventListener('click', async () => {
    sessionStorage.removeItem('current_user');
    await api.logout();
    window.location.href = 'login.html';
  });

  const btnDownload = document.getElementById('btn-download');
  if (btnDownload) btnDownload.addEventListener('click', downloadPDF);

  const btnDelete = document.getElementById('btn-delete-employee');
  if (btnDelete) {
    if (!api.isAdmin()) {
      btnDelete.style.display = 'none';
    } else {
      btnDelete.addEventListener('click', async () => {
        if (!employee) return;
        if (!confirm(`¿Eliminar permanentemente a "${employee.apellidos}, ${employee.nombres}"?`)) return;
        try {
          await api.deleteEmployee(employee.id);
          showEditorToast('Funcionario eliminado.', 'success');
          setTimeout(() => window.location.href = 'dashboard.html', 1800);
        } catch (err) { showEditorToast(err.message, 'danger'); }
      });
    }
  }

  // Tarea 3: Restricciones CONSULTA
  applyConsultaRestrictions();

  await populateGerenciaSelect();
  setupManualPhoto();
  setupSmartExtraction();
  setupInlineEdit();
  setupRemovePhoto();
  setupCustomBackground();  // Tarea 5
  setupCardImages();        // Anverso/Reverso images

  // View mode (read-only) — from dashboard "Consultar" button
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('mode') === 'view') {
    applyConsultaRestrictions(true); // Force read-only even for admins
  }

  const urlId = urlParams.get('id');
  const storedId = urlId || localStorage.getItem('selected_employee_id');
  if (urlId) localStorage.setItem('selected_employee_id', urlId);

  try {
    const res = await api.getEmployees();
    const list = res.data || [];
    if (!list.length) {
      showEditorToast('Atención: No hay empleados registrados en el sistema.', 'info');
      return; // Cero redirecciones
    }
    employee = list.find(e => String(e.id) === String(storedId)) || list[0];
    generateQR();
    renderDetails();
    renderCard();
  } catch (err) {
    console.error(err);
    showEditorToast('Error al cargar datos: ' + err.message, 'danger');
  }
}

// ── TAREA 3: RESTRICCIONES PARA ROL CONSULTA ─────────────────────────────────
function applyConsultaRestrictions(force = false) {
  const currentUser = api.getCurrentUser();
  const isAdmin = currentUser.role === 'ADMIN' || currentUser.temporary_role === 'ADMIN';
  const isConsulta = currentUser.role === 'CONSULTA' || currentUser.role === 'USUARIO';

  const shouldBlock = force || (!isAdmin && isConsulta);

  if (shouldBlock) {
    // Hacer los inputs del formulario de solo lectura
    document.querySelectorAll('#form-edit-employee input').forEach(el => el.setAttribute('readonly', 'true'));
    document.querySelectorAll('#form-edit-employee select').forEach(el => { el.disabled = true; });

    // Ocultar botones de acción
    ['btn-save-fields', 'btn-delete-employee', 'btn-upload-photo', 'btn-remove-photo', 'btn-smart-extract',
      'btn-upload-bg', 'btn-reset-bg', 'btn-upload-front', 'btn-reset-front', 'btn-upload-back', 'btn-reset-back'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
      });

    // Ocultar módulo de foto
    const photoModule = document.getElementById('card-photo-module');
    if (photoModule) photoModule.style.display = 'none';

    showEditorToast('Modo solo consulta — edición deshabilitada.', 'info');
  } else if (isAdmin) {
    // Garantizar que estén habilitados para Admin
    document.querySelectorAll('#form-edit-employee input').forEach(el => {
      if (el.id !== 'edit-cedula') el.removeAttribute('readonly');
    });
    document.querySelectorAll('#form-edit-employee select').forEach(el => { el.disabled = false; });

    ['btn-save-fields', 'btn-delete-employee', 'btn-upload-photo', 'btn-remove-photo', 'btn-smart-extract',
      'btn-upload-bg', 'btn-reset-bg', 'btn-upload-front', 'btn-reset-front', 'btn-upload-back', 'btn-reset-back'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = ''; // Restaurar display original
      });

    const photoModule = document.getElementById('card-photo-module');
    if (photoModule) photoModule.style.display = 'block';
  }
}


// ── QR ────────────────────────────────────────────────────────────────────────
function generateQR() {
  if (typeof QRious === 'undefined') { renderCard(); return; }
  const canvas = document.createElement('canvas');
  new QRious({
    element: canvas, value: `${VALIDATION_BASE_URL}?id=${employee.id}`,
    size: 200, level: 'M', background: '#ffffff', foreground: '#003366', padding: 8,
  });
  qrDataUrl = canvas.toDataURL('image/png');
  renderCard();
}

// ── DETALLES DEL FORMULARIO ───────────────────────────────────────────────────
async function populateGerenciaSelect() {
  const res = await api.getGerencias();
  const gerencias = res.data || [];
  const options = gerencias.map(g => `<option value="${g.nombre}">${g.nombre}</option>`).join('');
  document.querySelectorAll('.gerencia-select').forEach(sel => {
    const val = sel.value;
    sel.innerHTML = `<option value="">Seleccionar...</option>` + options;
    if (val) sel.value = val;
  });
}

function renderDetails() {
  const sub = document.getElementById('editor-subtitle');
  if (sub) sub.textContent = `${employee.apellidos || ''}, ${employee.nombres || ''}`;

  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
  set('edit-nombres', employee.nombres);
  set('edit-apellidos', employee.apellidos);
  set('edit-cedula', employee.cedula);
  set('edit-cargo', employee.cargo);
  set('edit-gerencia', employee.gerencia);
  set('edit-nacionalidad', employee.nacionalidad || 'V');
  set('edit-nivel-permiso', employee.nivel_permiso || 'Nivel 1');

  const btnR = document.getElementById('btn-remove-photo');
  if (btnR) btnR.style.display = employee.photo_url ? 'inline-flex' : 'none';
}

// ── CONTROLES ─────────────────────────────────────────────────────────────────
function setTemplate(t) { currentTemplate = t; renderCard(); }
function setOrientation(o) {
  currentOrientation = o;
  const h = document.getElementById('ori-h');
  const v = document.getElementById('ori-v');
  if (h) h.className = o === 'horizontal' ? 'btn btn-primary' : 'btn btn-secondary';
  if (v) v.className = o === 'vertical' ? 'btn btn-primary' : 'btn btn-secondary';
  renderCard();
}
function setFace(face) {
  currentFace = face;
  ['anverso', 'reverso'].forEach(f => {
    const b = document.getElementById(`face-${f}`);
    if (!b) return;
    b.style.background = face === f ? 'var(--color-primary)' : 'transparent';
    b.style.color = face === f ? '#fff' : 'var(--color-muted)';
  });
  renderCard();
}

// ── DISPATCHER ────────────────────────────────────────────────────────────────
function renderCard() {
  if (!employee) return;
  const wrapper = document.getElementById('id-card-wrapper');
  if (!wrapper) return;
  const is2025 = currentTemplate === '2025';
  const isVert = currentOrientation === 'vertical';
  const photo = employee.photo_url || makeAvatar(`${employee.nombres} ${employee.apellidos}`);
  const qrSrc = qrDataUrl || '';

  if (currentFace === 'reverso') {
    wrapper.innerHTML = isVert ? cardReversoVertical(qrSrc) : cardReversoHorizontal(qrSrc);
    if (cardBackImage) {
      const card = wrapper.querySelector('#id-card-print-reverso');
      if (card) {
        card.style.background = `url('${cardBackImage}') center/cover no-repeat`;
        if (card.children[0]) {
          card.children[0].style.background = 'transparent';
          card.children[0].style.borderBottom = 'none';
        }
      }
    }
  } else {
    const cardHtml = is2025
      ? (isVert ? card2025Vertical(photo, qrSrc) : card2025Horizontal(photo, qrSrc))
      : (isVert ? cardClassicVertical(photo, qrSrc) : cardClassic(photo, qrSrc));

    wrapper.innerHTML = cardHtml;
    if (cardFrontImage) {
      const card = wrapper.querySelector('#id-card-print');
      if (card) {
        card.style.background = `url('${cardFrontImage}') center/cover no-repeat`;
        if (card.children[0]) {
          card.children[0].style.background = 'transparent';
          card.children[0].style.boxShadow = 'none';
        }
      }
    }
  }

  const meta = document.getElementById('card-meta');
  if (meta) meta.textContent = `${is2025 ? 'Moderno 2025' : 'Clásico 2024'} · ${currentOrientation} · ${currentFace} · CR80 (${isVert ? '54×86' : '86×54'} mm)`;
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const _qr = (src, size = 60) => src ? `<img src="${src}" style="width:${size}px;height:${size}px;border:2px solid #e2e8f0;border-radius:5px;" />` : '<div style="width:60px;height:60px;background:#f1f5f9;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#94a3b8;">QR</div>';
const _stripe = h => `<div style="height:${h}px;background:linear-gradient(to right,#facc15 33%,#2563eb 33% 66%,#dc2626 66%);"></div>`;
const _nacBadge = nac => `<span style="background:${nac === 'E' ? '#fef3c7' : '#eff6ff'};color:${nac === 'E' ? '#92400e' : '#003366'};padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700;">${nac === 'E' ? '🌐 Extranjero' : '🇻🇪 Venezolano/a'}</span>`;
const _headerBg = () => customBackground
  ? `background:url('${customBackground}') center/cover no-repeat;`
  : `background:linear-gradient(135deg,#003366,#0a4a8c);`;

// ── TAREA 6: PLANTILLAS ───────────────────────────────────────────────────────

// ── ANVERSO HORIZONTAL (7 campos) ────────────────────────────────────────────
function card2025Horizontal(photo, qrSrc) {
  return `<div id="id-card-print" style="width:520px;aspect-ratio:86/54;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}height:92px;padding:14px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">
    <div>
      <div style="font-size:8px;opacity:.7;letter-spacing:1.5px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
      <div style="font-size:14px;font-weight:800;margin-top:3px;">Tesorería de Seguridad Social</div>
      <div style="font-size:8px;opacity:.55;margin-top:1px;">CARNET DE IDENTIFICACIÓN INSTITUCIONAL</div>
    </div>
    <img src="${MOCK_LOGO}" style="width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid rgba(255,255,255,.3);" />
  </div>
  <div style="position:relative;display:flex;flex:1;">
    <div style="position:absolute;top:-26px;left:18px;width:86px;height:116px;background:#fff;padding:3px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.2);z-index:2;">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;border-radius:6px;background:#e2e8f0;" />
    </div>
    <div style="padding:8px 16px 10px 118px;flex:1;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Apellidos / Nombres</div>
        <div style="font-size:16px;font-weight:800;color:#0f172a;line-height:1.1;">${employee.apellidos}</div>
        <div style="font-size:12px;font-weight:500;color:#334155;margin-bottom:4px;">${employee.nombres}</div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;">
          <span style="background:#eff6ff;color:#003366;padding:2px 9px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">${employee.cargo}</span>
          ${_nacBadge(employee.nacionalidad || 'V')}
        </div>
        <div style="font-size:10px;color:#64748b;margin-top:3px;">📂 ${employee.gerencia}</div>
      </div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:6px;">
        <div>
          <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">Cédula de Identidad</div>
          <div style="font-family:monospace;font-size:19px;font-weight:800;color:#003366;">${employee.cedula}</div>
        </div>
        ${_qr(qrSrc, 56)}
      </div>
    </div>
  </div>
  ${_stripe(9)}
</div>`;
}

// ── ANVERSO VERTICAL (7 campos) ───────────────────────────────────────────────
function card2025Vertical(photo, qrSrc) {
  return `<div id="id-card-print" style="width:320px;height:506px;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}padding:18px 16px 30px;text-align:center;color:#fff;">
    <img src="${MOCK_LOGO}" style="width:44px;height:44px;border-radius:50%;border:2px solid rgba(255,255,255,.3);margin-bottom:6px;" />
    <div style="font-size:7px;opacity:.7;letter-spacing:1.5px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
    <div style="font-size:12px;font-weight:800;margin-top:3px;">Tesorería de Seguridad Social</div>
    <div style="font-size:7px;opacity:.55;margin-top:1px;">CARNET DE IDENTIFICACIÓN</div>
  </div>
  <div style="display:flex;justify-content:center;margin-top:-24px;position:relative;z-index:2;">
    <div style="width:100px;height:130px;background:#fff;padding:3px;border-radius:9px;box-shadow:0 4px 16px rgba(0,0,0,.18);">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;border-radius:7px;background:#e2e8f0;" />
    </div>
  </div>
  <div style="flex:1;display:flex;flex-direction:column;align-items:center;padding:12px 18px 0;text-align:center;">
    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Apellidos / Nombres</div>
    <div style="font-size:17px;font-weight:800;color:#0f172a;line-height:1.15;">${employee.apellidos}</div>
    <div style="font-size:12px;font-weight:500;color:#334155;margin-bottom:5px;">${employee.nombres}</div>
    <div style="display:flex;gap:5px;flex-wrap:wrap;justify-content:center;margin-bottom:3px;">
      <span style="background:#eff6ff;color:#003366;padding:2px 10px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">${employee.cargo}</span>
    </div>
    <div style="font-size:10px;color:#64748b;margin-bottom:3px;">📂 ${employee.gerencia}</div>
    <div>${_nacBadge(employee.nacionalidad || 'V')}</div>
    <div style="width:100%;margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0;text-align:center;">
      <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">Cédula de Identidad</div>
      <div style="font-family:monospace;font-size:20px;font-weight:800;color:#003366;margin-top:2px;">${employee.cedula}</div>
    </div>
  </div>
  <div style="display:flex;justify-content:center;padding:10px 0 14px;">${_qr(qrSrc, 58)}</div>
  ${_stripe(9)}
</div>`;
}

// ── REVERSO HORIZONTAL (8 campos) ────────────────────────────────────────────
function cardReversoHorizontal(qrSrc) {
  return `<div id="id-card-print-reverso" style="width:520px;aspect-ratio:86/54;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  <div style="height:36px;background:#f8fafc;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #e2e8f0;">
    <span style="font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:2.5px;text-transform:uppercase;">Documento de Identificación</span>
  </div>
  <div style="flex:1;display:flex;gap:0;padding:0;">
    <!-- LEFT: datos personales -->
    <div style="flex:1;padding:12px 16px;display:flex;flex-direction:column;justify-content:space-between;border-right:1px solid #e2e8f0;">
      <div>
        <div style="font-size:14px;font-weight:800;color:#003366;line-height:1.2;">${employee.apellidos}</div>
        <div style="font-size:11px;font-weight:500;color:#334155;margin-bottom:5px;">${employee.nombres}</div>
        <div style="font-family:monospace;font-size:12px;color:#475569;margin-bottom:6px;">${employee.cedula}</div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;">
          <span style="background:#ede9fe;color:#5b21b6;padding:2px 9px;border-radius:4px;font-size:9px;font-weight:700;">🔐 ${employee.nivel_permiso || 'Nivel 1'}</span>
          ${_nacBadge(employee.nacionalidad || 'V')}
        </div>
        <div style="font-size:10px;color:#64748b;margin-top:5px;">📅 Ingreso: ${ui.formatDate(employee.fecha_ingreso)}</div>
      </div>
      <div>
        <div style="border-top:1px solid #94a3b8;width:150px;margin-bottom:3px;"></div>
        <div style="font-size:9px;color:#94a3b8;font-weight:600;letter-spacing:1px;">FIRMA DEL TITULAR</div>
      </div>
    </div>
    <!-- RIGHT: QR + emergencia -->
    <div style="width:170px;padding:12px 14px;display:flex;flex-direction:column;align-items:center;justify-content:space-between;">
      ${_qr(qrSrc, 70)}
      <div style="width:100%;font-size:9.5px;color:#64748b;text-align:left;line-height:1.55;">
        <div style="font-weight:700;font-size:9px;color:#475569;margin-bottom:3px;">En caso de emergencia:</div>
        <div style="border-bottom:1px solid #cbd5e1;margin-bottom:8px;"></div>
        <div style="border-bottom:1px solid #cbd5e1;"></div>
      </div>
    </div>
  </div>
  ${_stripe(9)}
</div>`;
}

// ── REVERSO VERTICAL (8 campos) ───────────────────────────────────────────────
function cardReversoVertical(qrSrc) {
  return `<div id="id-card-print-reverso" style="width:320px;height:506px;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  <div style="background:#f8fafc;padding:12px;text-align:center;border-bottom:1px solid #e2e8f0;">
    <span style="font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:2.5px;text-transform:uppercase;">Documento de Identificación</span>
  </div>
  <div style="flex:1;padding:18px 20px;display:flex;flex-direction:column;justify-content:space-between;">
    <div>
      <div style="font-size:16px;font-weight:800;color:#003366;line-height:1.2;">${employee.apellidos}</div>
      <div style="font-size:12px;font-weight:500;color:#334155;margin-bottom:4px;">${employee.nombres}</div>
      <div style="font-family:monospace;font-size:12px;color:#475569;margin-bottom:6px;">${employee.cedula}</div>
      <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:5px;">
        <span style="background:#ede9fe;color:#5b21b6;padding:2px 10px;border-radius:4px;font-size:9px;font-weight:700;">🔐 ${employee.nivel_permiso || 'Nivel 1'}</span>
        ${_nacBadge(employee.nacionalidad || 'V')}
      </div>
      <div style="font-size:10px;color:#64748b;">📅 Ingreso: ${ui.formatDate(employee.fecha_ingreso)}</div>
    </div>
    <div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:10px;color:#64748b;line-height:1.6;">
      Este carnet es personal e intransferible. Su uso indebido será sancionado conforme a la ley.
      <div style="margin-top:12px;">
        <div style="font-size:9px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">En caso de emergencia:</div>
        <div style="border-bottom:1px solid #cbd5e1;margin-top:8px;"></div>
        <div style="border-bottom:1px solid #cbd5e1;margin-top:12px;"></div>
      </div>
    </div>
    <div>
      <div style="display:flex;justify-content:center;margin-bottom:10px;">${_qr(qrSrc, 68)}</div>
      <div style="text-align:center;">
        <div style="border-top:1.5px solid #94a3b8;width:55%;margin:0 auto;"></div>
        <div style="font-size:9px;color:#94a3b8;margin-top:4px;font-weight:600;letter-spacing:1px;">FIRMA DEL TITULAR</div>
      </div>
    </div>
  </div>
  ${_stripe(9)}
</div>`;
}

// ── CLÁSICO HORIZONTAL (7 campos) ────────────────────────────────────────────
function cardClassic(photo, qrSrc) {
  return `<div id="id-card-print" style="width:520px;aspect-ratio:86/54;font-family:'Segoe UI',Tahoma,sans-serif;background:#fff;border-radius:10px;box-shadow:0 8px 22px rgba(0,0,0,.1);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}height:50px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:10px;">
      <img src="${MOCK_LOGO}" style="width:30px;height:30px;border-radius:50%;" />
      <div>
        <div style="font-size:12px;font-weight:700;color:#fff;">Tesorería de Seguridad Social</div>
        <div style="font-size:8px;color:#94a3b8;letter-spacing:1px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
      </div>
    </div>
    <div style="font-size:9px;color:#94a3b8;font-weight:600;letter-spacing:1px;">CARNET OFICIAL</div>
  </div>
  <div style="flex:1;display:flex;padding:12px 18px;gap:14px;">
    <div style="width:90px;min-height:106px;border:2px solid #e2e8f0;border-radius:7px;overflow:hidden;flex-shrink:0;">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;background:#f1f5f9;" />
    </div>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="font-size:16px;font-weight:700;color:#1e293b;line-height:1.2;">${employee.apellidos}</div>
        <div style="font-size:12px;font-weight:500;color:#475569;margin-bottom:4px;">${employee.nombres}</div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
          <span style="background:#f1f5f9;color:#1e3a5f;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;">${employee.cargo}</span>
          <span style="font-size:10px;color:#64748b;">— ${employee.gerencia}</span>
          ${_nacBadge(employee.nacionalidad || 'V')}
        </div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:flex-end;">
        <div>
          <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">C.I.</div>
          <div style="font-family:monospace;font-size:16px;font-weight:700;color:#0f172a;">${employee.cedula}</div>
        </div>
        ${_qr(qrSrc, 52)}
      </div>
    </div>
  </div>
  ${_stripe(7)}
</div>`;
}

// ── CLÁSICO VERTICAL (7 campos) ───────────────────────────────────────────────
function cardClassicVertical(photo, qrSrc) {
  return `<div id="id-card-print" style="width:320px;height:506px;font-family:'Segoe UI',Tahoma,sans-serif;background:#fff;border-radius:10px;box-shadow:0 8px 22px rgba(0,0,0,.1);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}padding:14px 16px;text-align:center;color:#fff;">
    <img src="${MOCK_LOGO}" style="width:36px;height:36px;border-radius:50%;margin-bottom:4px;" />
    <div style="font-size:11px;font-weight:700;">Tesorería de Seguridad Social</div>
    <div style="font-size:8px;color:#94a3b8;letter-spacing:1px;margin-top:1px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
  </div>
  <div style="flex:1;display:flex;flex-direction:column;align-items:center;padding:16px 18px 0;">
    <div style="width:96px;height:124px;border:2px solid #e2e8f0;border-radius:7px;overflow:hidden;margin-bottom:12px;">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;background:#f1f5f9;" />
    </div>
    <div style="font-size:17px;font-weight:700;color:#1e293b;text-align:center;line-height:1.2;">${employee.apellidos}</div>
    <div style="font-size:11px;font-weight:500;color:#475569;margin:2px 0 5px;text-align:center;">${employee.nombres}</div>
    <span style="background:#f1f5f9;color:#1e3a5f;padding:2px 10px;border-radius:4px;font-size:10px;font-weight:700;">${employee.cargo}</span>
    <div style="font-size:10px;color:#64748b;margin-top:3px;">📂 ${employee.gerencia}</div>
    <div style="margin-top:4px;">${_nacBadge(employee.nacionalidad || 'V')}</div>
    <div style="width:100%;margin-top:14px;border-top:1px solid #e2e8f0;padding-top:10px;text-align:center;">
      <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">C.I.</div>
      <div style="font-family:monospace;font-size:19px;font-weight:700;color:#0f172a;margin-top:2px;">${employee.cedula}</div>
    </div>
  </div>
  <div style="display:flex;justify-content:center;padding:10px 0 14px;">${_qr(qrSrc, 54)}</div>
  ${_stripe(7)}
</div>`;
}

// ── PDF EXPORT ────────────────────────────────────────────────────────────────
async function downloadPDF() {
  if (!employee) return;
  if (typeof html2pdf === 'undefined') {
    alert('Librería PDF no disponible.\nUsa "🖨️ Imprimir Físico" como alternativa.');
    return;
  }
  const btn = document.getElementById('btn-download');
  ui.setLoading(btn, true, 'Generando PDF...');
  const isVert = currentOrientation === 'vertical';
  const photo = employee.photo_url || makeAvatar(`${employee.nombres} ${employee.apellidos}`);
  const qrSrc = qrDataUrl || '';
  const filename = `carnet_${employee.cedula.replace(/[^a-zA-Z0-9]/g, '_')}.pdf`;
  const container = document.createElement('div');
  container.style.cssText = 'position:fixed;left:-9999px;top:0;z-index:-1;background:#fff;';
  container.innerHTML = `<div>${isVert ? card2025Vertical(photo, qrSrc) : card2025Horizontal(photo, qrSrc)}</div>
        <div style="page-break-before:always;" class="html2pdf__page-break"></div>
        <div>${isVert ? cardReversoVertical(qrSrc) : cardReversoHorizontal(qrSrc)}</div>`;
  document.body.appendChild(container);
  try {
    await html2pdf().set({
      margin: 0, filename,
      image: { type: 'jpeg', quality: 1.0 },
      html2canvas: { scale: 3, useCORS: true },
      jsPDF: { unit: 'mm', format: isVert ? [54, 86] : [86, 54], orientation: isVert ? 'portrait' : 'landscape' },
    }).from(container).save();
    showEditorToast('PDF generado correctamente.', 'success');
  } catch (err) {
    console.error(err);
    showEditorToast('Error al generar el PDF.', 'danger');
  } finally {
    document.body.removeChild(container);
    ui.setLoading(btn, false);
  }
}

// ── FORMULARIO ────────────────────────────────────────────────────────────────
function setupInlineEdit() {
  const form = document.getElementById('form-edit-employee');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btn-save-fields');
    const fields = {
      nombres: (document.getElementById('edit-nombres')?.value || '').trim(),
      apellidos: (document.getElementById('edit-apellidos')?.value || '').trim(),
      cargo: (document.getElementById('edit-cargo')?.value || '').trim(),
      gerencia: (document.getElementById('edit-gerencia')?.value || '').trim(),
      nacionalidad: (document.getElementById('edit-nacionalidad')?.value || 'V').trim(),
      nivel_permiso: (document.getElementById('edit-nivel-permiso')?.value || '').trim(),
    };
    if (!fields.nombres || !fields.apellidos || !fields.cargo || !fields.gerencia) {
      showEditorToast('Nombres, Apellidos, Cargo y Gerencia son obligatorios.', 'danger');
      return;
    }
    ui.setLoading(btn, true, 'Guardando...');
    try {
      await api.updateEmployee(employee.id, fields);
      Object.assign(employee, fields);
      renderDetails();
      renderCard();
      showEditorToast('Cambios guardados.', 'success');
    } catch (err) {
      showEditorToast(err.message || 'Error al guardar.', 'danger');
    } finally { ui.setLoading(btn, false); }
  });
}

// ── FOTOGRAFÍA CON CROPPER.JS (ROBUSTO) ─────────────────────────────────────────
function setupManualPhoto() {
  const input = document.getElementById('manual-photo-input');
  if (!input) return;  // Solo el input es estrictamente necesario

  const modal = document.getElementById('crop-modal');
  const cropImg = document.getElementById('crop-image');
  const btnCancel = document.getElementById('btn-crop-cancel');
  const btnApply = document.getElementById('btn-crop-apply');

  // Si el modal o Cropper.js no están disponibles, modo directo sin recorte
  const canCrop = !!(modal && cropImg && typeof Cropper !== 'undefined');

  // Helper: persiste la foto y re-renderiza el carnet
  async function applyPhoto(dataUrl) {
    try {
      await api.updateEmployee(employee.id, { photo_url: dataUrl });
      employee.photo_url = dataUrl;
      renderCard();
      renderDetails();
      const btnR = document.getElementById('btn-remove-photo');
      if (btnR) btnR.style.display = 'inline-flex';
      showEditorToast('Fotografía cargada y aplicada correctamente.', 'success');
    } catch (err) {
      showEditorToast(err.message || 'Error al guardar la foto.', 'danger');
    }
  }

  input.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    input.value = '';  // Permite re-seleccionar el mismo archivo

    const reader = new FileReader();
    reader.onerror = () => showEditorToast('No se pudo leer el archivo. Intente con otra imagen.', 'danger');

    if (!canCrop) {
      // ── Modo directo (sin modal de recorte) ──
      reader.onload = (ev) => applyPhoto(ev.target.result);
      reader.readAsDataURL(file);
      return;
    }

    // ── Modo con Cropper.js ──
    reader.onload = (ev) => {
      if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
      cropImg.src = ev.target.result;
      modal.style.display = 'flex';
      const onLoad = () => {
        cropImg.removeEventListener('load', onLoad);
        cropperInstance = new Cropper(cropImg, {
          aspectRatio: 3 / 4, viewMode: 1, autoCropArea: 0.85,
          movable: true, zoomable: true, rotatable: false, scalable: false,
        });
      };
      // Soporta imagen ya cacheada
      if (cropImg.complete && cropImg.naturalWidth) { onLoad(); }
      else { cropImg.addEventListener('load', onLoad); }
    };
    reader.readAsDataURL(file);
  });

  if (btnCancel) {
    btnCancel.addEventListener('click', () => {
      if (modal) modal.style.display = 'none';
      if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
    });
  }

  if (btnApply) {
    btnApply.addEventListener('click', async () => {
      if (!cropperInstance) return;
      const croppedDataUrl = cropperInstance
        .getCroppedCanvas({ width: 300, height: 400, imageSmoothingQuality: 'high' })
        .toDataURL('image/jpeg', 0.92);
      if (modal) modal.style.display = 'none';
      cropperInstance.destroy(); cropperInstance = null;
      await applyPhoto(croppedDataUrl);
    });
  }
}

function setupRemovePhoto() {
  const btn = document.getElementById('btn-remove-photo');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    if (!confirm('¿Eliminar la fotografía actual?')) return;
    try {
      await api.updateEmployee(employee.id, { photo_url: '' });
      employee.photo_url = '';
      renderCard(); btn.style.display = 'none';
      showEditorToast('Fotografía eliminada.', 'success');
    } catch (err) { showEditorToast(err.message, 'danger'); }
  });
}

// ── TAREA 5: FONDO PERSONALIZADO ─────────────────────────────────────────────
function setupCustomBackground() {
  const input = document.getElementById('custom-bg-input');
  const btnReset = document.getElementById('btn-reset-bg');
  if (!input) return;

  input.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      customBackground = ev.target.result;
      renderCard();
      showEditorToast('Fondo personalizado aplicado.', 'success');
    };
    reader.readAsDataURL(file);
    input.value = '';
  });

  if (btnReset) {
    btnReset.addEventListener('click', () => {
      customBackground = null;
      renderCard();
      showEditorToast('Fondo restablecido al predeterminado.', 'success');
    });
  }
}

// ── IMAGENES ANVERSO/REVERSO DEL CARNET ──────────────────────────────────────
function setupCardImages() {
  // Anverso (Front)
  const frontInput = document.getElementById('card-front-input');
  const btnResetFront = document.getElementById('btn-reset-front');
  if (frontInput) {
    frontInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (ev) => {
        cardFrontImage = ev.target.result;
        const preview = document.getElementById('front-preview');
        const img = document.getElementById('front-preview-img');
        if (preview && img) { preview.style.display = 'block'; img.src = cardFrontImage; }
        renderCard();
        showEditorToast('Imagen de anverso cargada.', 'success');
      };
      reader.readAsDataURL(file);
      frontInput.value = '';
    });
  }
  if (btnResetFront) {
    btnResetFront.addEventListener('click', () => {
      cardFrontImage = null;
      const preview = document.getElementById('front-preview');
      if (preview) preview.style.display = 'none';
      renderCard();
      showEditorToast('Imagen de anverso eliminada.', 'success');
    });
  }

  // Reverso (Back)
  const backInput = document.getElementById('card-back-input');
  const btnResetBack = document.getElementById('btn-reset-back');
  if (backInput) {
    backInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (ev) => {
        cardBackImage = ev.target.result;
        const preview = document.getElementById('back-preview');
        const img = document.getElementById('back-preview-img');
        if (preview && img) { preview.style.display = 'block'; img.src = cardBackImage; }
        renderCard();
        showEditorToast('Imagen de reverso cargada.', 'success');
      };
      reader.readAsDataURL(file);
      backInput.value = '';
    });
  }
  if (btnResetBack) {
    btnResetBack.addEventListener('click', () => {
      cardBackImage = null;
      const preview = document.getElementById('back-preview');
      if (preview) preview.style.display = 'none';
      renderCard();
      showEditorToast('Imagen de reverso eliminada.', 'success');
    });
  }
}

function setupSmartExtraction() {
  const btn = document.getElementById('btn-smart-extract');
  const input = document.getElementById('smart-extract-input');
  if (!btn || !input) return;
  btn.addEventListener('click', () => input.click());
  input.addEventListener('change', async (e) => {
    if (!e.target.files[0]) return;
    ui.setLoading(btn, true, 'Extrayendo...');
    try {
      const res = await api.smartExtraction();
      if (res?.data) {
        employee = Object.assign({}, employee, res.data);
        generateQR(); renderDetails(); renderCard();
        showEditorToast('Datos extraídos correctamente.', 'success');
      }
    } catch (err) { showEditorToast(err.message, 'danger'); }
    finally { ui.setLoading(btn, false); input.value = ''; }
  });
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
function showEditorToast(message, type = 'success') {
  const c = {
    success: { bg: '#d1fae5', color: '#065f46', border: '#6ee7b7' },
    danger: { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' },
    info: { bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' },
  }[type] || { bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' };
  const t = document.createElement('div');
  t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:99999;background:${c.bg};color:${c.color};border:1px solid ${c.border};padding:12px 20px;border-radius:10px;font-size:.875rem;font-weight:500;box-shadow:0 4px 20px rgba(0,0,0,.12);`;
  t.textContent = message;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

function printCurrentCard() { window.print(); }

// ── GLOBALS ───────────────────────────────────────────────────────────────────
window.setTemplate = setTemplate;
window.setOrientation = setOrientation;
window.setFace = setFace;
window.printCurrentCard = printCurrentCard;

init();