<?php
/**
 * NominaParser — Lectura de archivos de nómina en el servidor.
 * =============================================================================
 * Convierte un archivo de nómina (.xlsx, .csv, .docx) en una matriz de filas
 * asociativas, sin dependencias externas.
 *
 * DECISIÓN DE DISEÑO: todo se resuelve con extensiones que PHP ya trae
 * (zip, SimpleXML, mbstring). No hay Composer ni librerías por CDN, porque el
 * despliegue objetivo es un XAMPP en una red institucional que puede no tener
 * salida a internet — la importación tiene que funcionar igual sin ella.
 *
 * El parser NO escribe en la base de datos ni valida reglas de negocio: sólo
 * entrega filas. La validación vive en NominaMapper.
 */

class NominaParserException extends RuntimeException
{
}

class NominaParser
{
    /** Formatos que el sistema sabe leer. */
    public const FORMATOS = ['xlsx', 'csv', 'docx'];

    /** Tope de filas por archivo, para que un archivo enorme no agote la memoria. */
    public const MAX_FILAS = 20000;

    /**
     * Punto de entrada: detecta el formato por extensión y delega.
     *
     * @return array{encabezados: string[], filas: array<int, array<string, string>>}
     */
    public static function parsear(string $rutaArchivo, string $nombreOriginal): array
    {
        if (!is_readable($rutaArchivo)) {
            throw new NominaParserException('No se pudo leer el archivo cargado.');
        }

        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if (!in_array($ext, self::FORMATOS, true)) {
            throw new NominaParserException(
                'Formato no soportado: .' . $ext . '. Use .xlsx, .csv o .docx.'
            );
        }

        $matriz = match ($ext) {
            'csv' => self::leerCsv($rutaArchivo),
            'xlsx' => self::leerXlsx($rutaArchivo),
            'docx' => self::leerDocx($rutaArchivo),
        };

        return self::matrizAFilas($matriz);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lee un CSV tolerando lo que producen Excel y los sistemas administrativos
     * en Windows: separador ';' en configuración regional española, codificación
     * ISO-8859-1 (donde "Pérez" llega como bytes inválidos en UTF-8) y BOM.
     */
    private static function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new NominaParserException('No se pudo leer el archivo CSV.');
        }

        $contenido = self::normalizarTexto($contenido);

        // Sniffing del separador sobre la primera línea no vacía: se elige el
        // candidato que produzca más columnas.
        $primeraLinea = strtok($contenido, "\n") ?: '';
        $separador = ',';
        $mejor = 0;
        foreach ([',', ';', "\t", '|'] as $cand) {
            $n = count(str_getcsv($primeraLinea, $cand));
            if ($n > $mejor) {
                $mejor = $n;
                $separador = $cand;
            }
        }

        $matriz = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contenido);
        rewind($handle);
        while (($campos = fgetcsv($handle, 0, $separador)) !== false) {
            if (count($matriz) >= self::MAX_FILAS) {
                break;
            }
            $matriz[] = array_map(static fn($c) => trim((string) $c), $campos);
        }
        fclose($handle);

        return $matriz;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // XLSX
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lee la primera hoja de un .xlsx directamente desde su XML interno.
     *
     * Un .xlsx es un ZIP: xl/worksheets/sheetN.xml guarda las celdas y
     * xl/sharedStrings.xml el texto, que las celdas referencian por índice.
     */
    private static function leerXlsx(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new NominaParserException('El archivo .xlsx está dañado o no es un libro de Excel válido.');
        }

        try {
            $cadenas = self::leerCadenasCompartidas($zip);
            $rutaHoja = self::rutaPrimeraHoja($zip);

            $xmlHoja = $zip->getFromName($rutaHoja);
            if ($xmlHoja === false) {
                throw new NominaParserException('El libro no contiene ninguna hoja de cálculo legible.');
            }

            $hoja = self::cargarXml($xmlHoja);
            $matriz = [];

            foreach ($hoja->sheetData->row ?? [] as $fila) {
                if (count($matriz) >= self::MAX_FILAS) {
                    break;
                }
                $celdas = [];
                $maxCol = -1;

                foreach ($fila->c ?? [] as $celda) {
                    $ref = (string) ($celda['r'] ?? '');
                    $col = $ref !== '' ? self::refAIndiceColumna($ref) : $maxCol + 1;
                    $celdas[$col] = self::valorCelda($celda, $cadenas);
                    $maxCol = max($maxCol, $col);
                }

                if ($maxCol < 0) {
                    $matriz[] = [];
                    continue;
                }

                // Se rellenan los huecos: Excel omite las celdas vacías, pero la
                // matriz debe conservar la posición de cada columna.
                $normalizada = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $normalizada[] = $celdas[$i] ?? '';
                }
                $matriz[] = $normalizada;
            }

            return $matriz;
        } finally {
            $zip->close();
        }
    }

    /** Tabla de textos compartidos (xl/sharedStrings.xml). */
    private static function leerCadenasCompartidas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $sst = self::cargarXml($xml);
        $cadenas = [];
        foreach ($sst->si ?? [] as $si) {
            // Un texto con formato mixto se parte en varios <r><t>; hay que unirlos.
            if (isset($si->r)) {
                $partes = '';
                foreach ($si->r as $run) {
                    $partes .= (string) $run->t;
                }
                $cadenas[] = $partes;
            } else {
                $cadenas[] = (string) $si->t;
            }
        }
        return $cadenas;
    }

    /**
     * Resuelve la ruta de la primera hoja siguiendo workbook.xml y sus
     * relaciones. No siempre es sheet1.xml: al borrar hojas, Excel conserva la
     * numeración original de los archivos.
     */
    private static function rutaPrimeraHoja(ZipArchive $zip): string
    {
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($wbXml !== false && $relsXml !== false) {
            $wb = self::cargarXml($wbXml);
            $primera = $wb->sheets->sheet[0] ?? null;
            if ($primera !== null) {
                $rid = (string) $primera->attributes('r', true)['id'];
                if ($rid !== '') {
                    $rels = self::cargarXml($relsXml);
                    foreach ($rels->Relationship ?? [] as $rel) {
                        if ((string) $rel['Id'] === $rid) {
                            $target = ltrim((string) $rel['Target'], '/');
                            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                        }
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** Extrae el valor de una celda según su tipo declarado. */
    private static function valorCelda(SimpleXMLElement $celda, array $cadenas): string
    {
        $tipo = (string) ($celda['t'] ?? '');

        if ($tipo === 's') {
            $idx = (int) $celda->v;
            return $cadenas[$idx] ?? '';
        }
        if ($tipo === 'inlineStr') {
            return trim((string) ($celda->is->t ?? ''));
        }
        if ($tipo === 'b') {
            return ((string) $celda->v) === '1' ? 'VERDADERO' : 'FALSO';
        }

        // 'str' (resultado de fórmula) y numéricos sin tipo llegan en <v>.
        return trim((string) ($celda->v ?? ''));
    }

    /** "BC12" -> 54. Convierte la parte alfabética de la referencia en índice 0-based. */
    private static function refAIndiceColumna(string $ref): int
    {
        $indice = 0;
        $longitud = strlen($ref);
        for ($i = 0; $i < $longitud; $i++) {
            $c = strtoupper($ref[$i]);
            if ($c < 'A' || $c > 'Z') {
                break;
            }
            $indice = $indice * 26 + (ord($c) - 64);
        }
        return max(0, $indice - 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOCX
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extrae la primera tabla de un .docx.
     *
     * LIMITACIÓN CONOCIDA: sólo se lee la tabla más grande del documento. Word
     * permite celdas combinadas y encabezados repetidos por página, que aquí se
     * aplanan; por eso la pantalla de importación siempre muestra la vista
     * previa antes de escribir nada.
     */
    private static function leerDocx(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new NominaParserException('El archivo .docx está dañado o no es un documento de Word válido.');
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false) {
                throw new NominaParserException('El documento de Word no tiene contenido legible.');
            }

            $doc = self::cargarXml($xml);
            $doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $tablas = $doc->xpath('//w:tbl') ?: [];
            if (!$tablas) {
                throw new NominaParserException(
                    'El documento de Word no contiene ninguna tabla. La nómina debe estar en formato de tabla.'
                );
            }

            // Se elige la tabla con más filas: en un oficio institucional, la
            // nómina real suele venir después de tablas pequeñas de membrete.
            $mejorTabla = null;
            $mejorConteo = -1;
            foreach ($tablas as $tabla) {
                $tabla->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $n = count($tabla->xpath('./w:tr') ?: []);
                if ($n > $mejorConteo) {
                    $mejorConteo = $n;
                    $mejorTabla = $tabla;
                }
            }

            $matriz = [];
            foreach ($mejorTabla->xpath('./w:tr') ?: [] as $fila) {
                if (count($matriz) >= self::MAX_FILAS) {
                    break;
                }
                $fila->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $celdas = [];
                foreach ($fila->xpath('./w:tc') ?: [] as $celda) {
                    $celda->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    // El texto de una celda puede venir partido en varios <w:t>
                    // cuando Word aplica formato o corrige ortografía.
                    $texto = '';
                    foreach ($celda->xpath('.//w:t') ?: [] as $t) {
                        $texto .= (string) $t;
                    }
                    $celdas[] = trim(preg_replace('/\s+/u', ' ', $texto));
                }
                $matriz[] = $celdas;
            }

            return $matriz;
        } finally {
            $zip->close();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilidades comunes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convierte la matriz cruda en filas asociativas usando la primera fila no
     * vacía como encabezado.
     */
    private static function matrizAFilas(array $matriz): array
    {
        // Se descartan las filas de relleno que anteceden al encabezado real
        // (títulos, logos institucionales, líneas en blanco).
        $indiceEncabezado = null;
        foreach ($matriz as $i => $fila) {
            $noVacias = array_filter($fila, static fn($v) => trim((string) $v) !== '');
            if (count($noVacias) >= 2) {
                $indiceEncabezado = $i;
                break;
            }
        }

        if ($indiceEncabezado === null) {
            throw new NominaParserException('El archivo no contiene datos legibles.');
        }

        $encabezados = [];
        $vistos = [];
        foreach ($matriz[$indiceEncabezado] as $j => $celda) {
            $nombre = trim((string) $celda);
            if ($nombre === '') {
                $nombre = 'Columna ' . ($j + 1);
            }
            // Dos columnas con el mismo título romperían el array asociativo.
            if (isset($vistos[$nombre])) {
                $vistos[$nombre]++;
                $nombre .= ' (' . $vistos[$nombre] . ')';
            } else {
                $vistos[$nombre] = 1;
            }
            $encabezados[] = $nombre;
        }

        $filas = [];
        $total = count($matriz);
        for ($i = $indiceEncabezado + 1; $i < $total; $i++) {
            $fila = $matriz[$i];
            $noVacias = array_filter($fila, static fn($v) => trim((string) $v) !== '');
            if (!$noVacias) {
                continue; // fila totalmente en blanco
            }

            $asociativa = [];
            foreach ($encabezados as $j => $encabezado) {
                $asociativa[$encabezado] = trim((string) ($fila[$j] ?? ''));
            }
            $asociativa['_fila'] = $i + 1; // número de fila real, para el reporte
            $filas[] = $asociativa;
        }

        return ['encabezados' => $encabezados, 'filas' => $filas];
    }

    /** Quita el BOM y lleva el texto a UTF-8 venga de donde venga. */
    private static function normalizarTexto(string $texto): string
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        if (!mb_check_encoding($texto, 'UTF-8')) {
            // Exportaciones de Windows suelen venir en Windows-1252 / ISO-8859-1.
            $detectada = mb_detect_encoding($texto, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)
                ?: 'Windows-1252';
            $texto = mb_convert_encoding($texto, 'UTF-8', $detectada);
        }

        return str_replace("\r\n", "\n", $texto);
    }

    /**
     * Carga XML tratando el contenido como no confiable.
     *
     * El archivo lo sube un operador, y un .xlsx/.docx no es más que un ZIP con
     * XML dentro: cualquiera puede fabricar uno a mano. Por eso NO se pasa
     * LIBXML_NOENT — esa bandera *activa* la sustitución de entidades, que es
     * justamente el vector de XXE y de la bomba de entidades. LIBXML_NONET
     * corta además cualquier acceso de red durante el parseo.
     */
    private static function cargarXml(string $xml): SimpleXMLElement
    {
        $anterior = libxml_use_internal_errors(true);
        $elemento = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if ($elemento === false) {
            throw new NominaParserException('El contenido interno del archivo no es XML válido.');
        }
        return $elemento;
    }
}
