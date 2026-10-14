# Test site: shop pages on Vercel, API on Railway

Vercel serves the React shop pages. It cannot run the Laravel API: it has no MySQL, no always-on
worker for emails and texts, no timer every few minutes, and no lasting disk for seller photos. Railway
runs the API from `backend/Dockerfile`, with a MySQL database and a disk (volume) for photos.

```
Browser ──> https://<shop>.vercel.app          (frontend/, static pages)
   └──────> https://<api>.up.railway.app/api    (backend/ container: web server + queue worker + scheduler)
                 ├── MySQL (Railway database)
                 └── /var/www/html/storage/app  (Railway volume: photos)
```

This is for testing with people you invite. Demo accounts can be added, payments go to a built-in
test page where no money moves, and emails are only written to the logs. Testers should type made-up
names, addresses and phone numbers: everyone who knows the demo password can open the demo customer's
orders and addresses. Read "Going live later" before real customers use it.

You need: a GitHub login with access to `fikremariamF/spotlight-project`, a Railway account
(railway.com, sign in with GitHub) and a Vercel account (vercel.com, sign in with GitHub). Railway has a
trial credit and then a paid plan; Vercel's free Hobby plan is meant for non-commercial use. Check their
pricing pages for current terms.

The `main` branch is still empty while the feature branches wait to be merged, so both hosts are pointed
at the branch `feat/test-deploy` below. After everything is merged into `main`, switch both to `main`.

## 1. Make two secrets on your PC

In PowerShell, run this and keep the output (it is the app's encryption key, `APP_KEY`):

```powershell
$b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); 'base64:' + [Convert]::ToBase64String($b)
```

Also choose two passwords, at least 12 characters with letters, numbers and symbols:

- `ADMIN_PASSWORD` for your admin login
- `DEMO_USER_PASSWORD` for the demo sellers and customer

The site is on the public internet, so do not reuse the local demo passwords (`Demo-pass-123`,
`Admin-pass-123!`).

## 2. Railway: API, database and photo disk

1. railway.com, **New Project**, **Deploy from GitHub repo**, choose `spotlight-project`. Allow Railway's
   GitHub app to see the repository if asked. The first build fails because `main` is empty; that is fine.
2. Open the new service, **Settings**:
   - **Source**: Root Directory `/backend`, Branch `feat/test-deploy`.
   - **Networking**: **Generate Domain** with port `8080`. Note the address, for
     example `https://spotlight-api-production.up.railway.app`.
   - **Region** (if offered): EU West, the closest to Addis Ababa.
3. On the project canvas, **Create**, **Database**, **MySQL**.
4. Right-click the API service, **Attach volume**, mount path `/var/www/html/storage/app`.
   Without it, photos disappear on every deploy (the deploy log warns about this).
5. API service, **Variables**, **Raw Editor**, paste and fill in the `<...>` parts. If you do not know the
   Vercel address yet, use `https://<the name you will give the Vercel project>.vercel.app` and fix it in
   step 4.

   ```
   PORT=8080
   APP_ENV=staging
   APP_DEBUG=false
   APP_KEY=<output of step 1>
   APP_URL=https://<shop>.vercel.app
   API_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
   CORS_ALLOWED_ORIGINS=https://<shop>.vercel.app
   TRUSTED_PROXIES=REMOTE_ADDR
   DB_CONNECTION=mysql
   DB_URL=${{MySQL.MYSQL_URL}}
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   ADMIN_EMAIL=<your email>
   ADMIN_PASSWORD=<your admin password>
   SEED_DEMO=true
   DEMO_USER_PASSWORD=<demo password>
   MARKETPLACE_NAME=Betoch Furniture
   MARKETPLACE_PAYMENT_GATEWAY=fake
   MAIL_MAILER=log
   ```

   `${{...}}` are Railway references: the API's own domain and the database address, filled in by
   Railway. If your database service is not named `MySQL`, use its name instead.
6. **Deploy** (or wait for the automatic one). The deploy log shows `[start]` lines and ends with
   `Web server, queue worker and scheduler starting.` Opening `https://<api>.up.railway.app/up` shows the
   words "Application up" (unstyled: the API sends no page styles), and `/api/categories` shows the
   furniture categories.
7. Sign in with `ADMIN_EMAIL` and `ADMIN_PASSWORD`, change the password in **My account**, then delete
   `ADMIN_EMAIL`, `ADMIN_PASSWORD` and `DEMO_USER_PASSWORD` from the Railway variables and set
   `SEED_DEMO=false`. They are only needed on the first start, and a password left in the variables can
   be read by anyone who gets into the Railway project.

What happens on each start (`backend/docker/start.sh`): settings are cached, database tables are created or
updated, sub-cities and categories are added to a new database, demo data is added once (only with
`SEED_DEMO=true`, never with `APP_ENV=production`), and the admin is created if that email has no account
yet and no other administrator exists. Later starts never reset the admin's password, add a second admin
(even after you change the admin email on the site), or bring back categories or demo listings you deleted.

## 3. Vercel: shop pages

1. vercel.com, **Add New**, **Project**, import `spotlight-project`.
2. **Root Directory**: `frontend`. The rest comes from `frontend/vercel.json` (Vite, `npm run build`, `dist`).
3. **Environment Variables**: `VITE_API_URL` = the Railway address from step 2, for example
   `https://spotlight-api-production.up.railway.app` (no `/api` at the end).
4. Deploy. If the first deploy builds `main` and fails, open **Settings**, **Git**, set **Production
   Branch** to `feat/test-deploy`, then deploy that branch from the **Deployments** page.

`VITE_API_URL` is built into the pages: after changing it, redeploy on Vercel.

## 4. Connect the two

If the Vercel address differs from what you put in step 2.5, change `APP_URL` and
`CORS_ALLOWED_ORIGINS` on Railway to the exact address (with `https://`, nothing after `.app`), then
redeploy the API. Several addresses can be listed in `CORS_ALLOWED_ORIGINS`, separated by commas.

## 5. Check it works

1. Open the Vercel address: the home page shows demo listings with photos.
2. Sign in as `seller1@demo.local` with `DEMO_USER_PASSWORD` and add a listing with a phone photo.
3. Sign in with `ADMIN_EMAIL` and `ADMIN_PASSWORD` and approve that listing. In Admin > Audit log the
   approval should show your real public IP address (search "what is my IP" to compare). If entries from
   different people all show the same unfamiliar address, tell the developer, because sign-in limits
   would then apply to all visitors together. Do not set `TRUSTED_PROXIES=*` as a quick fix: it lets
   anyone pretend to be any address and get around the sign-in limits.
4. Redeploy the API and check the photo is still there (that proves the volume works).
5. As `customer@demo.local`, add an address, buy something with cash on delivery, and once with online
   payment: the test payment page lets you pick paid or failed.
6. Emails are not sent: Railway's deploy log shows them, password reset links included, so only share
   access to the Railway project with people you trust. Texts are listed in Admin > Text messages.

## Optional settings

- Maps and distance pricing: `GOOGLE_MAPS_BROWSER_KEY` and `GOOGLE_MAPS_SERVER_KEY` on Railway. Restrict
  the browser key to `https://<shop>.vercel.app/*` in Google Cloud, and set a daily quota on the Routes
  API there so a mistake cannot run up a bill. The API itself asks Google at most
  `GOOGLE_ROUTES_PER_HOUR` times an hour (default 200); past that, distances are estimated.
- Real emails: `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS` from your mail provider.
- Chapa test mode: `MARKETPLACE_PAYMENT_GATEWAY=chapa`, `CHAPA_SECRET_KEY=CHASECK_TEST-...`,
  `CHAPA_WEBHOOK_SECRET`; webhook URL in the Chapa dashboard `https://<api>.up.railway.app/api/payments/chapa/webhook`.
  Test keys and the built-in test payment page only work while `APP_ENV` is `staging` (or `local`); with
  `production` online payment is switched off until a live key is set.
- Page security: the shop pages send a Content Security Policy from `frontend/vercel.json`. If you add a
  script from another site (analytics, chat), add its address there or the browser blocks it.
- Separate worker: `ROLE` can be `web`, `worker` or `scheduler` to split the jobs into three Railway
  services from the same repository. The default `all` keeps them together, which is simpler and is
  needed while photos are on a volume (a Railway volume belongs to one service).

## When something goes wrong

The API's **Deploy Logs** on Railway explain a failed start in a line starting with `[start] ERROR:`.

| You see | Cause and fix |
| --- | --- |
| `APP_KEY is not set` | Add `APP_KEY` from step 1. |
| `ADMIN_PASSWORD ...` or "The password field must contain..." | Make the admin password longer, with a letter, number and symbol. |
| `Set DEMO_USER_PASSWORD` | Add it, or set `SEED_DEMO=false`. |
| `Database not ready yet` many times, then `Could not reach the database` | Check `DB_URL=${{MySQL.MYSQL_URL}}` and that the MySQL service is running. |
| Shop shows "Network Error" or the browser console says CORS | `CORS_ALLOWED_ORIGINS` must match the Vercel address exactly; redeploy the API after changing it. |
| Pages call the wrong server | `VITE_API_URL` on Vercel; redeploy the pages after changing it. |
| Photos broken | `API_URL` must be the Railway address; photos uploaded before the volume was attached are gone. |
| Too many sign-in attempts for everyone | See check 3 above about `TRUSTED_PROXIES`. |
| Map stays blank, browser console mentions Content Security Policy | Tell the developer the blocked address; it goes in `frontend/vercel.json`. |
| Railway says the app is not responding | The domain's port and the `PORT` variable must both be `8080`. |

## Going live later

Before real customers: `APP_ENV=production` (demo data and the test payment page are then refused), a real
Chapa key, a real mail provider, your own domain on both hosts (update `APP_URL`, `API_URL`,
`CORS_ALLOWED_ORIGINS`, `VITE_API_URL`), Railway database backups, and Vercel's terms for commercial use.
Demo accounts already created stay until you suspend them in Admin > Users: suspend them all, since
their password was shared with testers.
