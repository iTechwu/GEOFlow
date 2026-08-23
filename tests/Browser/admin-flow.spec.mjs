import { expect, test } from '@playwright/test';

const adminMobile = process.env.GEOFLOW_BROWSER_ADMIN_MOBILE;
const adminPassword = process.env.GEOFLOW_BROWSER_ADMIN_PASSWORD;
const expectSuperAdmin = process.env.GEOFLOW_BROWSER_EXPECT_SUPER === 'true';
const mobileFieldName = /Mobile number|手机号/i;
const passwordFieldName = /Password|密码/i;
const signInButtonName = /^(Sign in|登录)$/i;

function observeRuntime(page) {
    const diagnostics = {
        consoleErrors: [],
        pageErrors: [],
        failedRequests: [],
        serverErrors: [],
        forbiddenResponses: [],
    };

    page.on('console', (message) => {
        if (message.type() === 'error') {
            diagnostics.consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => diagnostics.pageErrors.push(error.message));
    page.on('requestfailed', (request) => {
        diagnostics.failedRequests.push({
            url: request.url(),
            error: request.failure()?.errorText || 'unknown',
        });
    });
    page.on('response', (response) => {
        if (response.status() >= 500) {
            diagnostics.serverErrors.push({
                status: response.status(),
                url: response.url(),
            });
        }
        if (response.status() === 403) {
            diagnostics.forbiddenResponses.push(response.url());
        }
    });

    return diagnostics;
}

function resetDiagnostics(diagnostics) {
    diagnostics.consoleErrors.length = 0;
    diagnostics.pageErrors.length = 0;
    diagnostics.failedRequests.length = 0;
    diagnostics.serverErrors.length = 0;
    diagnostics.forbiddenResponses.length = 0;
}

async function attachDiagnostics(testInfo, diagnostics) {
    await testInfo.attach('browser-runtime-diagnostics', {
        body: Buffer.from(JSON.stringify(diagnostics, null, 2)),
        contentType: 'application/json',
    });
}

async function expectCleanRuntime(diagnostics, expectedForbiddenUrl = null) {
    expect(diagnostics.pageErrors).toEqual([]);
    expect(diagnostics.serverErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
    const unexpectedForbiddenResponses = diagnostics.forbiddenResponses.filter((url) => expectedForbiddenUrl === null || !url.includes(expectedForbiddenUrl));
    expect(unexpectedForbiddenResponses).toEqual([]);
    expect(diagnostics.consoleErrors.filter((message) => !message.includes('server responded with a status of 403')).length).toBe(0);
}

async function waitForSsoFormToSettle(page) {
    await page.waitForLoadState('load');
    await page.waitForTimeout(1_500);
    await expect(page.getByRole('textbox', { name: mobileFieldName })).toBeVisible();
    await expect(page.getByRole('textbox', { name: passwordFieldName })).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test('访客从 GEO 后台入口进入统一认证页', async ({ page }, testInfo) => {
    const diagnostics = observeRuntime(page);

    await page.setViewportSize({ width: 390, height: 844 });
    const response = await page.goto('/geo_admin', { waitUntil: 'domcontentloaded' });

    expect(response?.status()).toBe(200);
    const ssoUrl = new URL(page.url());
    expect(ssoUrl.hostname).toBe('sso.ixicai.cn');
    expect(ssoUrl.pathname).toMatch(/\/login$/);
    await expect(page.getByRole('textbox', { name: mobileFieldName })).toBeVisible();
    await expect(page.getByRole('textbox', { name: passwordFieldName })).toBeVisible();
    await expect(page.getByRole('button', { name: signInButtonName })).toBeVisible();

    await attachDiagnostics(testInfo, diagnostics);
    await expectCleanRuntime(diagnostics);
});

test('管理员经 SSO 登录后访问 GEO 核心工作台', async ({ page }, testInfo) => {
    test.setTimeout(180_000);
    test.skip(!adminMobile || !adminPassword, '需要通过环境变量提供本地或测试环境管理员凭据');

    const diagnostics = observeRuntime(page);
    await page.goto('/geo_admin', { waitUntil: 'domcontentloaded' });
    await waitForSsoFormToSettle(page);

    const mobileField = page.getByRole('textbox', { name: mobileFieldName });
    const passwordField = page.getByRole('textbox', { name: passwordFieldName });
    await mobileField.fill(adminMobile);
    await passwordField.fill(adminPassword);
    await expect(mobileField).toHaveValue(adminMobile);
    await expect(passwordField).toHaveValue(adminPassword);

    const loginResponsePromise = page.waitForResponse((response) => response.url().includes('/api/auth/login/mobile'));
    await page.getByRole('button', { name: signInButtonName }).click();
    const loginResponse = await loginResponsePromise;
    expect(loginResponse.status(), 'SSO 手机号登录接口应成功').toBe(200);
    await page.waitForURL('**/geo_admin/dashboard', { waitUntil: 'domcontentloaded' });
    // SSO 页面切换会取消跨域预取请求；从 GEO dashboard 开始只观测本应用。
    resetDiagnostics(diagnostics);

    const corePages = [
        { path: '/geo_admin/dashboard', expectedStatus: 200 },
        { path: '/geo_admin/tasks', expectedStatus: 200 },
        { path: '/geo_admin/articles', expectedStatus: 200 },
        { path: '/geo_admin/ai-models', expectedStatus: 200 },
        { path: '/geo_admin/enterprise-knowledge', expectedStatus: 200 },
        {
            path: '/geo_admin/distribution',
            expectedStatus: expectSuperAdmin ? 200 : 403,
        },
    ];

    for (const { path, expectedStatus } of corePages) {
        const startedAt = Date.now();
        const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
        const elapsedMs = Date.now() - startedAt;

        expect(response?.status(), path).toBe(expectedStatus);
        if (expectedStatus === 403) {
            await expect(page.locator('body'), `${path} 应明确拒绝普通管理员`).toContainText('Forbidden');
            continue;
        }
        await expect(page.locator('h1').first(), `${path} 应有可见的页面主标题`).toBeVisible();
        expect(elapsedMs, `${path} DOMContentLoaded 不应超过 10 秒`).toBeLessThan(10_000);
        if (path === '/geo_admin/dashboard') {
            await page.screenshot({ path: 'storage/app/browser-regression-dashboard.png', fullPage: true });
            await testInfo.attach('authenticated-dashboard', {
                body: await page.screenshot({ fullPage: true }),
                contentType: 'image/png',
            });
        }
    }

    await attachDiagnostics(testInfo, diagnostics);
    await expectCleanRuntime(diagnostics, expectSuperAdmin ? null : '/geo_admin/distribution');
});
