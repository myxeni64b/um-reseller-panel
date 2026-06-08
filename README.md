# XUI / UM Reseller Panel

A production-oriented reseller panel built in **pure PHP** with **JSON storage**, designed for operators who want a lightweight control and business layer for two backend service types:

- **3x-ui / XUI** servers for subscription-based client delivery
- **MikroTik User Manager (UM / Radius)** servers for username/password based account delivery

The project is intentionally kept practical for **shared hosting**, standard VPS deployments, and operators who prefer a self-contained PHP application without a SQL dependency or a frontend framework stack.

This repository is the panel layer. It manages resellers, customer records, reseller credit accounting, public customer access, Telegram integration, logs, backups, and optional panel-to-panel sync. The backend servers remain the network/service layer.

---

## Table of contents

- [What this project is](#what-this-project-is)
- [Supported backend types](#supported-backend-types)
- [Main feature overview](#main-feature-overview)
- [Role-by-role features](#role-by-role-features)
- [How XUI and UM differ inside the panel](#how-xui-and-um-differ-inside-the-panel)
- [Credit accounting and business rules](#credit-accounting-and-business-rules)
- [Templates, profiles, and reseller permissions](#templates-profiles-and-reseller-permissions)
- [Public customer access](#public-customer-access)
- [Telegram bot](#telegram-bot)
- [Reseller API](#reseller-api)
- [Sync, cron, and operational automation](#sync-cron-and-operational-automation)
- [Security and hardening](#security-and-hardening)
- [Logs, transactions, backups, and maintenance](#logs-transactions-backups-and-maintenance)
- [Data model and storage layout](#data-model-and-storage-layout)
- [Requirements](#requirements)
- [Project structure](#project-structure)
- [Installation](#installation)
- [Server setup notes](#server-setup-notes)
- [Operational guidance](#operational-guidance)
- [Upgrade guidance](#upgrade-guidance)
- [GitHub publishing checklist](#github-publishing-checklist)
- [Limitations and practical notes](#limitations-and-practical-notes)
- [Release summary](#release-summary)

---

## What this project is

This project is a **reseller operations panel** with two goals:

1. keep service control and reseller/customer business rules in one place
2. keep deployment simple enough for shared hosting and ordinary PHP environments

The panel is intentionally responsible for business and support logic such as:

- admin and reseller accounts
- reseller restrictions and balance accounting
- customer records and public-access metadata
- notices and tickets
- Telegram bindings and bot state
- API keys and reseller API handling
- logs, backups, sync metadata, and housekeeping

The backend servers are responsible for the actual service accounts:

- **XUI / 3x-ui** for subscription/config-based clients
- **MikroTik User Manager** for Radius/UM users and profiles

The panel is therefore the source of truth for the business workflow, while XUI and MikroTik UM are the source of truth for live service state.

---

## Supported backend types

### 1) XUI / 3x-ui

XUI nodes are used for subscription-based service delivery. In this mode the panel supports:

- node connection testing
- inbound import into panel templates
- customer create/edit/toggle/delete/sync
- subscription and config generation
- QR presentation
- public `/user/<key>` subscription access
- `/get` customer lookup and delivery
- visible/manual sync and cron-based usage refresh

### 2) MikroTik User Manager (UM / Radius)

UM nodes are used for username/password based service delivery. In this mode the panel supports:

- node connection testing
- profile import into panel templates
- create/edit/toggle/delete/sync for UM users
- profile assignment on creation
- public and internal display of username/password and connection instructions
- `/get` support for UM customers
- visible/manual sync and cron-based usage refresh
- reseller API compatibility with UM-aware payloads and summaries

### UM connection modes

UM nodes can be configured to use either:

- **REST API**, when the environment supports it correctly
- **Internal MikroTik API**, for environments where RouterOS/User Manager API access is preferred or more reliable

For internal API mode, the panel supports both:

- **API plain**
- **API SSL / API-SSL**

TLS verification behavior remains configurable per node according to the panel settings and how the MikroTik endpoint is deployed.

---

## Main feature overview

The current release includes the following major capabilities:

- multi-node support
- two backend service types: **XUI** and **UM**
- pure PHP architecture with no SQL dependency
- JSON-based storage with locking and atomic write patterns where appropriate
- admin and reseller roles
- reseller balance accounting in **GB**
- typed templates:
  - XUI inbound templates
  - UM profile templates
- per-reseller allowed-template assignment
- customer creation flows for both XUI and UM
- typed customer list badges and actions
- public `/get` customer lookup portal
- public `/user/<subscription_key>` access page
- pure PHP QR generation
- notices and ticketing
- reseller API with optional encryption mode
- Telegram bot integration
- panel-to-panel sync support
- shared-hosting friendly cron helpers
- Apache/LiteSpeed hardening rules
- logging, transactions, backups, and cleanup helpers

---

## Role-by-role features

### Admin features

Admin can:

- install the panel and create the initial admin user
- create, edit, enable, disable, and remove resellers
- set reseller credit in GB
- define reseller restrictions and allowed limits
- manage XUI and UM nodes
- test connectivity for both server types
- import XUI inbounds into templates
- import UM profiles into templates
- define and edit templates manually
- assign allowed templates to each reseller
- review customers across the whole panel
- inspect activity logs, system logs, and credit transactions
- manage notices and tickets
- configure security, backup, Telegram, API, sync, cleanup, and UI-related settings
- create and rotate backups
- use or configure panel-to-panel sync

### Reseller features

Reseller can:

- log in through the same login interface as admin
- view dashboard, balance, restrictions, and notices
- create customers only from templates assigned by admin
- create either:
  - **XUI clients**
  - **UM clients**
- edit allowed customer fields according to the template type and reseller restrictions
- search, sort, and review customers
- use quick actions such as sync, toggle, delivery view, export, and delete when permitted
- change reseller password and manage Telegram linkage
- use the reseller API if enabled
- use tickets

### Customer / public features

Public and customer-facing features include:

- `/user/<subscription_key>` access page
- `/get` self-service lookup with local contact/PIN verification
- copy/export access helpers
- status, expiry, usage, and left-traffic view
- public notices

The exact delivery view depends on the customer type:

- **XUI customers** receive subscription and config-oriented delivery
- **UM customers** receive username/password plus connection information configured for the UM node

---

## How XUI and UM differ inside the panel

The panel deliberately keeps a common customer model while preserving the different service behavior of each backend.

### XUI customers

XUI customers are subscription/config based. They normally include:

- traffic quota chosen during create/edit within allowed rules
- expiration mode and expiration days
- IP limit
- subscription URL
- configs and QR output
- remote sync through the XUI adapter

### UM customers

UM customers are profile-based. Their behavior is intentionally different:

- reseller selects a **UM profile template** instead of a free traffic value
- the profile template defines `billing_gb`
- reseller does not set arbitrary GB during create
- reseller cannot freely switch the profile after creation like a normal XUI template change
- reseller can change the password
- delivery is username/password plus text/file connection instructions rather than subscription/config lists

### Typed badges and typed actions

Customer lists, detail views, and related UI surfaces show the service type clearly:

- **XUI** customers use the XUI visual type badge
- **UM** customers use the UM type badge

Actions also remain type-aware:

- XUI actions keep subscription/config tooling
- UM actions focus on username/password delivery and profile-based handling

---

## Credit accounting and business rules

The reseller balance model uses **GB** as the accounting unit for both backend types.

### XUI accounting

For XUI customers, the reseller is charged according to the traffic quota allocated to that customer.

### UM accounting

For UM customers, the reseller is charged according to the UM template’s **`billing_gb`** field.

That field is set by admin on the UM profile template and represents the GB cost consumed from the reseller balance when a UM customer is successfully created.

### Important accounting behavior

The panel is designed so that:

- reseller credit is consumed only after successful customer creation flow
- failed customer creation should not consume reseller balance
- deletion can refund remaining GB when applicable
- sync-before-delete logic helps keep refund behavior safer
- reduction below already-used traffic is blocked where appropriate

### Restrict mode

Restrict mode is an admin-controlled reseller limitation model. When enabled, the reseller is prevented from actions such as:

- deleting customers
- toggling customers
- lowering traffic or performing other disallowed mutations

The same restriction model applies to both XUI and UM customers.

### Per-reseller limits

Admin can define reseller-level limits such as:

- max IP limit
- max expiration days
- min customer traffic GB
- max customer traffic GB
- restricted mode

These limits are especially important for XUI flows, while UM flows follow the template billing model.

---

## Templates, profiles, and reseller permissions

Templates are central to the panel’s permission and provisioning model.

### XUI templates

XUI templates represent imported or manually defined **inbounds**. They contain data such as:

- inbound id / inbound name
- protocol
- listen / port
- network / security
- XUI JSON payload fields as needed

### UM templates

UM templates represent imported or manually defined **User Manager profiles**. They contain data such as:

- remote UM profile id/name
- title/public label
- `billing_gb`
- optional notes and sorting metadata

### Template assignment to resellers

Admin does not assign raw nodes directly to resellers. Instead, admin assigns **allowed templates**.

That means a reseller can only create customers from the templates that were explicitly granted.

This is true for both:

- XUI inbound templates
- UM profile templates

### Importing from backend servers

The panel supports backend-driven discovery:

- **XUI**: import inbounds
- **UM**: import profiles

Imported UM profiles intentionally do not guess accounting values. Admin should review and set `billing_gb` correctly after import.

---

## Public customer access

### `/user/<subscription_key>`

This route provides a customer-facing access page.

For **XUI** customers it shows subscription-oriented delivery such as:

- primary subscription URL
- fallback panel URL when applicable
- config list
- copy/export actions
- QR display

For **UM** customers it shows account-oriented delivery such as:

- username
- password
- profile/service details where available
- node-defined connection text or file/download reference
- status, expiry, usage, and left traffic where available

### `/get`

`/get` is an optional self-service customer lookup portal.

A customer can be identified using:

- **phone + PIN**
- **email + PIN**

Behavior notes:

- contact and PIN data are stored locally in the panel
- PIN is stored hashed
- these values are not stored in XUI or UM
- if a customer has no configured contact/PIN pair, `/get` access is not available for that customer

### Export helpers

The panel supports export/copy behavior adapted to the customer type:

- XUI export focuses on subscriptions/configs
- UM export focuses on username/password plus connection information

### QR codes

QR codes are generated locally in **pure PHP**.

That means:

- no Python dependency
- no external QR web service dependency
- better portability on shared hosting

---

## Telegram bot

The panel includes Telegram bot support for reseller and customer self-service.

### Transport modes

Supported bot operation styles include:

- webhook
- polling

### Proxy support

Telegram transport can be configured with proxy options such as:

- HTTP
- HTTPS
- SOCKS5

### Typical reseller bot functions

The exact message set depends on configuration and current build, but reseller-facing Telegram flows include operations such as:

- linking by one-time token
- balance view
- customer list and customer detail shortcuts
- sync-related actions
- access/delivery retrieval
- notices/help/menu workflows

### Typical customer bot functions

Customer Telegram interactions can expose information such as:

- account status
- usage
- access details
- subscription or UM account information depending on customer type

### Included helper files

The repository includes helper files for Telegram polling and service operation:

- `scripts/telegram_poll_runner.sh`
- `scripts/telegram_poll_cron.php`
- `scripts/telegram_bot.service.example`

Recommended deployment approach:

- **webhook** when practical
- **systemd service** on VPS when polling is preferred
- **cron** on shared hosting when persistent service is not available

---

## Reseller API

The panel includes an optional reseller API when enabled by admin.

### Authentication

Authentication supports reseller API keys through headers such as:

- `Authorization: Bearer <api-key>`
- `X-Reseller-Api-Key: <api-key>`

### Supported endpoint groups

The current V1 API surface includes operations such as:

- reseller profile
- allowed templates
- customers list
- customer details
- create customer
- edit customer
- toggle customer
- delete customer
- sync customer
- password change

### Type-aware payloads

The API is aware of both backend types and returns forward-compatible fields such as:

- `server_type`
- `template_id`
- `node_id`
- template-specific fields where relevant

UM-aware payloads include the UM template/profile information needed for external integrations.

### Encryption mode

Admin can enable reseller API encryption so payloads use the panel’s encrypted API mode instead of plain JSON-only transport.

### Example file

A PHP example is bundled in:

- `public/assets/examples/reseller_api_example.php.txt`

That example is intentionally simple and repository-friendly.

---

## Sync, cron, and operational automation

The panel supports several operational sync layers.

### Manual sync

Customer records can be manually refreshed from the backend service.

### Quick Sync Visible

Customer list screens support **Quick Sync Visible**, which lets the current visible result set be refreshed in bulk.

This is intended for day-to-day operational convenience.

### Maintenance cron

A shared-hosting-friendly maintenance runner is included at:

- `scripts/cron.php`

It can be called by cron and performs only the enabled tasks whose configured period is due.

Typical cron tasks include:

- customer state sync
- stale cache/temp cleanup
- automatic backup rotation

### Panel-to-panel sync

The project also includes optional panel sync helpers for master/slave style synchronization:

- `scripts/panel_sync_cron.php`

This is intended for environments where panel data should be synchronized across installations.

### UM integration in sync flows

UM customers are integrated into the same operational flows as XUI where applicable, including:

- manual customer sync
- quick visible sync
- cron-based state refresh
- API-driven sync actions
- delete/refund-sensitive refresh behavior

### Removed remote items

The panel includes safety behavior for customers that disappear remotely. When the backend confirms or safely implies that a remote customer no longer exists, the panel can mark the local record as removed instead of blindly treating it as a normal active service.

---

## Security and hardening

The project includes several layers of practical hardening.

### Request protections

- CSRF protection
- request normalization and sanitization
- same-origin / request metadata-aware POST checks with compatibility fallbacks
- brute-force and rate-limit handling for login and public access routes
- install locking after setup

### Browser and response protections

- no-cache and related headers where appropriate
- noindex/nofollow style headers
- CSP and related browser hardening
- sensitive path protection via server rules

### File/path protections

Included `.htaccess` rules protect direct URL access to areas such as:

- `app/`
- `storage/`
- `config.php`
- hidden files
- sensitive helper and extension patterns

### Page shield / JS hardening

The project includes optional page-shield style browser-side hardening features. These are additional protective wrappers and should not be treated as a replacement for HTTPS or proper server security.

### Password and secret handling

Sensitive values are handled locally inside the panel architecture. As with any PHP panel, production deployment should still include:

- valid HTTPS
- proper file permissions
- protected backups
- careful server access control

---

## Logs, transactions, backups, and maintenance

### System logs

The panel includes rotating/system log surfaces for operational visibility.

Depending on configuration and installed features, logs can include areas such as:

- login access/errors
- `/get` access/errors
- XUI access/errors
- UM access/errors
- security/firewall style errors
- sync/cron-related notes

Temporary low-level UM adapter debug tracing is not part of the normal public operational logging path in the final release.

### Activity logs

Admin can review reseller/customer activity actions such as:

- create
- edit
- sync
- toggle
- delete

### Transactions

Credit transactions track reseller allocation, customer consumption, adjustments, and refunds.

### Backups

The panel supports backup creation, download, and removal, along with configurable periodic backup rotation.

### Cleanup helpers

Cleanup and stale-file maintenance are designed to remove caches and temporary artifacts safely rather than touching business data indiscriminately.

---

## Data model and storage layout

This project intentionally uses **JSON storage** instead of SQL.

### Storage directories

Data is stored under `storage/` in areas such as:

- `backups/`
- `cache/`
- `config/`
- `data/`
- `locks/`
- `logs/`

### Core collections

The panel maintains collections such as:

- admins
- resellers
- nodes
- templates
- customers
- customer_links
- tickets
- ticket_messages
- credit_ledger
- activity
- notices
- telegram_bindings
- telegram_states

### Common record concepts

The data model is typed around:

- `server_type` on nodes/templates/customers
- reseller-owned customer records
- public access metadata kept locally
- per-customer sync state
- provider metadata for remote references such as UM user ids or XUI-related remote identifiers

### Why JSON

The JSON design keeps the project portable and simple to host. It also means the operator should be aware that this is not an ACID SQL system. The code is written to follow safe ordering and file-locking patterns where practical, but deployment should still be treated carefully.

---

## Requirements

Minimum intended environment:

- PHP **5.6+**
- cURL extension
- JSON support
- session support
- OpenSSL recommended
- Apache/LiteSpeed with `.htaccess` support, or equivalent rules on another web server

Practical production recommendation:

- modern PHP 8.x
- valid HTTPS
- Apache or LiteSpeed, or a correctly configured Nginx equivalent
- outbound access to the required XUI or MikroTik endpoints

---

## Project structure

```text
.
├── app/
│   ├── PanelApp.php
│   ├── bootstrap.php
│   ├── lib/
│   │   ├── JsonStore.php
│   │   ├── XuiAdapter.php
│   │   ├── MikrotikUmAdapter.php
│   │   ├── PurePhpQr.php
│   │   └── functions.php
│   └── views/
├── public/
│   ├── index.php
│   └── assets/
│       ├── app.css
│       ├── app.js
│       ├── key.js
│       └── examples/
├── scripts/
│   ├── cron.php
│   ├── panel_sync_cron.php
│   ├── telegram_poll_runner.sh
│   ├── telegram_poll_cron.php
│   └── telegram_bot.service.example
├── storage/
│   ├── backups/
│   ├── cache/
│   ├── config/
│   ├── data/
│   ├── locks/
│   └── logs/
├── config.php
├── index.php
├── .htaccess
└── README.md
```

### Key files

- `app/PanelApp.php` — main application/controller layer
- `app/lib/XuiAdapter.php` — XUI backend integration
- `app/lib/MikrotikUmAdapter.php` — MikroTik UM backend integration
- `app/lib/JsonStore.php` — JSON storage helper layer
- `app/lib/PurePhpQr.php` — local QR generation
- `scripts/cron.php` — shared-hosting-friendly maintenance runner
- `scripts/panel_sync_cron.php` — panel sync helper runner

---

## Installation

### Recommended deployment layout

Best practice:

- point the web root to `public/`
- keep `app/` and `storage/` outside direct web access when possible

Also supported:

- deploy the whole project under a domain root or subdirectory using the included Apache/LiteSpeed protection rules

### Basic install steps

1. Upload the project
2. Ensure `storage/` is writable by PHP
3. Open `/install`
4. Create the first admin account
5. Log in as admin
6. Add one or more backend nodes
7. Test node connection
8. Import templates:
   - import **inbounds** for XUI nodes
   - import **profiles** for UM nodes
9. Create resellers
10. Assign templates and limits to resellers
11. Configure security, API, Telegram, backup, sync, and cleanup settings as needed

### Install lock

After installation, the panel writes an install lock so `/install` cannot be reused to overwrite an existing deployment.

---

## Server setup notes

### XUI node notes

When adding an XUI node, enter values carefully.

Typical examples:

- Base URL: `https://node.example.com`
- Panel Path: `/panel`

Avoid duplicating path parts in both the base URL and the panel path fields.

### UM node notes

When adding a UM node, choose the appropriate API mode for the environment:

- REST
- Internal API plain
- Internal API SSL

Also configure the delivery mode that UM customers should receive from the panel:

- connection text
- connection file / file URL style delivery

### Connectivity realities

Some environments block outbound ports or have TLS/proxy limitations. If node test fails even with correct credentials, verify:

- firewall/outbound access
- host and port
- TLS verification settings
- RouterOS API service status for UM
- the XUI panel path and credentials for XUI nodes

---

## Operational guidance

### Creating XUI customers

A reseller selects an allowed XUI template and sets values such as:

- traffic quota
- expiration mode and days
- IP limit
- customer contact fields if public `/get` access is desired

### Creating UM customers

A reseller selects an allowed UM profile template. The panel then:

- calculates reseller billing from the template’s `billing_gb`
- creates the UM username/password account on the configured backend
- assigns the UM profile
- stores the local record with the provider metadata needed for later sync/toggle/delete

### Editing UM customers

UM customer editing is intentionally narrower than XUI editing. Password change and safe account updates are supported, while profile/template reassignment is intentionally restricted.

### Quick operational checks

After initial deployment, test the full path for each backend type you plan to use:

For XUI:

- node test
- inbound import
- create customer
- sync
- toggle
- delete
- public access

For UM:

- node test
- profile import
- create customer
- profile assignment verification
- sync
- toggle
- password edit
- delete
- public access

---

## Upgrade guidance

Before upgrading:

1. back up the full project
2. back up `storage/`
3. replace code carefully
4. preserve the live `storage/` directory if this is an upgrade rather than a fresh install

When upgrading across older internal builds, keep a backup first because newer releases introduced additional fields and behavior around:

- typed server support
- UM profiles and provider metadata
- Telegram settings and bindings
- API settings and compatibility fields
- public access metadata
- log types and transaction views
- sync metadata and maintenance logic

---

## GitHub publishing checklist

Before pushing publicly:

- keep `storage/` empty except placeholder files such as `.gitkeep`
- do not commit live customer data, backups, logs, cache, or secrets
- review `config.php`
- review `public/assets/key.js` and any deployment-specific values
- remove proprietary branding or production-only assets if needed
- add your own screenshots, license, and branding

This repository is designed to be GitHub-friendly after operational data is removed.

---

## Limitations and practical notes

- backend API behavior can vary by XUI build and RouterOS/User Manager version
- live testing against your exact deployment is always recommended
- shared hosting environments may restrict outbound ports or long-running processes
- Nginx users must provide their own equivalent rules for the included `.htaccess` protections
- page-shield and browser-side hardening are not substitutes for HTTPS
- JSON storage keeps the project simple, but it is not the same as a transactional SQL system

---

## Release summary

This release is the cumulative GitHub-ready package for the current feature line. It includes:

- admin/reseller/public panel flows
- dual backend support for **XUI** and **UM**
- typed templates and typed customers
- reseller credit accounting in GB
- XUI subscription/config delivery
- UM username/password/profile delivery
- `/get` and `/user/<subscription_key>` public access
- pure PHP QR generation
- notices, tickets, logs, transactions, and backups
- reseller API
- Telegram bot integration
- cron and panel sync helpers
- repository cleanup suitable for final release preparation

If you publish this project publicly, review branding, secrets, screenshots, and license details before the final push.
