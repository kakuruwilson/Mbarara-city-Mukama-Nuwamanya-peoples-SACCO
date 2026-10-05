# Kaguta Farmers SACCO — Management System

A responsive SACCO management system that runs on Windows 7/10, desktop/laptop PCs, and Android phones (via browser), both online and offline once installed locally.

## 1. Install XAMPP or WAMP

1. Download and install [XAMPP](https://www.apachefriends.org/) (works on Windows 7+) or WAMP.
2. Start the **Apache** and **MySQL** modules from the XAMPP/WAMP control panel.

## 2. Copy the project files

Copy the entire `SACCO-System` folder into your server's web root:
- XAMPP: `C:\xampp\htdocs\SACCO-System`
- WAMP: `C:\wamp64\www\SACCO-System`

## 3. Import the database

1. Open `http://localhost/phpmyadmin` in your browser.
2. Create a database named `sacco_db` (or let the import do it — the SQL file includes `CREATE DATABASE IF NOT EXISTS`).
3. Import `database/sacco.sql`.
4. **Important:** the seed admin account in `sacco.sql` has a placeholder password hash. Generate a real one by running this once, anywhere PHP is available:
   ```php
   <?php echo password_hash('your-chosen-password', PASSWORD_DEFAULT);
   ```
   Then update the `password_hash` column for the `admin` user in phpMyAdmin with the generated value.

## 4. Go fully offline (no internet needed at all)

Two things in this build currently load from the internet and should be swapped for local copies before deploying somewhere without connectivity:

- **Google Fonts** — the `@import` at the top of `css/style.css`. Download the Inter and Poppins font files and replace it with local `@font-face` rules, or simply delete the import to fall back to system fonts.
- **Font Awesome icons** — referenced as `icons/fontawesome/css/all.min.css` on every page. Download the free "Web Fonts + CSS" package from fontawesome.com and extract it into `icons/fontawesome/`.
- **Chart.js** — referenced as `js/vendor/chart.min.js` on `dashboard.html` and `reports.html`. Download the UMD build from chartjs.org and place it at that path.

Once those three assets are stored locally, the entire system runs with zero internet dependency.

## 5. Access from other computers on the same network

On the server machine, find its local IP (`ipconfig` on Windows, look for something like `192.168.1.x`). Other computers/phones on the same Wi-Fi/LAN can then open:

```
http://192.168.1.x/SACCO-System/
```

## 6. Going online later

Because the frontend is plain HTML/CSS/JS talking to PHP over HTTP, this same codebase can be uploaded to any shared PHP/MySQL web host later with no rework beyond updating `php/config.php` with the host's database credentials.

## Folder structure

```
SACCO-System/
├── index.html, login.html, register.html, forgot-password.html
├── dashboard.html, members.html, loans.html, savings.html,
│   transactions.html, reports.html, settings.html
├── member-dashboard.html  (member portal home — build out member-savings.html,
│                           member-loans.html etc. following the same pattern)
├── css/  (style.css, dashboard.css, responsive.css)
├── js/   (app.js, charts.js, validation.js, vendor/chart.min.js)
├── images/, icons/
├── database/sacco.sql
└── php/  (config.php, login.php, logout.php, register.php, members.php,
           loans.php, savings.php, includes/auth.php)
```

## Still to build out

- `member-savings.html`, `member-loans.html`, `member-transactions.html`, `member-notifications.html`, `member-profile.html` — linked from the member sidebar but not yet built; follow `member-dashboard.html`'s pattern.
- `php/shares.php` and `php/settings.php` — referenced by forms in `savings.html` and `settings.html`, not yet written.
- PDF/Excel export, SMS/email notifications, biometric/PIN login, and automatic backup — listed as advanced features to add next.
