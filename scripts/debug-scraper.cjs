const { chromium } = require('playwright');

const ORG_ID = '21117108341';

(async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        locale: 'ru-RU',
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    });
    const page = await context.newPage();
    
    await page.goto(`https://yandex.ru/maps/org/tretyakovskaya_galereya/${ORG_ID}/reviews/`, {
        waitUntil: 'networkidle',
        timeout: 30000,
    });
    
    // Find all unique class names containing "review"
    const reviewClasses = await page.evaluate(() => {
        const all = document.querySelectorAll('[class*="review"], [class*="Review"]');
        const classes = new Set();
        for (const el of all) {
            el.className.split(' ').forEach(c => {
                if (c.toLowerCase().includes('review')) classes.add(c);
            });
        }
        return [...classes].slice(0, 30);
    });
    console.log('Review-related classes:', reviewClasses.join('\n'));
    
    // Try to extract review data from DOM
    const reviews = await page.evaluate(() => {
        // Look for review list items
        const selectors = [
            '[class*="ReviewView"]',
            '[class*="review-view"]',
            '[class*="BusinessReview"]',
            '[class*="business-review"]',
            '[data-review-id]',
            '[class*="list-item"][class*="review"]',
        ];
        
        for (const sel of selectors) {
            const items = document.querySelectorAll(sel);
            if (items.length > 0) {
                console.log('Found with selector:', sel, 'count:', items.length);
                // Extract first item's structure
                const first = items[0];
                return {
                    selector: sel,
                    count: items.length,
                    sampleHtml: first.outerHTML.substring(0, 2000),
                    sampleText: first.innerText.substring(0, 500),
                };
            }
        }
        return null;
    });
    
    if (reviews) {
        console.log('\nReview selector found:', reviews.selector, '(', reviews.count, 'items)');
        console.log('Sample text:', reviews.sampleText);
        console.log('Sample HTML:', reviews.sampleHtml);
    } else {
        console.log('No review selector found, trying alternative approach...');
        
        // Look at what's rendered
        const bodyHtml = await page.evaluate(() => {
            const sidebar = document.querySelector('.sidebar-panel') || 
                           document.querySelector('[class*="sidebar"]') ||
                           document.querySelector('[class*="panel"]');
            return sidebar ? sidebar.innerHTML.substring(0, 3000) : document.body.innerHTML.substring(0, 3000);
        });
        console.log('Sidebar HTML:', bodyHtml);
    }
    
    await browser.close();
})();
