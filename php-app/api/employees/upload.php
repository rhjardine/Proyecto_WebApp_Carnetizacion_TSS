<?php
/**
 * POST /api/employees/upload.php
 * Sube y procesa la foto de un empleado con seguridad de nivel "Zero Trust".
 *
 * ESTRATEGIA DE SEGURIDAD EN PROFUNDIDAD:
 * 1. Validación de tipo MIME real (getimagesize) → rechaza archivos disfrazados
 * 2. Re-renderizado con GD → destruye cualquier payload/EXIF/metadato incrustado
 * 3. Nombre de archivo criptográfico (bin2hex(random_bytes(16))) → no predecible
 * 4. El archivo original NUNCA se guarda en disco
 * 5. Siempre se guarda como JPEG normalizado (sin importar si era PNG/JPG original)
 *
 * Requiere: ext-gd habilitado en PHP (incluido en PHP 8 por defecto).
 */
require_once __DIR__ . '/../../middleware/RBAC.php';
require_once __DIR__ . '/../../middleware/auth_check.php';

$db = getDB();
Security::requirePermission($db, 'carnet.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 1. Validación del campo employee_id
// ──────────────────────────────────────────────────────────────
$employeeId = intval($_POST['employee_id'] ?? 0);
if (!$employeeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'employee_id requerido.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 2. Verificar que se recibió un archivo sin errores de upload
// ──────────────────────────────────────────────────────────────
if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'El archivo supera upload_max_filesize en php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite del formulario.',
        UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente.',
        UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta directorio temporal en el servidor.',
        UPLOAD_ERR_CANT_WRITE => 'Error al escribir en disco.',
    ];
    $errCode = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $uploadErrors[$errCode] ?? 'Error desconocido al subir archivo.']);
    exit;
}

$tmpPath = $_FILES['photo']['tmp_name'];
$maxSize = 8 * 1024 * 1024; // 8 MB

// ──────────────────────────────────────────────────────────────
// 3. Validación de tamaño
// ──────────────────────────────────────────────────────────────
if ($_FILES['photo']['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El archivo supera el límite de 8 MB.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 4. Validación de tipo MIME REAL (no confiar en el nombre ni en el Content-Type del cliente)
//    getimagesize() lee los primeros bytes "magic bytes" para determinar el tipo real.
// ──────────────────────────────────────────────────────────────
$imageInfo = @getimagesize($tmpPath);

if ($imageInfo === false) {
    http_response_code(415);
    echo json_encode(['success' => false, 'message' => 'El archivo no es una imagen válida.']);
    exit;
}

$allowedMimeTypes = [
    IMAGETYPE_JPEG => 'JPEG',
    IMAGETYPE_PNG => 'PNG',
];

$imageType = $imageInfo[2]; // IMAGETYPE_JPEG (2) o IMAGETYPE_PNG (3)

if (!isset($allowedMimeTypes[$imageType])) {
    http_response_code(415);
    echo json_encode(['success' => false, 'message' => 'Solo se permiten imágenes JPG y PNG.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 5. VERIFICAR QUE GD ESTÁ DISPONIBLE
// ──────────────────────────────────────────────────────────────
if (!extension_loaded('gd')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'La extensión GD no está habilitada en el servidor PHP.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 6. CARGA Y RE-RENDERIZADO CON GD (núcleo del Zero Trust)
//    Se crea un nuevo recurso de imagen desde el archivo temporal.
//    Esto descarta TODOS los metadatos EXIF, comentarios, chunks PNG,
//    y cualquier payload malicioso incrustado en el archivo original.
// ──────────────────────────────────────────────────────────────
$sourceImage = match ($imageType) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
    IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
    default => false,
};

if ($sourceImage === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No se pudo decodificar la imagen. Archivo posiblemente corrupto.']);
    exit;
}

// Dimensiones de la imagen original
$origWidth = imagesx($sourceImage);
$origHeight = imagesy($sourceImage);

// Redimensionar si es demasiado grande (max 1200×1200) → ahorra disco y BW
$maxDim = 1200;
if ($origWidth > $maxDim || $origHeight > $maxDim) {
    $ratio = min($maxDim / $origWidth, $maxDim / $origHeight);
    $newWidth = (int) round($origWidth * $ratio);
    $newHeight = (int) round($origHeight * $ratio);
    $resized = imagecreatetruecolor($newWidth, $newHeight);

    // Fondo blanco para PNGs con canal alpha
    imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
    imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    imagedestroy($sourceImage);
    $finalImage = $resized;
} else {
    $finalImage = $sourceImage;
}

// ──────────────────────────────────────────────────────────────
// 7. GENERAR NOMBRE DE ARCHIVO CRIPTOGRÁFICAMENTE SEGURO
//    bin2hex(random_bytes(16)) → 32 caracteres hexadecimales únicos, no predecibles.
//    Nunca incluye datos del empleado ni timestamps.
// ──────────────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../../uploads/';

// Crea el directorio si no existe para evitar errores (FIX CRÍTICO)
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$secureFilename = bin2hex(random_bytes(16)) . '.jpg'; // siempre guardamos como JPEG
$destPath = $uploadDir . $secureFilename;
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$appBase = rtrim(dirname(dirname(dirname($scriptName))), '/');
$publicUrl = ($appBase ? $appBase : '') . '/uploads/' . $secureFilename;

// Asegurarse que el directorio sea escribible
if (!is_writable($uploadDir)) {
    imagedestroy($finalImage);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'El directorio de uploads no es escribible.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 8. GUARDAR COMO JPEG NORMALIZADO (calidad 90)
//    La imagen se guarda desde el recurso GD en memoria → el archivo original
//    NUNCA toca el disco de destino. EXIF/metadatos eliminados por diseño.
// ──────────────────────────────────────────────────────────────
$saved = imagejpeg($finalImage, $destPath, 90);
imagedestroy($finalImage);

if (!$saved) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar la imagen procesada.']);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 9. Actualizar la URL en la BD y borrar foto anterior si existe
// ──────────────────────────────────────────────────────────────
try {
    $db = getDB();

    // Obtener la foto anterior para borrarla del disco
    $prev = $db->prepare('SELECT foto_url FROM empleados WHERE id = :id');
    $prev->execute([':id' => $employeeId]);
    $prevPhoto = $prev->fetchColumn();

    // Actualizar con la nueva URL
    $stmt = $db->prepare('UPDATE empleados SET foto_url = :url, actualizado_el = NOW() WHERE id = :id');
    $stmt->execute([':url' => $publicUrl, ':id' => $employeeId]);

    if ($stmt->rowCount() < 1) {
        // Empleado no encontrado → borrar la imagen recién guardada
        @unlink($destPath);
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empleado no encontrado.']);
        exit;
    }

    $empStmt = $db->prepare('
        SELECT
            id,
            cedula,
            nacionalidad,
            primer_nombre,
            segundo_nombre,
            primer_apellido,
            segundo_apellido,
            cargo,
            foto_url
        FROM empleados
        WHERE id = :id
        LIMIT 1
    ');
    $empStmt->execute([':id' => $employeeId]);
    $employee = $empStmt->fetch();

    if (!$employee) {
        @unlink($destPath);
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empleado no encontrado.']);
        exit;
    }

    // Borrar la foto anterior del disco (limpieza)
    if ($prevPhoto) {
        $prevPath = $uploadDir . basename($prevPhoto);
        if (is_file($prevPath) && $prevPath !== $destPath) {
            @unlink($prevPath);
        }
    }

    // ── AUDIT LOG ──────────────────────────────────────────
    $fullName = trim(implode(' ', array_filter([
        $employee['primer_nombre'] ?? '',
        $employee['segundo_nombre'] ?? '',
        $employee['primer_apellido'] ?? '',
        $employee['segundo_apellido'] ?? '',
    ])));

    logAction($db, $authUser['id'], 'EMPLOYEE_PHOTO_UPLOADED', [
        'employee_id' => $employee['id'],
        'cedula' => $employee['cedula'],
        'nombre' => $fullName,
        'filename' => $secureFilename,
        'original_type' => $allowedMimeTypes[$imageType],
        'size_bytes' => $_FILES['photo']['size'],
    ]);
    // ───────────────────────────────────────────────────────

    echo json_encode([
        'success' => true,
        'message' => 'Fotografía procesada y actualizada correctamente.',
        'data' => $employee,
    ]);

} catch (Exception $e) {
    // Error en BD → limpiar el archivo recién guardado
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar la base de datos.']);
}
