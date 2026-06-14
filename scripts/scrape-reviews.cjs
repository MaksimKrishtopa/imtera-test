#!/usr/bin/env node
/**
 * Yandex Maps Reviews Scraper using Playwright (headless Chrome)
 * 
 * Strategy: Load the org reviews page with a real browser, expand and extract 
 * review data from the DOM (Schema.org microdata + CSS classes), scroll to load more.
 * 
 * Usage: node scrape-reviews.cjs <orgId> [maxReviews]
 * Output: JSON to stdout
 */

const { chromium } = require('playwright');

const ORG_ID = process.argv[2];
const MAX_REVIEWS = parseInt(process.argv[3] || '600', 10);
const SCROLL_PAUSE_MS = 2500;
const MAX_NO_NEW_RETRIES = 6;

if (!ORG_ID) {
    process.stderr.write(JSON.stringify({ error: 'Org ID is required' }) + '\n');
    process.exit(1);
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

const MONTHS_RU = {
    'января': 1, 'февраля': 2, 'марта': 3, 'апреля': 4,
    'мая': 5, 'июня': 6, 'июля': 7, 'августа': 8,
    'сентября': 9, 'октября': 10, 'ноября': 11, 'декабря': 12,
};

function parseRussianDate(dateStr) {
    if (!dateStr) return null;
    if (/^\d{4}-\d{2}-\d{2}/.test(dateStr)) {
        const d = new Date(dateStr);
        return isNaN(d.getTime()) ? null : d.toISOString();
    }
    const match = dateStr.match(/(\d{1,2})\s+([а-яА-Я]+)(?:\s+(\d{4}))?/);
    if (match) {
        const day = parseInt(match[1]);
        const month = MONTHS_RU[match[2].toLowerCase()];
        const year = match[3] ? parseInt(match[3]) : new Date().getFullYear();
        if (month) {
            return new Date(year, month - 1, day).toISOString();
        }
    }
    return null;
}

async function extractReviewsFromDOM(page) {
    return page.evaluate(() => {
        const reviewEls = document.querySelectorAll('[class*="business-review-view"][itemprop="review"]');
        const results = [];

        for (const el of reviewEls) {
            const authorNameEl = el.querySelector('[itemprop="author"] [itemprop="name"], .business-review-view__author-name [itemprop="name"]');
            const authorName = authorNameEl?.textContent?.trim() || 'Аноним';

            const avatarEl = el.querySelector('[class*="user-icon-view__icon"]');
            let authorAvatar = null;
            if (avatarEl) {
                const style = avatarEl.style.backgroundImage || '';
                const match = style.match(/url\("?(.+?)"?\)/);
                authorAvatar = match ? match[1] : null;
            }

            const starsEl = el.querySelector('[class*="business-rating-badge-view__stars"]');
            let rating = null;
            if (starsEl) {
                const ariaLabel = starsEl.getAttribute('aria-label') || '';
                const m = ariaLabel.match(/(\d(?:[.,]\d)?)\s+(?:Из|из)/i) ||
                           ariaLabel.match(/(?:Оценка|Rating)\s+(\d(?:[.,]\d)?)/i);
                if (m) {
                    rating = Math.round(parseFloat(m[1].replace(',', '.')));
                }
            }

            let text = null;
            const textEl = el.querySelector('[class*="review-view__body-full"]') ||
                            el.querySelector('[class*="review-view__body"]') ||
                            el.querySelector('[itemprop="description"], [itemprop="reviewBody"]');
            if (textEl) {
                text = textEl.innerText?.trim() || textEl.textContent?.trim() || null;
                if (text) {
                    text = text.replace(/\s*(?:Ещё|ещё)\s*$/, '').replace(/\s*(?:Свернуть|свернуть)\s*$/, '').trim();
                    if (!text) text = null;
                }
            }

            const dateEl = el.querySelector('[class*="review-view__date"], .business-review-view__date, time');
            const dateText = dateEl?.getAttribute('datetime') || dateEl?.textContent?.trim() || null;

            results.push({
                author_name: authorName,
                author_avatar: authorAvatar,
                rating: rating,
                text: text,
                reviewed_at_raw: dateText,
                yandex_review_id: el.getAttribute('data-review-id') || null,
            });
        }

        return results;
    });
}

async function extractOrgInfo(page) {
    return page.evaluate(() => {
        // Try 1: embedded JSON script state
        const scripts = Array.from(document.querySelectorAll('script'));
        for (const script of scripts) {
            const text = script.textContent || '';
            if (text.includes('"csrfToken"') && text.length > 5000) {
                try {
                    // Match the JSON object
                    const jsonStart = text.indexOf('{"config"');
                    if (jsonStart >= 0) {
                        const jsonStr = text.substring(jsonStart);
                        const data = JSON.parse(jsonStr);
                        const stack = data.stack || [];
                        for (const item of stack) {
                            for (const org of (item.results?.items || [])) {
                                if (org.type === 'Feature' && org.title) {
                                    const rd = org.ratingData || {};
                                    return {
                                        name: org.title,
                                        rating: parseFloat(rd.ratingValue) || null,
                                        reviews_count: parseInt(rd.reviewCount) || null,
                                        ratings_count: parseInt(rd.ratingCount) || null,
                                    };
                                }
                            }
                        }
                    }
                } catch {}
            }
        }

        // Try 2: Schema.org aggregateRating (most reliable)
        const aggregate = document.querySelector('[itemprop="aggregateRating"]');
        let rating = null;
        let reviewsCount = null;
        if (aggregate) {
            const rv = aggregate.querySelector('[itemprop="ratingValue"]');
            const rc = aggregate.querySelector('[itemprop="reviewCount"]');
            if (rv) {
                const v = parseFloat((rv.getAttribute('content') || rv.textContent || '').replace(',', '.'));
                if (!isNaN(v) && v >= 1 && v <= 5) rating = v;
            }
            if (rc) {
                reviewsCount = parseInt(rc.getAttribute('content') || rc.textContent || '0') || null;
            }
        }

        // Name from Schema.org or DOM
        const nameSchemaEl = document.querySelector('[itemtype*="schema.org"] [itemprop="name"], [itemprop="name"]');
        const nameDomEl = document.querySelector('[class*="orgpage-header-view__title"]');
        const name = nameSchemaEl?.getAttribute('content') || nameSchemaEl?.textContent?.trim()
                  || nameDomEl?.textContent?.trim() || null;

        // Ratings count (оценок) — look in the rating badge text
        let ratingsCount = null;
        const ratingBadgeEl = document.querySelector('.business-summary-rating-badge-view__rating-count, .business-rating-amount-view');
        if (ratingBadgeEl) {
            const text = ratingBadgeEl.textContent || '';
            const m = text.match(/(\d[\d\s]+)/);
            if (m) ratingsCount = parseInt(m[1].replace(/\s/g, ''));
        }

        return { name, rating, reviews_count: reviewsCount, ratings_count: ratingsCount };
    });
}

async function scrollReviewsList(page) {
    await page.evaluate(() => {
        const scrollable = [
            document.querySelector('.scroll__container'),
            document.querySelector('[class*="business-reviews-card-view__reviews-container"]'),
            document.querySelector('.sidebar-panel__content'),
            document.querySelector('[class*="panel__content"]'),
        ].find(el => el && el.scrollHeight > el.clientHeight + 50);

        if (scrollable) {
            scrollable.scrollTop += 3000;
        } else {
            window.scrollBy(0, 3000);
        }
    });
}

async function scrapeReviews() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--no-first-run', '--disable-gpu'],
    });

    const context = await browser.newContext({
        locale: 'ru-RU',
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        extraHTTPHeaders: { 'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.8' },
    });

    const page = await context.newPage();

    try {
        await page.goto(`https://yandex.ru/maps/org/${ORG_ID}/reviews/`, {
            waitUntil: 'networkidle',
            timeout: 30000,
        });

        await page.waitForSelector('[class*="business-review-view"][itemprop="review"]', { timeout: 15000 });
        await sleep(1500);

        const orgInfo = await extractOrgInfo(page);

        const reviewMap = new Map();
        let noNewRetries = 0;
        let prevCount = 0;

        while (reviewMap.size < MAX_REVIEWS && noNewRetries < MAX_NO_NEW_RETRIES) {
            const batch = await extractReviewsFromDOM(page);

            for (const r of batch) {
                const key = r.yandex_review_id || `${r.author_name}|${(r.text || '').substring(0, 80)}`;
                if (!reviewMap.has(key)) {
                    reviewMap.set(key, {
                        author_name: r.author_name,
                        author_avatar: r.author_avatar,
                        rating: r.rating,
                        text: r.text,
                        reviewed_at: parseRussianDate(r.reviewed_at_raw),
                        yandex_review_id: r.yandex_review_id,
                    });
                }
            }

            if (reviewMap.size === prevCount) {
                noNewRetries++;
            } else {
                noNewRetries = 0;
                prevCount = reviewMap.size;
            }

            if (reviewMap.size >= MAX_REVIEWS) break;

            await scrollReviewsList(page);
            await sleep(SCROLL_PAUSE_MS);
        }

        const reviews = Array.from(reviewMap.values()).slice(0, MAX_REVIEWS);

        process.stdout.write(JSON.stringify({
            org_id: ORG_ID,
            org_info: orgInfo,
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
