# Drew's Cafe — PHP Backend (Supabase replacement)

Kompletong PHP + MySQL API na papalit sa Supabase (Auth, Database, Storage,
at ang 3 Edge Functions para sa GCash/PayMongo payments). Ang React/Expo
frontend mo ay pinapanatili — babaguhin mo lang ang `src/lib/*.ts` files
para tumawag dito sa halip na sa Supabase. Tingnan ang
`frontend-integration-example.ts`.

Na-test na ang lahat ng endpoint (register, login, menu CRUD, orders,
role-based access, staff management, image upload) gamit ang live MySQL +
PHP built-in server.

## 1. Requirements

- PHP 8.1+ na may `pdo_mysql` at `curl` extensions
- MySQL 5.7+ / MariaDB 10.3+
- Isang web server (Apache/Nginx) o kahit `php -S` para sa dev

## 2. Setup

```bash
# 1. I-import ang schema
mysql -u root -p your_db < schema.sql

# 2. Kopyahin ang .env.example → .env at punan ng totoong values
cp .env.example .env
nano .env

# 3. (Dev only) subukan gamit ang built-in server
php -S 127.0.0.1:8000
```

May seed admin account na (`admin@drewscafe.test` / `Admin123!`) — mag-login
agad at baguhin ang password, o mag-INSERT ng sarili mong admin row.

## 3. Deployment (shared hosting / cPanel / VPS)

1. I-upload ang buong `php-backend/` folder (maliban sa `.env.example`) sa
   server mo, hal. `public_html/api/`.
2. Gawin ang `uploads/menu-images/` na writable ng web server user
   (`chmod 755` sana sapat na; `775` kung kailangan).
3. Punan ang `.env` gamit ang totoong DB credentials, `APP_BASE_URL` (URL
   papunta mismo dito, hal. `https://yourdomain.com/api`), at
   `UPLOADS_BASE_URL`.
4. Kunin ang `PAYMONGO_SECRET_KEY` mula sa PayMongo Dashboard, at i-set up
   ang webhook doon (tingnan ang comment sa `api/payments/webhook.php`)
   papunta sa `https://yourdomain.com/api/payments/webhook.php`.
5. Sa Expo app mo, itakda ang `EXPO_PUBLIC_API_BASE_URL=https://yourdomain.com/api`.

Sinasama ang `.htaccess` na nagba-block ng direktang HTTP access sa `.env`,
`.sql`, at `lib/`.

## 4. Endpoint reference

Lahat ng POST endpoints ay tumatanggap ng JSON body
(`Content-Type: application/json`). Mag-attach ng
`Authorization: Bearer <token>` kung kailangan ng sign-in.

| Method | Path | Auth | Ginagawa |
|---|---|---|---|
| POST | `/auth/register.php` | — | Gumawa ng bagong customer account |
| POST | `/auth/login.php` | — | Mag-sign in, ibinabalik ang bearer token |
| POST | `/auth/logout.php` | any | Bawiin ang kasalukuyang token |
| GET | `/auth/me.php` | any | Kunin ang sariling profile (app load) |
| POST | `/auth/update-profile.php` | any | I-update ang pangalan/phone |
| GET | `/menu/available.php` | — | Menu items na `available=true` (customer view) |
| GET | `/menu/all.php` | staff/admin | Lahat ng menu items |
| POST | `/menu/create.php` | staff/admin | Gumawa ng bagong item |
| POST | `/menu/update.php` | staff/admin | Partial update ng item |
| POST | `/menu/delete.php` | staff/admin | Burahin ang item |
| POST | `/menu/upload-image.php` | staff/admin | Multipart upload, `file` field, ibinabalik ang public URL |
| POST | `/orders/place.php` | any | Gumawa ng order + line items |
| GET | `/orders/customer.php` | any | Sariling order history |
| GET | `/orders/all.php` | staff/admin | Lahat ng orders + customer info |
| POST | `/orders/update-status.php` | staff/admin | Baguhin ang kitchen status |
| GET | `/orders/status.php?id=` | any | Poll ng `payment_status` (GCash checkout flow) |
| GET | `/orders/today-sales.php` | staff/admin | Dashboard summary card |
| GET | `/orders/sales-report.php` | staff/admin | Today/week/month + top items |
| GET | `/staff/list.php` | admin | Lahat ng staff/admin accounts |
| GET | `/staff/find-by-email.php?email=` | admin | Hanapin ang existing user bago i-promote |
| POST | `/staff/set-role.php` | admin | Baguhin ang role ng user |
| POST | `/payments/create-gcash-source.php` | any | Simulan ang PayMongo GCash checkout |
| POST | `/payments/webhook.php` | PayMongo signature | Tinatanggap ang PayMongo webhook events |
| GET | `/payments/payment-redirect.php` | — | HTTP(S) bridge pabalik sa app pagkatapos ng checkout |

## 5. Anong iba sa Supabase version

- **Auth**: opaque bearer tokens (`sessions` table) sa halip na Supabase
  JWT. Client-side, `AsyncStorage` pa rin ang gagamitin — token na lang
  ang laman sa halip na buong Supabase session object.
- **Row Level Security**: wala nang Postgres RLS; ang access control ay
  ginagawa ngayon sa PHP gamit ang `require_role()` / `require_user()` sa
  bawat endpoint.
- **Storage**: mga larawan ay naka-save na sa `uploads/menu-images/` sa
  server mismo (hindi na sa Supabase Storage bucket).
- **Edge Functions**: ang 3 Deno function (`create-gcash-source`,
  `paymongo-webhook`, `payment-redirect`) ay direktang na-port sa
  `api/payments/*.php`, kasama ang HMAC signature verification.

## 6. Ano ang kailangan mo pang gawin sa frontend

Ang `src/lib/supabase.ts` at ang mga file na gumagamit nito
(`menu.ts`, `orders.ts`, `staff.ts`, `payments.ts`, `AuthContext.tsx`) ay
kailangan mong palitan ng mga tawag papunta dito. Tingnan ang
`frontend-integration-example.ts` — halimbawa lang iyon ng pattern, hindi
kompletong file-by-file rewrite ng buong app.
