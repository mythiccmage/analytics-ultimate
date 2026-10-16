# Analytics Ultimate

Simple, private website statistics that install on ordinary cPanel hosting in a few minutes.
No database to create, no command line, no cookies, no IP addresses stored.

- Pageviews, unique visitors, new vs. returning, traffic sources, top pages
- **File downloads**: automatic for common file types, plus tracked links that count on the server
- Many sites, subdomains and even different domains in one install
- **Projects**: group pages and downloads into products or sections and compare them over months
- Keeps your own visits out (exclude-my-browser, bots and headless browsers ignored)
- One folder, plain PHP + SQLite, free and open source (MIT)

## Requirements

PHP 7.4 or newer with the `pdo_sqlite` extension (nearly every cPanel host has both; in cPanel see
*Select PHP Version → Extensions*). The setup page checks this for you.

## Install

1. Unzip and upload the `analytics-ultimate` folder to your website, for example into `public_html`
   (File Manager or FTP). Put it on the domain you want to use as your stats site.
2. Open `https://your-site.com/analytics-ultimate/setup.php` and fill in your sites, timezone and an admin password.
3. Copy the one line it gives you into your website, just before `</head>`:

   ```html
   <script defer src="https://your-site.com/analytics-ultimate/a.js"></script>
   ```

4. Delete `setup.php` (optional; it refuses to run again anyway) and open `index.php` to see your stats.
5. Visit `ignore.php` once in each of your own browsers so your visits aren't counted.

Your data lives in a folder **above** your web root when the host allows it (so it can never be downloaded),
otherwise in `analytics-ultimate/data/`, protected by `.htaccess`. Back that folder up if you care about history.

## Tracking several sites or subdomains

List every site on the Settings page (`*.example.com` covers all subdomains), then put the same one-line
script on all of them. They can be on completely different domains. Use the site picker on the dashboard to
look at one site at a time.

## Downloads

- Clicks on links to common file types (zip, exe, dmg, pkg, deb, apk, pdf, mp3 and more) are counted automatically.
- For reliable counts, add a **tracked link** on the Downloads tab and use it on your site instead of the direct
  file address: `https://your-site.com/analytics-ultimate/go.php?l=my-app`. The count happens on your server, so
  "save link as", download managers and shared links are counted too. You can also move the file to another host
  later without touching any page.
- Mark any other link with `data-ua-download`.

## Projects

On Settings → Projects, one line per project: `Name | sites | page starts with | download names start with`.
For example:

```
Shop      |                | /shop, /cart | shop-
Blog      | blog.example.com |            |
My App    |                | /my-app      | my-app
```

The Projects tab then shows visitors, returning visitors, pageviews and downloads per project, over the selected
range and all time, plus a month-by-month table. Handy for deciding what is worth your time.

## Events

`<button data-ua-event="signup">` counts clicks as the event "signup". From JavaScript: `AnalyticsUltimate.track("signup")`.

## Keeping yourself (and your tests) out

- `ignore.php` sets a cookie for that browser. For a site on a *different* domain than your stats site, add
  `?ua-ignore=1` to any page address of that site once; `?ua-ignore=0` turns counting back on.
- Scripts and tests can send the header `X-Analytics-Ignore: 1`.
- Bots, crawlers, uptime monitors and headless browsers are skipped automatically.
- Settings → Clean up data deletes records for a date range (e.g. old test traffic).

## Privacy

No cookies are set on your visitors and no IP addresses are stored. A random ID kept in the visitor's browser
(localStorage) counts unique visitors; it is not linked to a person. Add `data-respect-dnt="true"` to the script
tag to skip visitors who send Do Not Track. Whether you need a notice depends on where you and your visitors
are; this is not legal advice.

## Security notes

- The admin password is stored hashed; sign-in is rate limited; every change needs a per-session token.
- Only sites you list can send data. Tracked download links can only point where you set them (no open redirects).
- Don't leave extra copies of the data folder in your web root.

## Updating

Upload the new files over the old ones (keep your data folder). The database updates itself.

## Uninstalling

Delete the `analytics-ultimate` folder and its data folder, and remove the script line from your pages.

## Troubleshooting

- *Nothing shows up*: open your site, then check the address in the script line loads `a.js` in a browser, and that
  the site is on your list in Settings. Localhost isn't counted unless the script tag has `data-dev="true"`.
- *Setup says it can't write data*: make the `analytics-ultimate` folder writable (permissions 755) or create a
  `data` folder inside it.
- *Blank dashboard after upgrading PHP*: make sure the new PHP version still has `pdo_sqlite` enabled.

MIT licensed. Made by Ultisoft.
