<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NSBM SportsHub</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>

<body>
    <header class="site-header">
        <div class="topbar">
            <!-- Site branding -->
            <a href="/index.php" class="brand">
                <span class="brand-text">
                    <span>SportsHub</span>
                </span>
            </a>

            <!-- Desktop navigation -->
            <nav class="main-nav">
                <a href="/index.php">Home</a>
                <a href="/clubs/index.php">Sports clubs</a>
                <a href="/tournaments/index.php">Tournaments</a>
                <a href="/matches/index.php">Fixtures</a>
                <a href="/training/index.php">Training</a>
                <a href="/bookings/index.php">Facilities</a>
            </nav>

            <!-- Desktop and tablet account actions -->
            <div class="account-nav">
                <a href="/login.php">Log in</a>
                <a href="/register.php" class="join-btn">Register</a>
            </div>

            <!-- Mobile navigation controls -->
            <div class="mobile-controls">
                <a
                    href="/login.php"
                    class="mobile-account-link"
                    aria-label="Log in to your account">
                    <svg
                        viewBox="0 0 24 24"
                        width="24"
                        height="24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21a8 8 0 0 1 16 0"></path>
                    </svg>
                </a>

                <details class="mobile-menu">
                    <summary
                        class="mobile-menu-trigger"
                        aria-label="Toggle navigation menu">
                        <svg
                            viewBox="0 0 24 24"
                            width="26"
                            height="26"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            aria-hidden="true">
                            <path d="M4 7h16"></path>
                            <path d="M4 12h16"></path>
                            <path d="M4 17h16"></path>
                        </svg>
                    </summary>

                    <nav class="mobile-menu-panel" aria-label="Mobile navigation">
                        <a href="/index.php">Home</a>
                        <a href="/clubs/index.php">Sports clubs</a>
                        <a href="/tournaments/index.php">Tournaments</a>
                        <a href="/matches/index.php">Fixtures</a>
                        <a href="/training/index.php">Training</a>
                        <a href="/bookings/index.php">Facilities</a>

                        <div class="mobile-menu-account">
                            <a href="/login.php">Log in</a>
                            <a href="/register.php">Register</a>
                        </div>
                    </nav>
                </details>
            </div>
        </div>
    </header>