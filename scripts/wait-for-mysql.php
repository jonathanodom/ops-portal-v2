<?php

$fileEnvironment = parse_ini_file(__DIR__.'/../.env', false, INI_SCANNER_RAW) ?: [];
$value = static fn (string $key, string $fallback): string => (string) (getenv($key) ?: ($fileEnvironment[$key] ?? $fallback));

$host = $value('DB_HOST', '127.0.0.1');
$port = $value('DB_PORT', '3307');
$database = $value('DB_DATABASE', 'newday_ops');
$username = $value('DB_USERNAME', 'newday');
$password = $value('DB_PASSWORD', 'newday_local_only');

for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password);
        fwrite(STDOUT, "MySQL is ready.\n");
        exit(0);
    } catch (PDOException) {
        if ($attempt === 30) {
            fwrite(STDERR, "MySQL did not become ready within 60 seconds.\n");
            exit(1);
        }

        sleep(2);
    }
}
