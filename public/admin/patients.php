<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

admin_page_start('Patients', 'patients');
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Patients</p>
            <h1>People the desk already knows.</h1>
        </div>
        <p class="head-note">A starting list for discussion. Editing, search, and records are not connected yet.</p>
    </header>
    <section class="panel">
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Sample patients</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Latest visit type</th>
                        <th scope="col">Booking note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (preview_patients() as $patient): ?>
                        <tr>
                            <td><?php echo h($patient['name']); ?></td>
                            <td><?php echo h($patient['phone']); ?></td>
                            <td><?php echo h($patient['visit']); ?></td>
                            <td><?php echo h($patient['household']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
admin_page_end();
