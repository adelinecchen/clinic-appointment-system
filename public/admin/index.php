<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$appName = env_value('APP_NAME', 'Clinic Appointment System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
</head>
<body>
    <h1>Administrative dashboard</h1>
    <p>This page is a placeholder. Appointment, patient, and waitlist management are not implemented yet.</p>
    <p><a href="../index.php">Back to the patient site</a></p>
</body>
</html>
