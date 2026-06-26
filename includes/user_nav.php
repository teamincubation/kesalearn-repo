<?php
/**
 * KESA Learn - User Mobile Bottom Navigation
 * Professional app-like navigation for user pages
 */
$currentUserPage = basename($_SERVER['PHP_SELF'], '.php');
// My Events tab is active on dashboard and event-details pages
$isMyEventsActive = in_array($currentUserPage, ['dashboard', 'event-details']);
?>

<!-- Mobile Bottom Navigation -->
<nav class="user-bottom-nav">
    <a href="/user/dashboard" class="user-nav-item <?php echo $isMyEventsActive ? 'active' : ''; ?>">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span>My Events</span>
    </a>
    <a href="/user/certificates" class="user-nav-item <?php echo $currentUserPage === 'certificates' ? 'active' : ''; ?>">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        <span>Certificates</span>
    </a>
    <a href="/user/feedback" class="user-nav-item <?php echo $currentUserPage === 'feedback' ? 'active' : ''; ?>">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        <span>Feedback</span>
    </a>
    <a href="/user/profile.php" class="user-nav-item <?php echo $currentUserPage === 'profile' ? 'active' : ''; ?>">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        <span>Profile</span>
    </a>
</nav>

<style>
/* User Bottom Navigation */
.user-bottom-nav {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(255, 255, 255, 0.85) !important;
    backdrop-filter: blur(12px) saturate(1.5);
    -webkit-backdrop-filter: blur(12px) saturate(1.5);
    border-top: 1px solid var(--line);
    padding: 8px 0;
    padding-bottom: calc(8px + env(safe-area-inset-bottom));
    z-index: 1000;
    box-shadow: 0 -8px 24px rgba(20,22,40,0.06);
}

@media (max-width: 768px) {
    .user-bottom-nav {
        display: flex;
        justify-content: space-around;
        align-items: center;
    }
    
    /* Add padding to page content for bottom nav */
    .user-page-content {
        padding-bottom: 80px !important;
    }
}

.user-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 8px 12px;
    text-decoration: none;
    color: var(--ink-3) !important;
    font-size: 0.72rem;
    font-weight: 600;
    transition: all 0.2s var(--ease);
    border-radius: var(--radius-md);
    min-width: 56px;
    position: relative;
}

.user-nav-item svg {
    width: 22px;
    height: 22px;
    color: var(--ink-3);
    transition: all 0.2s var(--ease);
}

.user-nav-item.active {
    color: var(--red) !important;
}

.user-nav-item.active svg {
    color: var(--red) !important;
    transform: scale(1.1);
}

.user-nav-item.active::before {
    content: "";
    position: absolute;
    top: -8px;
    left: 22%;
    right: 22%;
    height: 3px;
    background: var(--red);
    border-radius: 0 0 3px 3px;
}

.user-nav-item:hover {
    color: var(--red) !important;
    background: var(--red-soft);
}

.user-nav-item:hover svg {
    color: var(--red) !important;
}

/* User Page Header with Dashboard Link */
.user-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: var(--nav-height);
    z-index: 100;
}

@media (min-width: 769px) {
    .user-page-header {
        display: none;
    }
}

.user-page-header-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.user-page-header-action {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: var(--red);
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s var(--ease);
    box-shadow: 0 3px 8px rgba(231,64,74,0.2);
}

.user-page-header-action:hover {
    background: var(--red-dark);
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(231,64,74,0.3);
}

.user-page-header-action svg {
    width: 16px;
    height: 16px;
}
</style>
