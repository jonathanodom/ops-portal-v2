import { test } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';

const sessionName = process.env.LOCAL_EXAMPLE_SESSION_NAME;
const sessionValue = process.env.LOCAL_EXAMPLE_SESSION_VALUE;
const localBaseUrl = process.env.LOCAL_EXAMPLE_BASE_URL;

test.describe('local example screenshot gallery', () => {
    test.skip(!sessionName || !sessionValue || !localBaseUrl, 'Local authenticated screenshot environment is not configured.');

    test('captures populated workspaces', async ({ page }, testInfo) => {
        const viewport = testInfo.project.name === 'mobile' ? 'mobile-390' : 'desktop-1440';
        const directory = path.resolve(process.cwd(), 'docs/local-example-data/screenshots', viewport);
        await fs.mkdir(directory, { recursive: true });
        await page.context().addCookies([{
            name: sessionName,
            value: sessionValue,
            url: localBaseUrl,
            httpOnly: true,
            sameSite: 'Lax',
        }]);

        for (const [name, route] of Object.entries({
            dashboard: '/office',
            customers: '/office/customers',
            service_tickets: '/office/service-tickets',
            field_today: '/field',
            review: '/office/closeout-reviews',
            billing: '/office/invoices',
            catalog: '/office/catalog/services',
            projects: '/office/projects',
        })) {
            await page.goto(`${localBaseUrl}${route}`);
            await page.waitForLoadState('networkidle');
            if (new URL(page.url()).pathname === '/login') {
                throw new Error(`The local screenshot session was not authenticated for ${route}.`);
            }
            await page.screenshot({ path: path.join(directory, `${name}.png`), fullPage: true });
        }
    });
});
