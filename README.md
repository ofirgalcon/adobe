# Adobe Creative Cloud Module for MunkiReport

Comprehensive reporting for Adobe Creative Cloud applications with  year edition detection and version tracking.

## Requirements

- **Adobe Remote Update Manager** at `/usr/local/bin/RemoteUpdateManager`
- **Adobe Uninstaller** at `/usr/local/bin/AdobeUninstaller`

### Downloads
- [Adobe Remote Update Manager](https://adminconsole.adobe.com/) → Packages
- [Adobe Uninstaller](https://helpx.adobe.com/uk/creative-cloud/help/uninstall-creative-cloud-desktop-app.html)

*Requires Adobe Enterprise license*

## Features

- **Application Inventory**: Complete list of installed Adobe applications
- **Version Tracking**: Current vs latest version comparison
- **Update Status**: Color-coded indicators (green=up-to-date, red=update needed, grey=unknown)
- **Year Edition Detection**: Automatic mapping to CC 2025, CC 2024, etc.
- **Smart Sorting**: Year editions sorted chronologically (newest first)
- **Efficient Caching**: Installed versions are read locally on every check-in. Adobe servers are contacted only after a successful contact is 6 hours old (`ADOBE_REMOTE_HOURS` in `scripts/adobe`). A failed contact is retried on the next check-in.

## Mapping Configuration

- Year-edition mappings are stored in `adobe_year_edition_map.yml`
- The module requires this YAML file for year-edition resolution (no hardcoded fallback)

## Data Collection

The client script is installed in MunkiReport preflight. It runs on each check-in. It does not have its own schedule.

Every check-in runs `AdobeUninstaller --list` on the Mac and rewrites `preflight.d/cache/adobe.plist`. If that command fails or prints nothing, the previous report is left in place. A successful list with no apps still clears the report.

Adobe is contacted only when the last successful contact is missing or older than 6 hours. That time is stored in `preflight.d/cache/adobe_remote.plist` and is written only after a success:

- `RemoteUpdateManager --action=list` asks Adobe's update service for the latest versions. Return code 0 is success.
- Creative Cloud Desktop (`KCCC`) looks up the current installer version on helpx.adobe.com.

If either contact fails, its success time is left unchanged, so the next check-in tries again. The last good latest versions are kept until a new contact succeeds.

## Table Schema

- **app_name** - Application name (e.g., "Adobe Photoshop")
- **sapcode** - Adobe's internal code (e.g., "PHSP")
- **base_version** - Base version number (e.g., "25.0")
- **year_edition** - Creative Cloud year (e.g., "CC 2025")
- **installed_version** - Currently installed version
- **latest_version** - Latest available version
- **is_up_to_date** - Update status (1=current, 0=outdated, NULL=unknown)

## User Interface

### Client Tab
Detailed table showing all Adobe applications with version comparison and color-coded update status:
- 🟢 **Green "Yes"** - Up-to-date
- 🔴 **Red "No"** - Update available  
- 🟡 **Yellow "Unknown"** - Cannot determine

### Widgets
- **App Names**: Distribution of installed applications
- **Year Editions**: Creative Cloud years (sorted newest first)
- **Update Status**: Overview of apps needing updates
- **SAP Codes**: Distribution of Adobe application codes

### Listings
Complete listing view with sortable columns for fleet management.

## Installation

1. Deploy Adobe Remote Update Manager and Adobe Uninstaller to client machines
2. Enable the Adobe module in your MunkiReport configuration
3. Data collection starts automatically on next Munki run

## Data Management

New client check-ins automatically calculate and store correct year editions.

## Troubleshooting

- **No data**: Verify Adobe tools are installed at correct paths
