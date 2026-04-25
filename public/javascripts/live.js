// Tiny polling helper for live updates on the WEB tier.
//
// Usage:
//   Live.poll('/api/network/stats', 4000, (data) => { ... patch DOM ... });
//
// The helper does not patch DOM itself (call sites know what to update).
// It just runs an interval, fetches JSON, and forwards to the callback.
// On fetch error: keeps polling and logs once per consecutive failure
// transition.

(function () {
  const Live = {
    /**
     * Poll a URL on a fixed interval.
     * @param {string} url
     * @param {number} intervalMs
     * @param {(data: any) => void} onData
     * @param {{ onError?: (err: Error) => void }} [options]
     * @returns {() => void} stop function
     */
    poll(url, intervalMs, onData, options = {}) {
      let stopped = false;
      let lastFailed = false;
      const tick = async () => {
        if (stopped) return;
        try {
          const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const data = await res.json();
          if (lastFailed) {
            lastFailed = false;
            console.info('[live] resumed:', url);
          }
          onData(data);
        } catch (err) {
          if (!lastFailed) {
            lastFailed = true;
            console.warn('[live] poll failed:', url, err.message);
            (options.onError || (() => {}))(err);
          }
        }
      };
      tick();
      const handle = setInterval(tick, intervalMs);
      return () => { stopped = true; clearInterval(handle); };
    },

    /**
     * Format a number with thousand separators. Used by tile updaters.
     */
    formatNumber(value, decimals = 0) {
      if (value === null || value === undefined || value === '') return '-';
      const n = Number(value);
      if (!Number.isFinite(n)) return '-';
      return n.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
    },

    formatMoney(value) {
      if (value === null || value === undefined || value === '') return '-';
      const n = Number(value);
      if (!Number.isFinite(n)) return '-';
      return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    },
  };

  window.Live = Live;
})();
