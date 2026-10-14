<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>ማሌ ዓርና — MASES</title>
    <style>
        :root {
            --color-bg: #fafafa;
            --color-surface: #ffffff;
            --color-text: #1a1a1a;
            --color-text-muted: #525252;
            --color-border: #e5e5e5;
            --color-accent: #1e3a8a;
            --color-accent-hover: #1e40af;
            --color-focus: #2563eb;

            --font-sans: "Noto Sans Ethiopic", "Abyssinica SIL", "Ebrima", "Nyala", "Kefa",
                         -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;

            --radius-md: 6px;
            --radius-lg: 10px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.04);
            --max-width: 640px;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: var(--font-sans);
            font-size: 16px;
            line-height: 1.7;
            color: var(--color-text);
            background-color: var(--color-bg);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .page {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 64px 24px 32px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            gap: 48px;
        }

        /* --- Brand ------------------------------------------------------- */

        .brand {
            text-align: center;
        }

        .brand__badge {
            display: inline-block;
            margin: 0 0 24px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            color: var(--color-accent);
            background-color: rgba(30, 58, 138, 0.08);
            border: 1px solid rgba(30, 58, 138, 0.18);
            border-radius: 999px;
        }

        .brand__name {
            margin: 0;
            font-size: 44px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.01em;
            color: var(--color-text);
        }

        .brand__full {
            margin: 12px 0 0;
            font-size: 15px;
            font-weight: 500;
            color: var(--color-text-muted);
            letter-spacing: 0.02em;
        }

        /* --- Intro ------------------------------------------------------- */

        .intro {
            text-align: center;
        }

        .intro__heading {
            margin: 0 0 16px;
            font-size: 18px;
            font-weight: 600;
            color: var(--color-text);
        }

        .intro__text {
            margin: 0 0 8px;
            font-size: 16px;
            color: var(--color-text);
        }

        .intro__note {
            margin: 0;
            font-size: 14px;
            color: var(--color-text-muted);
        }

        /* --- Status ------------------------------------------------------ */

        .status {
            padding: 32px 24px;
            background-color: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .status__heading {
            margin: 0 0 12px;
            font-size: 18px;
            font-weight: 600;
            color: var(--color-text);
        }

        .status__note {
            margin: 0 0 20px;
            font-size: 14px;
            color: var(--color-text-muted);
        }

        .status__link {
            display: inline-block;
            padding: 10px 24px;
            font-size: 15px;
            font-weight: 600;
            color: #ffffff;
            background-color: var(--color-accent);
            border-radius: var(--radius-md);
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .status__link:hover {
            background-color: var(--color-accent-hover);
        }

        .status__link:focus-visible {
            outline: 2px solid var(--color-focus);
            outline-offset: 2px;
        }

        /* --- Footer ------------------------------------------------------ */

        .footer {
            margin-top: auto;
            padding-top: 32px;
            text-align: center;
            border-top: 1px solid var(--color-border);
        }

        .footer__copy {
            margin: 0;
            font-size: 13px;
            color: var(--color-text-muted);
        }

        /* --- Responsive -------------------------------------------------- */

        @media (max-width: 480px) {
            .page {
                padding: 40px 16px 24px;
                gap: 32px;
            }

            .brand__name {
                font-size: 34px;
            }

            .status {
                padding: 24px 16px;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="brand">
            <p class="brand__badge">MASES</p>
            <h1 class="brand__name">ማሌ ዓርና</h1>
            <p class="brand__full">Maale Arna Smart System</p>
        </header>

        <section class="intro" aria-labelledby="intro-heading">
            <h2 id="intro-heading" class="intro__heading">የኤፒአይ አገልግሎት</h2>
            <p class="intro__text">
                ይህ ገጽ የማሌ ዓርና ስማርት ሲስተም (MASES) ኤፒአይ አገልግሎት መግቢያ ነው።
            </p>
            <p class="intro__note">
                ይህ ገጽ የተጠቃሚ ፖርታል ወይም አስተዳደራዊ ዳሽቦርድ አይደለም።
            </p>
        </section>

        <section class="status" aria-labelledby="status-heading">
            <h2 id="status-heading" class="status__heading">የአገልግሎት ሁኔታ</h2>
            <p class="status__note">
                የአገልግሎቱን ወቅታዊ ሁኔታ ለመመልከት የሚከተለውን አገናኝ ይጠቀሙ፦
            </p>
            <a class="status__link" href="/api/health">የአገልግሎት ሁኔታ</a>
        </section>

        <footer class="footer">
            <p class="footer__copy">© {{ date('Y') }} ማሌ ዓርና</p>
        </footer>
    </main>
</body>
</html>