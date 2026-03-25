<?php
$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<aside id="sidebar" class="sidebar fixed inset-y-0 left-0 w-64 bg-black text-white flex flex-col z-50 lg:translate-x-0">
    <!-- Logo -->
    <div class="p-5 border-b border-neutral-800">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
            </div>
            <div>
                <h1 class="text-lg font-bold">Grading Management System</h1>
                <p class="text-xs text-neutral-400">Teacher Portal</p>
            </div>
        </div>
        <button id="closeSidebar" class="lg:hidden p-2 rounded-lg hover:bg-neutral-800 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        <a href="/teacher/dashboard.php" class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            <span>Dashboard</span>
        </a>
        <a href="/teacher/classes.php" class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg <?= in_array($currentPage, ['classes', 'grades', 'raw_scores']) ? 'active' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <span>My Classes</span>
        </a>
        <a href="/teacher/profile.php" class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-lg <?= $currentPage === 'profile' ? 'active' : '' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
            <span>My Profile</span>
        </a>
    </nav>

    <!-- User Profile -->
    <div class="p-4 border-t border-neutral-800">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-white text-black rounded-full flex items-center justify-center text-sm font-medium">
                    <?= strtoupper(substr($currentUser['name'] ?? 'T', 0, 1)) ?>
                </div>
                <div>
                    <p class="text-sm font-medium"><?= $currentUser['name'] ?? 'Teacher' ?></p>
                    <p class="text-xs text-neutral-500">Teacher</p>
                </div>
            </div>
            <a href="/logout.php" class="text-neutral-400 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
            </a>
        </div>
    </div>
</aside>
