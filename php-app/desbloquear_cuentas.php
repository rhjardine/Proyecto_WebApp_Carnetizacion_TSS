<?php
/**
 * desbloquear_cuentas.php — Utilidad de Emergencia SCI-TSS
 * =========================================================
 * Desbloquea TODAS las cuentas bloqueadas y opcionalmente
 * resetea contraseñas a valores por defecto (demo).
 *
 * ⚠️  SOLO PARA DESARROLLO/EMERGENCIA. Eliminar en producción.
 *
 * URL: http://localhost/.../php-app/desbloquear_cuentas.php
 */
require_once __DIR__ . '/includes/db_mysql.php';

$mensaje = '';
$tipo = '';

// ── Acción: Desbloquear todas ─────────────────────────────
if (isset($_POST['desbloquear'])) {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "UPDATE usuarios SET bloqueado = 0, intentos_fallidos = 0, actualizado_el = NOW()"
        );
        $stmt->execute();
        $afectados = $stmt->rowCount();
        $mensaje = "✅ {$afectados} cuenta(s) desbloqueada(s) correctamente.";
        $tipo = 'success';
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . htmlspecialchars($e->getMessage());
        $tipo = 'error';
    }
}

// ── Acción: Resetear contraseñas a demo ───────────────────
if (isset($_POST['resetear'])) {
    try {
        $db = getDB();
        $defaults = [
            'admin' => 'admin123',
            'coordinador' => 'coord123',
            'analista' => 'analista123',
            'usuario' => 'usuario123',
            'consulta' => 'consulta123',
        ];
        $stmt = $db->prepare("UPDATE usuarios SET clave_hash = ?, bloqueado = 0, intentos_fallidos = 0, actualizado_el = NOW() WHERE usuario = ?");
        $count = 0;
        foreach ($defaults as $user => $pass) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt->execute([$hash, $user]);
            $count += $stmt->rowCount();
        }
        $mensaje = "✅ {$count} contraseña(s) reseteada(s) a valores por defecto.";
        $tipo = 'success';
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . htmlspecialchars($e->getMessage());
        $tipo = 'error';
    }
}

// ── Leer estado actual ────────────────────────────────────
$usuarios = [];
try {
    $db = getDB();
    $stmt = $db->query(
        "SELECT id, usuario, nombre_completo, rol, bloqueado, intentos_fallidos
         FROM usuarios ORDER BY bloqueado DESC, usuario"
    );
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    $mensaje = $mensaje ?: "❌ Error de conexión: " . htmlspecialchars($e->getMessage());
    $tipo = 'error';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desbloquear Cuentas — SCI-TSS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f1f5f9;
            padding: 30px;
            color: #1e293b;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: #0f172a;
        }

        .subtitle {
            color: #64748b;
            font-size: .9rem;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .1);
            margin-bottom: 20px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            font-size: .9rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }

        th,
        td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            font-size: .75rem;
            letter-spacing: .5px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
        }

        .badge-red {
            background: #fee2e2;
            color: #dc2626;
        }

        .badge-green {
            background: #dcfce7;
            color: #16a34a;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: .85rem;
            transition: all .2s;
        }

        .btn-unlock {
            background: #2563eb;
            color: white;
        }

        .btn-unlock:hover {
            background: #1d4ed8;
        }

        .btn-reset {
            background: #dc2626;
            color: white;
        }

        .btn-reset:hover {
            background: #b91c1c;
        }

        .btn-back {
            background: #e2e8f0;
            color: #475569;
            text-decoration: none;
            display: inline-block;
        }

        .btn-back:hover {
            background: #cbd5e1;
        }

        .warn {
            font-size: .8rem;
            color: #dc2626;
            margin-top: 12px;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🔓 Desbloquear Cuentas — SCI-TSS</h1>
        <p class="subtitle">Utilidad de emergencia para desbloquear cuentas y resetear contraseñas.</p>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $tipo ?>">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-bottom:16px;">Estado de Cuentas</h3>
            <?php if (count($usuarios) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Intentos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td>
                                    <?= $u['id'] ?>
                                </td>
                                <td><strong>
                                        <?= htmlspecialchars($u['usuario']) ?>
                                    </strong></td>
                                <td>
                                    <?= htmlspecialchars($u['nombre_completo']) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($u['rol']) ?>
                                </td>
                                <td>
                                    <?php if ($u['bloqueado']): ?>
                                        <span class="badge badge-red">🔒 Bloqueado</span>
                                    <?php else: ?>
                                        <span class="badge badge-green">✅ Activo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $u['intentos_fallidos'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="alert alert-warning">
                    ⚠️ No se pudieron cargar los usuarios. Verifique la conexión a la base de datos.
                </div>
            <?php endif; ?>

            <div class="actions">
                <form method="POST" style="display:inline;">
                    <button type="submit" name="desbloquear" class="btn btn-unlock">
                        🔓 Desbloquear Todas las Cuentas
                    </button>
                </form>
                <form method="POST" style="display:inline;"
                    onsubmit="return confirm('¿Está seguro? Esto reseteará TODAS las contraseñas a los valores por defecto (demo).');">
                    <button type="submit" name="resetear" class="btn btn-reset">
                        🔑 Resetear Contraseñas a Demo
                    </button>
                </form>
                <a href="login.html" class="btn btn-back">← Volver al Login</a>
            </div>
            <p class="warn">⚠️ Contraseñas por defecto: admin→admin123 | coordinador→coord123 | analista→analista123 |
                usuario→usuario123 | consulta→consulta123</p>
        </div>
    </div>
</body>

</html>