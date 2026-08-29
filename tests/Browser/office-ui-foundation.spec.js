import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const password = process.env.BETA_DEMO_PASSWORD;
const outputDir = process.env.OFFICE_UI_FOUNDATION_DIR;
const checkpointThreeDir = process.env.OFFICE_UI_CHECKPOINT3_DIR;

const viewports = [
    ['P', 390, 844],
    ['T', 768, 1024],
    ['L', 1280, 800],
    ['D', 1440, 900],
    ['W', 1920, 1080],
];

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('beta.super_admin@newdaytech.test');
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/login/);
}

async function expectAccessible(page) {
    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact));
    expect(serious).toEqual([]);
}

async function firstRecordHref(page, indexPath, pattern) {
    await page.goto(indexPath);
    const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((link) => link.getAttribute('href')));
    return hrefs.find((candidate) => candidate && pattern.test(new URL(candidate, 'http://127.0.0.1:8001').pathname));
}

test.skip(!password, 'BETA_DEMO_PASSWORD is required.');

test('shared primary toolbar is responsive and accessible on the proof workspaces', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project controls the exact viewport matrix.');
    test.setTimeout(180_000);
    await login(page);

    for (const [id, name, path] of [
        ['C01', 'customers', '/office/customers'],
        ['L01', 'locations', '/office/locations'],
        ['O01', 'opportunities', '/office/opportunities'],
        ['I01', 'invoices', '/office/invoices'],
    ]) {
        for (const [code, width, height] of viewports) {
            await page.setViewportSize({ width, height });
            await page.goto(path);
            await expect(page.locator('.office-primary-toolbar')).toBeVisible();
            await expect(page.locator('summary', { hasText: 'Filters' })).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            if (outputDir) await page.screenshot({ path: `${outputDir}/${id}-${name}-toolbar-default-${code}-final.png`, fullPage: true });
        }
    }
});

test('filter disclosure and active chips preserve real GET state', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Desktop project covers phone and desktop states.');
    await login(page);

    for (const [code, width, height] of [['P', 390, 844], ['D', 1440, 900]]) {
        await page.setViewportSize({ width, height });
        await page.goto('/office/customers?status=active&type=business');
        await expect(page.getByLabel('Remove filter: Status: Active')).toBeVisible();
        await expect(page.getByLabel('Remove filter: Type: Business')).toBeVisible();
        await page.locator('summary', { hasText: 'Filters' }).click();
        await expect(page.getByLabel('Status', { exact: true })).toHaveValue('active');
        await expect(page.getByLabel('Customer type')).toHaveValue('business');
        expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
        await expectAccessible(page);
        if (outputDir) await page.screenshot({ path: `${outputDir}/S09-customers-filter-panel-open-${code}-final.png`, fullPage: true });
    }

    await page.goto('/office/opportunities?view=list&priority=urgent');
    await expect(page.getByRole('link', { name: 'List', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByLabel('Remove filter: Priority: Urgent')).toBeVisible();
});

test('checkpoint two workspaces share responsive toolbar and filter behavior', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project controls the focused viewport matrix.');
    test.setTimeout(180_000);
    await login(page);

    for (const [id, path, heading, hasFilters] of [
        ['J01', '/office/projects', 'Projects / Engagements', true],
        ['ST01', '/office/service-tickets', 'Service Tickets', true],
        ['D01', '/office/dispatch', 'Dispatch', true],
        ['R01', '/office/closeout-reviews', 'Closeout queue', true],
        ['K01', '/office/catalog/services', 'Products & Services', true],
        ['K02', '/office/catalog/products', 'Products & Services', true],
        ['K03', '/office/catalog/packages', 'Products & Services', true],
        ['K06', '/office/subscriptions', 'Customer Services', true],
        ['A06', '/office/quote-approvals', 'Quote approvals', false],
        ['A01', '/office/settings/organization', 'Settings', false],
    ]) {
        for (const [width, height] of [[390, 844], [1440, 900]]) {
            await page.setViewportSize({ width, height });
            await page.goto(path);
            await expect(page.locator('.office-primary-toolbar')).toBeVisible();
            await expect(page.getByRole('heading', { name: heading, exact: true, level: 1 })).toBeVisible();
            if (hasFilters) await expect(page.locator('summary', { hasText: 'Filters' })).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            if (outputDir) await page.screenshot({ path: `${outputDir}/${id}-${width}-final.png`, fullPage: true });
        }
    }
});

test('checkpoint three forms and record details share compact action contracts', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project controls the focused viewport matrix.');
    test.setTimeout(180_000);
    await login(page);

    for (const [id, path, heading] of [
        ['C06', '/office/customers/create', 'Add customer'],
        ['J11', '/office/projects/create', 'New Project / Engagement'],
        ['O08', '/office/opportunities/create', 'New opportunity'],
        ['ST05', '/office/service-tickets/create', 'New service ticket'],
        ['I06', '/office/invoices/create', 'New invoice'],
        ['K10', '/office/catalog/services/create', 'Add service'],
        ['K11', '/office/catalog/products/create', 'Add product'],
        ['K12', '/office/catalog/packages/create', 'Add package'],
    ]) {
        for (const [width, height] of [[390, 844], [1440, 900]]) {
            await page.setViewportSize({ width, height });
            await page.goto(path);
            await expect(page.getByRole('heading', { name: heading, exact: true, level: 1 })).toBeVisible();
            await expect(page.locator('.office-form-shell').first()).toBeVisible();
            await expect(page.locator('.office-form-actions')).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            const actionHeights = await page.locator('.office-form-actions a, .office-form-actions button').evaluateAll((controls) => controls.map((control) => control.getBoundingClientRect().height));
            expect(actionHeights.every((height) => height >= 44)).toBeTruthy();
            await expectAccessible(page);
            if (checkpointThreeDir) await page.screenshot({ path: `${checkpointThreeDir}/${id}-${width}-final.png`, fullPage: true });
        }
    }

    const details = [
        ['ST03', await firstRecordHref(page, '/office/service-tickets', /^\/office\/service-tickets\/\d+$/u)],
        ['R02', await firstRecordHref(page, '/office/closeout-reviews', /^\/office\/closeout-reviews\/\d+$/u)],
    ];

    for (const [id, href] of details) {
        expect(href, `${id} needs a reachable seeded record`).toBeTruthy();
        for (const [width, height] of [[390, 844], [1440, 900]]) {
            await page.setViewportSize({ width, height });
            await page.goto(href);
            await expect(page.locator('.office-record-header')).toBeVisible();
            expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBeTruthy();
            await expectAccessible(page);
            if (checkpointThreeDir) await page.screenshot({ path: `${checkpointThreeDir}/${id}-${width}-final.png`, fullPage: true });
        }
    }
});

test('checkpoint four hardens representative Office workspaces across tablet and desktop widths', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project controls the hardening viewport matrix.');
    test.setTimeout(300_000);
    await login(page);

    const routes = [
        ['/office/customers', true],
        ['/office/projects', true],
        ['/office/opportunities', true],
        ['/office/service-tickets', true],
        ['/office/dispatch', true],
        ['/office/closeout-reviews', true],
        ['/office/invoices', true],
        ['/office/catalog/services', true],
        ['/office/settings/organization', false],
        ['/office/operations/health', false],
    ];
    const metrics = [];

    for (const [code, width, height] of viewports.filter(([candidate]) => ['T', 'L', 'W'].includes(candidate))) {
        await page.setViewportSize({ width, height });

        for (const [path, usesPrimaryToolbar] of routes) {
            const response = await page.goto(path);
            expect(response?.ok(), `${path} should render at ${code}`).toBeTruthy();
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('h1')).toHaveCount(1);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();

            const payloadBytes = (await response.body()).byteLength;
            expect(payloadBytes, `${path} HTML payload should remain bounded`).toBeLessThan(2_500_000);
            metrics.push({ code, path, payloadBytes });

            if (usesPrimaryToolbar) {
                const toolbar = page.locator('.office-primary-toolbar');
                await expect(toolbar).toBeVisible();
                if (width >= 1280) {
                    const box = await toolbar.boundingBox();
                    expect(box?.height, `${path} toolbar should remain compact at ${code}`).toBeLessThanOrEqual(88);
                }
            }

            if (['/office/customers', '/office/service-tickets', '/office/invoices', '/office/settings/organization'].includes(path)) {
                await expectAccessible(page);
            }
        }
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/office/customers');
    const firstAction = page.locator('.office-primary-toolbar a, .office-primary-toolbar button, .office-primary-toolbar summary').first();
    await firstAction.focus();
    const focusStyle = await firstAction.evaluate((element) => {
        const style = getComputedStyle(element);
        return { boxShadow: style.boxShadow, outlineStyle: style.outlineStyle };
    });
    expect(focusStyle.boxShadow !== 'none' || focusStyle.outlineStyle !== 'none').toBeTruthy();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();

    const largest = metrics.reduce((current, metric) => metric.payloadBytes > current.payloadBytes ? metric : current, metrics[0]);
    console.log(`Office UI hardening: ${metrics.length} route/viewport checks; largest HTML payload ${largest.payloadBytes} bytes at ${largest.path} (${largest.code}).`);
});
