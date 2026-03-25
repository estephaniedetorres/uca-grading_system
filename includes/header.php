<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'GradeMate' ?> - GradeMate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#fafafa',
                            100: '#f5f5f5',
                            200: '#e5e5e5',
                            300: '#d4d4d4',
                            400: '#a3a3a3',
                            500: '#737373',
                            600: '#525252',
                            700: '#404040',
                            800: '#262626',
                            900: '#171717',
                        },
                        sidebar: '#000000'
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar-link { transition: all 0.2s ease; }
        .sidebar-link:hover { background-color: rgba(255,255,255,0.1); }
        .sidebar-link.active { background-color: rgba(255,255,255,0.15); border-left: 3px solid white; }
        
        /* Mobile Responsive Styles */
        @media (max-width: 1023px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
                pointer-events: none;
            }
            .sidebar.open {
                transform: translateX(0);
                pointer-events: auto;
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 40;
            }
            .sidebar-overlay.open {
                display: block;
            }
            .main-content {
                margin-left: 0 !important;
            }
        }
        
        /* Table responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .alert-auto-hide {
            position: fixed;
            top: 1.4rem;
            left: 50%;
            transform: translateX(-50%);
            min-width: min(320px, 80%);
            max-width: min(480px, 90%);
            z-index: 60;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
            border: 1px solid rgba(0, 0, 0, 0.08);
            background-clip: padding-box;
            margin: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .k12-hour-dim {
            background-color: #ffffff;
            color: inherit;
            border-color: #d1d5db;
        }
        
        /* Card grid responsive */
        @media (max-width: 640px) {
            .responsive-grid {
                grid-template-columns: 1fr !important;
            }
        }
        
        /* Form responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr !important;
            }
        }
        
        /* Hide on mobile */
        @media (max-width: 640px) {
            .hide-mobile {
                display: none !important;
            }
        }

        /* Print styles */
        @media print {
            .no-print, .sidebar, .mobile-header, #sidebarOverlay, .lg\:hidden { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .print-area { border: none !important; box-shadow: none !important; }
            body { background: white !important; }
        }
        
        /* Mobile header */
        .mobile-header {
            display: none;
        }
        @media (max-width: 1023px) {
            .mobile-header {
                display: flex;
            }
        }
    </style>
</head>
<body class="bg-white">
    <!-- Mobile Header -->
    <div class="mobile-header fixed top-0 left-0 right-0 h-16 bg-black text-white z-40 items-center justify-between px-4 lg:hidden">
        <button id="menuToggle" class="p-2 rounded-lg hover:bg-neutral-800 transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
            </div>
            <span class="font-bold">Grading Management System</span>
        </div>
        <div class="w-10"></div>
    </div>
    
    <!-- Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden"></div>
    
    <!-- Add padding for mobile header -->
    <div class="lg:hidden h-16"></div>
