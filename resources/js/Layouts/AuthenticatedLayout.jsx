import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { 
    LayoutDashboard, 
    Clock, 
    Receipt, 
    Layers, 
    Calculator, 
    PieChart, 
    Menu, 
    X, 
    LogOut, 
    User as UserIcon,
    Settings 
} from 'lucide-react';

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // Navigation links mapping to all our built modules
    const navigation = [
        { name: 'Dashboard', href: route('dashboard'), icon: LayoutDashboard, active: route().current('dashboard') },
        { name: 'Shift Operations', href: route('shifts.index'), icon: Clock, active: route().current('shifts.*') },
        { name: 'Sales History', href: route('sales.history.index'), icon: Receipt, active: route().current('sales.history.*') },
        { name: 'Settlement', href: route('settlement.periods.index'), icon: Layers, active: route().current('settlement.*') },
        { name: 'Accounting', href: route('accounting.outbox.index'), icon: Calculator, active: route().current('accounting.*') },
        { name: 'Tax Reports', href: route('reports.tax.index'), icon: PieChart, active: route().current('reports.*') },
    ];

    return (
        <div className="min-h-screen bg-gray-50 flex">
            {/* Sidebar Overlay for mobile */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 z-40 bg-gray-900/50 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar */}
            <div className={`fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-white transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static lg:block ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex items-center justify-between h-16 px-4 bg-slate-950 border-b border-slate-800">
                    <Link href={route('dashboard')} className="flex items-center gap-3">
                        <ApplicationLogo className="block h-8 w-auto fill-current text-indigo-500" />
                        <span className="text-xl font-bold tracking-tight text-white">IPOS Admin</span>
                    </Link>
                    <button onClick={() => setSidebarOpen(false)} className="lg:hidden text-slate-400 hover:text-white">
                        <X size={20} />
                    </button>
                </div>

                <div className="flex flex-col flex-grow h-[calc(100vh-4rem)]">
                    <nav className="flex-1 px-3 py-6 space-y-1 overflow-y-auto">
                        <div className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4 px-3">
                            Modules
                        </div>
                        {navigation.map((item) => (
                            <Link
                                key={item.name}
                                href={item.href}
                                className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                                    item.active 
                                        ? 'bg-indigo-600 text-white' 
                                        : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                }`}
                            >
                                <item.icon size={18} className={item.active ? 'text-white' : 'text-slate-400'} />
                                {item.name}
                            </Link>
                        ))}
                    </nav>

                    <div className="p-4 bg-slate-950 border-t border-slate-800">
                        <div className="flex items-center gap-3 mb-4">
                            <div className="w-8 h-8 rounded-full bg-indigo-500 flex items-center justify-center text-sm font-bold text-white uppercase">
                                {user.name.charAt(0)}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-white truncate">{user.name}</p>
                                <p className="text-xs text-slate-400 truncate">{user.email}</p>
                            </div>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                            <Link href={route('profile.edit')} className="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors flex-1 flex justify-center" title="Profile Settings">
                                <Settings size={18} />
                            </Link>
                            <Link href={route('logout')} method="post" as="button" className="p-2 text-slate-400 hover:text-rose-400 hover:bg-slate-800 rounded-lg transition-colors flex-1 flex justify-center" title="Log Out">
                                <LogOut size={18} />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            {/* Main Content */}
            <div className="flex-1 flex flex-col min-w-0 max-h-screen overflow-hidden">
                {/* Top Header */}
                <header className="bg-white border-b border-gray-200 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8 shrink-0">
                    <div className="flex items-center">
                        <button 
                            onClick={() => setSidebarOpen(true)}
                            className="mr-4 lg:hidden text-gray-500 hover:text-gray-700"
                        >
                            <Menu size={24} />
                        </button>
                        
                        {/* Page Header (Breadcrumbs/Title) */}
                        {header && (
                            <div className="flex-1 text-gray-800">
                                {header}
                            </div>
                        )}
                    </div>
                    
                    <div className="flex items-center gap-4">
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button className="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors">
                                    <span>{user.name}</span>
                                    <UserIcon size={16} className="text-gray-400" />
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                <Dropdown.Link href={route('logout')} method="post" as="button" className="text-rose-600 hover:text-rose-700">Log Out</Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                {/* Page Content */}
                <main className="flex-1 overflow-y-auto bg-gray-50 relative">
                    {children}
                </main>
            </div>
        </div>
    );
}
