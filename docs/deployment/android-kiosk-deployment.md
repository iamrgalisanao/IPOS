# Android Kiosk Deployment Guide

This document outlines the deployment procedure for configuring Android tablets to run the IPOS Terminal as a hardened PWA kiosk.

## 1. Prerequisites
- Android 10+ Tablet (e.g., Samsung Galaxy Tab A8, Lenovo Tab M10)
- Google Chrome installed (latest version)
- Network connectivity (Wi-Fi or Cellular) to the IPOS backend
- Bluetooth or Network Receipt Printer (e.g., Star Micronics, Epson)

## 2. Initial Device Configuration

1. **System Updates**: Ensure the Android OS is fully updated.
2. **Display Settings**:
   - Set Screen Timeout to **Never** (or the maximum allowed).
   - Set Brightness to an appropriate level for the environment.
   - Disable Auto-Rotate (lock the screen in Landscape mode).
3. **Battery Optimization**:
   - Go to Settings > Apps > Chrome > Battery.
   - Set Battery usage to **Unrestricted**. This ensures the Service Worker and background syncs are not killed by the OS when the screen dims or another app is briefly opened.

## 3. PWA Installation

1. Open Google Chrome.
2. Navigate to `https://[your-ipos-domain]/pos/terminal/checkout`.
3. Log in with a cashier or admin account.
4. Tap the three-dot menu in the top right corner of Chrome.
5. Select **Add to Home screen** (or **Install app**).
6. Confirm the prompt. The IPOS icon will appear on the Android Home Screen.

## 4. Hardware Integration

For the MVP architecture, IPOS uses the `BrowserPrintAdapter` or network-based printing.

1. **Bluetooth/USB Printers**:
   - Pair the printer via Android Bluetooth settings or connect via USB OTG.
   - Install the manufacturer's Android Print Service plugin from the Google Play Store (e.g., Epson Print Enabler, Star Print Service).
   - Verify the printer prints a test page from the plugin app.
   - In IPOS, tapping "Print Receipt" will trigger the native Android print dialog via the fallback adapter.

2. **Network Printers**:
   - Ensure the printer is on the same local network as the tablet.
   - Configure the IPOS tenant/branch settings to point to the network printer IP address (if using a future backend print server architecture).

## 5. Kiosk Lockdown (App Pinning)

To prevent cashiers from exiting the POS application:

1. Go to Settings > Security (or Biometrics and security).
2. Find and enable **App Pinning** (or "Screen Pinning").
3. Launch the IPOS PWA from the Home Screen.
4. Open the Android Recents/Multitasking view.
5. Tap the IPOS app icon at the top of the card and select **Pin this app**.
6. (Optional) Set a PIN to unpin the app to prevent unauthorized exit.

## 6. Recovery and Troubleshooting

- **Offline Mode**: If Wi-Fi drops, the `pos-sw.js` Service Worker will serve the cached shell. The POS will display an amber "Offline" status. Offline checkouts will be queued.
- **Syncing Offline Sales**: Once the network is restored, the POS will automatically attempt to sync queued sales. You can also tap the sync button to retry manually.
- **App Refresh**: If the application state becomes corrupt, cashiers can swipe down (if overscroll is enabled) or use the "Refresh" button (if provided in the UI). In pinned mode, unpin the app, swipe it away from Recents, and relaunch.
