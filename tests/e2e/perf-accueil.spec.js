// Mesure de chargement de l'accueil de la borne (Cr 1.e.8), hors suite E2E ordinaire :
// ne tourne qu'avec PERF=1, car un temps d'affichage depend de la machine.
//
//   PERF=1 PERF_PAGES=/index.html PERF_RUNS=5 npx playwright test perf-accueil.spec.js
//
// Chromium, cache desactive, debit reduit par le protocole DevTools : 1,6 Mbit/s
// descendant, 750 kbit/s montant, 150 ms de latence. Pour chaque page, mediane sur
// PERF_RUNS chargements de :
//   - la fin de reception de la banniere (Resource Timing, responseEnd) ;
//   - la fin du chargement de la page (Navigation Timing, loadEventEnd) ;
//   - le Largest Contentful Paint (plus grand element affiche, PerformanceObserver) ;
//   - les octets recus pour la banniere (encodedBodySize).
const { test } = require('@playwright/test');

const PAGES = (process.env.PERF_PAGES || '/index.html').split(',');
const RUNS = Number(process.env.PERF_RUNS || 5);

const median = (values) => {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.floor(sorted.length / 2)];
};

test.skip(!process.env.PERF, 'mesure de performance : lancer avec PERF=1');
test.setTimeout(10 * 60 * 1000);

for (const url of PAGES) {
  test(`chargement de ${url}`, async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false,
      latency: 150,
      downloadThroughput: (1.6 * 1000 * 1000) / 8,
      uploadThroughput: (750 * 1000) / 8,
    });

    const lcp = [];
    const bytes = [];
    const bannerEnd = [];
    const loadEnd = [];
    for (let run = 0; run < RUNS; run++) {
      await page.goto(url, { waitUntil: 'load' });
      const sample = await page.evaluate(() => new Promise((resolve) => {
        new PerformanceObserver((list) => {
          const entries = list.getEntries();
          const last = entries[entries.length - 1];
          const banners = performance.getEntriesByType('resource').filter((r) => r.name.includes('mc-landing-banner'));
          const banner = banners[0];
          const navigation = performance.getEntriesByType('navigation')[0];
          resolve({
            lcp: last.startTime,
            bannerEnd: banner ? banner.responseEnd : -1,
            loadEnd: navigation ? navigation.loadEventEnd : -1,
            element: last.element ? last.element.className : '',
            bytes: banner ? banner.encodedBodySize : -1,
            file: banners.map((r) => r.name.split('/').pop()).join(' + '),
          });
        }).observe({ type: 'largest-contentful-paint', buffered: true });
      }));
      lcp.push(sample.lcp);
      bytes.push(sample.bytes);
      bannerEnd.push(sample.bannerEnd);
      loadEnd.push(sample.loadEnd);
      console.log(`PERF ${url} passage ${run + 1} : banniere recue ${Math.round(sample.bannerEnd)} ms, page chargee ${Math.round(sample.loadEnd)} ms, LCP ${Math.round(sample.lcp)} ms (${sample.element}), ${sample.file} ${sample.bytes} octets`);
    }
    console.log(`PERF ${url} MEDIANE : banniere recue ${Math.round(median(bannerEnd))} ms, page chargee ${Math.round(median(loadEnd))} ms, LCP ${Math.round(median(lcp))} ms, banniere ${median(bytes)} octets (${RUNS} passages)`);
  });
}
