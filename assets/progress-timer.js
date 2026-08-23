// Shared "still working" indicator for slow Bright Data calls. Bright Data
// doesn't expose a real completion percentage (only collecting/running/ready),
// so this shows elapsed seconds instead of a fake percent — honest feedback
// that the scraper is actually working, not stuck.
function startElapsedTimer(el, label) {
    const start = Date.now();
    const tick = () => {
        const secs = Math.round((Date.now() - start) / 1000);
        el.textContent = `${label}… ${secs}s`;
    };
    tick();
    return setInterval(tick, 1000);
}

function stopElapsedTimer(handle) {
    clearInterval(handle);
}
