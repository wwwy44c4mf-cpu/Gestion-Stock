<?php

declare(strict_types=1);

$directory = '/workspace/.secrets';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Impossible de créer le répertoire des secrets.');
}

foreach (['app_secret', 'db_password', 'db_admin_password'] as $name) {
    $path = $directory.'/'.$name;
    // Ne jamais écraser un secret existant : la base conserve son mot de passe.
    $handle = @fopen($path, 'x');
    if ($handle !== false) {
        fwrite($handle, bin2hex(random_bytes(32))."\n");
        fclose($handle);
        chmod($path, 0644); // Lisible par les UID des conteneurs ; répertoire hôte privé.
    } elseif (!is_file($path) || trim((string) file_get_contents($path)) === '') {
        throw new RuntimeException('Secret absent ou vide : '.$name);
    }
}

// Delivery stays disabled until an operator configures the existing SMTP service.
$mailHandle = @fopen($directory.'/mailer_dsn', 'x');
if ($mailHandle !== false) {
    fwrite($mailHandle, "smtp://"."smtp.invalid:587?require_tls=true\n");
    fclose($mailHandle);
    chmod($directory.'/mailer_dsn', 0644);
}

if (!is_file('/workspace/.env')) {
    copy('/workspace/.env.example', '/workspace/.env');
}
echo "Configuration locale prête ; secrets existants conservés.\n";
