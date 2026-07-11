import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

if (window.IPOS_CONTEXT) {
    if (window.IPOS_CONTEXT.tenantId) {
        window.axios.defaults.headers.common['X-Tenant-ID'] = window.IPOS_CONTEXT.tenantId;
    }
    if (window.IPOS_CONTEXT.branchId) {
        window.axios.defaults.headers.common['X-Branch-ID'] = window.IPOS_CONTEXT.branchId;
    }
    if (window.IPOS_CONTEXT.terminalId) {
        window.axios.defaults.headers.common['X-Terminal-ID'] = window.IPOS_CONTEXT.terminalId;
    }
}
