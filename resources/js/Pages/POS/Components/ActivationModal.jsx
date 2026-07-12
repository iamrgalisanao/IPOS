import React, { useState } from 'react';
import axios from 'axios';
import { catalogCache } from '@/POS/offline/catalogCache';
import {
    Key,
    ShieldAlert,
    Loader2,
    CheckCircle2,
    WifiOff
} from 'lucide-react';

export default function ActivationModal({ onActivated }) {
    const [rawCode, setRawCode] = useState('');
    const [displayCode, setDisplayCode] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errorMsg, setErrorMsg] = useState(null);
    const [successMsg, setSuccessMsg] = useState(null);

    const handleInputChange = (e) => {
        const val = e.target.value;
        // Normalize: uppercase, remove everything except alphanumeric characters
        const normalized = val.toUpperCase().replace(/[^A-Z0-9]/g, '');

        if (normalized.length > 8) return;

        setRawCode(normalized);

        // Group format: AB12-CD34
        if (normalized.length > 4) {
            setDisplayCode(`${normalized.slice(0, 4)}-${normalized.slice(4)}`);
        } else {
            setDisplayCode(normalized);
        }
    };

    const getFriendlyError = (status, serverMessage) => {
        const msg = (serverMessage || '').toLowerCase();

        if (status === 422) {
            return 'This code is invalid, expired, or already used. Generate a new code in Back Office if needed.';
        }

        if (status === 403) {
            if (msg.includes('suspended')) {
                return 'This terminal profile is suspended. Contact an administrator.';
            }
            if (msg.includes('revoked')) {
                return 'This terminal activation has been revoked. Generate a new activation code.';
            }
            if (msg.includes('already bound') || msg.includes('bound to another')) {
                return 'This terminal profile is already bound to another device. Revoke or regenerate activation first.';
            }
            return serverMessage || 'This terminal profile cannot be activated.';
        }

        return 'Failed to connect to the server. Please check your internet connection and try again.';
    };

    const submit = async (e) => {
        e.preventDefault();
        if (rawCode.length !== 8) return;

        setSubmitting(true);
        setErrorMsg(null);
        setSuccessMsg(null);

        // Retrieve or generate a stable browser-install identifier. This is a
        // binding signal; authenticated middleware remains the authorization.
        let deviceId;
        try {
            deviceId = localStorage.getItem('ipos_device_id');
            if (!deviceId) {
                if (!globalThis.crypto?.randomUUID) {
                    throw new Error('Secure browser identifier generation is unavailable.');
                }
                deviceId = `DEV-${globalThis.crypto.randomUUID()}`;
                localStorage.setItem('ipos_device_id', deviceId);
            }
        } catch {
            setErrorMsg('Secure browser storage is unavailable. Enable site storage and reload before activating.');
            setSubmitting(false);
            return;
        }

        try {
            const res = await axios.post('/api/pos/activate', {
                activation_code: rawCode,
                device_id: deviceId
            });

            if (res.data && res.data.success) {
                const data = res.data;
                const terminal = data.terminal;

                if (!terminal || !data.bootstrap_payload) {
                    throw new Error('Activation response is missing terminal configuration.');
                }

                // Persist terminal identity locally
                try {
                    localStorage.setItem('ipos_sales_machine_profile_id', terminal.sales_machine_profile_id);
                    localStorage.setItem('ipos_tenant_id', terminal.tenant_id);
                    localStorage.setItem('ipos_branch_id', terminal.branch_id);
                    localStorage.setItem('ipos_terminal_code', terminal.profile_code);
                    localStorage.setItem('ipos_activated_at', new Date().toISOString());
                } catch {
                    setErrorMsg('The terminal was activated, but browser storage failed. Ask an administrator to revoke and regenerate the activation code after enabling site storage.');
                    return;
                }

                try {
                    await catalogCache.writeBootstrapPayload(data.bootstrap_payload);
                } catch {
                    localStorage.setItem('ipos_bootstrap_refresh_required', '1');
                }

                // Set Axios global headers
                axios.defaults.headers.common['X-Tenant-ID'] = terminal.tenant_id;
                axios.defaults.headers.common['X-Branch-ID'] = terminal.branch_id;
                axios.defaults.headers.common['X-Terminal-ID'] = terminal.sales_machine_profile_id;
                axios.defaults.headers.common['X-Device-ID'] = deviceId;

                setSuccessMsg('Terminal activated successfully! Initializing POS environment...');

                if (onActivated) {
                    onActivated(data);
                }
                window.location.reload();
            } else {
                setErrorMsg('Activation failed. Server returned an invalid response.');
            }
        } catch (err) {
            console.error('Activation handshake failed', {
                status: err?.response?.status || null,
                code: err?.response?.data?.code || null,
            });
            const status = err?.response?.status;
            const msg = err?.response?.data?.message;
            setErrorMsg(getFriendlyError(status, msg));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md">
            <div className="bg-slate-900 border border-slate-800/80 rounded-[2.5rem] p-8 max-w-md w-full shadow-2xl relative overflow-hidden">
                <div className="absolute top-0 right-0 p-6 opacity-[0.03] text-indigo-500 pointer-events-none">
                    <Key size={240} />
                </div>

                <div className="flex flex-col items-center text-center">
                    <div className="p-4 bg-indigo-500/10 text-indigo-400 rounded-3xl mb-6 border border-indigo-500/20">
                        <Key size={32} />
                    </div>

                    <h2 className="text-2xl font-black text-white tracking-tight uppercase">Activate POS Terminal</h2>
                    <p className="text-slate-400 text-sm font-semibold mt-2 leading-relaxed max-w-xs">
                        Enter the 8-character activation code generated in the Back Office to bind this hardware device.
                    </p>

                    <form onSubmit={submit} className="w-full mt-8 space-y-6">
                        <div>
                            <input
                                type="text"
                                value={displayCode}
                                onChange={handleInputChange}
                                placeholder="AB12-CD34"
                                disabled={submitting || successMsg}
                                className="w-full bg-slate-950 border border-slate-800 rounded-2xl px-6 py-4 text-center font-mono text-2xl font-black tracking-widest text-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 uppercase placeholder-slate-700 focus:outline-none transition-colors"
                            />
                        </div>

                        {errorMsg && (
                            <div className="flex gap-2 p-4 bg-rose-500/10 border border-rose-500/20 text-rose-300 rounded-2xl text-left">
                                <ShieldAlert className="shrink-0 mt-0.5" size={16} />
                                <span className="text-xs font-semibold leading-normal">{errorMsg}</span>
                            </div>
                        )}

                        {successMsg && (
                            <div className="flex gap-2 p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 rounded-2xl text-left">
                                <CheckCircle2 className="shrink-0 mt-0.5 animate-bounce" size={16} />
                                <span className="text-xs font-semibold leading-normal">{successMsg}</span>
                            </div>
                        )}

                        <button
                            type="submit"
                            disabled={rawCode.length !== 8 || submitting || successMsg}
                            className="w-full inline-flex h-14 items-center justify-center gap-2 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-black text-sm uppercase tracking-widest transition-colors shadow-lg shadow-indigo-600/25 disabled:opacity-50 disabled:shadow-none"
                        >
                            {submitting ? (
                                <>
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    Activating...
                                </>
                            ) : (
                                'Activate Terminal'
                            )}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
