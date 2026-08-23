<?php
// Canonical list of sidebar sections a scoped (non-super-admin) user can be
// granted or denied access to. Keys are stored in user_sidebar_sections.
// change_password.php is intentionally excluded (every logged-in user always
// keeps it). users.php (User Management) is ALSO intentionally excluded even
// though everything else here was opened up — granting it would let a
// scoped user create/edit other users' permissions, including escalating
// their own to super admin, so it stays hard-gated in Sidebar.php/users.php.
function sidebarSectionsList(): array
{
    return [
        'dashboard'           => ['label' => 'Dashboard', 'page' => 'index.php'],
        'report'              => ['label' => 'Social Media Report', 'page' => 'report.php'],
        'weekly_posts'        => ['label' => 'Posting Activity', 'page' => 'weekly_posts.php'],
        'yt_monthly'          => ['label' => 'YT Monthly Videos', 'page' => 'yt_monthly.php'],
        'no_activity_report'  => ['label' => 'Follower Activity Report', 'page' => 'no_activity_report.php'],
        'manual_publish'      => ['label' => 'Publish Content', 'page' => 'manual_publish.php'],
        'syndication_report'  => ['label' => 'Integration Report', 'page' => 'syndication_report.php'],
        'post_breakdown'      => ['label' => 'Post Breakdown Report', 'page' => 'post_breakdown_report.php'],
        'reshare_compliance'  => ['label' => 'Reshare Compliance', 'page' => 'reshare_compliance_report.php'],
        'target_pages'        => ['label' => 'Target Pages', 'page' => 'target_pages.php'],
        'exchange_token'      => ['label' => 'Exchange Token', 'page' => 'exchange_token.php'],
        'submit_post_check'   => ['label' => 'Post Approval', 'page' => 'submit_post_check.php'],
        'email_validator'     => ['label' => 'Email Validator', 'page' => 'email_validator.php'],
        'sales_report'        => ['label' => 'Sales Report', 'page' => 'sales_report.php'],
        'stock_report'        => ['label' => 'Stock Report', 'page' => 'stock_report.php'],
        'visit_report'        => ['label' => 'Visit Report', 'page' => 'visit_report.php'],
        'ageing_report'       => ['label' => 'Ageing Report', 'page' => 'ageing_report.php'],
        'crm_report'          => ['label' => 'CRM Report', 'page' => 'crm_report.php'],
        'crm_parameters'      => ['label' => 'CRM Parameters', 'page' => 'crm_parameters.php'],
        'crm_data_quality'    => ['label' => 'CRM Data Quality Checker', 'page' => 'crm_data_quality_check.php'],
        'brand_assets'        => ['label' => 'Brand Assets', 'page' => 'brand_assets.php'],
    ];
}
