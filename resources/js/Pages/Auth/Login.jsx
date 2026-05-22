import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail, Lock, ArrowRight, ShieldCheck, AlertCircle, Bot, Sparkles } from 'lucide-react';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import ApplicationLogo from '@/Components/ApplicationLogo';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [isFocusedEmail, setIsFocusedEmail] = useState(false);
    const [isFocusedPassword, setIsFocusedPassword] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="min-h-screen bg-[#070a13] text-slate-100 flex flex-col justify-center items-center p-4 relative overflow-hidden font-sans selection:bg-cyan-500/30 selection:text-cyan-300">
            <Head title="Log In - ABBADEV POS" />

            {/* Ambient Background Glowing Orbs (Business Card Color Palette) */}
            <div className="absolute top-[-20%] left-[-10%] w-[60vw] h-[60vw] rounded-full bg-indigo-950/20 blur-[120px] pointer-events-none" />
            <div className="absolute bottom-[-10%] right-[-10%] w-[50vw] h-[50vw] rounded-full bg-cyan-950/30 blur-[130px] pointer-events-none animate-pulse duration-10000" />
            <div className="absolute top-[40%] right-[10%] w-[30vw] h-[30vw] rounded-full bg-blue-950/15 blur-[100px] pointer-events-none" />

            {/* Subtle Tech Cyber Grid Overlay */}
            <div className="absolute inset-0 bg-[linear-gradient(to_right,#1e293b08_1px,transparent_1px),linear-gradient(to_bottom,#1e293b08_1px,transparent_1px)] bg-[size:24px_24px] pointer-events-none" />

            {/* Glassmorphic Brand Container */}
            <div className="w-full max-w-[450px] z-10 flex flex-col items-center">
                
                {/* 3D Chrome Metallic Circular Logo representing ABBADEV */}
                <div className="mb-8 relative group">
                    {/* Glowing Logo Backdrop Shadow */}
                    <div className="absolute -inset-1.5 bg-gradient-to-r from-cyan-500 to-blue-600 rounded-full blur opacity-40 group-hover:opacity-75 transition duration-1000 group-hover:duration-200" />
                    
                    {/* Metallic Outer Ring */}
                    <div className="relative w-28 h-28 rounded-full bg-gradient-to-b from-slate-200 via-slate-400 to-slate-600 p-[3px] shadow-[0_10px_25px_-5px_rgba(0,0,0,0.7)]">
                        {/* Chrome Highlights Inner Ring */}
                        <div className="w-full h-full rounded-full bg-gradient-to-t from-slate-700 via-slate-900 to-slate-950 p-[1.5px] flex items-center justify-center relative overflow-hidden">
                            
                            {/* Shiny Metallic Chrome Sweep Reflection */}
                            <div className="absolute top-0 -left-[100%] w-[50%] h-[200%] bg-gradient-to-r from-transparent via-white/10 to-transparent rotate-[35deg] group-hover:animate-shine pointer-events-none" />
                            
                            {/* Inner Circle Glow */}
                            <div className="absolute inset-[3px] rounded-full bg-[#0a0d16]/90 border border-slate-800/80 flex items-center justify-center p-3">
                                {/* Vector Network/Tree SVG Logo (ABBADEV Identity) */}
                                <ApplicationLogo className="w-full h-full drop-shadow-[0_0_12px_rgba(6,182,212,0.8)] text-cyan-400" />
                            </div>
                        </div>
                    </div>

                    {/* Cute Robot Floating Badge (Honoring Business Card Mascot) */}
                    <div className="absolute -bottom-1 -right-2 bg-gradient-to-r from-slate-200 via-slate-400 to-slate-600 p-[1.5px] rounded-xl shadow-lg animate-bounce duration-5000">
                        <div className="bg-[#0b0f19] px-2 py-1 rounded-[10px] flex items-center gap-1 border border-slate-800">
                            <Bot className="w-3.5 h-3.5 text-cyan-400 drop-shadow-[0_0_4px_rgba(34,211,238,0.5)]" />
                            <span className="text-[9px] font-black tracking-widest text-slate-300 font-mono">AI</span>
                        </div>
                    </div>
                </div>

                {/* Brand Text */}
                <div className="text-center mb-6">
                    <h2 className="text-2xl font-black tracking-[0.25em] text-white uppercase font-sans select-none">
                        ABBADEV
                    </h2>
                    <p className="text-[10px] font-black uppercase tracking-[0.4em] text-cyan-400 drop-shadow-[0_0_8px_rgba(6,182,212,0.3)] mt-1">
                        AI & SOFTWARE
                    </p>
                </div>

                {/* Premium Hardened Card Representation */}
                <div className="w-full bg-[#0b0f19]/70 border border-slate-800/80 rounded-2xl p-8 shadow-[0_20px_50px_rgba(0,0,0,0.5)] backdrop-blur-xl relative overflow-hidden group/card hover:border-slate-700/60 transition-all duration-300">
                    
                    {/* Metallic Silver Header Border */}
                    <div className="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-slate-500 via-slate-300 to-slate-600" />
                    
                    {/* Shiny Sweep Overlay */}
                    <div className="absolute inset-0 bg-gradient-to-tr from-transparent via-cyan-500/[0.015] to-transparent pointer-events-none" />

                    {/* Beautiful Angled Blue Accent Highlight (Strict Parity with Business Card bottom style) */}
                    <div className="absolute bottom-0 right-0 w-36 h-36 bg-gradient-to-tl from-cyan-500/10 via-blue-600/5 to-transparent rounded-bl-full pointer-events-none" />
                    
                    {/* High-Fidelity Accent Stripe at the absolute bottom corner */}
                    <div className="absolute bottom-0 right-0 w-24 h-[6px] bg-gradient-to-r from-cyan-500 via-blue-500 to-[#6FB1FC] rounded-tl-full shadow-[0_0_15px_rgba(6,182,212,0.5)]" />
                    
                    {/* Left corner chrome-blue accent */}
                    <div className="absolute bottom-0 left-0 w-12 h-[3px] bg-gradient-to-r from-slate-400 to-cyan-500 rounded-tr-full" />

                    {/* Banner Alerts */}
                    {status && (
                        <div className="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm font-medium rounded-xl flex items-center gap-3 backdrop-blur-md animate-in fade-in slide-in-from-top-2">
                            <ShieldCheck className="w-5 h-5 flex-shrink-0" />
                            <span>{status}</span>
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-5">
                        {/* Email Address */}
                        <div className="space-y-1.5">
                            <label htmlFor="email" className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                <Mail className="w-3.5 h-3.5 text-cyan-500" />
                                Email Address
                            </label>
                            
                            <div className="relative">
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    autoComplete="username"
                                    required
                                    onFocus={() => setIsFocusedEmail(true)}
                                    onBlur={() => setIsFocusedEmail(false)}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className={`w-full px-4 py-3 bg-[#070a13]/90 border text-slate-200 placeholder-slate-600 rounded-xl text-sm font-medium outline-none transition-all duration-200 ${
                                        isFocusedEmail 
                                        ? 'border-cyan-500/80 shadow-[0_0_12px_rgba(6,182,212,0.15)] bg-slate-950' 
                                        : 'border-slate-800 hover:border-slate-700/80'
                                    }`}
                                    placeholder="Enter your email"
                                />
                            </div>
                            <InputError message={errors.email} className="mt-1 text-xs text-rose-500" />
                        </div>

                        {/* Password */}
                        <div className="space-y-1.5">
                            <div className="flex justify-between items-center">
                                <label htmlFor="password" className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                    <Lock className="w-3.5 h-3.5 text-cyan-500" />
                                    Password
                                </label>
                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-[11px] font-bold text-cyan-400/80 hover:text-cyan-300 hover:underline transition-colors focus:outline-none"
                                    >
                                        Forgot Password?
                                    </Link>
                                )}
                            </div>
                            
                            <div className="relative">
                                <input
                                    id="password"
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    autoComplete="current-password"
                                    required
                                    onFocus={() => setIsFocusedPassword(true)}
                                    onBlur={() => setIsFocusedPassword(false)}
                                    onChange={(e) => setData('password', e.target.value)}
                                    className={`w-full px-4 py-3 bg-[#070a13]/90 border text-slate-200 placeholder-slate-600 rounded-xl text-sm font-medium outline-none transition-all duration-200 ${
                                        isFocusedPassword 
                                        ? 'border-cyan-500/80 shadow-[0_0_12px_rgba(6,182,212,0.15)] bg-slate-950' 
                                        : 'border-slate-800 hover:border-slate-700/80'
                                    }`}
                                    placeholder="••••••••"
                                />
                            </div>
                            <InputError message={errors.password} className="mt-1 text-xs text-rose-500" />
                        </div>

                        {/* Remember Me */}
                        <div className="flex items-center">
                            <label className="flex items-center cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                    className="sr-only peer"
                                />
                                <div className="w-4 h-4 bg-[#070a13] border border-slate-800 rounded flex items-center justify-center peer-checked:border-cyan-500 peer-checked:bg-cyan-600/20 text-cyan-400 transition-all">
                                    {data.remember && (
                                        <svg className="w-3 h-3 fill-current" viewBox="0 0 20 20">
                                            <path d="M0 11l2-2 5 5L18 3l2 2L7 18z" />
                                        </svg>
                                    )}
                                </div>
                                <span className="ms-2 text-xs font-bold text-slate-400 tracking-wide uppercase">
                                    Remember Session
                                </span>
                            </label>
                        </div>

                        {/* Submit Button */}
                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full relative group/btn bg-gradient-to-r from-cyan-600 via-blue-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs uppercase tracking-[0.2em] py-3.5 px-4 rounded-xl shadow-lg shadow-cyan-500/20 active:scale-[0.98] transition-all duration-300 disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center gap-2 overflow-hidden border border-cyan-400/20"
                        >
                            {/* Shiny glint effect on hover */}
                            <div className="absolute top-0 -left-[100%] w-[50%] h-[200%] bg-gradient-to-r from-transparent via-white/20 to-transparent rotate-[35deg] group-hover/btn:animate-shine pointer-events-none" />
                            
                            <span>Authenticate</span>
                            <ArrowRight className="w-4 h-4 transition-transform group-hover/btn:translate-x-1" />
                        </button>
                    </form>
                </div>

                {/* Footer Info */}
                <div className="mt-8 text-center text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2">
                    <span>IPOS SECURE ENGINE</span>
                    <span className="w-1.5 h-1.5 rounded-full bg-cyan-500/50 animate-ping" />
                    <span>v2.6.4</span>
                </div>
            </div>
        </div>
    );
}
