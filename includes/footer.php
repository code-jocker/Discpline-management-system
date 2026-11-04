            <?php if (basename($_SERVER['PHP_SELF']) === 'login.php'): ?>
            <footer class="official-footer">
                <strong><?= e($sbms_header_settingsModel->get('school_name', 'SBMS')) ?></strong>
                <span>Student Behavior Management System &middot; Version 1.0</span>
                <span>Developed by KARANGWA Vainqueur Alain</span>
            </footer>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <?php 
    $viteFooterAssets = $viteFooterAssets ?? [];
    vite_tags($viteFooterAssets); 
    ?>
</body>
</html>
