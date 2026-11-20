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
    <div class="container-fluid px-4 d-flex flex-wrap justify-content-between align-items-center h-100 small">
        <span>&copy; 2026 Badan Usaha Milik Kampus - Universitas Muhammadiyah Gorontalo (UMGO)</span>
        <span class="d-none d-md-inline">Qmas Air Mineral &nbsp;·&nbsp; Suara Muhammadiyah Corner &nbsp;·&nbsp; Daqam Caffe</span>
    </div>
</footer>

<script src="<?= base_url('/assets/js/charts.js') ?>?v=1"></script>
<script src="<?= base_url('/assets/js/main.js') ?>?v=3"></script>
</body>
</html>
