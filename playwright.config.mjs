import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.GEOFLOW_BROWSER_BASE_URL || 'https://geo.local.dofe.ai';
const channel = process.env.PLAYWRIGHT_CHANNEL || 'chrome';

export default defineConfig({
    testDir: './tests/Browser',
    timeout: 120_000,
    expect: {
        timeout: 10_000,
    },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    outputDir: 'storage/app/playwright-results',
    use: {
        baseURL,
        channel,
        headless: process.env.PLAYWRIGHT_HEADLESS !== 'false',
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        ...devices['Desktop Chrome'],
    },
});
