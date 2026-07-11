import React, { useState } from 'react';
import { Lock, Unlock, Eye, EyeOff, UserMinus, AlertCircle, RefreshCw, Clock, CheckCircle } from 'lucide-react';
import { router } from '@inertiajs/react';
import axios from 'axios';

export default function TerminalLockScreen({ cashierName, onUnlock }) {
    const [activeTab, setActiveTab] = useState('unlock'); // 'unlock' or 'clock'
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [pin, setPin] = useState('');
    const [error, setError] = useState(null);
    const [successMessage, setSuccessMessage] = useState(null);
    const [loading, setLoading] = useState(false);
    const [shake, setShake] = useState(false);

    const handleUnlock = async (e) => {
        e.preventDefault();
        if (!password) return;

        setLoading(true);
        setError(null);
        setSuccessMessage(null);

        try {
            const response = await axios.post(route('pos.unlock'), { password });
            if (response.data.success) {
                onUnlock(response.data);
            }
        } catch (err) {
            triggerShake();
            setError(err.response?.data?.message || 'Invalid password.');
        } finally {
            setLoading(false);
        }
    };

    const handleClockToggle = async (e) => {
        e.preventDefault();
        if (!pin) return;

        setLoading(true);
        setError(null);
        setSuccessMessage(null);

        try {
            const response = await axios.post(route('pos.timecard.toggle'), { pin }, {
                headers: {
                    'X-Device-ID': getDeviceId(),
                },
            });
            if (response.data.success) {
                setSuccessMessage(response.data.message);
                setPin('');
                // Clear success message after 3 seconds
                setTimeout(() => setSuccessMessage(null), 3000);
            }
        } catch (err) {
            triggerShake();
            setError(err.response?.data?.message || 'Invalid PIN or rate limited.');
        } finally {
            setLoading(false);
        }
    };

    const triggerShake = () => {
        setShake(true);
        setTimeout(() => setShake(false), 500);
    };

    const getDeviceId = () => {
        const key = 'ipos_device_id';
        let deviceId = localStorage.getItem(key);

        if (!deviceId) {
            deviceId = 'DEV-' + Math.random().toString(36).substring(2, 15);
            localStorage.setItem(key, deviceId);
        }

        return deviceId;
    };

    const handleKeypadPress = (val) => {
        if (loading) return;
        setError(null);
        setSuccessMessage(null);
        if (val === 'clear') {
            setPin('');
        } else if (val === 'backspace') {
            setPin(prev => prev.slice(0, -1));
        } else {
            if (pin.length < 8) {
                setPin(prev => prev + val);
            }
        }
    };

    const handleSwitchUser = () => {
        router.post(route('logout'));
    };

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/80 backdrop-blur-2xl transition-all duration-500 overflow-hidden">
            {/* Ambient Background Glows */}
            <div className="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full bg-indigo-500/10 blur-[120px] pointer-events-none" />
            <div className="absolute bottom-[-10%] right-[-10%] w-[50%] h-[50%] rounded-full bg-violet-500/10 blur-[120px] pointer-events-none" />

            {/* Glowing Centerpiece Container */}
            <div className={`w-[min(90vw,26rem)] p-8 bg-slate-900/60 border border-slate-800 rounded-3xl backdrop-blur-xl shadow-2xl relative transition-all duration-300 ${shake ? 'animate-bounce' : ''} border-indigo-500/20`}>

                {/* Header Section */}
                <div className="flex flex-col items-center mb-6">
                    <div className="relative group mb-4">
                        <div className="absolute inset-0 bg-indigo-500/20 rounded-full blur-md group-hover:blur-lg transition-all animate-pulse" />
                        <div className="w-16 h-16 rounded-full bg-slate-950 border border-indigo-500/30 flex items-center justify-center relative z-10">
                            {loading ? (
                                <RefreshCw className="w-6 h-6 text-indigo-400 animate-spin" />
                            ) : activeTab === 'unlock' ? (
                                <Lock className="w-6 h-6 text-indigo-400" />
                            ) : (
                                <Clock className="w-6 h-6 text-indigo-400" />
                            )}
                        </div>
                    </div>

                    <span className="text-[10px] font-black uppercase tracking-[0.25em] text-indigo-400 mb-1">
                        Terminal Secure
                    </span>
                    <h2 className="text-xl font-bold text-slate-200 text-center tracking-tight">
                        {cashierName}
                    </h2>
                </div>

                {/* Tab Selector */}
                <div className="flex bg-slate-950/60 p-1 rounded-xl border border-slate-800 mb-6">
                    <button
                        type="button"
                        onClick={() => { setActiveTab('unlock'); setError(null); setSuccessMessage(null); }}
                        className={`flex-1 py-2 text-xs font-bold uppercase tracking-wider rounded-lg transition-all ${activeTab === 'unlock' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200'}`}
                    >
                        Unlock Terminal
                    </button>
                    <button
                        type="button"
                        onClick={() => { setActiveTab('clock'); setError(null); setSuccessMessage(null); }}
                        className={`flex-1 py-2 text-xs font-bold uppercase tracking-wider rounded-lg transition-all ${activeTab === 'clock' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200'}`}
                    >
                        Timecard Clock
                    </button>
                </div>

                {/* Notifications */}
                {error && (
                    <div className="mb-6 bg-rose-500/10 border border-rose-500/20 text-rose-400 px-4 py-3 rounded-xl flex items-center gap-3 text-xs font-semibold animate-in fade-in slide-in-from-top-2">
                        <AlertCircle className="w-4 h-4 shrink-0" />
                        <span>{error}</span>
                    </div>
                )}

                {successMessage && (
                    <div className="mb-6 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 px-4 py-3 rounded-xl flex items-center gap-3 text-xs font-semibold animate-in fade-in slide-in-from-top-2">
                        <CheckCircle className="w-4 h-4 shrink-0" />
                        <span>{successMessage}</span>
                    </div>
                )}

                {/* TAB 1: Unlock Terminal */}
                {activeTab === 'unlock' && (
                    <form onSubmit={handleUnlock} className="space-y-6">
                        <div className="space-y-2">
                            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                Security Credentials
                            </label>
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    placeholder="Enter cashier password..."
                                    disabled={loading}
                                    className="w-full bg-slate-950 border border-slate-800 focus:border-indigo-500 rounded-xl px-4 py-3.5 text-slate-200 text-sm font-medium tracking-wide placeholder-slate-600 transition-all focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-50 font-mono"
                                    autoFocus
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
                                >
                                    {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
                        </div>

                        <div className="flex flex-col gap-3">
                            <button
                                type="submit"
                                disabled={loading || !password}
                                className="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 disabled:bg-indigo-900/50 disabled:text-indigo-400 text-white rounded-xl py-3.5 text-xs font-bold uppercase tracking-widest transition-all active:scale-[0.98] shadow-lg shadow-indigo-600/10 hover:shadow-indigo-600/20"
                            >
                                <Unlock size={14} />
                                Unlock Console
                            </button>

                            <button
                                type="button"
                                onClick={handleSwitchUser}
                                disabled={loading}
                                className="w-full flex items-center justify-center gap-2 border border-slate-800 hover:bg-slate-800 text-slate-400 hover:text-slate-200 rounded-xl py-3.5 text-xs font-bold uppercase tracking-widest transition-all active:scale-[0.98]"
                            >
                                <UserMinus size={14} />
                                Switch User / Exit
                            </button>
                        </div>
                    </form>
                )}

                {/* TAB 2: Timecard Clock In/Out */}
                {activeTab === 'clock' && (
                    <form onSubmit={handleClockToggle} className="space-y-6">
                        <div className="space-y-2">
                            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block text-center">
                                Enter Employee PIN
                            </label>

                            {/* PIN Display Dots */}
                            <div className="flex justify-center gap-3 py-3">
                                {[...Array(4)].map((_, i) => (
                                    <div
                                        key={i}
                                        className={`w-3.5 h-3.5 rounded-full border transition-all duration-150 ${
                                            pin.length > i
                                                ? 'bg-indigo-400 border-indigo-400 scale-110 shadow-lg shadow-indigo-500/50'
                                                : 'bg-slate-950 border-slate-800'
                                        }`}
                                    />
                                ))}
                            </div>
                        </div>

                        {/* Numeric Keypad Grid */}
                        <div className="grid grid-cols-3 gap-3 max-w-[280px] mx-auto">
                            {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((num) => (
                                <button
                                    key={num}
                                    type="button"
                                    onClick={() => handleKeypadPress(num.toString())}
                                    className="aspect-square flex items-center justify-center bg-slate-950/80 hover:bg-slate-850 active:bg-slate-800 border border-slate-850 hover:border-slate-700 text-slate-200 text-lg font-bold rounded-2xl transition-all shadow-md active:scale-95"
                                >
                                    {num}
                                </button>
                            ))}
                            <button
                                type="button"
                                onClick={() => handleKeypadPress('clear')}
                                className="aspect-square flex items-center justify-center bg-slate-950/40 hover:bg-slate-900 text-slate-500 text-xs font-bold rounded-2xl transition-all active:scale-95 border border-slate-850"
                            >
                                Clear
                            </button>
                            <button
                                type="button"
                                onClick={() => handleKeypadPress('0')}
                                className="aspect-square flex items-center justify-center bg-slate-950/80 hover:bg-slate-850 active:bg-slate-800 border border-slate-850 hover:border-slate-700 text-slate-200 text-lg font-bold rounded-2xl transition-all shadow-md active:scale-95"
                            >
                                0
                            </button>
                            <button
                                type="button"
                                onClick={() => handleKeypadPress('backspace')}
                                className="aspect-square flex items-center justify-center bg-slate-950/40 hover:bg-slate-900 text-slate-500 text-xs font-bold rounded-2xl transition-all active:scale-95 border border-slate-850"
                            >
                                Back
                            </button>
                        </div>

                        <div className="flex flex-col gap-3">
                            <button
                                type="submit"
                                disabled={loading || pin.length < 4}
                                className="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 disabled:bg-indigo-900/50 disabled:text-indigo-400 text-white rounded-xl py-3.5 text-xs font-bold uppercase tracking-widest transition-all active:scale-[0.98] shadow-lg shadow-indigo-600/10 hover:shadow-indigo-600/20"
                            >
                                Submit Clock Toggle
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
