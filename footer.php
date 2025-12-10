</main>
<footer class="footer text-center py-3 mt-auto border-top">
    <small>&copy; <?=date('Y')?> SecureAuth Demo</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Явна ініціалізація dropdown (на випадок, якщо автодата-API не спрацює)
    document.addEventListener('DOMContentLoaded', function () {
        if (window.bootstrap && document.querySelectorAll('[data-bs-toggle="dropdown"]').length) {
            document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
                try { new bootstrap.Dropdown(el); } catch(e) {}
            });
        }
    });
</script>
</body>
</html>

