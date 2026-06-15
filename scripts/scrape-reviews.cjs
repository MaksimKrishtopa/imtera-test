#!/usr/bin/env node
/**
 * Yandex Maps Reviews Scraper — Playwright headless Chromium
 *
 * Strategy:
 *  1. Load the org reviews page with a real browser (bypasses bot protection)
 *  2. Block heavy resources (images, fonts, CSS) to cut memory & load time
 *  3. Use 'domcontentloaded' — NOT 'networkidle' (networkidle causes crashes
 *     because Yandex pages continuously poll the network)
 *  4. Scroll the reviews container to lazy-load all reviews
 *  5. Extract review data from Schema.org microdata + DOM attributes
 *
 * Usage: node scrape-reviews.cjs <orgId> [maxReviews]
 * Output: single JSON line to stdout
 */

'use strict';

const { chromium } = require('playwright');

const ORG_ID      = process.argv[2];
const MAX_REVIEWS = parseInt(process.argv[3] || '600', 10);

// Tuned for Railway free-tier (~512 MB RAM):
// shorter pause = faster, but too short misses lazy-loaded reviews
const SCROLL_PAUSE_MS    = 2200;
const MAX_NO_NEW_RETRIES = 5;
const PAGE_LOAD_TIMEOUT  = 45000;
const ELEMENT_TIMEOUT    = 20000;

if (!ORG_ID) {
    process.stderr.write(JSON.stringify({ error: 'Org ID is required' }) + '\n');
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
    // ISO or datetime attribute
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
            // Author
            const authorEl = el.querySelector(
                '[itemprop="author"] [itemprop="name"], ' +
                '.business-review-view__author-name [itemprop="name"], ' +
                '[class*="user-icon-view__name"]'
            );
            const author = authorEl?.textContent?.trim() || 'Аноним';

            // Avatar
            const avatarEl = el.querySelector('[class*="user-icon-view__icon"]');
            let avatar = null;
            if (avatarEl) {
                const m = (avatarEl.style.backgroundImage || '').match(/url\("?(.+?)"?\)/);
                avatar = m ? m[1] : null;
            }

            // Rating (stars aria-label)
            let rating = null;
            const starsEl = el.querySelector('[class*="business-rating-badge-view__stars"]');
            if (starsEl) {
                const lbl = starsEl.getAttribute('aria-label') || '';
                const m = lbl.match(/(\d(?:[.,]\d)?)\s*(?:из|Из|of)/i) ||
                          lbl.match(/(?:Оценка|Rating)\s*(\d(?:[.,]\d)?)/i) ||
                          lbl.match(/^(\d(?:[.,]\d)?)/);
                if (m) rating = Math.round(parseFloat(m[1].replace(',', '.')));
            }

            // Review text (expand "Ещё" links are already expanded after scrolling)
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

            // Date
            const dateEl = el.querySelector(
                '[class*="review-view__date"], ' +
                '.business-review-view__date, ' +
                'time[datetime]'
            );
            const dateRaw = dateEl?.getAttribute('datetime') || dateEl?.textContent?.trim() || null;

            out.push({
                author_name: author,
                author_avatar: avatar,
                rating,
                text,
                reviewed_at_raw: dateRaw,
                yandex_review_id: el.getAttribute('data-review-id') || null,
            });
        }
        return out;
    });
}

async function extractOrgInfo(page) {
    return page.evaluate(() => {
        // Schema.org aggregateRating
        const agg  = document.querySelector('[itemprop="aggregateRating"]');
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

        // Organisation name
        const nameEl = document.querySelector(
            '[itemtype*="schema.org"] [itemprop="name"], ' +
            '[class*="orgpage-header-view__title"], ' +
            'h1[class*="orgpage"]'
        );
        const name = nameEl?.getAttribute('content') || nameEl?.textContent?.trim() || null;

        // Total ratings count (оценок) — usually a bigger number next to stars
        let ratingsCount = null;
        const ratingAmountEl = document.querySelector(
            '.business-summary-rating-badge-view__rating-count, ' +
            '.business-rating-amount-view, ' +
            '[class*="rating-amount"]'
        );
        if (ratingAmountEl) {
            const m = (ratingAmountEl.textContent || '').match(/(\d[\d\s]+)/);
            if (m) ratingsCount = parseInt(m[1].replace(/\s/g, ''));
        }

        return { name, rating, reviews_count: reviewsCount, ratings_count: ratingsCount };
    });
}

async function scrollReviewsList(page) {
    await page.evaluate(() => {
        // Try to find the reviews scroll container, fall back to window scroll
        const container = (
            document.querySelector('.scroll__container') ||
            document.querySelector('[class*="business-reviews-card-view__reviews-container"]') ||
            document.querySelector('.sidebar-panel__content') ||
            document.querySelector('[class*="panel__content"]')
        );
        if (container && container.scrollHeight > container.clientHeight + 50) {
            container.scrollTop += 3500;
        } else {
            window.scrollBy(0, 3500);
        }
    });
}

async function scrapeReviews() {
    const browser = await chromium.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            // Critical in Docker/Railway: Chrome uses /tmp instead of /dev/shm
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--no-zygote',
            // Cut unnecessary background work
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-default-apps',
            '--disable-sync',
            '--disable-translate',
            '--mute-audio',
            '--no-first-run',
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

    // Block heavy resources — saves load time and RAM.
    // NOTE: CSS is intentionally NOT blocked — it's needed for the scroll
    // container to have correct dimensions (scroll height calculations).
    await page.route('**', (route) => {
        const type = route.request().resourceType();
        if (['image', 'font', 'media', 'websocket'].includes(type)) {
            return route.abort();
        }
        // Block Yandex ad / metrics
        const url = route.request().url();
        if (/mc\.yandex|metrika|counter|ads\.yandex|adfox/.test(url)) {
            return route.abort();
        }
        return route.continue();
    });

    try {
        const reviewsUrl = `https://yandex.ru/maps/org/${ORG_ID}/reviews/`;

        // Use 'domcontentloaded' — NOT 'networkidle':
        // Yandex Maps continuously polls the network so networkidle never
        // (or very late) resolves, keeping the browser alive until OOM crash.
        await page.goto(reviewsUrl, {
            waitUntil: 'domcontentloaded',
            timeout: PAGE_LOAD_TIMEOUT,
        });

        // Wait for at least one review to appear in the DOM
        await page.waitForSelector('[class*="business-review-view"][itemprop="review"]', {
            timeout: ELEMENT_TIMEOUT,
        });

        // Brief pause for the first batch to fully render
        await sleep(1500);

        const orgInfo = await extractOrgInfo(page);

        const reviewMap = new Map();
        let noNewRetries = 0;
        let prevCount    = 0;

        while (reviewMap.size < MAX_REVIEWS && noNewRetries < MAX_NO_NEW_RETRIES) {
            const batch = await extractReviewsFromDOM(page);

            for (const r of batch) {
                const key = r.yandex_review_id ||
                            `${r.author_name}|${(r.text || '').substring(0, 80)}`;
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

        const reviews = Array.from(reviewMap.values()).slice(0, MAX_REVIEWS);

        process.stdout.write(JSON.stringify({
            org_id:         ORG_ID,
            org_info:       orgInfo,
            reviews,
            total_captured: reviews.length,
        }) + '\n');

    } finally {
        await browser.close();
    }
}

scrapeReviews().catch(err => {
    process.stderr.write(JSON.stringify({ error: err.message }) + '\n');
    process.exit(1);
});
