# Password Protected Content

A lightweight WordPress shortcode plugin that lets you password-protect specific sections of a page without restricting access to the entire page. Keep your hero, introduction and other public content visible while protecting selected sections with a password.

![Version](https://img.shields.io/badge/version-2.0.0-blue)
![PHP](https://img.shields.io/badge/PHP-7.3%2B-blue)

## Features

- Protect individual sections rather than entire pages.
- Use wrapping shortcodes or separate start and end shortcodes across blocks and page-builder widgets.
- Customise the password prompt and unlock button.
- Remember successful unlocks using a signed cookie with a 10-day lifetime.
- Basic brute-force protection: 10 failed attempts per IP address and protected area, with a 15-minute expiry on the attempt counter.
- Users with the relevant editing permissions can view protected content without entering a password.
- Server-side HTML filtering rather than simply hiding content with CSS or JavaScript.
- Minimal default styling that can be overridden in your theme.

## Requirements

- WordPress (minimum supported version has not been specified).
- PHP 7.3 or later.
- HTTPS recommended.

## Installation

1. Download the plugin PHP file and rename it to `password-protected-content.php`.
2. Create a folder named `password-protected-content` inside `wp-content/plugins/`.
3. Upload the PHP file into that folder.
4. In your WordPress dashboard, go to **Plugins → Installed Plugins**.
5. Activate **Password Protected Content**.

Alternatively, ZIP the plugin folder and upload it through **Plugins → Add New Plugin → Upload Plugin**.

## Usage

### Option 1: Protect a single section

Wrap the content you want to protect in the following shortcode:

```text
[protected password="your-password"]
This content is only visible after entering the correct password.
[/protected]
```

Replace `your-password` with a strong, unique password.

### Option 2: Protect multiple blocks or page-builder sections

Place the opening shortcode immediately before the content you want to protect and the closing shortcode immediately after it. Use shortcode-capable blocks or widgets.

```text
Public hero section

[protected_start password="your-password"]

Protected text, images, blocks or widgets.

[protected_end]

Public contact section
```

The hero and contact sections remain publicly visible. Visitors see a password form in place of the protected section.

### Option 3: Customise the password form

```text
[protected password="your-password" message="Enter your access password to continue." button="View Content"]
Your protected content goes here.
[/protected]
```

The same attributes are supported by `[protected_start]`.

## Shortcode attributes

| Attribute | Required | Default | Description |
| --- | --- | --- | --- |
| `password` | Yes | None | Password required to unlock the content. |
| `message` | No | This content is password protected. Enter the password to view it. | Message displayed above the password field. |
| `button` | No | Unlock | Submit button label. |

Always supply a non-empty password and match each opening shortcode with its closing shortcode. An empty password on a split opening shortcode does not create a protection boundary.

## Styling

The plugin includes basic form styling. Add your own CSS through your theme or WordPress Additional CSS settings.

```css
.ppc-locked {
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 24px;
}

.ppc-locked button {
    background: #222;
    color: #fff;
    border: 0;
    padding: 12px 20px;
    cursor: pointer;
}
```

Available selectors include `.ppc-locked`, `.ppc-message`, `.ppc-error`, `.ppc-locked form` and `.ppc-locked input[type=password]`.

## How it works

The shortcodes insert internal HTML markers. The plugin buffers the rendered page and replaces locked regions with a password form before sending the HTML to the browser. It attempts to preserve structural HTML tags needed by the surrounding layout.

On successful password entry, a signed cookie allows subsequent visits to display the unlocked area. Areas on the same page using the same password share an unlock identifier.

## Security and compatibility notes

- Exclude protected pages from full-page caching, including CDN and server-level caches. The plugin requests no-cache behaviour, but external caches may ignore it. Purge existing cached copies.
- Protection applies to filtered front-end HTML, not necessarily REST API responses, feeds, search excerpts, builder data or other output channels. Verify these separately.
- Direct media URLs and downloadable files are not protected by these shortcodes.
- Structural HTML tags and their attributes may be retained to preserve layouts. Do not place secrets in HTML attributes.
- Nested or overlapping protected regions are not supported reliably by the current implementation. Use separate, non-overlapping regions.
- A missing closing shortcode can hide the remainder of the page from locked visitors.
- Test your theme and page builder while logged out, including the page source and cache behaviour. Specific builder versions have not been verified.
- The plugin is not a substitute for a fully audited access-control or membership system.

## Frequently asked questions

**Can I use different passwords on the same page?**  
Yes. Separate non-overlapping regions can use different passwords.

**Do visitors need a WordPress account?**  
No. Visitors can unlock content using the configured password.

**Why can I see the content without entering a password?**  
Users with the relevant editing permissions bypass the password form. Test in a private browser window while logged out.

**How do I lock the content again?**  
Clear the relevant `ppc_` cookie or use a new private browsing session. There is no built-in lock button.

**Does the plugin have a settings page?**  
No. Configuration is handled through shortcode attributes.

## Changelog

### 2.0.0

Current version includes wrapping and split shortcodes, server-side output filtering, signed unlock cookies, basic attempt limiting and customisable password forms.

## Licence

No licence is declared in the supplied plugin source. The repository owner should select and add a licence before distributing the plugin under open-source terms.
