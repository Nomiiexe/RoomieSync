<?php
if (!isset($rootPath)) {
    $rootPath = './';
}
?>
  <footer class="mt-auto py-4 text-center text-secondary-custom no-print" style="font-size: 0.84rem; border-top: 1px solid var(--rs-border);">
    <div class="container">
      <p class="mb-1 fw-semibold text-dark-accent">
        <i class="bi bi-house-heart me-1"></i> RoomieSync &mdash; Household Management System
      </p>
      <p class="mb-0 text-secondary-custom" style="font-size: 0.78rem;">
        &copy; <?php echo date('Y'); ?> RoomieSync. All rights reserved.
      </p>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo $rootPath; ?>assets/js/main.js"></script>
</body>
</html>

