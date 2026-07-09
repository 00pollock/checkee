# Checkee

A post-registration operations plugin for WordPress events. Checkee sits between your registration form and your attendees — capturing submissions, generating QR codes, handling check-in, and syncing tags to ActiveCampaign.

---

## What it does

- **Captures registrations** from Kadence Forms Pro automatically — no extra configuration per form
- **Sends a confirmation email** with a unique QR code to each attendee on registration
- **Handles check-in** — scanning the QR code with any camera auto-checks in the attendee instantly, no app required
- **Syncs ActiveCampaign tags** — applies configurable tags on check-in and check-out, and removes the registration tag when a registration is deleted
- **Attendee management** — search, paginate, bulk check-in/check-out/delete, and export to CSV per event from the WordPress admin

---

## Requirements

- WordPress 6.3+
- PHP 8.1+
- [Kadence Forms Pro](https://www.kadencewp.com/) (Advanced Form block)
- ActiveCampaign account (optional — for tag sync)

---

## Installation

1. Go to the [latest release](https://github.com/00pollock/checkee/releases/latest) and download `checkee.zip`
2. In WordPress, go to **Plugins → Add New → Upload Plugin**
3. Upload `checkee.zip` and click **Install Now**, then **Activate**
4. Navigate to **Checkee** in the WordPress sidebar to get started

---

## Setup

### 1. Connect ActiveCampaign (optional)

Go to **Checkee → Settings** and enter your ActiveCampaign account URL and API key. Click **Test Connection** to verify.

### 2. Create an event mapping

Go to **Checkee → Events → Add Event**. Fill in:

- **Event name** — matches the title of your WordPress event page
- **Kadence form ID** — the post ID of the page containing your registration form
- **Field mappings** — map your form's email, first name, and last name fields
- **ActiveCampaign tags** — tags to apply on check-in, check-out, and to remove on registration deletion

### 3. That's it

When someone submits your Kadence form, Checkee automatically records the registration, emails them a QR code, and is ready for check-in.

---

## Checking in attendees

Each registration confirmation email contains a unique QR code. At your event, scan it with any phone camera — the attendee is checked in instantly and the page confirms their name and status. No app, no button, no extra steps.

You can also manage attendees from **Checkee → Events → [your event] → Attendees** — search, paginate through large lists, select multiple attendees for bulk check-in/check-out/delete, and export the full list to CSV.

---

## Updates

This plugin uses [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). When a new release is published here, your WordPress dashboard will show an update notification — just click **Update** like any other plugin.

---

## License

GPL-2.0-or-later
