<?php
$hash = '$2y$10$INF/JbG/i3qMWhb0sDogIOBvUobRwpDLVoD3jVJK8qve9A8lsbrFu';
$password = 'admin123';
if (password_verify($password, $hash)) {
    echo "Password verified successfully!\n";
} else {
    echo "Password verification failed.\n";
}
