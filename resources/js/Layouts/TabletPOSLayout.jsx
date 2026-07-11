import React from 'react';
import { Clock3, History, Settings, Wifi, WifiOff, RefreshCw, LayoutGrid } from 'lucide-react';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import { Head, Link } from '@inertiajs/react';

export default function TabletPOSLayout({ children }) {
    const { isOnline, status, lastSyncedAt } = useConnectivityStore();
    const [isRefreshProtected, setIsRefreshProtected] = React.useState(
        typeof window !== 'undefined' ? Boolean(window.IPOS_SERVICE_WORKER_READY) : false
    );
    const navItems = [
        { label: 'Checkout', routeName: 'pos.terminal.checkout', icon: LayoutGrid },
        { label: 'Shift', routeName: 'pos.terminal.shift', icon: Clock3 },
        { label: 'Sync', routeName: 'pos.terminal.sync-status', icon: History },
        { label: 'Settings', routeName: 'pos.terminal.settings', icon: Settings },
    ];

    const isCurrentRoute = (routeName) => {
        try {
            return route().current(routeName);
        } catch (err) {
            return false;
        }
    };

    // Prevent accidental pull-to-refresh and back gestures on Android/iOS
    React.useEffect(() => {
        const preventOverscroll = (e) => {
            if (e.touches && e.touches.length > 1) return;
        };
        document.body.style.overscrollBehavior = 'none';
        window.addEventListener('touchmove', preventOverscroll, { passive: false });
        return () => {
            document.body.style.overscrollBehavior = 'auto';
            window.removeEventListener('touchmove', preventOverscroll);
        };
    }, []);

    React.useEffect(() => {
        if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
            setIsRefreshProtected(false);
            return;
        }

        const refreshProtectionState = async () => {
            const controlled = Boolean(navigator.serviceWorker.controller);
            setIsRefreshProtected(controlled);

            if (!controlled) {
                return;
            }

            try {
                const response = await fetch('/__ipos-sw-health', { cache: 'no-store' });
                setIsRefreshProtected(response.ok);
            } catch (err) {
                setIsRefreshProtected(false);
            }
        };

        refreshProtectionState();

        const handleControllerChange = () => {
            window.IPOS_SERVICE_WORKER_READY = true;
            refreshProtectionState();
        };

        navigator.serviceWorker.addEventListener('controllerchange', handleControllerChange);

        const interval = window.setInterval(refreshProtectionState, 5000);

        return () => {
            navigator.serviceWorker.removeEventListener('controllerchange', handleControllerChange);
            window.clearInterval(interval);
        };
    }, []);

    return (
        <div className="tablet-pos-shell h-screen w-screen overflow-hidden bg-slate-950 text-slate-100 flex flex-col select-none touch-pan-y">
            <Head>
                <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
                <meta name="theme-color" content="#020617" />
            </Head>

            {/* Tablet Header / Status Bar */}
            <div className="h-14 bg-slate-900 border-b border-slate-800 flex items-center justify-between px-4 shrink-0 shadow-sm z-50">
                <div className="flex items-center gap-6">
                    <Link href={route('pos.terminal.checkout')} className="flex items-center gap-3 active:scale-95 transition-transform">
                        <div className="bg-blue-600 rounded-lg p-1.5 shadow-lg shadow-blue-500/20">
                            <LayoutGrid className="w-5 h-5 text-white" />
                        </div>
                        <span className="font-bold tracking-widest text-slate-100 text-lg">
                            IPOS <span className="text-blue-500 text-sm align-top">TERMINAL</span>
                        </span>
                    </Link>
                    
                    <nav className="hidden md:flex items-center gap-1 ml-4 border-l border-slate-800 pl-6">
                        {navItems.map((item) => {
                            const Icon = item.icon;
                            const active = isCurrentRoute(item.routeName);

                            return (
                                <Link
                                    key={item.routeName}
                                    href={route(item.routeName)}
                                    className={`inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                                        active
                                            ? 'bg-indigo-500/15 text-indigo-200'
                                            : 'hover:bg-slate-800 text-slate-300 hover:text-white'
                                    }`}
                                >
                                    <Icon className="h-4 w-4" />
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                </div>

                <div className="flex items-center gap-4 text-sm font-medium">
                    {/* Sync Status */}
                    <div className="flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-800/50">
                        <RefreshCw className="w-4 h-4 text-slate-400" />
                        <span className="text-slate-400">
                            {lastSyncedAt ? new Date(lastSyncedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Pending'}
                        </span>
                    </div>
                    {/* Network Status */}
                    <div className={`hidden lg:flex items-center gap-2 px-3 py-1.5 rounded-full ${isRefreshProtected ? 'bg-blue-500/10 text-blue-300' : 'bg-amber-500/10 text-amber-300'}`}>
                        <span className="h-2 w-2 rounded-full bg-current" />
                        <span>{isRefreshProtected ? 'Refresh Protected' : 'Refresh Not Protected'}</span>
                    </div>
                    <div className={`flex items-center gap-2 px-3 py-1.5 rounded-full ${isOnline ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400'}`}>
                        {isOnline ? <Wifi className="w-4 h-4" /> : <WifiOff className="w-4 h-4" />}
                        <span>{status === 'online' ? 'Online' : 'Offline'}</span>
                    </div>
                </div>
            </div>
            
            {/* Main Content Area */}
            <div className="flex-1 relative overflow-hidden bg-slate-950">
                {children}
            </div>
        </div>
    );
}
