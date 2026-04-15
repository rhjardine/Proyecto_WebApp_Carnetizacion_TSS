/**
 * upload.js — Photo upload portal logic.
 */
let validatedEmployee = null;
let selectedFile = null;

// Cargar token CSRF al inicio de la página
api.initCsrf();

document.getElementById('btn-logout').addEventListener('click', async () => {
    await api.logout();
    window.location.href = 'login.html';
});

// --- Validation ---
document.getElementById('btn-validate').addEventListener('click', validateCedula);
document.getElementById('cedula-input').addEventListener('keyup', (e) => {
    if (e.key === 'Enter') validateCedula();
});

async function validateCedula() {
    const cedula = document.getElementById('cedula-input').value.trim();
    const btn = document.getElementById('btn-validate');
    const resEl = document.getElementById('validation-result');
    const errEl = document.getElementById('validation-error');

    resEl.style.display = 'none';
    errEl.style.display = 'none';

    if (!cedula) { errEl.textContent = 'Ingrese una cédula.'; errEl.style.display = 'flex'; return; }

    ui.setLoading(btn, true, 'Buscando...');
    try {
        const res = await api.getEmployees({ cedula });

        if (!res.data || res.data.length === 0) {
            errEl.textContent = `No se encontró ningún funcionario con la cédula "${cedula}".`;
            errEl.style.display = 'flex';
            validatedEmployee = null;
            document.getElementById('upload-section').style.cssText = 'opacity:.4;pointer-events:none;';
        } else {
            const found = res.data[0];
            validatedEmployee = found;
            resEl.innerHTML = `✅ <strong>${found.nombre}</strong> · ${found.cargo} · ${found.departamento}`;
            resEl.style.display = 'flex';
            document.getElementById('upload-section').style.cssText = 'opacity:1;pointer-events:auto;';
        }
    } catch (err) {
        errEl.textContent = 'Error de conexión. Intente nuevamente.';
        errEl.style.display = 'flex';
    } finally {
        ui.setLoading(btn, false);
    }
}

// --- Dropzone ---
const dropzone = document.getElementById('dropzone');
const photoInput = document.getElementById('photo-input');

dropzone.addEventListener('click', () => photoInput.click());
dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    handleFile(e.dataTransfer.files[0]);
});
photoInput.addEventListener('change', (e) => handleFile(e.target.files[0]));

function handleFile(file) {
    if (!file) return;
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        alert('Solo se permiten imágenes JPG y PNG.'); return;
    }
    if (file.size > 5 * 1024 * 1024) {
        alert('El archivo supera el límite de 5MB.'); return;
    }
    selectedFile = file;
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById('preview-img').src = e.target.result;
        document.getElementById('photo-preview').style.display = 'block';
        document.getElementById('btn-upload').disabled = false;
    };
    reader.readAsDataURL(file);
}

// --- Upload ---
document.getElementById('btn-upload').addEventListener('click', async () => {
    if (!validatedEmployee || !selectedFile) return;

    const btn = document.getElementById('btn-upload');
    const succEl = document.getElementById('upload-success');
    const errEl2 = document.getElementById('upload-error');

    succEl.style.display = 'none';
    errEl2.style.display = 'none';
    ui.setLoading(btn, true, 'Enviando...');

    const formData = new FormData();
    formData.append('employee_id', validatedEmployee.id);
    formData.append('photo', selectedFile);

    try {
        await api.uploadPhoto(formData);
        succEl.innerHTML = `✅ Fotografía de <strong>${validatedEmployee.nombre}</strong> actualizada correctamente.`;
        succEl.style.display = 'flex';
        // Reset
        selectedFile = null;
        validatedEmployee = null;
        document.getElementById('cedula-input').value = '';
        document.getElementById('photo-preview').style.display = 'none';
        document.getElementById('validation-result').style.display = 'none';
        document.getElementById('upload-section').style.cssText = 'opacity:.4;pointer-events:none;';
        btn.disabled = true;
    } catch (err) {
        errEl2.textContent = err.message;
        errEl2.style.display = 'flex';
    } finally {
        ui.setLoading(btn, false);
    }
});
