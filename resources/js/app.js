import './bootstrap';

const connectivityBanner = document.querySelector('[data-connectivity-banner]');

function updateConnectivity() {
    if (!connectivityBanner) return;

    connectivityBanner.hidden = navigator.onLine;
}

window.addEventListener('online', updateConnectivity);
window.addEventListener('offline', updateConnectivity);
updateConnectivity();
