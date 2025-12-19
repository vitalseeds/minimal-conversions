# Minimal Meta CAPI Conversion (No Pixel)

Privacy-focused, server-side conversion tracking for Meta (Facebook) ads without requiring the Meta Pixel.

## What It Does

- Captures `fbclid` from Meta ad clicks and stores as first-party cookie (7 days)
- Sends server-side conversion events to Meta Conversions API
- Works without client-side Meta Pixel JavaScript
- Compatible with ad blockers
- Minimal tracking footprint

## Installation

1. Copy plugin folder to `/wp-content/plugins/`
2. Activate in WordPress Admin > Plugins
3. Configure in Settings > Minimal Meta Conversions

## Configuration

Required settings (Settings > Minimal Meta Conversions):

- **Pixel ID** - Your Meta Pixel/Dataset ID (numeric)
- **Access Token** - Generate in Meta Events Manager > Settings > Conversions API
- **Event Name** - Select conversion type (default: Purchase)

Optional settings:

- **Test Event Code** - For testing (generate in Events Manager > Test Events)
- **Debug Logging** - Enable to log events to plugin log file

## Usage

Add shortcode to your conversion/thank-you page:

```
[meta_capi_conversion]
```

The shortcode fires a conversion event when:
- Page loads with the shortcode
- Visitor has a stored `fbclid` cookie (came from Meta ad)
- Pixel ID and Access Token are configured

## How It Works

1. **Visitor clicks Meta ad** → `fbclid` parameter captured from URL
2. **Stored as cookie** → First-party cookie for 7 days
3. **Conversion page** → Shortcode detects cookie
4. **API call** → Server sends event to Meta Conversions API
5. **Attribution** → Meta attributes conversion to ad campaign

## Features

- ✅ Server-side only (no browser pixel)
- ✅ First-party cookies
- ✅ Standard Meta event names
- ✅ Test mode support
- ✅ Debug logging
- ✅ Duplicate prevention (1 hour cooldown)
- ✅ Works with HTTPS

## Requirements

- WordPress with WP Cron
- PHP 7.0+
- SSL recommended (for secure cookies)
- Meta Business account with Pixel/Dataset

## Meta Setup

1. **Events Manager** → Select your Pixel
2. **Settings** → Conversions API
3. **Generate Access Token** → Copy to plugin settings
4. **Test Events** → Generate test code (optional, for testing)

## Debugging

Enable debug logging checkbox in plugin settings. Logs are written to:

**Log file:** `wp-content/minimal-conversions.log`

Example log entries:

- `[2025-01-15 14:23:45] Captured fbclid from URL: abc123...`
- `[2025-01-15 14:25:10] Firing conversion event: Purchase | fbclid: abc123...`
- `[2025-01-15 14:25:11] API Response [200]: {"events_received":1...}`

**No WP_DEBUG required** - The plugin writes to its own log file independently.

## Version

0.1.0

## License

GPL2
