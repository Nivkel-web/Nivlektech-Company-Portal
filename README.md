# Company Portal

A modern company portal built with **PHP 8**, **MySQL 8**, **PDO** and **Bootstrap 5**, with a web installer, a responsive admin dashboard and a built-in support ticketing suite.

---

## Features

### Core portal
- ✅ Easy web installer
- ✅ User management
- ✅ Employee management
- ✅ Department management
- ✅ Company documents
- ✅ Upload Center
- ✅ Announcements
- ✅ Activity logs
- ✅ Company settings
- ✅ Backup & restore database
- ✅ Responsive admin dashboard

### New: Ticketing suite
- 🎫 **Ticketing System** – staff create tickets, reply, add internal notes, assign and change status. Every ticket gets a due time based on its priority (urgent 4h, high 24h, medium 72h, low 120h).
- 📡 **Live Management** *(admin)* – an auto-refreshing board with live counters, who is online, workload per agent, overdue/urgent highlighting, and quick status/assignee changes straight from each card. Optional sound on new tickets.
- 📊 **Report Analytics** *(admin)* – pick a date range to see totals, average first response, average resolution time, SLA met %, tickets per day, by status / priority / category / department, agent performance, and **export to CSV**.
- 📚 **IT Knowledge Base** *(IT team only)* – step-by-step fixes written by the team. When an agent opens a ticket, the portal suggests the most relevant articles (matched on subject, description, tags and category), and one click inserts the fix into the reply. When a ticket is resolved, a "what fixed it" note can be turned into a new article. Each article tracks views and helped / didn't help votes.
- 🔀 **Auto-Assign** *(admin settings)* – new tickets go to the right IT person automatically. Give each IT member specialties (ticket categories), an optional **max open tickets** limit and an **Away** switch. The ticket goes to a free specialist with the fewest open tickets (ties rotate), or to the least busy person if no specialist is available. Admins can still reassign, auto-assign one ticket from its page, or auto-assign all unassigned tickets at once.
- 💻 **Asset Tracking** *(IT team)* – inventory of laptops, printers, phones, network gear and more: serial number, who holds it, location, status (in use / in stock / in repair / retired) and warranty date with "ending soon / expired" warnings. People can pick the **affected device** when opening a ticket (only their own devices), and each device keeps its ticket history, flags **problem devices** (3+ tickets in 90 days), and appears in the analytics report. CSV export included.
- 💬 **SMS Alerts** *(admin settings, IT team receives)* – text messages to the IT team through **Africa's Talking** or **Twilio** (or a log-only test mode). Sends on urgent/high new tickets, when a ticket is assigned to someone, and when a ticket is 30 minutes from its due time or overdue. IT members are opt-in, numbers like `0712 345 678` are converted to `+254…` automatically, and every message is recorded in an SMS Log.
- ✉️ **Email Notification** *(admin)* – send through **SMTP**, PHP `mail()` or a log-only test mode. Choose which events send mail, send a test email, and review everything in the Email Log.

---

## Requirements

- PHP 8+ (PDO + `pdo_mysql`)
- MySQL 8+
- Apache or IIS

---

## Installation

1. Copy the project to your web server.
2. Open `http://localhost/install`
3. Complete the installer.
4. Log in with the admin account created during installation.

### Upgrading an existing install
Upload the new files over your existing copy. The ticketing tables are created automatically the first time any ticket page is opened. (The same SQL is also at the end of `install/database.sql` for fresh installs.)

### Turning on email
**Email Notification → choose a method → switch on → Save → Send test**.
Notification emails contain links built from `BASE_URL` in `config/config.php`, so set it to your real site address.
Gmail needs an *App Password*, not your normal password.

### Turning on SMS alerts
1. Open **SMS Alerts**, choose **Africa's Talking** or **Twilio**, and enter the keys from your provider account.
   (Africa's Talking username `sandbox` is their free test environment; Twilio trial accounts can only text verified numbers. SMS is not free on live accounts — you pay per message.)
2. In **IT team — who gets texted**, enter each person's number and tick **SMS on**. Nobody is texted until ticked.
3. Pick which new-ticket priorities should text (default: High and Urgent), switch SMS on, **Save**, then **Send test**.
4. For the "30 minutes to due time / overdue" alerts, schedule `cron/check-due.php` to run every 10 minutes (each alert is sent once per ticket):
   - **Linux:** `crontab -e` and add a line that runs every 10 minutes: `*/10 * * * * php /path/to/portal/cron/check-due.php`
   - **Windows (IIS):** Task Scheduler → Create Task → Trigger: repeat every 10 minutes → Action: start `php.exe` with argument `C:\path\to\portal\cron\check-due.php`

### Using Auto-Assign
1. Open **Auto-Assign**. For each IT person tick **Auto-assign**, choose their **specialties** (ticket categories), and optionally set a **Max open** limit.
2. Tick **Automatically assign new tickets** and **Save**. Use **Away** when someone is on leave — they are skipped until you untick it.
3. A new ticket then goes to a specialist who is not away and under their limit, choosing the one with the fewest open tickets. The ticket's conversation records who it was given to and why.

### Using Asset Tracking
1. Open **Asset Tracking → Add Asset** and fill in the device (set **Held by** to the person using it).
2. Staff choose **Affected device** when creating a ticket; IT can also change it on the ticket page.
3. Open any device to see its full ticket history. Devices with 3 or more tickets in 90 days are flagged as **problem devices**.

### Who can do what

| Page | Admin | User |
|---|---|---|
| Create tickets, reply, see own / assigned tickets | ✅ | ✅ |
| See all tickets, assign, change status, internal notes | ✅ | only on tickets assigned to them |
| Live Management, Report Analytics, Email Notification | ✅ | ❌ |
| SMS Alerts settings and SMS Log | ✅ | ❌ |
| Auto-Assign settings | ✅ | ❌ |
| Asset Tracking (list, add, edit, history, export) | ✅ | only users whose department is **IT** |
| Delete an asset | ✅ | ❌ |
| Pick one of **their own** devices on a new ticket | ✅ | ✅ |
| Receive SMS alerts | ✅ if opted in | only IT-department users who are opted in |
| IT Knowledge Base (read, suggestions, write articles) | ✅ | only users whose department is **IT** |

The "IT team" is every admin plus any active user whose department is named **IT**. Everyone else cannot open the Knowledge Base, see suggestions, or read resolution notes.

---

## Screenshots

### Core portal

| | |
|---|---|
| **Login** ![Login](screenshots/login.png) | **Dashboard** ![Dashboard](screenshots/dashboard.png) |
| **Users** ![Users](screenshots/users.png) | **Announcements** ![Announcements](screenshots/announcements.png) |
| **Upload Center** ![Upload Center](screenshots/upload-center.png) | **Activity Logs** ![Activity Logs](screenshots/activity-logs.png) |
| **Settings** ![Settings](screenshots/settings.png) | **Installer** ![Installer](screenshots/installer.png) |

### Ticketing System

![Ticket list](screenshots/ticketing-list.png)

![Ticket conversation](screenshots/ticketing-view.png)

![New ticket](screenshots/ticketing-new.png)

### IT Knowledge Base (IT team only)

![Knowledge Base](screenshots/kb-list.png)

![Suggested fixes on a ticket](screenshots/kb-suggestions.png)

![Article](screenshots/kb-article.png)

![New article](screenshots/kb-new.png)

### Auto-Assign

![Auto-Assign](screenshots/auto-assign.png)

### Asset Tracking

![Asset list](screenshots/assets.png)

![Asset history](screenshots/asset-view.png)

![Device on a ticket](screenshots/ticket-device.png)

### SMS Alerts

![SMS settings](screenshots/sms-settings.png)

![SMS log](screenshots/sms-log.png)

### Live Management

![Live Management](screenshots/live-management.png)

### Report Analytics

![Report Analytics](screenshots/report-analytics.png)

### Email Notification

![Email settings](screenshots/email-settings.png)

![Email log](screenshots/email-log.png)

> The new-feature screenshots use demo data (names and `example.com` addresses are made up).

---

## License

MIT License

---

Developed by **Nivlektech** · [github.com/Nivkel-web](https://github.com/Nivkel-web/)
