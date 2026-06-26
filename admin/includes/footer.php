            </div><!-- /admin-content -->
        </div><!-- /admin-main -->
    </div><!-- /admin-layout -->
    
    <script src="/assets/js/admin.js"></script>
    <?php if (isset($extraJS)): foreach ((array)$extraJS as $js): ?>
        <script src="<?php echo $js; ?>"></script>
    <?php endforeach; endif; ?>
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
