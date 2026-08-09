=== Manual Translations for Polylang ===
Contributors: kzdev
Tags: polylang, translation, localization, multilingual, ajax
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

# Manual Translations for Polylang

Manually translate frontend strings on the fly with full Polylang support and CSV import/export.

---

## 🚀 Overview

**Manual Translations for Polylang** is a lightweight utility plugin designed to resolve the common issue of untranslatable frontend strings (e.g. dynamic cart widgets, checkout fragments, AJAX loaders, and JS-injected content). By hook-in to Polylang's active languages, it allows you to easily map original strings to custom translation values and injects them client-side in real-time.

---

## ✨ Features

* Centralized translation management UI under the Polylang "Languages" menu
* Client-side text replacement engine powered by `MutationObserver`
* Instant translation of dynamic AJAX changes (like WooCommerce cart increments, checkout updates)
* No page reloads – fully reactive Single Page App (SPA) dashboard
* Selectable rows per page (10, 20, 50, 100) and live instant filtering
* One-click CSV Export (for selected or all translations)
* Seamless CSV Import (with Merge or Overwrite mode support)
* Deep security integration (Nonces, user capabilities, output escaping, and unslashing validation)
* Safe database-only storage in WordPress Options (no file clutter, compatible with backups)
* Graceful fallback to default site locale if Polylang is missing or disabled

---

## 🧠 Storage

Translations are stored safely in the database using the standard WordPress Options API.

* No custom database tables are created
* No template or script files are generated in your directories
* 100% compatible with default WordPress backup, migration, and export plugins

---

## 🖥️ Usage

### Create a Translation

1. Go to **WP Admin → Languages → Manual Translations**
2. Click **Add New Translation**
3. In the inline row editor:
   * Enter the **Source String** (the exact text visible on the frontend, e.g. `Subtotal`)
   * Enter the translations for each of your active Polylang languages
4. Click the checkmark **Save** button (or press **Enter**)
5. The translation is immediately active!

---

### Inline Editing

To modify an existing translation:
1. Double-click or click on any translation cell in the table
2. Type your changes
3. Click away (blur) or press **Enter** to save instantly via AJAX

---

### CSV Import & Export

* **Export Selected**: Check specific translation checkboxes, and click **Export Selected to CSV**.
* **Export All**: Click **Export All to CSV** at the bottom to download a full CSV backup.
* **Import**: Select a CSV, choose the import mode (**Merge** to update/add or **Overwrite** to replace all), and click **Upload and Import**.

---

## 🛡️ Notes

* Skipped tags: Scripts, styles, textareas, inputs, and code elements are ignored to prevent breaking layouts or administrative fields.
* Exact Match: Text matching is case-sensitive and trimmed of surrounding whitespaces.

---

## 🔧 Requirements

* WordPress 5.0 or higher
* PHP 8.0 or higher
* Polylang (optional, falls back to default locale)

---

## 📄 License

GPLv2 or later
https://www.gnu.org/licenses/gpl-2.0.html
