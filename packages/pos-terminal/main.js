const { app, BrowserWindow } = require('electron');
const path = require('path');
const fs = require('fs');

// Determine path for config file
// For portability, we check next to the executable first, then fallback to user data.
const exeDir = path.dirname(app.getPath('exe'));
const localConfigPath = path.join(exeDir, 'config.json');
const userDataConfigPath = path.join(app.getPath('userData'), 'config.json');

const projectConfigPath = path.join(__dirname, 'config.json');

let config = {
    posUrl: 'http://localhost:8000/pos/terminal/checkout'
};

// Try to load config
try {
    if (fs.existsSync(projectConfigPath)) {
        Object.assign(config, JSON.parse(fs.readFileSync(projectConfigPath, 'utf8')));
    } else if (fs.existsSync(localConfigPath)) {
        Object.assign(config, JSON.parse(fs.readFileSync(localConfigPath, 'utf8')));
    } else if (fs.existsSync(userDataConfigPath)) {
        Object.assign(config, JSON.parse(fs.readFileSync(userDataConfigPath, 'utf8')));
    } else {
        // Create default config in user data if none exists
        fs.writeFileSync(userDataConfigPath, JSON.stringify(config, null, 4));
    }
} catch (err) {
    console.error("Failed to load config:", err);
}

// Override via env var if provided (useful for dev)
const POS_URL = process.env.IPOS_URL || config.posUrl;

let mainWindow;
let retryTimer = null;

function clearRetryTimer() {
    if (retryTimer) {
        clearTimeout(retryTimer);
        retryTimer = null;
    }
}

function scheduleRetry() {
    clearRetryTimer();
    retryTimer = setTimeout(() => {
        if (mainWindow && !mainWindow.isDestroyed()) {
            mainWindow.loadURL(POS_URL);
        }
    }, 5000);
}

function offlineFallbackUrl(errorDescription, errorCode) {
    const html = `
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IPOS Terminal Offline</title>
    <style>
        html, body {
            width: 100%;
            height: 100%;
            margin: 0;
            background: #020617;
            color: #e2e8f0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        body {
            display: grid;
            place-items: center;
        }
        main {
            width: min(90vw, 32rem);
            padding: 2rem;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 1.25rem;
            background: rgba(15, 23, 42, 0.78);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
            text-align: center;
        }
        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.35rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        p {
            margin: 0.4rem 0;
            color: #94a3b8;
            line-height: 1.5;
        }
        code {
            color: #c4b5fd;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <main>
        <h1>IPOS Terminal</h1>
        <p>The local POS server is not reachable yet.</p>
        <p>The terminal will retry automatically every few seconds.</p>
        <p><code>${String(errorDescription || 'Offline').replace(/[<>&"]/g, '')} (${errorCode || 'network'})</code></p>
    </main>
</body>
</html>`;

    return `data:text/html;charset=utf-8,${encodeURIComponent(html)}`;
}

function createWindow() {
    mainWindow = new BrowserWindow({
        // Full Kiosk Mode Lockdown
        kiosk: true,
        fullscreen: true,
        frame: false,
        autoHideMenuBar: true,
        webPreferences: {
            devTools: false,
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
        },
    });

    const allowedBasePath = new URL(POS_URL).origin;
    
    mainWindow.webContents.on('will-navigate', (event, url) => {
        if (!url.startsWith(allowedBasePath) && !url.startsWith('data:text/html')) {
            console.log(`[Security] Blocked navigation to external URL: ${url}`);
            event.preventDefault();
        }
    });

    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        return { action: 'deny' };
    });

    mainWindow.webContents.on('before-input-event', (event, input) => {
        if (input.key === 'F12' || input.key === 'F11') event.preventDefault();
        if (input.key === 'F5' || (input.control && input.key.toLowerCase() === 'r')) event.preventDefault();
        if (input.alt && input.key === 'F4') event.preventDefault();
        if (input.control && input.key.toLowerCase() === 'w') event.preventDefault();
    });

    mainWindow.webContents.on('context-menu', (event) => {
        event.preventDefault();
    });

    mainWindow.webContents.on('did-finish-load', () => {
        if (mainWindow.webContents.getURL().startsWith(allowedBasePath)) {
            clearRetryTimer();
        }
    });

    // Handle main-frame load failures gracefully. The browser service worker covers
    // normal offline refreshes after one successful online load.
    mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription, validatedURL, isMainFrame) => {
        if (!isMainFrame || String(validatedURL || '').startsWith('data:text/html')) {
            return;
        }

        mainWindow.loadURL(offlineFallbackUrl(errorDescription, errorCode));
        scheduleRetry();
    });

    mainWindow.loadURL(POS_URL);
}

const gotTheLock = app.requestSingleInstanceLock();
if (!gotTheLock) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) mainWindow.restore();
            mainWindow.focus();
        }
    });

    app.whenReady().then(() => {
        createWindow();

        app.on('activate', function () {
            if (BrowserWindow.getAllWindows().length === 0) createWindow();
        });
    });
}

app.on('window-all-closed', function () {
    if (process.platform !== 'darwin') app.quit();
});
