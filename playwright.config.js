// Configuration Playwright (E2E borne). CommonJS : la racine n'est pas "type:module".
// La stack est montee a part (tests/e2e/run.sh) ; BASE_URL pointe vers wakdo-web.
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  // Headless : tourne sur serveur sans ecran (dans le conteneur Playwright officiel).
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    // run.sh fixe BASE_URL (hostname .test, joignable via --add-host).
    baseURL: process.env.BASE_URL || 'http://kiosk.wakdo.test',
    headless: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
