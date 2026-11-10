# Onefile

**A whole CMS in one `index.php`.**

No frameworks. No `vendor/`. No build step. Drop one file on PHP hosting, open the browser, and you’re writing.

Onefile is a tiny editorial journal that still feels grown-up: ivory layout, soft motion, a real editor, and admin tools that usually require a plugin zoo.

---

## Why it exists

Most “simple” CMSes aren’t simple once you install them. Themes, plugins, database dashboards, update warnings.

Onefile flips that:

- **One file** carries the app, the UI, the APIs, the install wizard, and even the `.htaccess` templates it writes for you.
- **First visit installs itself** (SQLite or MySQL), then **strips the installer out** of the source so production stays lean.
- **SPA feel, PHP heart** — hash routes in the browser, JSON actions on the server, session auth with CSRF.

It’s for people who want a personal magazine, a lab notebook, or a quiet blog — without renting complexity.

---

## What you get

| For readers | For you |
| --- | --- |
| Journal home with intro, tags, search | Drafts, publish, **schedule**, **pin** |
| Reading time + previous/next | Autosave + local draft safety net |
| SEO description + social-friendly meta | Tags, media uploads, image/link/file picker |
| Public **RSS** feed | Revisions (preview / restore) |
| Light / dark / **follow system** | Import Markdown or HTML |
| Clean ivory editorial look | Dashboard, audit log, backup / restore |
| | Password change, login lockout, optional **TOTP 2FA** |
| | Edit `index.php` from the admin UI |

Security-minded defaults: SQLite files and uploads are guarded by rules embedded in the app and written to disk when you ask.

---

## Quick start

**Requirements:** PHP 8+ with PDO (SQLite and/or MySQL). Apache recommended if you want the app to write `.htaccess` protection rules for you.

1. Copy `index.php` into a web directory.
2. Open it in the browser.
3. Walk through the install wizard — pick SQLite for zero ops, or MySQL if you already have one.
4. Sign in. Write something imperfect on purpose. Publish when ready.

That’s it. No Composer. No npm. No migrate CLI.

RSS lives at:

```text
/index.php?action=rss
```

---

## How it feels to run

- **Compose** in a contenteditable editor with toolbar actions for images, files, and links from your media library.
- **Schedule** a piece for later; pin the one that should stay at the top of the shelf.
- **Import** a Markdown note or an HTML dump when you’re migrating from elsewhere.
- **Turn on 2FA** when the journal leaves “just me on localhost” territory.
- **Backup** to JSON before you experiment. Restore when curiosity goes too far.

---

## Project layout

```text
index.php     # the entire application
README.md     # you are here
```

Runtime files such as `data.sqlite` and `uploads/` are created on the server and should stay off git (see `.gitignore`).

---

## License

Use it, fork it, bend it. If you ship something lovely with it, a star or a note is appreciated — not required.
