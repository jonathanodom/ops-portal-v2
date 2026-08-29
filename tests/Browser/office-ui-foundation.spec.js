import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const password = process.env.BETA_DEMO_PASSWORD;
const outputDir = process.env.OFFICE_UI_FOUNDATION_DIR;

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
