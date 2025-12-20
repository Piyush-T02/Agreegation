const { chromium } = require("playwright");
const timers = require('timers/promises');
const fs = require('fs').promises;
const path = require('path');
const { logger, createTransportInstance } = require("../utils/logger.js");

class EventBriteCore {
    constructor(logFilename, stateFilename) {
        this.browser = null;
        this.browserIsClosed = false;
        this.page = null;
        this.MAX_PAGINATION = 50;
        this.extPath = path.resolve(__dirname, "../extensions/capsolver");
        this.dataFolderPath = path.resolve(__dirname, "../data/");
        this.stateJsonPath = path.join(this.dataFolderPath, stateFilename);
        this.DEFAUL_TIMEOUT = 15_000;

        logger.add(createTransportInstance(logFilename));
    }

    /**
     * Wait for a random delay.
     * @param {'S'|'M'|'L'} size - S: 2-4s, M: 5-8s, L: 8-12s
     */
    async randomDelay(size = 'M', context = "") {
        let min, max;
        switch (size) {
            case 'S':
                min = 2000; max = 4000; break;
            case 'L':
                min = 8000; max = 12000; break;
            case 'M':
            default:
                min = 4000; max = 8000; break;
        }
        const ms = Math.floor(Math.random() * (max - min + 1)) + min;
        logger.info(`random delay of ${ms}ms ${context}`);

        return timers.setTimeout(ms);
    }

    /**
     * Send a message to Slack
     * @param {string} text - Message text
     * @param {string} channel - Slack channel
     * @param {string} botname - Bot name
     * @returns {Promise<Object|boolean>} Response data or false on error
     */
    async slackPush(text, channel, botname) {
        const slack_webhook_url = process.env.SLACK_WEBHOOK_URL;
        if (!slack_webhook_url || !channel || !botname || !text) {
            return;
        }
        try {
            const body = {
                username: botname,
                channel: channel,
                text: text
            };

            const response = await fetch(
                slack_webhook_url,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(body)
                }
            );

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Slack push error:', error.message);
            return false;
        }
    }

    async loadState() {
        try {
            await fs.access(this.stateJsonPath);
            const stateBinary = await fs.readFile(this.stateJsonPath, 'utf8');
            const savedState = JSON.parse(stateBinary);

            // Only load if not completed and not too old (2 days)
            const now = new Date();
            const twoDaysBefore = new Date(new Date().setDate(now.getDate() - 2));

            if (!savedState.end && savedState.exit_time && new Date(savedState.exit_time) > twoDaysBefore) {
                this.state = savedState;
                logger.info(`State loaded successfully from previous session: ${JSON.stringify(this.state)}`);
            } else {
                logger.info("Starting fresh - previous state was completed or too old");
            }
        } catch (error) {
            if (error.code === 'ENOENT') {
                logger.info("No previous state file found, starting fresh");
            } else if (error instanceof SyntaxError) {
                logger.error("Invalid JSON in state file, starting fresh");
            } else {
                logger.error(`Error loading state: ${error.message}`);
            }
        }
    }

    async saveState() {
        try {
            const stateJson = JSON.stringify(this.state, null, 2);
            logger.info("writing state to file");
            await fs.writeFile(this.stateJsonPath, stateJson, 'utf8');
            logger.info("State saved successfully");
        } catch (error) {
            logger.error(`ERR_SAVING_STATE: ${error?.message}`)
        }
    }

    async sendToQueue(event_ids, metadata) {
        let tries = 0;

        while (tries < 3) {
            // Build log prefix: /city/category/page
            const city = metadata?.place || 'unknown';
            const category = metadata?.slug || 'unknown';
            const page = metadata?.page || 'unknown';
            const logPrefix = `/${city}/${category}?page=${page}`;

            try {
                const response = await fetch('https://allevents.in/api/index.php/events/eventbrite/insert_events', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ event_ids })
                });

                const body = await response.json();

                if (response.status !== 200) {
                    logger.error(`🚫 HTTP error sending data to queue for ${logPrefix}: Status ${response.status}`);
                    logger.error("error.json", body);
                }

                if (body.error === 0) {
                    logger.info(`✅🚀 Sent & inserted ${body?.data?.updated_ids}/${body?.data?.count_of_ids} ids for ${logPrefix} to queue`);
                    return true;
                } else {
                    logger.warn(`🚫 API error for ${logPrefix}: ${body.message}`);
                }

            } catch (e) {
                logger.error(e.message, e);
                logger.error(`🔥 Error on send queue for ${logPrefix}`);
            }

            tries++;
            await this.randomDelay("M", "on sendToQueue retry");
        }

        return false;
    }

    async init() {
        try {
            logger.info(`SCRIPT started @[${new Date().toISOString()}]`);

            // check if data folder exists then create
            try {
                const stats = await fs.stat(this.dataFolderPath);

                if (stats.isDirectory()) {
                    logger.info(`data folder exists - ${this.dataFolderPath}`);
                }

            } catch (error) {
                if (error.code === "ENOENT") {
                    logger.error("data folder doesn't exist. Creating folder...");

                    try {
                        await fs.mkdir(this.dataFolderPath, { recursive: true });
                        logger.info("Folder creation success");
                    } catch (mkdirError) {
                        logger.error(`Error creating folder: ${mkdirError}`);
                    }
                } else {
                    logger.error("error checking for 'data' directory existence!")
                }
            }

            this.browser = await chromium.launchPersistentContext("", {
                headless: process.env.NODE_ENV !== false,
                channel: "chromium",
                proxy: process.env.PROXY_URL ?
                    {
                        server: process.env.PROXY_URL,
                        username: process.env.PROXY_USERNAME,
                        password: process.env.PROXY_PASSWORD,
                    } :
                    undefined,
                userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                extraHTTPHeaders: {
                    'Accept-Language': 'en-US,en;q=0.9',
                    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Connection': 'keep-alive',
                    'Upgrade-Insecure-Requests': '1'
                },
                locale: 'en-US',
                timezoneId: 'America/New_York',
                args: [
                    // Extension (keep if you really need it)
                    `--disable-extensions-except=${this.extPath}`,
                    `--load-extension=${this.extPath}`,
                  
                    // REQUIRED on most cloud servers
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                  
                    // Reduce detection (optional)
                    '--disable-blink-features=AutomationControlled',
                  
                    // Stability on Linux servers
                    '--disable-dev-shm-usage',
                  
                    // Security model simplification (optional)
                    '--disable-features=IsolateOrigins,site-per-process',
                ],                  
                viewport: { width: 1920, height: 1080 },
            });

            // ON close handler for unexpected browser exits
            this.browser.on("close", async (bc) => {
                this.browserIsClosed = true;
                logger.error("Browser has been closed!");

                if (this.browser) {
                    await this.cleanUp();
                }

                process.exit(1);
            });
        } catch (error) {
            logger.error(`Failed to initialize browser: ${error.message}`);
            throw new Error(`Browser initialization failed: ${error.message}`);
        }

        this.browser.setDefaultTimeout(this.DEFAUL_TIMEOUT);

        logger.info("Browser initialized!");
    }

    async cleanUp() {
        logger.info("Closing the browser!");

        await this.page?.close();
        await this.browser?.close();
    }

    async scrapePage(place, slug, page_index, pageStateCallback) {
        let retry = 0;
        const MAX_RETRIES = 3;
        let noMoreEvents = false;
        let currentPagination = page_index || 1;
        let url = `https://www.eventbrite.com/d/${place.toLowerCase()}/${slug.toLowerCase()}/?page=${currentPagination}`;

        try {
            this.page = await this.browser.newPage();
        } catch (error) {
            logger.error(`Failed to create new page for ${place}/${slug}: ${error.message}`);
            throw error;
        }

        // Bypass webdriver detection
        await this.page.addInitScript(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
        });

        try {
            await this.page.goto(url, {
                waitUntil: "domcontentloaded"
            });

            logger.info(`NEW URL OPENED - ${url}`);
        } catch (error) {
            logger.error(`NEW_PAGE_OPEN_ERROR: ${error.message}`);
        }

        while (!noMoreEvents && currentPagination <= this.MAX_PAGINATION) {
            let sent = false;
            try {
                await this.randomDelay('S', "waiting for event cards");
                const eventCard = await this.page.waitForSelector('.event-card__horizontal').catch(() => null);

                /* wait for this element to appear, indicating the next page button is ready */
                const horizontal_events = await this.page.waitForSelector(".DiscoverHorizontalEventCard-module__priceWrapper___3rOUY").catch(() => false);
                if (horizontal_events === false) {
                    await this.randomDelay("S", "after price element not found");
                }

                const event_ids = await this.page.evaluate(() => {
                    const event_ids = new Set();
                    const atags = document.querySelectorAll("a[href*='/e/']");  
                    console.log(atags);
                    atags.forEach(atag => { 
                        const url = new URL(atag.href);
                        const match = url.pathname.match(/-(\d+)$/);

                        const id = match ? parseInt(match[1]) : NaN;

                        if (!isNaN(id)) {
                            event_ids.add(id);
                        }
                    });

                    const btn = document.querySelector('[data-testid="page-next"]');
                    if (btn) {
                        btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }

                    return Array.from(event_ids)
                });

                /* SEND to Q */
                if (event_ids.length > 0) {
                    sent = await this.sendToQueue(event_ids, {
                        place,
                        page: currentPagination,
                        slug
                    });

                    await this.randomDelay('S', "after event id's sent");
                } else {
                    logger.info("Empty array of id's found!");
                }

                let nextPageButton = this.page.getByTestId('page-next');

                if (await nextPageButton.isVisible() || event_ids.length) {
                    currentPagination++;
                    await pageStateCallback(currentPagination);
                    logger.info("Clicking next button!");
                    await nextPageButton.click();
                } else {
                    logger.info("No more events found.");
                    noMoreEvents = true;
                }
            } catch (error) {
                logger.error(`Error in scrapePage for ${place}/${slug} at page ${currentPagination}: ${error.message}\n${error.stack}`);

                if (sent || retry >= MAX_RETRIES) {
                    if (retry >= MAX_RETRIES) {
                        logger.error(`Max retries reached for ${place}/${slug} page ${currentPagination}, skipping`);
                    }
                    retry = 0;
                    currentPagination++;
                    await pageStateCallback(currentPagination);
                    break;
                } else {
                    retry++;
                    logger.info(`retrying ${place}/${slug} - page ${currentPagination}:`)

                    try {
                        await this.page.reload({ waitUntil: 'domcontentloaded' });
                        logger.info(`Page reloaded for retry attempt ${retry}`);
                    } catch (reloadError) {
                        logger.warn(`Failed to reload page: ${reloadError.message}`);
                    }
                    await this.randomDelay('M', "after page reload for retry");
                }
            }
        }

        await this.page.close();
        this.page = null;
    }

}

module.exports = EventBriteCore;
