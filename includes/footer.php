<?php
$isPublic = $isPublic ?? false;
$user = current_user();
?>
<?php if (!$isPublic && $user): ?>
        </main>
    </div>
</div>
<?php else: ?>
        </main>
<?php endif; ?>

<footer class="portal-footer">
    <div class="container-fluid px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>&copy; <?= date('Y') ?> Badan Usaha Milik Kampus · Universitas Muhammadiyah Gorontalo</span>
        <span class="small">Qmas Air Mineral <span class="footer-dot">·</span> Suara Muhammadiyah Corner <span class="footer-dot">·</span> Daqam Caffe</span>
    </div>
</footer>

<script src="<?= base_url('/assets/js/charts.js') ?>?v=3"></script>
<script src="<?= base_url('/assets/js/main.js') ?>?v=6"></script>
</body>
</html>
