<?php

$password = 'admin123';
$hash = password_hash($password, PASSWORD_BCRYPT);

if ($hash === false) {
    fwrite(STDERR, "No se pudo generar el hash BCRYPT.\n");
    exit(1);
}

if (password_verify($password, $hash)) {
    echo "Password verified successfully!\n";
    echo "Generated hash: {$hash}\n";
} else {
    echo "Password verification failed.\n";
    exit(1);
}
