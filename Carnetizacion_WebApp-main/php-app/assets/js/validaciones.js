/**
 * validaciones.js — Validación estricta de entradas en cliente (SCI-TSS)
 * ========================================================================
 * TAREA 3: Validación de cédula en tiempo real.
 *
 * DESCRIPCIÓN:
 *  Implementa event listeners que interceptan y bloquean en tiempo real
 *  cualquier intento de ingresar caracteres no numéricos en el campo
 *  de cédula de identidad.
 *
 * JUSTIFICACIÓN TÉCNICA:
 *  El nuevo esquema MySQL (carnetizacion_tss) almacena la cédula como
 *  valor ESTRICTAMENTE numérico en la columna `cedula` (VARCHAR 20).
 *  El prefijo V/E reside exclusivamente en la columna `nacionalidad`.
 *  Un CONSTRAINT CHECK a nivel de BD (REGEXP '^[0-9]+$') refuerza esto.
 *  Esta validación en cliente es la PRIMERA LÍNEA DE DEFENSA, complementaria
 *  al constraint de base de datos.
 *
 * CARACTERES BLOQUEADOS EXPLÍCITAMENTE:
 *  - Letras: A-Z, a-z (especialmente 'V', 'v', 'E', 'e')
 *  - Guión: - (comúnmente usado en formato V-12345678)
 *  - Punto: . (usado en formato 12.345.678)
 *  - Espacio y cualquier otro caracter no numérico
 *
 * @version 2.0.0-preproduccion
 */
'use strict';

// ── SELECTOR DE CAMPOS DE CÉDULA ─────────────────────────────────────────────
// Aplica a todos los inputs de cédula en cualquier página que cargue este script.
// Selector cubre: nombre de campo, ID del campo, y data-attribute de validación.
const CEDULA_SELECTORS = [
  'input[name="cedula"]',
  'input[id="input-cedula-new"]',
  'input[id="edit-cedula"]',
  'input[data-validate="cedula"]',
];

// ── MENSAJES DE ERROR LOCALIZADOS ─────────────────────────────────────────────
const MSG_SOLO_NUMEROS = 'Solo se permiten dígitos (0-9). No escriba prefijos como V-, E- ni puntos o guiones.';
const MSG_LONGITUD_MIN = 'La cédula debe tener al menos 5 dígitos.';
const MSG_LONGITUD_MAX = 'La cédula no puede exceder los 10 dígitos.';
const MSG_VALIDA       = ''; // Sin mensaje cuando es válida

// ── UTILIDADES DE UI ──────────────────────────────────────────────────────────
/**
 * mostrarErrorCedula(input, mensaje) — Muestra/oculta el mensaje de error
 * asociado al input de cédula.
 *
 * Estrategia de búsqueda del contenedor de error:
 *  1. Elemento con ID derivado del input: `<id>-error`
 *  2. Elemento con data-error-for="<name>"
 *  3. Siguiente sibling con clase .cedula-error
 *
 * @param {HTMLInputElement} input   - El campo de cédula
 * @param {string}           mensaje - Mensaje de error (vacío = válido)
 */
function mostrarErrorCedula(input, mensaje) {
  const isValido = !mensaje;

  // Buscar elemento de error asociado
  let errorEl = null;
  if (input.id) {
    errorEl = document.getElementById(`${input.id}-error`);
  }
  if (!errorEl && input.name) {
    errorEl = document.querySelector(`[data-error-for="${input.name}"]`);
  }
  if (!errorEl) {
    // Buscar en el parent form-group
    const grupo = input.closest('.form-group');
    if (grupo) errorEl = grupo.querySelector('small, .error-msg');
  }

  // Estilos visuales del input
  if (isValido) {
    input.style.borderColor  = '';
    input.style.boxShadow    = '';
  } else {
    input.style.borderColor = '#dc2626';
    input.style.boxShadow   = '0 0 0 2px rgba(220,38,38,.2)';
  }

  // Mostrar/ocultar el mensaje de error
  if (errorEl) {
    errorEl.textContent    = mensaje;
    errorEl.style.display  = isValido ? 'none' : 'block';
  }
}

/**
 * validarCedula(valor) — Valida el valor actual de un campo de cédula.
 *
 * Reglas:
 *  1. Solo dígitos (0-9) — ningún otro caracter.
 *  2. Longitud mínima: 5 dígitos.
 *  3. Longitud máxima: 10 dígitos.
 *
 * @param {string} valor - Valor del input (solo dígitos esperados)
 * @returns {string} Mensaje de error (vacío si es válido)
 */
function validarCedula(valor) {
  if (!valor) return ''; // Vacío es aceptable (el 'required' del HTML lo maneja)
  if (!/^[0-9]+$/.test(valor)) return MSG_SOLO_NUMEROS;
  if (valor.length < 5)        return MSG_LONGITUD_MIN;
  if (valor.length > 10)       return MSG_LONGITUD_MAX;
  return MSG_VALIDA;
}

// ── CONFIGURAR VALIDACIÓN EN UN INPUT ESPECÍFICO ──────────────────────────────
/**
 * configurarValidacionCedula(input) — Inyecta todos los event listeners
 * necesarios en un campo de cédula específico.
 *
 * Eventos manejados:
 *  - keydown:  Bloquea teclas no numéricas ANTES de que modifiquen el valor.
 *  - paste:    Filtra el texto pegado para permitir solo dígitos.
 *  - input:    Limpia caracteres no numéricos que lleguen por otros medios
 *              (ej: autocompletado del navegador, IME inputs).
 *  - blur:     Validación completa al perder el foco.
 *  - compositionend: Maneja inputs de métodos de escritura (IME) como teclados
 *              virtuales en dispositivos móviles que puedan insertar texto.
 *
 * @param {HTMLInputElement} input - El campo de cédula a configurar
 */
function configurarValidacionCedula(input) {
  if (!input || input._cedulaValidada) return; // Evitar configuración duplicada
  input._cedulaValidada = true;

  // ── Atributos HTML de accesibilidad y semántica ─────────────────────────────
  input.setAttribute('inputmode', 'numeric');    // Teclado numérico en móvil
  input.setAttribute('pattern', '[0-9]*');       // Validación HTML5 nativa
  input.setAttribute('autocomplete', 'off');     // Deshabilitar autocompletado
  input.setAttribute('autocorrect', 'off');      // Safari: deshabilitar corrección
  input.setAttribute('spellcheck', 'false');     // Deshabilitar corrector ortográfico
  if (!input.maxLength || input.maxLength === -1) {
    input.setAttribute('maxlength', '10');        // Máximo 10 dígitos
  }
  if (!input.placeholder) {
    input.setAttribute('placeholder', '12345678'); // Placeholder indicativo
  }

  // ── EVENT: keydown — Bloqueo preventivo ────────────────────────────────────
  // Se ejecuta ANTES de que el caracter entre al campo.
  // Permite: dígitos 0-9, teclas de navegación y edición.
  input.addEventListener('keydown', (e) => {
    const { key, ctrlKey, metaKey, altKey } = e;

    // Permitir combinaciones de teclas de sistema (Ctrl+C, Ctrl+V, Ctrl+A, etc.)
    if (ctrlKey || metaKey) return;
    // Permitir Alt+ combinaciones (accesibilidad)
    if (altKey) return;

    // Teclas de navegación y edición: siempre permitidas
    const teclasPermitidas = [
      'Backspace', 'Delete', 'Tab', 'Enter', 'Escape',
      'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
      'Home', 'End', 'PageUp', 'PageDown',
      'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
    ];

    if (teclasPermitidas.includes(key)) return;

    // Dígitos del teclado principal (0-9) y numérico
    if (/^[0-9]$/.test(key)) return;

    // BLOQUEAR EXPLÍCITAMENTE:
    // - Prefijos de cédula venezolana: V, v, E, e
    // - Caracteres de formateo: guión (-), punto (.), espacio ( )
    // - Cualquier letra o símbolo no numérico
    e.preventDefault();

    // Indicar al usuario por qué fue bloqueado
    if (/[VvEe]/.test(key)) {
      mostrarErrorCedula(input, `El prefijo "${key}" no se ingresa aquí. Use el selector de Nacionalidad para V/E.`);
      // Limpiar el mensaje después de 3 segundos
      setTimeout(() => {
        if (!input.value || /^[0-9]+$/.test(input.value)) {
          mostrarErrorCedula(input, '');
        }
      }, 3000);
    } else if (key === '-' || key === '.' || key === ',') {
      mostrarErrorCedula(input, `El carácter "${key}" no se permite. Ingrese solo dígitos (0-9).`);
      setTimeout(() => {
        if (!input.value || /^[0-9]+$/.test(input.value)) {
          mostrarErrorCedula(input, '');
        }
      }, 3000);
    }
  });

  // ── EVENT: paste — Filtrar texto pegado ────────────────────────────────────
  // Intercepta el texto que el usuario pega (Ctrl+V, clic derecho → pegar).
  // Extrae solo los dígitos del texto pegado y los inserta limpiamente.
  input.addEventListener('paste', (e) => {
    e.preventDefault();

    const textoOriginal = (e.clipboardData || window.clipboardData).getData('text');
    const soloDigitos   = textoOriginal.replace(/[^0-9]/g, '');

    if (!soloDigitos && textoOriginal) {
      // El texto pegado no contenía ningún dígito
      mostrarErrorCedula(input, `Texto pegado inválido: "${textoOriginal}". Solo se aceptan dígitos.`);
      return;
    }

    if (textoOriginal !== soloDigitos && textoOriginal) {
      // Se eliminaron caracteres no numéricos (ej: V-12345678 → 12345678)
      console.info(`[SCI-TSS Cédula] Texto pegado limpiado: "${textoOriginal}" → "${soloDigitos}"`);
    }

    // Insertar los dígitos limpios en la posición del cursor
    const start = input.selectionStart ?? input.value.length;
    const end   = input.selectionEnd   ?? input.value.length;
    const valorActual = input.value;
    const nuevoValor  = valorActual.substring(0, start) + soloDigitos + valorActual.substring(end);

    // Respetar longitud máxima
    const maxLen  = parseInt(input.maxLength) || 10;
    input.value   = nuevoValor.substring(0, maxLen);

    // Actualizar posición del cursor
    const nuevaPos = Math.min(start + soloDigitos.length, maxLen);
    input.setSelectionRange(nuevaPos, nuevaPos);

    // Disparar evento input para que otros listeners lo procesen
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });

  // ── EVENT: input — Limpiar caracteres no numéricos ─────────────────────────
  // Maneja casos que escapan del keydown (IME, autocompletado del navegador,
  // inputs de voz, etc.).
  input.addEventListener('input', (e) => {
    const valorOriginal = input.value;
    const valorLimpio   = valorOriginal.replace(/[^0-9]/g, '');

    if (valorOriginal !== valorLimpio) {
      const posicion = input.selectionStart;
      input.value    = valorLimpio;
      // Intentar mantener la posición del cursor (aproximada)
      const nuevaPos = Math.max(0, posicion - (valorOriginal.length - valorLimpio.length));
      input.setSelectionRange(nuevaPos, nuevaPos);
    }

    // Validar y mostrar/ocultar error en tiempo real
    const error = validarCedula(valorLimpio);
    // Mostrar error solo si el campo tiene contenido (no mientras está vacío)
    if (valorLimpio.length > 0) {
      mostrarErrorCedula(input, error);
    } else {
      mostrarErrorCedula(input, '');
    }
  });

  // ── EVENT: blur — Validación completa al perder el foco ────────────────────
  input.addEventListener('blur', () => {
    const error = validarCedula(input.value);
    mostrarErrorCedula(input, error);
  });

  // ── EVENT: focus — Limpiar errores al entrar al campo ──────────────────────
  input.addEventListener('focus', () => {
    // Solo limpiar el borde rojo si el valor actual es válido
    if (!input.value || /^[0-9]+$/.test(input.value)) {
      input.style.borderColor = '';
      input.style.boxShadow   = '';
    }
  });

  // ── EVENT: compositionend — Para teclados IME (móvil) ──────────────────────
  // Algunos teclados virtuales insertan texto durante la composición.
  // compositionend se dispara cuando el texto está completamente ingresado.
  input.addEventListener('compositionend', () => {
    const valorLimpio = input.value.replace(/[^0-9]/g, '');
    if (input.value !== valorLimpio) {
      input.value = valorLimpio;
    }
    const error = validarCedula(valorLimpio);
    if (valorLimpio.length > 0) {
      mostrarErrorCedula(input, error);
    }
  });
}

// ── INICIALIZACIÓN ────────────────────────────────────────────────────────────
/**
 * inicializarValidacionesCedula() — Busca todos los campos de cédula en la
 * página y les aplica la validación estricta.
 *
 * Se invoca:
 *  1. Al cargar el DOM (DOMContentLoaded).
 *  2. Después de que el JS cree modales o formularios dinámicos (observer).
 */
function inicializarValidacionesCedula() {
  const selector = CEDULA_SELECTORS.join(', ');
  document.querySelectorAll(selector).forEach(input => {
    configurarValidacionCedula(input);
  });
}

// ── MUTATION OBSERVER — Detectar campos añadidos dinámicamente ────────────────
// Los modales de "Nuevo Empleado" y "Editar Empleado" se inyectan en el DOM
// DESPUÉS de que el JS se carga. El MutationObserver detecta nuevos inputs
// y les aplica la validación automáticamente.
function observarNuevosInputs() {
  const observer = new MutationObserver((mutaciones) => {
    for (const mutacion of mutaciones) {
      for (const nodo of mutacion.addedNodes) {
        if (nodo.nodeType !== Node.ELEMENT_NODE) continue;

        const selector = CEDULA_SELECTORS.join(', ');

        // El nodo mismo puede ser un input de cédula
        if (nodo.matches && nodo.matches(selector)) {
          configurarValidacionCedula(nodo);
        }

        // O puede contener inputs de cédula descendentes
        if (nodo.querySelectorAll) {
          nodo.querySelectorAll(selector).forEach(input => {
            configurarValidacionCedula(input);
          });
        }
      }
    }
  });

  observer.observe(document.body, {
    childList: true,  // Observar hijos directos
    subtree:   true,  // Observar todos los descendientes
  });

  return observer;
}

// ── PUNTO DE ENTRADA ──────────────────────────────────────────────────────────
if (document.readyState === 'loading') {
  // DOM aún cargando: esperar a que esté listo
  document.addEventListener('DOMContentLoaded', () => {
    inicializarValidacionesCedula();
    observarNuevosInputs();
  });
} else {
  // DOM ya cargado (script cargado al final del body o con defer)
  inicializarValidacionesCedula();
  observarNuevosInputs();
}
