# PHP + MySQL News Site with Social Share Tracking

Requires **PHP 8.1 or newer** and MySQL / MariaDB (XAMPP includes both).

## Features

**Front-end**
- Home page with the news posts in cards (3 per row), and a page for each post.
  The home page uses keyset (cursor) pagination with **Newer / Older** links, so every page loads equally fast
  however many posts there are (no page numbers, no `COUNT(*)`).
- Share buttons (icons) on every post card and under each post title.
  The buttons are built from the `social_platforms` table (Facebook, X, WhatsApp, Telegram, Email by default).
  To add another social network, insert a row in that table.
- Each button shares the page URL. For example
  `post.php?id=30&title=things_aren_t_going_well_at_all`.
- Page metadata (title, description, image) is provided through Open Graph and Twitter Card tags in
  `includes/header.php`.
- Every click on a share button is sent to `public/track_share.php` with the page URL and the platform.
  The server checks the input, looks up the post title, and stores the click in `share_clicks` together with
  the post id. In the same transaction it adds 1 to the matching row of the daily summary table
  `share_clicks_daily`.

**Back-end admin**
- Login with sessions, a CSRF token and `password_hash()` / `password_verify()`.
- Dashboard with charts: clicks over time, clicks by social media, and most shared pages (top 10),
  plus a "Latest clicks" table (newest 50).
- Filters that apply to every chart, number and table: date range (quick ranges or custom dates),
  social media (checkbox dropdown), page, and group by day / week / month.
- **Download CSV** button: the numbers for the current filters as a file (one row per day, page and social media).
  It opens in Excel and Google Sheets, and streams the rows, so it also works with a very large range.
- The **Page** filter is a search box. It searches the post titles (or a post id) on the server and returns
  only a few matches. It matches the start of a title or the start of a word, not text in the middle of a word.

**Built for large data**
- The charts, numbers and top-10 read `share_clicks_daily` (one row per day, page and platform), not every click,
  so they stay fast with tens of millions of clicks.
- Pages are identified by `post_id` (a small integer), not by their URL.
- Only "Latest clicks" reads `share_clicks`, and only the newest 50 rows, straight from an index.
- The page search never reads the clicks table; it uses indexes on `posts.title`.

## Setup (XAMPP)

1. Install XAMPP and start **Apache** and **MySQL** from the control panel.
2. Copy the project folder into `C:\xampp\htdocs\` (macOS/Linux: the `htdocs` folder of XAMPP).
   The folder can have any name. The examples below use `share_tracker`.
3. Open `http://localhost/phpmyadmin`, go to **Import**, choose `database.sql`, click **Import**.
   This creates the database `moon_news` with the tables and the sample posts.
4. Check `config/config.php`. The defaults match XAMPP (user `root`, empty password).
5. Open the website: `http://localhost/share_tracker/public/index.php`
6. Open the admin: `http://localhost/share_tracker/admin/login.php`
   - Username: `admin`
   - Password: `Admin@12345`


## Testing the tracking

1. Open any page on the website and click a share button (the share window can be closed).
2. Log in to the admin dashboard. The click appears in "Latest clicks" and the charts and numbers go up.
3. Optional: in phpMyAdmin open tables `share_clicks` (the click) and `share_clicks_daily` (the counter).

## Folder structure

```
share_tracker/
├── config/config.php          database settings, SITE_URL, PDO connection
├── database.sql               tables + sample data
├── includes/
│   ├── helpers.php            escaping, URL helpers, slugify, social platform list
│   ├── header.php             <head> with Open Graph / Twitter meta tags
│   ├── share_buttons.php      share icon buttons, built from social_platforms
│   ├── footer.php
│   ├── auth.php               session, login guard, CSRF
│   └── stats.php              filters, queries and page search for the dashboard
├── public/                    front-end
│   ├── index.php, post.php    home page (keyset pagination), post page
│   ├── track_share.php        endpoint that validates and stores share clicks
│   ├── js/share.js            sends the click to the endpoint
│   ├── css/style.css
│   └── img/share-default.png
└── admin/
    ├── login.php, dashboard.php, logout.php
    ├── pages.php              JSON for the dashboard's Page search box
    ├── export.php             CSV download of the dashboard numbers
    └── assets/
        ├── chart.umd.js       Chart.js (saved locally, works offline)
        └── dashboard.js       draws the charts, handles the filters
```

## Database tables

| Table | Purpose |
|---|---|
| `admins` | Admin login (password stored as a hash). |
| `posts` | The news posts (`title`, `content`, `tags`). Indexed for the home page (`created_at, id`) and for the admin page search (`title`, FULLTEXT `title`). |
| `social_platforms` | One row per share button: `slug`, `name`, `share_url_template`, `icon_svg`, `color`, `opens_new_tab`, `sort_order`. |
| `share_clicks` | One row per click: `post_id` (0 = home page), `page_url`, `page_title`, `platform` (= `social_platforms.slug`), `clicked_at`. |
| `share_clicks_daily` | Summary of `share_clicks`: one row per day, page (`post_id`) and platform with a click counter. The dashboard charts and numbers read this table. `track_share.php` updates it with every click. |

## Security and input validation

- All SQL uses prepared statements, and all output is escaped with `htmlspecialchars()` (`e()` helper).
- Share click endpoint: only POST; `platform` and `page_url` must be plain text; the platform must exist
  in `social_platforms`; the URL must be a clean http(s) address of this website that points to the home
  page or an existing post. The post title is looked up on the server, never taken from the browser.
- Login: CSRF token, type and length checks (username up to 50 characters, letters, numbers and `_ . @ -`;
  password up to 200 characters), generic error message, session ID regenerated after login.
- Post id and dashboard filters are validated (whole number, real dates, fixed lists for platform and grouping,
  or a whole number). Typed search text is never put into the SQL: it goes through
  prepared statements, and only letters and digits are passed to the FULLTEXT search.
- Database errors: every page that reads or writes the database has a try / catch. A failure is written to the server's error log and the visitor sees a plain "Something went wrong" page (or a JSON error for the endpoints), never a PHP error with passwords or file paths. A safety net in `includes/helpers.php` does the same for any error that is not caught.
- No personal data is stored with a share click: only the page URL, its title, the platform and the time.
- Admin session: logged out after 30 minutes without activity (`SESSION_IDLE_LIMIT` in `includes/auth.php`).
