# CSTS Blog (Separate Project)

A standalone news/events blog inspired by the structure of university-style news portals, with a built-in admin dashboard.

## Location

- Project folder: `/Users/issasukatuabdullahi/Desktop/csts-blog`
- Separate from `cgs` and `csts-web`

## Features

- Public news/events listing with search and category filter
- Individual post pages
- Admin login and dashboard
- Create, edit, and delete posts
- SQLite database auto-initialized on first load

## Run Locally

```bash
cd /Users/issasukatuabdullahi/Desktop/csts-blog
php -S localhost:9010
```

Then open:

- Public blog: `http://localhost:9010/index.php`
- Admin login: `http://localhost:9010/admin/login.php`

## Default Admin Credentials

- Username: `admin`
- Password: `Admin@123`

## Integration Done

CSTS site footer now includes **Blog Admin** link in:

- `/Users/issasukatuabdullahi/Desktop/csts-web/index.html`

Current link target is:

- `../csts-blog/admin/login.php`

You can replace this with your production URL later (for example, `https://blog.cstsghana.com/admin/login.php`).
