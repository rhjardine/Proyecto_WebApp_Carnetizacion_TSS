<?php
/**
 * NominaMapper — Detección de columnas y validación de filas de nómina.
 * =============================================================================
 * Traduce los encabezados reales de un archivo institucional a los campos de
 * la tabla `empleados`, y clasifica cada fila antes de que se escriba nada.
 *
 * MOTIVO: la importación anterior exigía el encabezado literal 'Cédula', con
 * tilde y mayúscula exactas. Una nómina que dijera 'CEDULA', 'C.I.' o
 * 'Nro Documento' no producía ni una coincidencia, y el sistema informaba
 * "importada" igualmente. Aquí el emparejamiento se hace sobre el encabezado
 * normalizado (sin tildes, sin puntuación, en minúsculas).
 */

class NominaMapper
{
    /**
     * Sinónimos aceptados por campo, en orden de preferencia.
     * Se comparan ya normalizados: sin tildes, sin signos, en minúsculas.
     */
    public const SINONIMOS = [
        'cedula' => ['cedula', 'ci', 'cid', 'documento', 'nrodocumento', 'numerodocumento',
                     'identificacion', 'nrocedula', 'numerocedula', 'cedulaidentidad', 'dni'],
        'nacionalidad' => ['nacionalidad', 'nac', 'tipodocumento', 'tipocedula'],
        'primer_nombre' => ['primernombre', 'nombre', 'nombres', '1ernombre', 'nombre1'],
        'segundo_nombre' => ['segundonombre', '2donombre', 'nombre2'],
        'primer_apellido' => ['primerapellido', 'apellido', 'apellidos', '1erapellido', 'apellido1'],
        'segundo_apellido' => ['segundoapellido', '2doapellido', 'apellido2'],
        'cargo' => ['cargo', 'puesto', 'denominacioncargo', 'descripcioncargo', 'ocupacion'],
        'gerencia' => ['gerencia', 'dependencia', 'unidad', 'departamento', 'adscripcion',
                       'oficina', 'direccion', 'unidadadministrativa'],
        'email' => ['email', 'correo', 'correoelectronico', 'mail', 'correoinstitucional'],
        'fecha_ingreso' => ['fechaingreso', 'ingreso', 'fechadeingreso', 'fechaadmision'],
    ];

    /** Campos sin los cuales no se puede dar de alta a un funcionario. */
    public const OBLIGATORIOS = ['cedula', 'primer_nombre', 'primer_apellido', 'cargo'];

    /**
     * Propone un mapeo campo -> encabezado a partir de los encabezados leídos.
     *
     * @param string[] $encabezados
     * @return array<string, string|null>
     */
    public static function detectarMapeo(array $encabezados): array
    {
        $normalizados = [];
        foreach ($encabezados as $enc) {
            $normalizados[$enc] = self::normalizar($enc);
        }

        $mapeo = [];
        $usados = [];

        foreach (self::SINONIMOS as $campo => $sinonimos) {
            $mapeo[$campo] = null;

            // Primera pasada: coincidencia exacta del encabezado normalizado.
            foreach ($sinonimos as $sinonimo) {
                foreach ($normalizados as $original => $norm) {
                    if ($norm === $sinonimo && !in_array($original, $usados, true)) {
                        $mapeo[$campo] = $original;
                        $usados[] = $original;
                        continue 3;
                    }
                }
            }

            // Segunda pasada: el encabezado contiene el sinónimo ("nro. de cédula
            // del funcionario"). Menos precisa, por eso va después de la exacta.
            foreach ($sinonimos as $sinonimo) {
                if (strlen($sinonimo) < 4) {
                    continue; // 'ci' o 'nac' generarían falsos positivos
                }
                foreach ($normalizados as $original => $norm) {
                    if (str_contains($norm, $sinonimo) && !in_array($original, $usados, true)) {
                        $mapeo[$campo] = $original;
                        $usados[] = $original;
                        continue 3;
                    }
                }
            }
        }

        return $mapeo;
    }

    /**
     * Extrae y valida los datos de una fila según el mapeo.
     *
     * @return array{datos: array<string,mixed>|null, error: string|null}
     */
    public static function extraerFila(array $fila, array $mapeo): array
    {
        $valor = static function (?string $encabezado) use ($fila): string {
            if ($encabezado === null || !array_key_exists($encabezado, $fila)) {
                return '';
            }
            return trim((string) $fila[$encabezado]);
        };

        $cedulaCruda = $valor($mapeo['cedula'] ?? null);
        $cedula = preg_replace('/[^0-9]/', '', $cedulaCruda);

        // La nacionalidad suele venir pegada a la cédula ("V-12345678").
        $nacionalidad = strtoupper($valor($mapeo['nacionalidad'] ?? null));
        if ($nacionalidad === '' && preg_match('/^\s*([VE])\s*[-.\s]/i', $cedulaCruda, $m)) {
            $nacionalidad = strtoupper($m[1]);
        }
        $nacionalidad = in_array($nacionalidad, ['V', 'E'], true) ? $nacionalidad : 'V';

        if ($cedula === '') {
            return ['datos' => null, 'error' => 'Sin cédula'];
        }
        if (strlen($cedula) < 5 || strlen($cedula) > 10) {
            return ['datos' => null, 'error' => "Cédula inválida ({$cedulaCruda}): debe tener entre 5 y 10 dígitos"];
        }

        $primerNombre = $valor($mapeo['primer_nombre'] ?? null);
        $segundoNombre = $valor($mapeo['segundo_nombre'] ?? null);
        $primerApellido = $valor($mapeo['primer_apellido'] ?? null);
        $segundoApellido = $valor($mapeo['segundo_apellido'] ?? null);

        // Una sola columna "Nombres y Apellidos" es habitual en oficios de Word.
        if ($primerApellido === '' && $primerNombre !== '' && str_word_count($primerNombre, 0, 'áéíóúÁÉÍÓÚñÑ') >= 2) {
            $partes = preg_split('/\s+/u', $primerNombre);
            $primerNombre = array_shift($partes);
            $primerApellido = array_shift($partes) ?? '';
            if ($partes) {
                $segundoApellido = $segundoApellido !== '' ? $segundoApellido : implode(' ', $partes);
            }
        }

        if ($primerNombre === '') {
            return ['datos' => null, 'error' => 'Sin nombre'];
        }
        if ($primerApellido === '') {
            return ['datos' => null, 'error' => 'Sin apellido'];
        }

        $cargo = $valor($mapeo['cargo'] ?? null);
        if ($cargo === '') {
            return ['datos' => null, 'error' => 'Sin cargo'];
        }

        $email = $valor($mapeo['email'] ?? null);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Un correo mal escrito no debe descartar al funcionario entero:
            // se ignora el campo y el resto de la fila entra igual.
            $email = '';
        }

        return [
            'datos' => [
                'nacionalidad' => $nacionalidad,
                'cedula' => $cedula,
                'primer_nombre' => self::capitalizar($primerNombre),
                'segundo_nombre' => $segundoNombre !== '' ? self::capitalizar($segundoNombre) : null,
                'primer_apellido' => self::capitalizar($primerApellido),
                'segundo_apellido' => $segundoApellido !== '' ? self::capitalizar($segundoApellido) : null,
                'cargo' => $cargo,
                'gerencia' => $valor($mapeo['gerencia'] ?? null),
                'email' => $email !== '' ? $email : null,
                'fecha_ingreso' => self::normalizarFecha($valor($mapeo['fecha_ingreso'] ?? null)),
            ],
            'error' => null,
        ];
    }

    /**
     * Convierte a AAAA-MM-DD las formas en que una nómina expresa una fecha.
     * Devuelve null si no se reconoce: es preferible dejarla vacía a inventarla.
     */
    public static function normalizarFecha(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        // Excel guarda las fechas como número de serie. El origen es 1899-12-30
        // por el bug histórico del año 1900 que Excel conserva por compatibilidad.
        if (preg_match('/^\d{5}$/', $valor)) {
            $serial = (int) $valor;
            if ($serial > 0 && $serial < 60000) {
                return (new DateTimeImmutable('1899-12-30'))
                    ->modify("+{$serial} days")
                    ->format('Y-m-d');
            }
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        // dd/mm/aaaa — el formato de uso corriente en Venezuela.
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $valor, $m)) {
            $dia = (int) $m[1];
            $mes = (int) $m[2];
            $anio = (int) $m[3];
            if ($anio < 100) {
                $anio += $anio < 50 ? 2000 : 1900;
            }
            if (checkdate($mes, $dia, $anio)) {
                return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
            }
        }

        return null;
    }

    /** "JUAN CARLOS" o "juan carlos" -> "Juan Carlos". */
    public static function capitalizar(string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto));
        return mb_convert_case($texto, MB_CASE_TITLE, 'UTF-8');
    }

    /** Encabezado -> clave comparable: sin tildes, sin signos, en minúsculas. */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $texto) ?? '';
    }

    /** Campos obligatorios que el mapeo no logró resolver. */
    public static function faltantes(array $mapeo): array
    {
        $faltan = [];
        foreach (self::OBLIGATORIOS as $campo) {
            if (empty($mapeo[$campo])) {
                $faltan[] = $campo;
            }
        }
        return $faltan;
    }
}
