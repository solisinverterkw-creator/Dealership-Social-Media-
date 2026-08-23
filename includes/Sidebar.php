<?php
$__currentPage = basename($_SERVER['SCRIPT_NAME']);
function navLinkClass(string $page, string $current): string
{
    return $page === $current ? 'sidebar-link active' : 'sidebar-link';
}
?>
<button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">☰</button>
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>
<aside class="sidebar" id="app-sidebar">
  <div class="sidebar-brand">
    <span class="sidebar-logo-plate"><img src="assets/suzuki-logo.png" alt="Suzuki" class="sidebar-logo"></span>
    <span>ROSP - Dealers Social Media</span>
  </div>
  <nav>
    <?php if (Auth::canView('dashboard')): ?><a href="index.php" class="<?= navLinkClass('index.php', $__currentPage) ?>">Dashboard</a><?php endif; ?>
    <?php if (Auth::canView('report')): ?><a href="report.php" class="<?= navLinkClass('report.php', $__currentPage) ?>">Social Media Report</a><?php endif; ?>
    <?php if (Auth::canView('weekly_posts')): ?><a href="weekly_posts.php" class="<?= navLinkClass('weekly_posts.php', $__currentPage) ?>">Posting Activity</a><?php endif; ?>
    <?php if (Auth::canView('yt_monthly')): ?><a href="yt_monthly.php" class="<?= navLinkClass('yt_monthly.php', $__currentPage) ?>">YT Monthly Videos</a><?php endif; ?>
    <?php if (Auth::canView('no_activity_report')): ?><a href="no_activity_report.php" class="<?= navLinkClass('no_activity_report.php', $__currentPage) ?>">Follower Activity Report</a><?php endif; ?>
    <div style="margin:16px 0 6px; padding:0 12px; font-size:10px; color:var(--muted); font-family:'IBM Plex Mono', monospace; text-transform:uppercase; letter-spacing:0.5px;">Facebook Integration</div>
    <?php if (Auth::canView('manual_publish')): ?><a href="manual_publish.php" class="<?= navLinkClass('manual_publish.php', $__currentPage) ?>">Publish Content</a><?php endif; ?>
    <?php if (Auth::canView('syndication_report')): ?><a href="syndication_report.php" class="<?= navLinkClass('syndication_report.php', $__currentPage) ?>">Integration Report</a><?php endif; ?>
    <?php if (Auth::canView('post_breakdown')): ?><a href="post_breakdown_report.php" class="<?= navLinkClass('post_breakdown_report.php', $__currentPage) ?>">Post Breakdown Report</a><?php endif; ?>
    <?php if (Auth::canView('reshare_compliance')): ?><a href="reshare_compliance_report.php" class="<?= navLinkClass('reshare_compliance_report.php', $__currentPage) ?>">Reshare Compliance</a><?php endif; ?>
    <?php if (Auth::canView('target_pages')): ?><a href="target_pages.php" class="<?= navLinkClass('target_pages.php', $__currentPage) ?>">Target Pages</a><?php endif; ?>
    <?php if (Auth::canView('exchange_token')): ?><a href="exchange_token.php" class="<?= navLinkClass('exchange_token.php', $__currentPage) ?>">Exchange Token</a><?php endif; ?>
    <div style="margin:16px 0 6px; padding:0 12px; font-size:10px; color:var(--muted); font-family:'IBM Plex Mono', monospace; text-transform:uppercase; letter-spacing:0.5px;">Content Compliance</div>
    <?php if (Auth::canView('submit_post_check')): ?><a href="submit_post_check.php" class="<?= navLinkClass('submit_post_check.php', $__currentPage) ?>">Post Approval</a><?php endif; ?>
    <?php if (Auth::canView('email_validator')): ?><a href="email_validator.php" class="<?= navLinkClass('email_validator.php', $__currentPage) ?>">Email Validator</a><?php endif; ?>
    <?php if (Auth::canView('sales_report') || Auth::canView('stock_report') || Auth::canView('visit_report') || Auth::canView('ageing_report') || Auth::canView('crm_report') || Auth::canView('crm_parameters') || Auth::canView('crm_data_quality')): ?>
    <div style="margin:16px 0 6px; padding:0 12px; font-size:10px; color:var(--muted); font-family:'IBM Plex Mono', monospace; text-transform:uppercase; letter-spacing:0.5px;">Sales &amp; Stock</div>
    <?php endif; ?>
    <?php if (Auth::canView('sales_report')): ?><a href="sales_report.php" class="<?= navLinkClass('sales_report.php', $__currentPage) ?>">Sales Report</a><?php endif; ?>
    <?php if (Auth::canView('stock_report')): ?><a href="stock_report.php" class="<?= navLinkClass('stock_report.php', $__currentPage) ?>">Stock Report</a><?php endif; ?>
    <?php if (Auth::canView('visit_report')): ?><a href="visit_report.php" class="<?= navLinkClass('visit_report.php', $__currentPage) ?>">Visit Report</a><?php endif; ?>
    <?php if (Auth::canView('ageing_report')): ?><a href="ageing_report.php" class="<?= navLinkClass('ageing_report.php', $__currentPage) ?>">Ageing Report</a><?php endif; ?>
    <?php if (Auth::canView('crm_report')): ?><a href="crm_report.php" class="<?= navLinkClass('crm_report.php', $__currentPage) ?>">CRM Report</a><?php endif; ?>
    <?php if (Auth::canView('crm_parameters')): ?><a href="crm_parameters.php" class="<?= navLinkClass('crm_parameters.php', $__currentPage) ?>">CRM Parameters</a><?php endif; ?>
    <?php if (Auth::canView('crm_data_quality')): ?><a href="crm_data_quality_check.php" class="<?= navLinkClass('crm_data_quality_check.php', $__currentPage) ?>">CRM Data Quality Checker</a><?php endif; ?>
    <?php if (Auth::canView('brand_assets')): ?><a href="brand_assets.php" class="<?= navLinkClass('brand_assets.php', $__currentPage) ?>">Brand Assets</a><?php endif; ?>
    <?php if (Auth::isSuperAdmin()): ?>
    <a href="users.php" class="<?= navLinkClass('users.php', $__currentPage) ?>">User Management</a>
    <?php endif; ?>
    <a href="change_password.php" class="<?= navLinkClass('change_password.php', $__currentPage) ?>">Change Password</a>
  </nav>
  <select class="theme-select" id="theme-select" onchange="setTheme(this.value)">
    <option value="dark">🌙 Dark</option>
    <option value="light">☀️ Light</option>
    <option value="midnight">🌌 Midnight</option>
    <option value="slate">🪨 Slate</option>
    <option value="contrast">◐ High Contrast</option>
  </select>
  <div class="sidebar-footer">Developed By Wasim Javed</div>
</aside>
<script>
function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('theme', theme);
}
document.getElementById('theme-select').value = document.documentElement.getAttribute('data-theme') || 'dark';

function toggleSidebar() {
  document.getElementById('app-sidebar').classList.toggle('open');
  document.getElementById('sidebar-backdrop').classList.toggle('visible');
}
function closeSidebar() {
  document.getElementById('app-sidebar').classList.remove('open');
  document.getElementById('sidebar-backdrop').classList.remove('visible');
}
document.getElementById('sidebar-toggle').addEventListener('click', toggleSidebar);
document.getElementById('sidebar-backdrop').addEventListener('click', toggleSidebar);

// On mobile the sidebar overlays the page — close it immediately on tapping
// any nav link so the transition to the new page feels instant instead of
// leaving the menu visibly open while the next page loads.
document.querySelectorAll('.sidebar nav a').forEach((link) => {
  link.addEventListener('click', closeSidebar);
});
</script>
