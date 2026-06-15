'use strict';

const { chromium } = require('playwright');

const REVIEWS_URL        = process.argv[2];
const MAX_REVIEWS        = parseInt(process.argv[3] || '570', 10);
const BATCH_SIZE         = 50;
const SCROLL_PAUSE_MS    = 2500;
const MAX_NO_NEW_RETRIES = 6;
const PAGE_LOAD_TIMEOUT  = 45000;
const ELEMENT_TIMEOUT    = 20000;

if (!REVIEWS_URL) {
    process.stdout.write(JSON.stringify({ error: 'Reviews URL is required' }) + '\n');
    process.exit(1);
}

function sleep(ms) {
    return new Promise(r => setTimeout(r, ms));
}

const MONTHS_RU = {
    'января': 1, 'февраля': 2, 'марта': 3, 'апреля': 4,
    'мая': 5, 'июня': 6, 'июля': 7, 'августа': 8,
    'сентября': 9, 'октября': 10, 'ноября': 11, 'декабря': 12,
};

function parseRussianDate(str) {
    if (!str) return null;
    if (/^\d{4}-\d{2}-\d{2}/.test(str)) {
        const d = new Date(str);
        return isNaN(d.getTime()) ? null : d.toISOString();
    }
    const m = str.match(/(\d{1,2})\s+([а-яА-Я]+)(?:\s+(\d{4}))?/);
    if (m) {
        const day   = parseInt(m[1]);
        const month = MONTHS_RU[m[2].toLowerCase()];
        const year  = m[3] ? parseInt(m[3]) : new Date().getFullYear();
        if (month) return new Date(year, month - 1, day).toISOString();
    }
    return null;
}

async function extractReviewsFromDOM(page) {
    return page.evaluate(() => {
        const els = document.querySelectorAll('[class*="business-review-view"][itemprop="review"]');
        const out = [];
        for (const el of els) {
            const authorEl = el.querySelector(
                '[itemprop="author"] [itemprop="name"], ' +
                '.business-review-view__author-name [itemprop="name"]'
            );
            const author = authorEl?.textContent?.trim() || 'Аноним';

            const avatarEl = el.querySelector('[class*="user-icon-view__icon"]');
            let avatar = null;
            if (avatarEl) {
                const m = (avatarEl.style.backgroundImage || '').match(/url\("?(.+?)"?\)/);
                avatar = m ? m[1] : null;
            }

            let rating = null;
            const starsEl = el.querySelector('[class*="business-rating-badge-view__stars"]');
            if (starsEl) {
                const lbl = starsEl.getAttribute('aria-label') || '';
                const m = lbl.match(/(\d(?:[.,]\d)?)\s*(?:из|Из)/i) ||
                          lbl.match(/(?:Оценка|Rating)\s*(\d(?:[.,]\d)?)/i) ||
                          lbl.match(/^(\d(?:[.,]\d)?)/);
                if (m) rating = Math.round(parseFloat(m[1].replace(',', '.')));
            }

            let text = null;
            const textEl = el.querySelector(
                '[class*="review-view__body-full"], ' +
                '[class*="review-view__body"], ' +
                '[itemprop="reviewBody"], ' +
                '[itemprop="description"]'
            );
            if (textEl) {
                text = (textEl.innerText || textEl.textContent || '').trim()
                    .replace(/\s*(?:Ещё|ещё|Развернуть|Свернуть|свернуть)\s*$/, '').trim() || null;
            }

            const dateEl = el.querySelector(
                '[class*="review-view__date"], .business-review-view__date, time[datetime]'
            );
            const dateRaw = dateEl?.getAttribute('datetime') || dateEl?.textContent?.trim() || null;

            out.push({
                author_name:      author,
                author_avatar:    avatar,
                rating,
                text,
                reviewed_at_raw:  dateRaw,
                yandex_review_id: el.getAttribute('data-review-id') || null,
            });
        }
        return out;
    });
}

async function extractOrgInfo(page) {
    return page.evaluate(() => {
        const agg = document.querySelector('[itemprop="aggregateRating"]');
        let rating = null, reviewsCount = null;
        if (agg) {
            const rv = agg.querySelector('[itemprop="ratingValue"]');
            const rc = agg.querySelector('[itemprop="reviewCount"]');
            if (rv) {
                const v = parseFloat((rv.getAttribute('content') || rv.textContent || '').replace(',', '.'));
                if (!isNaN(v) && v >= 1 && v <= 5) rating = v;
            }
            if (rc) reviewsCount = parseInt(rc.getAttribute('content') || rc.textContent || '0') || null;
        }

        const nameEl = document.querySelector(
            '[itemtype*="schema.org"] [itemprop="name"], ' +
            '[class*="orgpage-header-view__title"], h1[class*="orgpage"]'
        );
        const name = nameEl?.getAttribute('content') || nameEl?.textContent?.trim() || null;

        let ratingsCount = null;
        const ratingAmountEl = document.querySelector(
            '.business-summary-rating-badge-view__rating-count, ' +
            '.business-rating-amount-view, [class*="rating-amount"]'
        );
        if (ratingAmountEl) {
            const m = (ratingAmountEl.textContent || '').match(/(\d[\d\s]+)/);
            if (m) ratingsCount = parseInt(m[1].replace(/\s/g, ''));
        }

        return { name, rating, reviews_count: reviewsCount, ratings_count: ratingsCount };
    });
}

async function scrollReviewsList(page) {
    const panelSelector =
        '.scroll__container, ' +
        '[class*="business-reviews-card-view__reviews-container"], ' +
        '.sidebar-panel__content, ' +
        '[class*="panel__content"]';

    const panel = await page.$(panelSelector);
    if (panel) {
        const box = await panel.boundingBox();
        if (box) {
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        }
    }
    await page.mouse.wheel(0, 3000);
}

async function scrapeReviews() {
    const browser = await chromium.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--no-first-run',
            '--no-zygote',
            '--single-process',
            '--disable-extensions',
            '--disable-background-networking',
            '--mute-audio',
            '--safebrowsing-disable-auto-update',
        ],
    });

    const context = await browser.newContext({
        locale: 'ru-RU',
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
                   'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        extraHTTPHeaders: { 'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.8' },
    });

    const page = await context.newPage();

    await page.route('**', (route) => {
        const type = route.request().resourceType();
        if (['image', 'font', 'media', 'websocket'].includes(type)) {
            return route.abort();
        }
        const url = route.request().url();
        if (/mc\.yandex|metrika|counter|ads\.yandex|adfox/.test(url)) {
            return route.abort();
        }
        return route.continue();
    });

    try {
        await page.goto(REVIEWS_URL, {
            waitUntil: 'domcontentloaded',
            timeout: PAGE_LOAD_TIMEOUT,
        });

        await page.waitForSelector('[class*="business-review-view"][itemprop="review"]', {
            timeout: ELEMENT_TIMEOUT,
        });
        await sleep(1500);

        const orgInfo = await extractOrgInfo(page);
        process.stdout.write(JSON.stringify({ type: 'info', org_info: orgInfo }) + '\n');

        const reviewMap = new Map();
        let noNewRetries = 0;
        let prevCount    = 0;
        let emittedCount = 0;

        while (reviewMap.size < MAX_REVIEWS && noNewRetries < MAX_NO_NEW_RETRIES) {
            const batch = await extractReviewsFromDOM(page);

            for (const r of batch) {
                const key = r.yandex_review_id || `${r.author_name}|${(r.text || '').substring(0, 80)}`;
                if (!reviewMap.has(key)) {
                    reviewMap.set(key, {
                        author_name:      r.author_name,
                        author_avatar:    r.author_avatar,
                        rating:           r.rating,
                        text:             r.text,
                        reviewed_at:      parseRussianDate(r.reviewed_at_raw),
                        yandex_review_id: r.yandex_review_id,
                    });
                }
            }

            if (reviewMap.size - emittedCount >= BATCH_SIZE) {
                const newReviews = Array.from(reviewMap.values()).slice(emittedCount, reviewMap.size);
                process.stdout.write(JSON.stringify({ type: 'batch', reviews: newReviews }) + '\n');
                emittedCount = reviewMap.size;
            }

            if (reviewMap.size === prevCount) {
                noNewRetries++;
            } else {
                noNewRetries = 0;
                prevCount    = reviewMap.size;
            }

            if (reviewMap.size >= MAX_REVIEWS) break;

            await scrollReviewsList(page);
            await sleep(SCROLL_PAUSE_MS);
        }

        const remaining = Array.from(reviewMap.values()).slice(emittedCount);
        if (remaining.length > 0) {
            process.stdout.write(JSON.stringify({ type: 'batch', reviews: remaining }) + '\n');
        }

        process.stdout.write(JSON.stringify({ type: 'done', total: reviewMap.size }) + '\n');

    } finally {
        await browser.close();
    }
}

scrapeReviews().catch(err => {
    process.stdout.write(JSON.stringify({ error: err.message }) + '\n');
    process.exit(1);
});
