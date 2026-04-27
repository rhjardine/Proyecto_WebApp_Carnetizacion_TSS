/**
 * editor.js — Editor de Carnet SCI-TSS
 * =====================================================
 * REFACTORIZACIÓN v3.3 (Producción Segura + Resiliencia)
 * - Manejo de fallos al cargar IDs "fantasmas" eliminados desde localStorage.
 * - Modal elegante (SweetAlert2) para confirmación de borrado de foto.
 * - Fallback de Avatar (getSafeAvatar) para prevenir colapsos.
 * - Binding explícito de eventos en controles de diseño.
 */
'use strict';

let employee = null;
let currentTemplate = 'blanco';
let currentOrientation = 'vertical';
let currentFace = 'anverso';
let qrDataUrl = null;
let customBackground = null;   // fondo personalizado (Base64)
let cardFrontImage = null;   // Imagen anverso personalizada (Base64)
let cardBackImage = null;   // Imagen reverso personalizada (Base64)
let cropperInstance = null;   // Cropper.js

// ── INIT ──────────────────────────────────────────────────────────────────────
async function init() {
  // Guard: si no hay sesion activa, redirigir al login
  const sessionUser = typeof api !== 'undefined' ? api.getCurrentUser() : null;
  if (!sessionUser || !sessionUser.username) {
    window.location.href = 'login.html';
    return;
  }

  if (typeof api.initCsrf === 'function') await api.initCsrf();

  const user = api.getCurrentUser();
  if (user.username) {
    const ra = document.getElementById('user-role');
    const displayName = user.full_name
      ? `${user.username} (${user.full_name})`
      : user.username.charAt(0).toUpperCase() + user.username.slice(1);
    const el = document.getElementById('user-name'); if (el) el.textContent = displayName;
    if (ra) {
      const effRole = (user.effective_role || user.role || '').toUpperCase();
      let rt = 'Solo Consulta';
      if (effRole === 'ADMIN') rt = 'Administrador';
      if (effRole === 'COORD') rt = 'Coordinador';
      if (effRole === 'ANALISTA') rt = 'Analista';

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
  }

  const btnLogout = document.getElementById('btn-logout');
  if (btnLogout) {
    btnLogout.addEventListener('click', async () => {
      sessionStorage.removeItem('current_user');
      await api.logout();
      window.location.href = 'login.html';
    });
  }

  const btnDownload = document.getElementById('btn-download');
  if (btnDownload) btnDownload.addEventListener('click', downloadPDF);

  const btnDelete = document.getElementById('btn-delete-employee');
  if (btnDelete) {
    if (!api.isAdmin()) {
      btnDelete.style.display = 'none';
    } else {
      btnDelete.addEventListener('click', async () => {
        if (!employee) return;
        const nombreCompleto = `${employee.apellidos || employee.primer_apellido}, ${employee.nombres || employee.primer_nombre}`;
        if (typeof Swal !== 'undefined') {
          const result = await Swal.fire({
            title: '¿Confirmar eliminación?',
            text: `¿Eliminar permanentemente a "${nombreCompleto}"?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
          });
          if (!result.isConfirmed) return;
        } else {
          if (!confirm(`¿Eliminar permanentemente a "${nombreCompleto}"?`)) return;
        }

        try {
          await api.deleteEmployee(employee.id);
          // FIX: Limpiamos el ID del localStorage para evitar el error "Fantasma"
          localStorage.removeItem('selected_employee_id');
          showEditorToast('Funcionario eliminado.', 'success');
          setTimeout(() => window.location.href = 'dashboard.html', 1800);
        } catch (err) { showEditorToast(err.message, 'danger'); }
      });
    }
  }

  applyConsultaRestrictions();

  try {
    await populateGerenciaSelect();
  } catch (err) {
    console.warn('No se pudo cargar la lista de gerencias:', err.message);
  }

  setupManualPhoto();
  setupSmartExtraction();
  setupInlineEdit();
  setupRemovePhoto(); // UX Mejorada
  setupCustomBackground();
  setupCardImages();

  // Blindaje de Event Listeners para botones de Plantilla, Orientación y Cara
  const templateSelector = document.getElementById('template-selector');
  if (templateSelector) {
    currentTemplate = templateSelector.value || 'blanco';
    templateSelector.addEventListener('change', (e) => setTemplate(e.target.value));
  }

  const oriH = document.getElementById('ori-h');
  if (oriH) oriH.addEventListener('click', () => setOrientation('horizontal'));

  const oriV = document.getElementById('ori-v');
  if (oriV) oriV.addEventListener('click', () => setOrientation('vertical'));

  const faceA = document.getElementById('face-anverso');
  if (faceA) faceA.addEventListener('click', () => setFace('anverso'));

  const faceR = document.getElementById('face-reverso');
  if (faceR) faceR.addEventListener('click', () => setFace('reverso'));

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('mode') === 'view') {
    applyConsultaRestrictions(true);
  }

  // ── MANEJO RESILIENTE DE CARGA DE EMPLEADOS ────────────────────────────────
  const rawUrlId = urlParams.get('id');
  const urlId = (rawUrlId && rawUrlId !== 'undefined' && rawUrlId !== 'null') ? rawUrlId : null;
  const urlCedula = (urlParams.get('cedula') || '').replace(/[^0-9]/g, '');

  let storedId = urlId || localStorage.getItem('selected_employee_id');
  if (storedId === 'undefined' || storedId === 'null') storedId = null;

  if (urlId) localStorage.setItem('selected_employee_id', urlId);

  try {
    let list = [];

    // 1er Intento: Cargar el ID almacenado en memoria o URL
    if (storedId) {
      try {
        const res = await api.getEmployees({ id: storedId });
        list = Array.isArray(res.data) ? res.data : (res.data ? [res.data] : []);
        if (!list || !list.length) throw new Error("Empleado no encontrado");
      } catch (e) {
        console.warn("El empleado guardado fue eliminado o no existe. Limpiando selección...");
        localStorage.removeItem('selected_employee_id');
        storedId = null; // Forzamos el fallback
      }
    }

    // 2do Intento: Fallback si no había ID o el ID era un "fantasma" eliminado
    if (!storedId) {
      if (urlCedula) {
        const res = await api.getEmployees({ cedula: urlCedula, limit: 1 });
        list = Array.isArray(res.data) ? res.data : (res.data ? [res.data] : []);
      } else {
        const res = await api.getEmployees({ limit: 1 }); // Trae al último registrado
        list = Array.isArray(res.data) ? res.data : (res.data ? [res.data] : []);
      }
    }

    // Validación Final
    if (!list || !list.length) {
      showEditorToast(`No hay empleados registrados en la base de datos para mostrar.`, 'info');
      return; // Detenemos la carga gráfica sin lanzar errores rojos
    }

    employee = list[0];
    generateQR();
    renderDetails();
    renderCard();
    setupZoom();
  } catch (err) {
    console.error(err);
    showEditorToast('Error de red al cargar los datos.', 'danger');
  }
}

// ── RESTRICCIONES PARA ROL CONSULTA ─────────────────────────────────────────
function applyConsultaRestrictions(force = false) {
  const isAdminCoord = api.isAdminCoord();
  const isAdmin = api.isAdmin();
  const user = api.getCurrentUser();
  const role = (user.effective_role || user.role || '').toUpperCase();
  const isConsulta = (role === 'CONSULTA' || role === 'USUARIO');

  const shouldBlock = force || (!isAdminCoord && isConsulta);

  if (shouldBlock) {
    document.querySelectorAll('#form-edit-employee input').forEach(el => el.setAttribute('readonly', 'true'));
    document.querySelectorAll('#form-edit-employee select').forEach(el => { el.disabled = true; });

    ['btn-save-fields', 'btn-delete-employee', 'btn-upload-photo', 'btn-remove-photo', 'btn-smart-extract',
      'btn-upload-bg', 'btn-reset-bg', 'btn-upload-front', 'btn-reset-front', 'btn-upload-back', 'btn-reset-back'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
      });

    const btnPrint = document.querySelector('button[onclick="printCurrentCard()"]');
    if (btnPrint) btnPrint.style.display = 'none';

    const photoModule = document.getElementById('card-photo-module');
    if (photoModule) photoModule.style.display = 'none';

    showEditorToast('Modo solo consulta — edición e impresión física deshabilitadas.', 'info');
  } else if (isAdminCoord) {
    document.querySelectorAll('#form-edit-employee input').forEach(el => {
      if (el.id !== 'edit-cedula') el.removeAttribute('readonly');
    });
    document.querySelectorAll('#form-edit-employee select').forEach(el => { el.disabled = false; });

    ['btn-save-fields', 'btn-upload-photo', 'btn-remove-photo', 'btn-smart-extract',
      'btn-upload-bg', 'btn-reset-bg', 'btn-upload-front', 'btn-reset-front', 'btn-upload-back', 'btn-reset-back'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = '';
      });

    const btnDelete = document.getElementById('btn-delete-employee');
    if (btnDelete) {
      if (role !== 'ADMIN') {
        btnDelete.style.display = 'none';
      } else {
        btnDelete.style.display = '';
      }
    }

    const photoModule = document.getElementById('card-photo-module');
    if (photoModule) photoModule.style.display = 'block';
  }
}

// ── ZOOM ──────────────────────────────────────────────────────────────────────
let currentZoom = 100;
function setupZoom() {
  const savedZoom = parseInt(sessionStorage.getItem('editor_zoom') || '100', 10);
  currentZoom = savedZoom;
  _applyZoom();
}
function adjustZoom(delta) {
  currentZoom = Math.min(200, Math.max(40, currentZoom + delta));
  _applyZoom();
  sessionStorage.setItem('editor_zoom', String(currentZoom));
}
function resetZoom() {
  currentZoom = 100;
  _applyZoom();
  sessionStorage.removeItem('editor_zoom');
}
function _applyZoom() {
  const wrapper = document.getElementById('id-card-wrapper');
  const label = document.getElementById('zoom-label');
  if (wrapper) wrapper.style.transform = `scale(${currentZoom / 100})`;
  if (label) label.textContent = currentZoom + '%';
}

// ── QR ────────────────────────────────────────────────────────────────────────
function generateQR() {
  if (typeof QRious === 'undefined') { renderCard(); return; }
  const canvas = document.createElement('canvas');
  new QRious({
    element: canvas, value: `${typeof VALIDATION_BASE_URL !== 'undefined' ? VALIDATION_BASE_URL : ''}?id=${employee.id}`,
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
  if (!employee) return;
  const primerNombre = (employee.primer_nombre || employee.nombres?.split(' ')[0] || '').trim();
  const segundoNombre = (employee.segundo_nombre || employee.nombres?.split(' ').slice(1).join(' ') || '').trim();
  const primerApellido = (employee.primer_apellido || employee.apellidos?.split(' ')[0] || '').trim();
  const segundoApellido = (employee.segundo_apellido || employee.apellidos?.split(' ').slice(1).join(' ') || '').trim();

  const nombresCompletos = [primerNombre, segundoNombre].filter(Boolean).join(' ');
  const apellidosCompletos = [primerApellido, segundoApellido].filter(Boolean).join(' ');

  const sub = document.getElementById('editor-subtitle');
  if (sub) sub.textContent = `${apellidosCompletos}, ${nombresCompletos}`;

  const set = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.value = val || '';
  };

  set('edit-primer-nombre', primerNombre);
  set('edit-segundo-nombre', segundoNombre);
  set('edit-primer-apellido', primerApellido);
  set('edit-segundo-apellido', segundoApellido);
  set('edit-nombres', nombresCompletos);
  set('edit-apellidos', apellidosCompletos);

  const cedulaNum = (employee.cedula || '').replace(/[^0-9]/g, '');
  set('edit-cedula', cedulaNum);
  set('edit-cargo', employee.cargo);
  set('edit-gerencia', employee.gerencia);
  set('edit-nacionalidad', employee.nacionalidad || 'V');
  set('edit-nivel-permiso', employee.nivel_permiso || 'Nivel 1');

  const dynContainer = document.getElementById('dynamic-fields-container');
  if (dynContainer) {
    dynContainer.innerHTML = '';
    if (employee.datos_adicionales) {
      let dt = employee.datos_adicionales;
      if (typeof dt === 'string') {
        try { dt = JSON.parse(dt); } catch (e) { }
      }
      if (dt && typeof dt === 'object') {
        for (const [k, v] of Object.entries(dt)) {
          if (typeof window.addDynamicField === 'function') {
            window.addDynamicField(k, v);
          }
        }
      }
    }
  }

  const btnR = document.getElementById('btn-remove-photo');
  const hasPhoto = !!(employee.photo_url || employee.foto_url);
  if (btnR) btnR.style.display = hasPhoto ? 'inline-flex' : 'none';
}

// ── CONTROLES SEGUROS ─────────────────────────────────────────────────────────
function setTemplate(t) {
  if (!employee) return;
  currentTemplate = t;
  renderCard();
}

function setOrientation(o) {
  if (!employee) return;
  currentOrientation = o;
  const h = document.getElementById('ori-h');
  const v = document.getElementById('ori-v');

  if (h && v) {
    if (o === 'horizontal') {
      h.style.backgroundColor = 'var(--color-primary, #0f172a)'; h.style.color = '#fff';
      v.style.backgroundColor = '#f1f5f9'; v.style.color = '#64748b';
    } else {
      v.style.backgroundColor = 'var(--color-primary, #0f172a)'; v.style.color = '#fff';
      h.style.backgroundColor = '#f1f5f9'; h.style.color = '#64748b';
    }
  }
  renderCard();
}

function setFace(face) {
  if (!employee) return;
  currentFace = face;
  ['anverso', 'reverso'].forEach(f => {
    const b = document.getElementById(`face-${f}`);
    if (!b) return;
    b.style.background = face === f ? 'var(--color-primary, #0f172a)' : 'transparent';
    b.style.color = face === f ? '#fff' : 'var(--color-muted, #64748b)';
  });
  renderCard();
}

// ── GENERADOR DE AVATAR ROBUSTO ───────────────────────────────────────────────
function getSafeAvatar(name) {
  if (typeof window.makeAvatar === 'function') return window.makeAvatar(name);
  if (typeof ui !== 'undefined' && typeof ui.makeAvatar === 'function') return ui.makeAvatar(name);

  const canvas = document.createElement('canvas');
  canvas.width = 120; canvas.height = 160;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#e2e8f0'; ctx.fillRect(0, 0, 120, 160);
  ctx.fillStyle = '#64748b'; ctx.font = 'bold 48px sans-serif';
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText((name || 'T').charAt(0).toUpperCase(), 60, 80);
  return canvas.toDataURL('image/jpeg');
}

// ── DISPATCHER ────────────────────────────────────────────────────────────────
function renderCard() {
  if (!employee) return;
  const wrapper = document.getElementById('id-card-wrapper');
  if (!wrapper) return;
  const is2025 = currentTemplate === '2025';
  const isVert = currentOrientation === 'vertical';

  const nameForAvatar = `${employee.primer_nombre || employee.nombres || ''} ${employee.primer_apellido || employee.apellidos || ''}`.trim();
  const photo = employee.photo_url || employee.foto_url || getSafeAvatar(nameForAvatar);
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

// ── HELPERS GRAFICOS (Diseño 100% conservado) ─────────────────────────────────
const MOCK_LOGO_URL = typeof MOCK_LOGO !== 'undefined' ? MOCK_LOGO : '';
const _qr = (src, size = 60) => src ? `<img src="${src}" style="width:${size}px;height:${size}px;border:2px solid #e2e8f0;border-radius:5px;" />` : '<div style="width:60px;height:60px;background:#f1f5f9;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#94a3b8;">QR</div>';
const _nacBadge = nac => `<span style="background:${nac === 'E' ? '#fef3c7' : '#eff6ff'};color:${nac === 'E' ? '#92400e' : '#003366'};padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700;">${nac === 'E' ? '🌐 Extranjero' : '🇻🇪 Venezolano/a'}</span>`;
const _headerBg = () => customBackground
  ? `background:url('${customBackground}') center/cover no-repeat;`
  : `background:linear-gradient(135deg,#003366,#0a4a8c);`;

const _nombres = (emp) => {
  const pn = (emp.primer_nombre || '').trim();
  const sn = (emp.segundo_nombre || '').trim();
  return [pn, sn].filter(Boolean).join(' ') || emp.nombres || '';
};

const _apellidos = (emp) => {
  const pa = (emp.primer_apellido || '').trim();
  const sa = (emp.segundo_apellido || '').trim();
  return [pa, sa].filter(Boolean).join(' ') || emp.apellidos || '';
};

const _cedula = (emp) => {
  const nac = emp.nacionalidad || 'V';
  const num = (emp.cedula || '').replace(/[^0-9]/g, '');
  return `${nac}-${num}`;
};

const _headerInstitucional = () => `
  <div style="${_headerBg()}padding:12px 18px 10px;color:#fff;display:flex;justify-content:space-between;align-items:center;">
    <div>
      <div style="font-size:7px;opacity:.75;letter-spacing:1.5px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
      <div style="font-size:12px;font-weight:800;margin-top:2px;">Tesorería de Seguridad Social</div>
      <div style="font-size:7px;opacity:.6;margin-top:1px;">CARNET DE IDENTIFICACIÓN INSTITUCIONAL</div>
    </div>
    <img src="${MOCK_LOGO_URL}" style="width:38px;height:38px;object-fit:contain;border-radius:50%;border:2px solid rgba(255,255,255,.35);" />
  </div>`;

const _headerInstitucionalVertical = () => `
  <div style="${_headerBg()}padding:14px 16px 26px;text-align:center;color:#fff;">
    <img src="${MOCK_LOGO_URL}" style="width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.3);margin-bottom:5px;" />
    <div style="font-size:7px;opacity:.7;letter-spacing:1.5px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
    <div style="font-size:11px;font-weight:800;margin-top:2px;">Tesorería de Seguridad Social</div>
    <div style="font-size:7px;opacity:.55;margin-top:1px;">CARNET DE IDENTIFICACIÓN</div>
  </div>`;

const _footerInstitucional = (height = 9) => `
  <div style="display:flex;flex-direction:column;">
    <div style="background:#002244;padding:2px 10px;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-size:7px;color:rgba(255,255,255,.65);letter-spacing:.5px;">Tesorería de Seguridad Social — TSS</span>
      <span style="font-size:7px;color:rgba(255,255,255,.45);">DOCUMENTO OFICIAL</span>
    </div>
    <div style="height:${height}px;background:linear-gradient(to right,#facc15 33%,#2563eb 33% 66%,#dc2626 66%);"></div>
  </div>`;

// ── PLANTILLAS ────────────────────────────────────────────────────────────────
function card2025Horizontal(photo, qrSrc) {
  const nombres = _nombres(employee);
  const apellidos = _apellidos(employee);
  const cedula = _cedula(employee);
  const fecha = typeof ui !== 'undefined' ? ui.formatDate(employee.fecha_ingreso) : employee.fecha_ingreso;

  return `<div id="id-card-print" style="width:520px;aspect-ratio:86/54;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  ${_headerInstitucional()}
  <div style="position:relative;display:flex;flex:1;">
    <div style="position:absolute;top:-26px;left:16px;width:82px;height:110px;background:#fff;padding:3px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.2);z-index:2;">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;border-radius:6px;background:#e2e8f0;" />
    </div>
    <div style="padding:7px 14px 8px 112px;flex:1;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Apellidos</div>
        <div style="font-size:14px;font-weight:800;color:#0f172a;line-height:1.1;">${apellidos}</div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-top:2px;">Nombres</div>
        <div style="font-size:10px;font-weight:600;color:#334155;margin-bottom:3px;">${nombres}</div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
          <span style="background:#eff6ff;color:#003366;padding:2px 7px;border-radius:4px;font-size:9px;font-weight:700;text-transform:uppercase;">${employee.cargo || ''}</span>
          ${_nacBadge(employee.nacionalidad || 'V')}
        </div>
        <div style="font-size:9px;color:#64748b;margin-top:2px;">🏛 ${employee.gerencia || ''}</div>
      </div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:5px;">
        <div>
          <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">Cédula de Identidad</div>
          <div style="font-family:monospace;font-size:17px;font-weight:800;color:#003366;">${cedula}</div>
          <div style="font-size:8px;color:#94a3b8;margin-top:1px;">📅 Ingreso: ${fecha}</div>
        </div>
        ${_qr(qrSrc, 52)}
      </div>
    </div>
  </div>
  ${_footerInstitucional(9)}
  ${_renderDatosAdicionales(employee.datos_adicionales)}
</div>`;
}

function card2025Vertical(photo, qrSrc) {
  const nombres = _nombres(employee);
  const apellidos = _apellidos(employee);
  const cedula = _cedula(employee);
  const fecha = typeof ui !== 'undefined' ? ui.formatDate(employee.fecha_ingreso) : employee.fecha_ingreso;

  return `<div id="id-card-print" style="width:320px;height:506px;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  ${_headerInstitucionalVertical()}
  <div style="display:flex;justify-content:center;margin-top:-22px;position:relative;z-index:2;">
    <div style="width:96px;height:126px;background:#fff;padding:3px;border-radius:9px;box-shadow:0 4px 16px rgba(0,0,0,.18);">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;border-radius:7px;background:#e2e8f0;" />
    </div>
  </div>
  <div style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 16px 0;text-align:center;">
    <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:1px;">Apellidos</div>
    <div style="font-size:15px;font-weight:800;color:#0f172a;line-height:1.15;">${apellidos}</div>
    <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-top:3px;margin-bottom:1px;">Nombres</div>
    <div style="font-size:10px;font-weight:600;color:#334155;margin-bottom:4px;">${nombres}</div>
    <span style="background:#eff6ff;color:#003366;padding:2px 9px;border-radius:4px;font-size:9px;font-weight:700;text-transform:uppercase;margin-bottom:2px;">${employee.cargo || ''}</span>
    <div style="font-size:9px;color:#64748b;margin-bottom:2px;">🏛 ${employee.gerencia || ''}</div>
    <div>${_nacBadge(employee.nacionalidad || 'V')}</div>
    <div style="width:100%;margin-top:10px;padding-top:8px;border-top:1px solid #e2e8f0;text-align:center;">
      <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">Cédula de Identidad</div>
      <div style="font-family:monospace;font-size:17px;font-weight:800;color:#003366;margin-top:1px;">${cedula}</div>
      <div style="font-size:8px;color:#94a3b8;margin-top:2px;">📅 Ingreso: ${fecha}</div>
    </div>
  </div>
  <div style="display:flex;justify-content:center;padding:8px 0 10px;">${_qr(qrSrc, 54)}</div>
  ${_footerInstitucional(9)}
  ${_renderDatosAdicionales(employee.datos_adicionales)}
</div>`;
}

function cardReversoHorizontal(qrSrc) {
  const nombres = _nombres(employee);
  const apellidos = _apellidos(employee);
  const cedula = _cedula(employee);
  const fecha = typeof ui !== 'undefined' ? ui.formatDate(employee.fecha_ingreso) : employee.fecha_ingreso;

  return `<div id="id-card-print-reverso" style="width:520px;aspect-ratio:86/54;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}height:30px;display:flex;align-items:center;justify-content:center;">
    <span style="font-size:9px;font-weight:700;color:rgba(255,255,255,.85);letter-spacing:2px;text-transform:uppercase;">ESTE CARNET ES INTRANSFERIBLE</span>
  </div>
  <div style="flex:1;display:flex;">
    <div style="flex:1;padding:9px 13px;display:flex;flex-direction:column;justify-content:space-between;border-right:1px solid #e2e8f0;">
      <div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Apellidos</div>
        <div style="font-size:12px;font-weight:800;color:#003366;line-height:1.2;">${apellidos}</div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-top:2px;">Nombres</div>
        <div style="font-size:10px;font-weight:600;color:#334155;margin-bottom:3px;">${nombres}</div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Cédula de Identidad</div>
        <div style="font-family:monospace;font-size:11px;font-weight:700;color:#475569;margin-bottom:3px;">${cedula}</div>
        <div style="font-size:9px;font-weight:600;color:#334155;">${employee.cargo || ''}</div>
        <div style="font-size:8px;color:#64748b;">${employee.gerencia || ''}</div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:2px;">
          <span style="background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:4px;font-size:8px;font-weight:700;">🔐 ${employee.nivel_permiso || 'Nivel 1'}</span>
          ${_nacBadge(employee.nacionalidad || 'V')}
        </div>
        <div style="font-size:8px;color:#64748b;margin-top:2px;">📅 Ingreso: ${fecha}</div>
      </div>
      <div>
        <div style="border-top:1px solid #94a3b8;width:130px;margin-bottom:2px;"></div>
        <div style="font-size:8px;color:#94a3b8;font-weight:600;letter-spacing:1px;">FIRMA DEL TITULAR</div>
      </div>
    </div>
    <div style="width:155px;padding:10px 12px;display:flex;flex-direction:column;align-items:center;justify-content:space-between;">
      ${_qr(qrSrc, 64)}
      <div style="width:100%;font-size:8px;color:#64748b;line-height:1.4;">
        <div style="font-weight:700;font-size:8px;color:#475569;margin-bottom:2px;">En caso de emergencia o extravío:</div>
        <div style="font-weight:700;color:#dc2626;font-size:8.5px;">(0212) 7053400 / 7053401</div>
        <div style="border-bottom:1px solid #cbd5e1;margin-top:5px;"></div>
      </div>
    </div>
  </div>
  ${_footerInstitucional(9)}
  ${_renderDatosAdicionales(employee.datos_adicionales)}
</div>`;
}

function cardReversoVertical(qrSrc) {
  const nombres = _nombres(employee);
  const apellidos = _apellidos(employee);
  const cedula = _cedula(employee);
  const fecha = typeof ui !== 'undefined' ? ui.formatDate(employee.fecha_ingreso) : employee.fecha_ingreso;

  return `<div id="id-card-print-reverso" style="width:320px;height:506px;font-family:Inter,'Segoe UI',sans-serif;background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}padding:9px 14px;text-align:center;">
    <span style="font-size:8px;font-weight:700;color:rgba(255,255,255,.85);letter-spacing:2px;text-transform:uppercase;">ESTE CARNET ES INTRANSFERIBLE</span>
  </div>
  <div style="flex:1;padding:12px 16px;display:flex;flex-direction:column;justify-content:space-between;">
    <div>
      <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Apellidos</div>
      <div style="font-size:14px;font-weight:800;color:#003366;line-height:1.2;">${apellidos}</div>
      <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-top:2px;">Nombres</div>
      <div style="font-size:11px;font-weight:600;color:#334155;margin-bottom:3px;">${nombres}</div>
      <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Cédula de Identidad</div>
      <div style="font-family:monospace;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">${cedula}</div>
      <div style="font-size:10px;font-weight:600;color:#334155;">${employee.cargo || ''}</div>
      <div style="font-size:8px;color:#64748b;margin-bottom:3px;">${employee.gerencia || ''}</div>
      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:3px;">
        <span style="background:#ede9fe;color:#5b21b6;padding:1px 7px;border-radius:4px;font-size:8px;font-weight:700;">🔐 ${employee.nivel_permiso || 'Nivel 1'}</span>
        ${_nacBadge(employee.nacionalidad || 'V')}
      </div>
      <div style="font-size:9px;color:#64748b;">📅 Ingreso: ${fecha}</div>
    </div>
    <div style="background:#f8fafc;border-radius:8px;padding:9px;font-size:8.5px;color:#64748b;line-height:1.55;">
      El portador es trabajador de este instituto. Su uso indebido será sancionado conforme a la ley.
      <div style="margin-top:7px;">
        <div style="font-size:7.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">En caso de emergencia o extravío llamar al:</div>
        <div style="font-weight:700;color:#dc2626;font-size:9px;margin-top:2px;">(0212) 7053400 / 7053401</div>
        <div style="border-bottom:1px solid #cbd5e1;margin-top:5px;"></div>
      </div>
    </div>
    <div>
      <div style="display:flex;justify-content:center;margin-bottom:7px;">${_qr(qrSrc, 62)}</div>
      <div style="text-align:center;">
        <div style="border-top:1.5px solid #94a3b8;width:55%;margin:0 auto;"></div>
        <div style="font-size:8px;color:#94a3b8;margin-top:3px;font-weight:600;letter-spacing:1px;">FIRMA DEL TITULAR</div>
      </div>
    </div>
  </div>
  ${_footerInstitucional(9)}
</div>`;
}

function cardClassic(photo, qrSrc) {
  const nombres = _nombres(employee);
  const apellidos = _apellidos(employee);
  const cedula = _cedula(employee);
  const fecha = typeof ui !== 'undefined' ? ui.formatDate(employee.fecha_ingreso) : employee.fecha_ingreso;

  return `<div id="id-card-print" style="width:520px;aspect-ratio:86/54;font-family:'Segoe UI',Tahoma,sans-serif;background:#fff;border-radius:10px;box-shadow:0 8px 22px rgba(0,0,0,.1);overflow:hidden;display:flex;flex-direction:column;">
  <div style="${_headerBg()}height:44px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:8px;">
      <img src="${MOCK_LOGO_URL}" style="width:26px;height:26px;border-radius:50%;" />
      <div>
        <div style="font-size:11px;font-weight:700;color:#fff;">Tesorería de Seguridad Social</div>
        <div style="font-size:7px;color:rgba(255,255,255,.7);letter-spacing:1px;text-transform:uppercase;">República Bolivariana de Venezuela</div>
      </div>
    </div>
    <div style="font-size:8px;color:rgba(255,255,255,.55);font-weight:600;letter-spacing:1px;">CARNET OFICIAL</div>
  </div>
  <div style="flex:1;display:flex;padding:10px 14px;gap:11px;">
    <div style="width:84px;min-height:98px;border:2px solid #e2e8f0;border-radius:7px;overflow:hidden;flex-shrink:0;">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;background:#f1f5f9;" />
    </div>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Apellidos</div>
        <div style="font-size:13px;font-weight:800;color:#1e293b;line-height:1.2;">${apellidos}</div>
        <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-top:2px;">Nombres</div>
        <div style="font-size:10px;font-weight:500;color:#475569;margin-bottom:3px;">${nombres}</div>
        <div style="display:flex;gap:3px;flex-wrap:wrap;align-items:center;">
          <span style="background:#f1f5f9;color:#1e3a5f;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700;">${employee.cargo || ''}</span>
          <span style="font-size:8px;color:#64748b;">${employee.gerencia || ''}</span>
          ${_nacBadge(employee.nacionalidad || 'V')}
        </div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:flex-end;">
        <div>
          <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">Cédula de Identidad</div>
          <div style="font-family:monospace;font-size:14px;font-weight:700;color:#0f172a;">${cedula}</div>
          <div style="font-size:8px;color:#94a3b8;">📅 ${fecha}</div>
        </div>
        ${_qr(qrSrc, 48)}
      </div>
    </div>
  </div>
  ${_footerInstitucional(7)}
</div>`;
}

function cardClassicVertical(photo, qrSrc) {
  const nombres = _nombres(employee);
  const apellidos = _apellidos(employee);
  const cedula = _cedula(employee);
  const fecha = typeof ui !== 'undefined' ? ui.formatDate(employee.fecha_ingreso) : employee.fecha_ingreso;

  return `<div id="id-card-print" style="width:320px;height:506px;font-family:'Segoe UI',Tahoma,sans-serif;background:#fff;border-radius:10px;box-shadow:0 8px 22px rgba(0,0,0,.1);overflow:hidden;display:flex;flex-direction:column;">
  ${_headerInstitucionalVertical()}
  <div style="display:flex;justify-content:center;margin-top:-20px;position:relative;z-index:2;">
    <div style="width:90px;height:118px;border:2px solid #e2e8f0;border-radius:7px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.14);">
      <img src="${photo}" style="width:100%;height:100%;object-fit:cover;background:#f1f5f9;" />
    </div>
  </div>
  <div style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 14px 0;text-align:center;">
    <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:1px;">Apellidos</div>
    <div style="font-size:14px;font-weight:800;color:#1e293b;text-align:center;line-height:1.2;">${apellidos}</div>
    <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-top:3px;margin-bottom:1px;">Nombres</div>
    <div style="font-size:10px;font-weight:500;color:#475569;margin-bottom:4px;">${nombres}</div>
    <span style="background:#f1f5f9;color:#1e3a5f;padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700;">${employee.cargo || ''}</span>
    <div style="font-size:9px;color:#64748b;margin-top:2px;">${employee.gerencia || ''}</div>
    <div style="margin-top:3px;">${_nacBadge(employee.nacionalidad || 'V')}</div>
    <div style="width:100%;margin-top:10px;border-top:1px solid #e2e8f0;padding-top:8px;text-align:center;">
      <div style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:1.2px;">Cédula de Identidad</div>
      <div style="font-family:monospace;font-size:16px;font-weight:800;color:#0f172a;margin-top:1px;">${cedula}</div>
      <div style="font-size:8px;color:#94a3b8;margin-top:2px;">📅 Ingreso: ${fecha}</div>
    </div>
  </div>
  <div style="display:flex;justify-content:center;padding:8px 0 11px;">${_qr(qrSrc, 50)}</div>
  ${_footerInstitucional(7)}
</div>`;
}

// ── PDF EXPORT ────────────────────────────────────────────────────────────────
async function downloadPDF() {
  if (!employee) return;
  if (typeof html2canvas === 'undefined' || typeof jspdf === 'undefined') {
    alert('Librerías PDF (html2canvas/jspdf) no disponibles.\nUsa "🖨️ Imprimir Físico" como alternativa.');
    return;
  }
  const btn = document.getElementById('btn-download');
  if (typeof ui !== 'undefined') ui.setLoading(btn, true, 'Generando PDF...');

  const isVert = currentOrientation === 'vertical';
  const filename = `carnet_${(employee.cedula || '').replace(/[^a-zA-Z0-9]/g, '_')}.pdf`;
  const container = document.getElementById('id-card-wrapper');

  try {
    // 1) Renderizar Anverso
    setFace('anverso');
    await new Promise(r => setTimeout(r, 200)); // Render safety delay

    const canvasFront = await html2canvas(container, { scale: 3, useCORS: true, logging: false });
    const imgFront = canvasFront.toDataURL('image/jpeg', 1.0);

    // 2) Renderizar Reverso
    setFace('reverso');
    await new Promise(r => setTimeout(r, 200));

    const canvasBack = await html2canvas(container, { scale: 3, useCORS: true, logging: false });
    const imgBack = canvasBack.toDataURL('image/jpeg', 1.0);

    // 3) Instanciar jsPDF
    const pdf = new jspdf.jsPDF({
      orientation: isVert ? 'portrait' : 'landscape',
      unit: 'mm',
      format: isVert ? [54, 86] : [86, 54]
    });

    const w = isVert ? 54 : 86;
    const h = isVert ? 86 : 54;

    // 4) Guardar PDF
    pdf.addImage(imgFront, 'JPEG', 0, 0, w, h);
    pdf.addPage();
    pdf.addImage(imgBack, 'JPEG', 0, 0, w, h);
    pdf.save(filename);

    showEditorToast('PDF generado correctamente.', 'success');
  } catch (err) {
    console.error(err);
    showEditorToast('Error al generar el PDF.', 'danger');
  } finally {
    setFace('anverso'); // Estado original
    if (typeof ui !== 'undefined') ui.setLoading(btn, false);
  }
}

// ── FORMULARIO ────────────────────────────────────────────────────────────────
function setupInlineEdit() {
  const form = document.getElementById('form-edit-employee');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btn-save-fields');

    const primerNombre = (document.getElementById('edit-primer-nombre')?.value || document.getElementById('edit-nombres')?.value?.split(' ')[0] || '').trim();
    const segundoNombre = (document.getElementById('edit-segundo-nombre')?.value || document.getElementById('edit-nombres')?.value?.split(' ').slice(1).join(' ') || '').trim();
    const primerApellido = (document.getElementById('edit-primer-apellido')?.value || document.getElementById('edit-apellidos')?.value?.split(' ')[0] || '').trim();
    const segundoApellido = (document.getElementById('edit-segundo-apellido')?.value || document.getElementById('edit-apellidos')?.value?.split(' ').slice(1).join(' ') || '').trim();
    const cargo = (document.getElementById('edit-cargo')?.value || '').trim();
    const gerencia = (document.getElementById('edit-gerencia')?.value || '').trim();
    const nacionalidad = (document.getElementById('edit-nacionalidad')?.value || 'V').trim();
    const nivelPermiso = (document.getElementById('edit-nivel-permiso')?.value || '').trim();

    // Capturar campos dinámicos
    const dynFields = {};
    document.querySelectorAll('.dynamic-field-row').forEach(row => {
      const n = row.querySelector('.dyn-name').value.trim();
      const v = row.querySelector('.dyn-val').value.trim();
      if (n) dynFields[n] = v;
    });

    if (!primerNombre || !primerApellido || !cargo || !gerencia) {
      showEditorToast('Primer Nombre, Primer Apellido, Cargo y Gerencia son obligatorios.', 'danger');
      return;
    }

    const fields = {
      primer_nombre: primerNombre,
      segundo_nombre: segundoNombre || null,
      primer_apellido: primerApellido,
      segundo_apellido: segundoApellido || null,
      nombres: [primerNombre, segundoNombre].filter(Boolean).join(' '),
      apellidos: [primerApellido, segundoApellido].filter(Boolean).join(' '),
      cargo,
      gerencia,
      nacionalidad,
      nivel_permiso: nivelPermiso,
      datos_adicionales: JSON.stringify(dynFields)
    };

    if (typeof ui !== 'undefined') ui.setLoading(btn, true, 'Guardando...');
    try {
      await api.updateEmployee(employee.id, fields);
      Object.assign(employee, fields);
      renderDetails();
      renderCard();
      showEditorToast('Cambios guardados correctamente.', 'success');
    } catch (err) {
      showEditorToast(err.message || 'Error al guardar los cambios.', 'danger');
    } finally {
      if (typeof ui !== 'undefined') ui.setLoading(btn, false);
    }
  });
}

function setupManualPhoto() {
  const input = document.getElementById('manual-photo-input');
  if (!input) return;

  const modal = document.getElementById('crop-modal');
  const cropImg = document.getElementById('crop-image');
  const btnCancel = document.getElementById('btn-crop-cancel');
  const btnApply = document.getElementById('btn-crop-apply');

  const canCrop = !!(modal && cropImg && typeof Cropper !== 'undefined');

  async function applyPhoto(dataUrl) {
    try {
      const blob = await (await fetch(dataUrl)).blob();
      const fd = new FormData();
      fd.append('employee_id', employee.id);
      fd.append('photo', blob, `emp_${employee.id}.jpg`);

      const res = await api.uploadPhoto(fd);
      const serverPhotoUrl = res.data.photo_url || res.data.foto_url;

      employee.photo_url = serverPhotoUrl;
      employee.foto_url = serverPhotoUrl;

      renderCard();
      renderDetails();

      const btnR = document.getElementById('btn-remove-photo');
      if (btnR) btnR.style.display = 'inline-flex';
      showEditorToast('Fotografía procesada y guardada en servidor.', 'success');
    } catch (err) {
      console.error('[Editor Photo Error]', err);
      showEditorToast('Error al procesar foto: ' + (err.message || 'Error técnico'), 'danger');
    }
  }

  input.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    input.value = '';

    const reader = new FileReader();
    reader.onerror = () => showEditorToast('No se pudo leer el archivo. Intente con otra imagen.', 'danger');

    if (!canCrop) {
      reader.onload = (ev) => applyPhoto(ev.target.result);
      reader.readAsDataURL(file);
      return;
    }

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

// ── REFACTORIZACIÓN UX: SweetAlert2 para eliminar foto ────────────────────────
function setupRemovePhoto() {
  const btn = document.getElementById('btn-remove-photo');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    // Usar SweetAlert2 si está disponible (Diseño coherente)
    if (typeof Swal !== 'undefined') {
      const result = await Swal.fire({
        title: '¿Eliminar fotografía?',
        text: '¿Está seguro de que desea eliminar la fotografía actual de este empleado?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
      });
      if (!result.isConfirmed) return;
    } else {
      // Fallback nativo
      if (!confirm('¿Eliminar la fotografía actual?')) return;
    }

    try {
      await api.updateEmployee(employee.id, { photo_url: '' });
      employee.photo_url = '';
      if (employee.foto_url !== undefined) employee.foto_url = '';
      renderCard();
      btn.style.display = 'none';
      showEditorToast('Fotografía eliminada correctamente.', 'success');
    } catch (err) {
      showEditorToast(err.message || 'Error al eliminar fotografía', 'danger');
    }
  });
}

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

function setupCardImages() {
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
    if (typeof ui !== 'undefined') ui.setLoading(btn, true, 'Extrayendo...');
    try {
      const res = await api.smartExtraction();
      if (res?.data) {
        employee = Object.assign({}, employee, res.data);
        generateQR(); renderDetails(); renderCard();
        showEditorToast('Datos extraídos correctamente.', 'success');
      }
    } catch (err) { showEditorToast(err.message, 'danger'); }
    finally {
      if (typeof ui !== 'undefined') ui.setLoading(btn, false);
      input.value = '';
    }
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

// ── GLOBALS (Expuestos para uso del DOM heredado) ─────────────────────────────
window.setTemplate = setTemplate;
window.setOrientation = setOrientation;
window.setFace = setFace;
window.printCurrentCard = printCurrentCard;

function _renderDatosAdicionales(datos) {
  if (!datos) return '';
  let dt;
  try {
    dt = typeof datos === 'string' ? JSON.parse(datos) : datos;
  } catch (e) { return ''; }

  if (!dt || Object.keys(dt).length === 0) return '';

  let html = '';
  let y = 30;
  const esc = (s) => String(s).replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\'': '&#39;', '"': '&quot;' }[c] || c));
  for (const [k, v] of Object.entries(dt)) {
    html += `<div style="position:absolute; top:${y}px; left:10px; background:rgba(255,255,255,0.85); border:1px dashed #94a3b8; padding:3px 6px; font-size:9px; color:#1e293b; border-radius:4px; z-index:100; cursor:move; user-select:none; pointer-events:auto;" title="Arrastra para posicionar">
      <strong>${esc(k)}: </strong>${esc(v)}
    </div>`;
    y += 24;
  }
  return html;
}

window.addDynamicField = function (name = '', value = '') {
  const container = document.getElementById('dynamic-fields-container');
  if (!container) return;
  const div = document.createElement('div');
  div.className = 'form-group dynamic-field-row';
  div.style.margin = '0';
  div.style.display = 'flex';
  div.style.gap = '5px';
  div.innerHTML = `
        <input type="text" placeholder="Nombre (ej. Tipo Sangre)" value="${name}" class="form-input dyn-name" style="padding:7px; flex:1; font-size:.7rem; border-color:#cbd5e1" />
        <input type="text" placeholder="Valor (ej. O+)" value="${value}" class="form-input dyn-val" style="padding:7px; flex:1; font-size:.7rem; border-color:#cbd5e1" />
        <button type="button" class="btn btn-secondary btn-remove-dyn" style="padding:5px 8px; color: #ef4444; border-color: #fca5a5" onclick="this.parentElement.remove()">X</button>
    `;
  container.appendChild(div);
};

init();