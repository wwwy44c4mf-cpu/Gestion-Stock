<?php

declare(strict_types=1);

// Sonde isolée dans une queue qu’aucun worker métier ne consomme.
$db = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DATABASE_HOST'), getenv('DATABASE_PORT'), getenv('DATABASE_NAME')),
    getenv('DATABASE_USER'),
    trim(file_get_contents(getenv('DATABASE_PASSWORD_FILE'))),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$queue = 'soc01_storage_probe';
if (($argv[1] ?? '') === 'write') {
    $db->beginTransaction();
    $db->prepare('DELETE FROM messenger_messages WHERE queue_name = ?')->execute([$queue]);
    $db->prepare('INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
        ->execute(['soc01-storage-probe', '{}', $queue]);
    $db->commit();
    echo "Sonde persistante écrite.\n";
} elseif (($argv[1] ?? '') === 'check') {
    $query = $db->prepare('SELECT body FROM messenger_messages WHERE queue_name = ?');
    $query->execute([$queue]);
    if ($query->fetchAll(PDO::FETCH_COLUMN) !== ['soc01-storage-probe']) {
        throw new RuntimeException('La sonde persistante est absente ou invalide.');
    }
    $db->prepare('DELETE FROM messenger_messages WHERE queue_name = ?')->execute([$queue]);
    echo "Persistance vérifiée après recréation ; sonde supprimée.\n";
} else {
    throw new InvalidArgumentException('Utiliser write ou check.');
}
