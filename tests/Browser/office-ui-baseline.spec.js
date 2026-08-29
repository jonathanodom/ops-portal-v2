import { test, expect } from '@playwright/test';

const password = process.env.BETA_DEMO_PASSWORD;
const outputDir = process.env.OFFICE_UI_FINAL_DIR || process.env.OFFICE_UI_BASELINE_DIR;
const captureStage = process.env.OFFICE_UI_FINAL_DIR ? 'final' : 'baseline';

const viewports = [
    ['P', 390, 844],
    ['T', 768, 1024],
    ['L', 1280, 800],
    ['D', 1440, 900],
    ['W', 1920, 1080],
];

const primaryIndexes = [
    ['H01', 'home', '/office'],
    ['C01', 'customers', '/office/customers'],
    ['C09', 'locations', '/office/locations'],
    ['J01', 'projects', '/office/projects'],
    ['O01', 'opportunities', '/office/opportunities'],
    ['ST01', 'service-tickets', '/office/service-tickets'],
    ['D01', 'dispatch', '/office/dispatch'],
    ['R01', 'closeout-reviews', '/office/closeout-reviews'],
    ['B01', 'billing-handoffs', '/office/billing-handoffs'],
    ['I01', 'invoices', '/office/invoices'],
    ['K01', 'catalog-services', '/office/catalog/services'],
    ['K02', 'catalog-products', '/office/catalog/products'],
    ['K03', 'catalog-packages', '/office/catalog/packages'],
    ['K04', 'catalog-categories', '/office/catalog/categories'],
    ['K05', 'catalog-units', '/office/catalog/units'],
    ['K06', 'customer-services', '/office/subscriptions'],
    ['A01', 'settings-organization', '/office/settings/organization'],
    ['A03', 'settings-billing', '/office/settings/billing'],
    ['A04', 'settings-invoices', '/office/settings/invoices'],
    ['A05', 'settings-commercial', '/office/settings/commercial'],
    ['A06', 'commercial-library', '/office/commercial-library'],
    ['A08', 'operational-health', '/office/operations/health'],
    ['A09', 'admin-archive', '/office/admin/archive'],
    ['P01', 'quote-approvals', '/office/quote-approvals'],
];

const responsiveIndexes = new Set([
    'H01', 'C01', 'J01', 'O01', 'ST01', 'D01', 'R01', 'B01', 'I01', 'K01', 'K02',
]);

const majorDetails = [
    ['C04', 'customer', '/office/customers', /^\/office\/customers\/\d+$/u, true],
    ['C10', 'location', '/office/locations', /^\/office\/locations\/\d+$/u, false],
    ['J03', 'project', '/office/projects', /^\/office\/projects\/\d+$/u, true],
    ['ST03', 'service-ticket', '/office/service-tickets', /^\/office\/service-tickets\/\d+$/u, true],
    ['R02', 'closeout-review', '/office/closeout-reviews', /^\/office\/closeout-reviews\/\d+$/u, true],
    ['I03', 'invoice', '/office/invoices', /^\/office\/invoices\/\d+$/u, false],
];

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('beta.super_admin@newdaytech.test');
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/login/);
}

async function capture(page, id, name, viewport) {
    const [code, width, height] = viewport;
    await page.setViewportSize({ width, height });
    const response = await page.goto(primaryIndexes.find(([candidate]) => candidate === id)[2]);
    expect(response?.ok(), `${id} should render successfully`).toBeTruthy();
    await expect(page.locator('main')).toBeVisible();
    await page.screenshot({
        path: `${outputDir}/${id}-${name}-index-default-${code}-${captureStage}.png`,
        fullPage: true,
    });
}

test.skip(!password || !outputDir, 'BETA_DEMO_PASSWORD and an Office UI capture directory are required.');

test('capture reachable Office workspace baselines', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project controls the exact viewport matrix.');
    test.setTimeout(300_000);
    await login(page);

    for (const [id, name] of primaryIndexes) {
        const required = responsiveIndexes.has(id)
            ? viewports
            : viewports.filter(([code]) => code === 'D');

        for (const viewport of required) {
            await capture(page, id, name, viewport);
        }
    }
});

test('capture reachable Office major-detail baselines', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project controls the exact viewport matrix.');
    test.setTimeout(300_000);
    await login(page);

    for (const [id, name, indexPath, linkPattern, responsive] of majorDetails) {
        await page.goto(indexPath);
        const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((link) => link.getAttribute('href')));
        const href = hrefs.find((candidate) => {
            if (!candidate) return false;
            const path = new URL(candidate, 'http://127.0.0.1:8001').pathname;
            return linkPattern.test(path);
        });

        expect(href, `${id} needs a reachable seeded record`).toBeTruthy();
        const required = responsive ? viewports : viewports.filter(([code]) => code === 'D');

        for (const [code, width, height] of required) {
            await page.setViewportSize({ width, height });
            const response = await page.goto(href);
            expect(response?.ok(), `${id} should render successfully`).toBeTruthy();
            await expect(page.locator('main')).toBeVisible();
            await page.screenshot({
                path: `${outputDir}/${id}-${name}-detail-default-${code}-${captureStage}.png`,
                fullPage: true,
            });
        }
    }
});
