/**
 * Bridge to the Telegram Bot API for servers that cannot reach api.telegram.org (it is filtered in Iran).
 *
 * The bot calls https://<this worker's address>/bot<token>/<method> exactly as it would call Telegram,
 * and the worker forwards the request untouched. It only serves the bot whose numeric ID is in the
 * BOT_ID variable (the part of the token before ":"), so it cannot be used as an open proxy.
 * The token itself is never stored here.
 */
export default {
    async fetch(request, env) {
        const url = new URL(request.url);

        if (url.pathname === '/' || url.pathname === '/health') {
            return new Response('ok');
        }

        const match = url.pathname.match(/^\/(?:file\/)?bot(\d+):[A-Za-z0-9_-]+\/[A-Za-z0-9_./-]+$/);
        if (!match || match[1] !== String(env.BOT_ID)) {
            return new Response('Not found', { status: 404 });
        }

        return fetch(new Request(`https://api.telegram.org${url.pathname}${url.search}`, request));
    },
};
