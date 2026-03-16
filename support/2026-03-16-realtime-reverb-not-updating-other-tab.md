# Support: Reverb realtime — other tab does not update

**Date:** 2026-03-16  
**Status:** Investigation guide  
**Issue:** Two web tabs open; creating/updating a thought in one tab does not update the other tab.

**Environments:** This doc covers both **local** and **production (Laravel Cloud)**. For production, jump to [§ Production (Laravel Cloud)](#production-laravel-cloud) first.

## How realtime is supposed to work

- **Tab A:** Open Stream, Home (recent thoughts), or Ideas.
- **Tab B (or MCP):** Create a new thought.
- **Expected:** Tab A’s list refreshes automatically (with Reverb: almost immediately; with polling: within ~20 seconds).

If Tab A never updates, work through the checklist below.

---

## 1. Confirm you’re using Reverb (not polling)

Realtime can run in two modes. For **instant** updates you must be in **reverb** mode.

**Check:**

- In `.env` you should have:
  - `REALTIME_DRIVER=reverb`
  - `BROADCAST_CONNECTION=reverb`
- If either is missing or set to `polling`, the app uses **polling** (refresh every ~20s). That can feel like “the other tab doesn’t update” if you expect instant updates.

**Fix:** Set both and restart the app (e.g. `php artisan config:clear` and reload the page).

---

## 2. Reverb server must be running

The Reverb WebSocket server is a separate process. If it’s not running, the browser never gets events.

**Check:**

- Run: `php artisan reverb`
- Leave it running in a terminal. You should see something like “Reverb server started on 0.0.0.0:8080” (or your configured port).

**Fix:** Start Reverb before testing. For production, use Laravel Cloud WebSockets or run Reverb as a service.

---

## 3. Reverb env vars (so the front end can connect)

The browser connects to Reverb using values from your broadcasting config. If these are wrong or missing, Echo never connects.

**Check in `.env`:**

- `REVERB_APP_ID`
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`
- `REVERB_HOST` — for **local**: usually `localhost` or `127.0.0.1`
- `REVERB_PORT` — for **local**: usually `8080` (match `REVERB_SERVER_PORT` in `config/reverb.php` or Reverb’s actual port)
- `REVERB_SCHEME` — for **local**: use `http` (so the client uses `ws://` not `wss://`)

If you use `php artisan reverb:install` it can add these; for local you may need to set `REVERB_HOST=localhost`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`.

**Check in the browser:**

- Open DevTools → Console.
- Type: `window.ideatub?.realtime`
- You should see an object with `driver: 'reverb'`, `reverb_key` (non-null), `reverb_host`, `reverb_port`, `reverb_scheme`, `user_id`, etc.
- If `driver` is `'polling'` or `reverb_key` is null, the front end will not use Echo (see step 1 and env vars above).

---

## 4. Echo must connect to Reverb (WebSocket)

Even with the right config, Echo can fail to connect (wrong host/port, TLS, or CORS).

**Check in the browser:**

- DevTools → Network → filter by “WS” (WebSockets).
- Reload a page that has realtime (e.g. Stream).
- You should see a WebSocket request to your Reverb host/port (e.g. `ws://localhost:8080/...` or `wss://...`). Status should be “101” or similar (connected).
- If there is no WS request or it fails (e.g. connection refused), the client is not talking to Reverb.

**Typical local fixes:**

- **Wrong port:** Set `REVERB_PORT=8080` (or whatever port Reverb prints on start) and ensure `config/broadcasting.php` passes it to the client (it uses `REVERB_PORT`).
- **Wrong host:** If the app runs at `http://ideatub.test`, set `REVERB_HOST=ideatub.test` so the browser connects to `ws://ideatub.test:8080`. Using `127.0.0.1` in the app but opening the site at `localhost` can also cause mismatches.
- **TLS:** Locally use `REVERB_SCHEME=http` so the client uses `ws://` (no TLS).

---

## 5. Broadcasting auth (`/broadcasting/auth`)

Private channels require the browser to authenticate. If this fails, Echo won’t subscribe and you won’t get events.

**Check:**

- In Network, when the WebSocket connects you should see a POST to `/broadcasting/auth` with status **200**. If it’s **401** or **403**, the subscription to the private user channel will fail.
- **401:** Not logged in in that tab (session/cookie).
- **403:** Channel authorization failed (e.g. wrong channel name or user).

**Fix:** Ensure you’re logged in in the tab that should receive updates, and that you’re testing with the same user in both tabs.

---

## 6. Which tab is supposed to update?

Realtime only **refetches the list** on these pages when a **new thought** is created:

- **Stream** — list of thoughts (all or by tag)
- **Home** — “Recent thoughts” (only when you’re **not** in search results; if you have `?q=...` in the URL, realtime refetch does not run for that view)
- **Ideas** — list of ideas

**Check:**

- The tab you expect to update is actually on one of these pages (Stream, Home with no search, or Ideas).
- You’re creating a **new thought** (e.g. from the other tab or via MCP). Editing a thought (e.g. changing tags) does **not** trigger a broadcast in the current implementation; only **creation** does.

---

## 7. Quick local Reverb checklist

Use this for a minimal local test:

1. **.env**
   - `REALTIME_DRIVER=reverb`
   - `BROADCAST_CONNECTION=reverb`
   - `REVERB_APP_ID=1` (or any non-empty value)
   - `REVERB_APP_KEY=ideatub-key` (or any non-empty value)
   - `REVERB_APP_SECRET=ideatub-secret` (or any non-empty value)
   - `REVERB_HOST=localhost`
   - `REVERB_PORT=8080`
   - `REVERB_SCHEME=http`

2. **Start Reverb:** `php artisan reverb` (leave it running).

3. **Clear config:** `php artisan config:clear`.

4. **Browser:** Open Stream (or Home with no search) in Tab A. Open Home in Tab B. In Tab B, submit a new thought. Tab A should refetch and show the new thought within a second or two.

5. **If Tab A still doesn’t update:** In Tab A open DevTools → Console and check for errors; Network → WS to confirm the WebSocket is connected and that POST `/broadcasting/auth` returned 200.

---

## 8. If you prefer not to run Reverb locally (polling)

You can rely on **polling** instead:

- Leave `REALTIME_DRIVER=polling` (or unset).
- No need to run `php artisan reverb`.
- The other tab will still update, but only every **~20 seconds** (polling interval). So create a thought and wait up to 20s; the other tab should then refresh the list.

If polling is working, you’ll see periodic requests to `/api/thoughts/realtime-check?since=...` in the Network tab.

---

## Production (Laravel Cloud)

On **production** you don’t run `php artisan reverb` yourself. Laravel Cloud runs Reverb for you when you attach a **WebSocket application** to your environment.

### 1. WebSocket app must be attached

**Check:**

- Laravel Cloud dashboard → your **project** → **environment** (e.g. production).
- Under **Resources** or **WebSockets**, confirm a **WebSocket application** (Reverb) is **attached** to this environment.
- If there is no WebSocket app, the browser has nothing to connect to and the other tab will not update (unless polling is used).

**Fix:**

- Add a WebSocket application to the environment (e.g. “Add resource” → WebSockets / Reverb).
- Choose region and connection limits as needed. Cloud will inject the Reverb env vars (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, etc.) into the environment.
- Redeploy so the web app gets the new variables.

Docs: [Laravel Cloud WebSockets](https://cloud.laravel.com/docs/resources/websockets).

### 2. Production env vars for realtime

**Check in Laravel Cloud → Environment variables:**

- `REALTIME_DRIVER=reverb` — without this, the app uses **polling** (other tab updates only every ~20s).
- `BROADCAST_CONNECTION=reverb` — without this, the Laravel app won’t broadcast to Reverb.

After attaching the WebSocket app, Cloud usually sets the `REVERB_*` variables automatically. You only need to set:

- `REALTIME_DRIVER=reverb`
- `BROADCAST_CONNECTION=reverb`

(unless your Cloud setup uses different names).

**Fix:** Add or update those two in the production environment, then **redeploy** so the new values are in effect.

### 3. Verify what the browser gets (production)

**Check on the live site:**

- Open your production app (e.g. https://yourapp.cloud.laravel.com).
- Open DevTools → **Console** and run: `window.ideatub?.realtime`
- You should see:
  - `driver: 'reverb'`
  - `reverb_key` (non-null string)
  - `reverb_host`, `reverb_port`, `reverb_scheme` (matching Cloud’s Reverb endpoint)
  - `user_id` (number)

If `driver` is `'polling'` or `reverb_key` is null, the front end is not in Reverb mode (see steps 1 and 2).

### 4. WebSocket connection on production

**Check:**

- DevTools → **Network** → filter **WS**.
- Reload a page that uses realtime (e.g. Stream).
- You should see a WebSocket to the Reverb host (e.g. `wss://...`) with status **101** (or similar “connected”).

If there is no WS request or it fails (e.g. 4xx/5xx or connection error):

- **Mixed content:** App is `https://` but Reverb is `ws://` or wrong host → fix `REVERB_SCHEME` / `REVERB_HOST` so the client uses `wss://` and the correct host.
- **Wrong host/port:** Cloud injects the correct Reverb host/port when the WebSocket app is attached. If you overrode them, remove overrides or set them to the values Cloud provides.
- **CORS / cookies:** Ensure the site and the WebSocket are on the same domain or that Reverb allows your app’s origin; session cookie must be sent for `/broadcasting/auth`.

### 5. Broadcasting auth on production

Same as local: in Network, when the WebSocket connects there should be a POST to **`/broadcasting/auth`** with status **200**. If it’s **401** or **403**, the private channel subscription fails and the other tab won’t get events. Ensure you’re logged in in the tab that should update.

### 6. Quick production checklist

1. **Laravel Cloud:** WebSocket (Reverb) app is **attached** to the environment.
2. **Env vars:** `REALTIME_DRIVER=reverb` and `BROADCAST_CONNECTION=reverb` are set; **redeploy** after changing them.
3. **Browser:** On the production URL, `window.ideatub?.realtime` shows `driver: 'reverb'` and a non-null `reverb_key`.
4. **Network:** WS request to Reverb succeeds; POST `/broadcasting/auth` returns 200.
5. **Tab:** The tab that should update is on **Stream**, **Home** (no search), or **Ideas**, and you’re creating a **new thought** in the other tab (not editing).

If all of the above are correct and the other tab still doesn’t update, check application logs on Laravel Cloud for broadcast or Reverb errors.

### 7. Event received but list doesn’t update

If in **Network** you see the WebSocket message with `"event":"App\\Events\\ThoughtCreated"` (or similar) but the Stream/Home/Ideas list does not refresh, the client listener may not be matching the event name. The front end listens for the custom event name **`ThoughtCreated`** (via `broadcastAs()` on the event). If the event class does not define `broadcastAs()` returning `'ThoughtCreated'`, Laravel sends the full class name and the listener won’t fire. Fix: add `public function broadcastAs(): string { return 'ThoughtCreated'; }` to `App\Events\ThoughtCreated`.
