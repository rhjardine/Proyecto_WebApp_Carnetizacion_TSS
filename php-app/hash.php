<?php
file_put_contents(
    'out.txt',
    "admin123: " . password_hash('admin123', PASSWORD_BCRYPT) . "\n" .
    "coord123: " . password_hash('coord123', PASSWORD_BCRYPT) . "\n" .
    "analista123: " . password_hash('analista123', PASSWORD_BCRYPT) . "\n" .
    "usuario123: " . password_hash('usuario123', PASSWORD_BCRYPT) . "\n" .
    "consulta123: " . password_hash('consulta123', PASSWORD_BCRYPT) . "\n"
);
