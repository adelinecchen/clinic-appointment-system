<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$appName = env_value('APP_NAME', 'Clinic Appointment System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
</head>
<body>
    <h1><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p>Patient website entry point. Appointment booking is not implemented yet.</p>
    <p>Health check: <a href="api/health.php">api/health.php</a></p>
</body>
</html>
