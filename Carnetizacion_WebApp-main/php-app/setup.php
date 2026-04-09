<?php
/**
 * setup.php — Herramienta de configuración inicial del sistema
 * Ejecutar solo UNA VEZ para verificar conexión y configurar contraseñas
 * 
 * URL: http://localhost:8000/setup.php
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Inicial — SCI‑TSS</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f8fafc;
        }

        h1 {
            color: #003366;
            border-bottom: 3px solid #003366;
            padding-bottom: 10px;
        }

        h2 {
            color: #0284c7;
            margin-top: 30px;
        }

        .ok {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 10px 16px;
            border-radius: 6px;
            margin: 8px 0;
        }

        .err {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 10px 16px;
            border-radius: 6px;
            margin: 8px 0;
        }

        .info {
            background: #dbeafe;
            border-left: 4px solid #0284c7;
            padding: 10px 16px;
            border-radius: 6px;
            margin: 8px 0;
        }

        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
        }

        code {
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .1);
        }

        th {
            background: #003366;
            color: #fff;
            padding: 10px 14px;
            text-align: left;
            font-size: .85rem;
        }

        td {
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            font-size: .875rem;
        }

        tr:last-child td {
            border-bottom: none;
        }
    </style>
</head>

<body>
    <h1>🔧 Setup Inicial — Sistema de Carnetización Inteligente – TSS</h1>
    <p style="color:#64748b;">Esta página solo debe ser usada por el administrador durante la configuración inicial.</p>

    <?php
    require_once __DIR__ . '/includes/db.php';

    // ── 1. Verificar conexión ──────────────────────────────────────────────────────
    echo "<h2>1. Conexión a PostgreSQL (Supabase)</h2>";
    try {
        $pdo->query("SELECT 1");
        echo "<div class='ok'>✅ Conexión PDO establecida correctamente.</div>";
    } catch (Exception $e) {
        echo "<div class='err'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<p>Verifique <code>includes/db.php</code> y las credenciales de Supabase.</p>";
        exit;
    }

    // ── 2. Verificar tablas ────────────────────────────────────────────────────────
    echo "<h2>2. Estado de las Tablas</h2>";
    $tables = ['users', 'gerencias', 'employees', 'audit_logs'];
    foreach ($tables as $table) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "<div class='ok'>✅ <strong>$table</strong>: $count registros.</div>";
        } catch (Exception $e) {
            echo "<div class='err'>❌ <strong>$table</strong> no encontrada. ¿Ejecutaste schema.sql?</div>";
        }
    }

    // ── 3. Sendar / verificar usuarios de demo ─────────────────────────────────────
    echo "<h2>3. Usuarios Demo (Contraseñas en Texto Plano para DEV)</h2>";
    echo "<div class='info'>ℹ️ Las contraseñas en texto plano son detectadas por auth.php automáticamente. 
Para producción, use el botón de abajo para migrar a bcrypt.</div>";

    $demoUsers = [
        ['admin', 'admin123', 'Administrador Principal TSS', 'ADMIN'],
        ['coordinador', 'coord123', 'Coordinador Demo', 'COORD'],
        ['analista', 'analista123', 'Analista Demo', 'ANALISTA'],
        ['usuario', 'usuario123', 'Usuario Demo', 'USUARIO'],
    ];

    $insCount = 0;
    foreach ($demoUsers as [$uname, $pass, $name, $role]) {
        $stmt = $pdo->prepare("SELECT id, password_hash, is_locked FROM users WHERE username = ?");
        $stmt->execute([$uname]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $pdo->prepare(
                "INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?::user_role)"
            )->execute([$uname, $pass, $name, $role]);
            echo "<div class='ok'>✅ Usuario <strong>$uname</strong> creado (pass: <code>$pass</code>).</div>";
            $insCount++;
        } else {
            // Unlock and update password if needed
            $isOldBcrypt = (strlen($existing['password_hash']) >= 60 && str_starts_with($existing['password_hash'], '$2'));
            $isWrongHash = $isOldBcrypt && !password_verify($pass, $existing['password_hash']);

            if ($isWrongHash || $existing['is_locked']) {
                $pdo->prepare(
                    "UPDATE users SET password_hash = ?, failed_attempts = 0, is_locked = FALSE WHERE username = ?"
                )->execute([$pass, $uname]);
                $action = [];
                if ($isWrongHash)
                    $action[] = "contraseña actualizada a texto plano";
                if ($existing['is_locked'])
                    $action[] = "cuenta desbloqueada";
                echo "<div class='ok'>🔄 <strong>$uname</strong>: " . implode(', ', $action) . ".</div>";
            } else {
                echo "<div class='info'>ℹ️ <strong>$uname</strong>: ya existe (sin cambios).</div>";
            }
        }
    }

    // ── 4. Mostrar estado actual de usuarios ───────────────────────────────────────
    echo "<h2>4. Estado Actual de Usuarios</h2>";
    $users = $pdo->query("SELECT username, full_name, role, failed_attempts, is_locked FROM users ORDER BY role")->fetchAll();
    echo "<table><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Intentos</th><th>Bloqueado</th></tr></thead><tbody>";
    foreach ($users as $u) {
        $lockedClass = $u['is_locked'] ? 'style="color:#dc2626;font-weight:700;"' : '';
        echo "<tr>
        <td><code>{$u['username']}</code></td>
        <td>{$u['full_name']}</td>
        <td>{$u['role']}</td>
        <td>{$u['failed_attempts']}</td>
        <td $lockedClass>" . ($u['is_locked'] ? '🔒 Sí' : '✅ No') . "</td>
    </tr>";
    }
    echo "</tbody></table>";

    // ── 5. Hashes bcrypt de referencia ────────────────────────────────────────────
    echo "<h2>5. Hashes bcrypt para Producción</h2>";
    echo "<div class='info'>Copie estos valores a seed.sql para reemplazar las contraseñas en texto plano antes del despliegue a producción.</div>";
    echo "<pre>";
    foreach ($demoUsers as [$uname, $pass]) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        echo "-- $uname / $pass\n";
        echo "UPDATE users SET password_hash = '$hash' WHERE username = '$uname';\n\n";
    }
    echo "</pre>";

    echo "<h2>✅ Setup Completado</h2>";
    echo "<p><a href='login.html' style='background:#003366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>→ Ir al Login</a></p>";
    echo "<p style='color:#dc2626;margin-top:20px;font-size:.8rem;'>⚠️ Elimine este archivo (setup.php) después de completar la configuración inicial en producción.</p>";
    ?>
</body>

</html>