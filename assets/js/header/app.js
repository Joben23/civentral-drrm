// Header Module Bootstrap
window.loadCiventralScript('assets/js/header/clock.js');
window.loadCiventralScript('assets/js/header/theme.js');
window.loadCiventralScript('assets/js/header/dropdown.js');
window.loadCiventralScript('assets/js/header/logout-modal.js');
window.loadCiventralScript('assets/js/header/inactivity.js');
window.loadCiventralScript('assets/js/header/sidebar.js', () => {
    // Initialization executes after all header scripts have loaded
    updateHeaderClock();
    syncThemeToggleIcon();
    setInterval(updateHeaderClock, 10000);

    const activityEvents = ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'];
    activityEvents.forEach(event => {
        document.addEventListener(event, handleAdminActivity, { passive: true });
    });

    // Make sure countdownInterval is accessible (declared in inactivity.js)
    if (typeof updateCountdownDisplay === 'function') {
        countdownInterval = setInterval(updateCountdownDisplay, 1000);
        updateCountdownDisplay();
    }
});
