/**
 * CDP 直驱的视觉验证脚本（零依赖，Node 24 内置 WebSocket）。
 *
 * 用法：VERIFY_BASE=http://127.0.0.1:9501 node verify-home.mjs [输出目录]
 * 产出：桌面 1440 与移动 390 两张截图，并返回横向溢出等量测结果。
 */

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';

import { mkdir, writeFile } from 'node:fs/promises';

const createPage = async (url) => {
    const res = await fetch(`${CDP}/json/new?${encodeURIComponent(url)}`, { method: 'PUT' });
    if (!res.ok) throw new Error(`创建页面失败: ${res.status}`);
    return res.json();
};

const connect = (wsUrl) =>
    new Promise((resolve, reject) => {
        const ws = new WebSocket(wsUrl);
        ws.onopen = () => resolve(ws);
        ws.onerror = (e) => reject(new Error('WebSocket 失败: ' + (e.message || '')));
    });

let nextId = 0;
const send = (ws, method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++nextId;
        const onMessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.id !== id) return;
            ws.removeEventListener('message', onMessage);
            if (data.error) reject(new Error(`${method}: ${data.error.message}`));
            else resolve(data.result);
        };
        ws.addEventListener('message', onMessage);
        ws.send(JSON.stringify({ id, method, params }));
    });

const evaluate = async (ws, expression) => {
    const result = await send(ws, 'Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });
    if (result.exceptionDetails) {
        throw new Error('页面脚本异常: ' + JSON.stringify(result.exceptionDetails.text));
    }
    return result.result.value;
};

const waitFor = async (ws, expression, timeoutMs = 10000) => {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        if (await evaluate(ws, expression)) return true;
        await new Promise((r) => setTimeout(r, 120));
    }
    return false;
};

const capture = async (ws, path, width, height, mobile) => {
    await send(ws, 'Emulation.setDeviceMetricsOverride', {
        width,
        height,
        deviceScaleFactor: mobile ? 2 : 1,
        mobile,
    });
    await evaluate(ws, 'window.scrollTo(0,0)');
    await new Promise((r) => setTimeout(r, 350));
    const shot = await send(ws, 'Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: false,
        fromSurface: true,
    });
    await writeFile(path, Buffer.from(shot.data, 'base64'));

    return evaluate(
        ws,
        `(() => {
            const doc = document.documentElement;
            return {
                scrollWidth: doc.scrollWidth,
                clientWidth: doc.clientWidth,
                overflowX: doc.scrollWidth - doc.clientWidth,
                bodyHeight: document.body.scrollHeight,
                viewport: { w: window.innerWidth, h: window.innerHeight },
            };
        })()`
    );
};

const main = async () => {
    await mkdir(OUT, { recursive: true });

    const page = await createPage('about:blank');
    const ws = await connect(page.webSocketDebuggerUrl);

    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');

    await send(ws, 'Page.navigate', { url: BASE + '/' });
    const mounted = await waitFor(ws, `document.querySelectorAll('main').length > 0 && document.readyState === 'complete'`);
    if (!mounted) throw new Error('页面未完成挂载');

    const desktop = await capture(ws, `${OUT}/home-desktop.png`, 1440, 960, false);
    const mobile = await capture(ws, `${OUT}/home-mobile.png`, 390, 844, true);

    const details = await evaluate(
        ws,
        `(() => {
            const css = document.querySelector('link[rel=stylesheet]');
            const body = document.body;
            const style = getComputedStyle(body);
            return {
                title: document.title,
                cssHref: css ? css.getAttribute('href') : null,
                bg: style.backgroundColor,
                color: style.color,
                font: style.fontFamily,
                heading: (document.querySelector('h1') || {}).textContent?.trim() || null,
                stylesheetLoaded: css ? css.sheet !== null : false,
                cssRules: css && css.sheet ? css.sheet.cssRules.length : 0,
                hasHorizontalScrollbar: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        })()`
    );

    console.log(JSON.stringify({ desktop, mobile, details }, null, 2));

    await send(ws, 'Page.close').catch(() => {});
    ws.close();
};

main().catch((error) => {
    console.error('验证失败:', error.message);
    process.exit(1);
});
