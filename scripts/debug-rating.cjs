const { chromium } = require('playwright');
(async () => {
    const b = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const ctx = await b.newContext({
        locale: 'ru-RU',
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'
    });
    const p = await ctx.newPage();
    await p.goto('https://yandex.ru/maps/org/21117108341/reviews/', { waitUntil: 'networkidle', timeout: 30000 });
    await p.waitForSelector('[itemprop="review"]', { timeout: 15000 });
    
    const info = await p.evaluate(() => {
        const ratingEls = [];
        document.querySelectorAll('[class*="rating"]').forEach(el => {
            const t = (el.textContent || '').trim().substring(0, 60);
            if (t && /\d/.test(t)) {
                ratingEls.push({ cls: el.className.substring(0,80), text: t });
            }
        });

        // Also check aggregate schema
        const aggregate = document.querySelector('[itemprop="aggregateRating"]');
        const schemaRating = aggregate ? {
            value: aggregate.querySelector('[itemprop="ratingValue"]')?.content || aggregate.querySelector('[itemprop="ratingValue"]')?.textContent,
            count: aggregate.querySelector('[itemprop="reviewCount"]')?.content || aggregate.querySelector('[itemprop="reviewCount"]')?.textContent,
        } : null;
        
        return { ratingEls: ratingEls.slice(0, 30), schemaRating };
    });
    
    console.log('Schema aggregate:', JSON.stringify(info.schemaRating));
    info.ratingEls.forEach(r => console.log(r.cls.substring(0,60), '|', r.text));
    await b.close();
})().catch(e => console.error(e.message));
