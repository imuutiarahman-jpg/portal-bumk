<?php
require_once __DIR__ . '/../../config/app.php';

$title = 'Unit Usaha';
$isPublic = true;
$user = current_user();
require_once __DIR__ . '/../../includes/portal_units.php';

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="portal-section" id="unit-usaha" aria-labelledby="unitTitle">
    <div class="container-fluid px-4">
        <div class="text-center mb-5">
            <div class="section-eyebrow">Menu Unit Usaha</div>
            <h2 class="section-title" id="unitTitle">Pilih Unit Usaha Anda</h2>
            <p class="section-lead mb-0">Setiap unit memiliki dashboard, transaksi, stok, dan laporan tersendiri.</p>
        </div>

        <div class="unit-grid">
            <?php foreach ($units as $u):
                $unit = unit_by_code($u['code']);
            ?>
            <article class="unit-card" aria-labelledby="card-<?= e($u['code']) ?>">
                <div class="card-accent"></div>
                <div class="card-body">
                    <div class="card-icon mx-auto"><i class="bi <?= e($u['icon']) ?>" aria-hidden="true"></i></div>
                    <h3 class="card-title text-center" id="card-<?= e($u['code']) ?>"><?= e(strtoupper($u['short'])) ?></h3>
                    <div class="card-subname text-center"><?= e($unit['name'] ?? $u['code']) ?></div>
                    <p class="card-text text-center"><?= e($unit['description'] ?? '') ?></p>
                    <ul>
                        <?php foreach ($u['items'] as $item): ?>
                            <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= e($linkFor($u['code'])) ?>" class="btn btn-unit btn-brand text-white">
                        <?= e($u['cta']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-4">
            <a href="<?= base_url('/modules/portal/panduan.php') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-question-circle"></i> Lihat Panduan Akses
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
