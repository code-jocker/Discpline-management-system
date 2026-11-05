            <?php if (basename($_SERVER['PHP_SELF']) === 'login.php'): ?>
            <footer class="official-footer">
                <strong><?= e($sbms_header_settingsModel->get('school_name', 'SBMS')) ?></strong>
                <span>Student Behavior Management System &middot; Version 1.0</span>
                <span>Developed by KARANGWA Vainqueur Alain</span>
            </footer>
            <?php endif; ?>
        </main>
    </div>

    <script src="<?= e(app_base_url()) ?>/assets/vendor/jquery-3.7.1.min.js"></script>
    <?php 
    $viteFooterAssets = $viteFooterAssets ?? [];
    vite_tags($viteFooterAssets); 
    ?>
    <script src="<?= htmlspecialchars(vite_asset('assets/js/toast.js')) ?>?v=<?= (int) (@filemtime(dirname(__DIR__) . '/assets/js/toast.js') ?: 1) ?>"></script>
</body>
</html>
