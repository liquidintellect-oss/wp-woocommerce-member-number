# WooCommerce Member Number

Assigns a unique, configurable number to customers when a configured product is purchased. Works with WooCommerce's High-Performance Order Storage (HPOS).

## Features

### Automatic Number Assignment
- Assigns a unique number to a customer the moment their order reaches a configured status (Processing and/or Completed).
- Numbers are permanently tied to the purchasing user account.
- Guest orders are supported — the number is linked to the account if the guest later registers with the same billing email.
- Assignment is idempotent: purchasing the same trigger product more than once will never create a second number for the same customer.
- Subscription renewal orders are automatically skipped.

### Configurable Terminology
Every user-facing string that mentions the number type uses a configurable label — no hardcoded "Member Number" text anywhere. Examples of what you can call it:
- Member Number
- Super Club Number
- Loyalty Card Number
- Patron ID

Separate singular and plural labels are supported (e.g. "Member Number" / "Member Numbers").

### Flexible Number Format
Numbers are generated from a token-based format template. Available tokens:

| Token | Description | Example output |
|-------|-------------|----------------|
| `{PREFIX}` | Configurable prefix string | `MBR-` |
| `{SEQ}` | Auto-incrementing sequence, zero-padded | `000042` |
| `{YEAR}` | Current 4-digit year | `2026` |
| `{MONTH}` | Current 2-digit month | `03` |
| `{RAND4}` | 4 random uppercase alphanumeric characters | `A7X3` |
| `{RAND6}` | 6 random uppercase alphanumeric characters | `A7X3Q1` |

Example templates and their output:
- `{PREFIX}{SEQ}` → `MBR-000042`
- `{YEAR}-{SEQ}` → `2026-000042`
- `{PREFIX}{YEAR}-{SEQ}` → `MBR-2026-000042`

A live preview updates as you type in the settings page, so you can see exactly what generated numbers will look like before saving.

Additional sequence controls:
- **Starting sequence** — set the first number to use (only effective before any numbers have been issued)
- **Sequence pad length** — control zero-padding width (e.g. 6 → `000001`)
- **Min / Max value** — restrict the range of valid sequence values (enforced when customers choose their own number)

### Chosen Number Feature (Optional)
Customers can optionally choose their own number at checkout for an additional fee.

- An opt-in field appears at checkout when the trigger product is in the cart.
- The customer types their desired number and clicks "Check Availability" — an instant AJAX check confirms whether it is free.
- A temporary reservation locks the number while the customer completes checkout, preventing two customers from claiming the same number simultaneously.
- The fee is added as a standard WooCommerce cart fee with a configurable label.
- Configurable reservation TTL (default 30 minutes) with automatic cleanup via WP-Cron.
- If a race condition occurs (number taken between reservation and order completion), the plugin can either auto-assign the next available number and refund the fee, or hold the order for admin review — your choice.
- Chosen numbers are tracked separately from auto-assigned numbers and are visible in the admin list with a "Chosen" badge.

### Admin Member List
A dedicated **WooCommerce → Member Numbers** page lists every assigned number with:
- Member number
- Assignment type badge (Auto / Chosen)
- Customer name and email (linked to the user profile)
- Order number (linked to the order)
- Status badge (Active / Suspended / Revoked)
- Assignment date

**Filtering and search:**
- Filter tabs: All, Active, Suspended, Revoked, Chosen
- Full-text search by number, name, or email

**Row actions** on each record:
- Suspend
- Reactivate
- Revoke

**Bulk actions:** Suspend, Reactivate, or Revoke multiple records at once.

**Manual assignment form:** Assign a number to any customer from the admin, linked to any existing order. Leave the number field blank to auto-generate, or type a specific number to assign it directly.

### Product Configuration
Trigger products can be configured in two ways:

1. **WooCommerce → Settings → Member Numbers → Trigger Products** — a searchable multi-select that lets you add any number of products at once.
2. **Product edit page → General tab** — an "Assigns a [Label]" checkbox directly on each product. Products marked this way show a badge in the WooCommerce product list.

### User Profile Integration
- Admins can view and edit a customer's number directly from the WordPress user edit screen.
- Changing the number from the user profile validates uniqueness before saving.

### My Account Page
The customer's number is displayed on their WooCommerce My Account dashboard.

### Customer Email Notification
Optionally send a transactional email to the customer when their number is assigned. This is a standard WooCommerce email that can be customised from **WooCommerce → Settings → Emails**.

### Refund Handling
When a trigger product order is fully refunded, the plugin can:
- Do nothing (numbers remain permanent — default)
- Suspend the number automatically
- Notify an admin email address

### Data Management
An optional setting will drop all plugin database tables, options, and user meta when the plugin is deleted. Disabled by default to protect data.

---

## Requirements

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher

---

## How To

### Install the Plugin
1. Download the latest release zip from the releases page.
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Install Now**, then **Activate**.

### Configure the Plugin
Go to **WooCommerce → Settings** and click the **Member Numbers** tab (the tab label reflects your configured plural label).

#### 1. Set Your Labels
Under **Labels**, enter the singular and plural names for your number type. Every user-facing string — menus, emails, checkout fields, My Account — will use these labels automatically.

#### 2. Set Trigger Products
Under **Trigger Products**, search for and select one or more products. Purchasing any of these products will trigger number assignment.

Alternatively, open any product's edit page, go to the **General** tab inside the **Product data** panel, and check the **Assigns a [Label]** box.

#### 3. Configure the Number Format
Under **Number Format**:
1. Set the **Format template** — combine tokens to build the structure you want (e.g. `{PREFIX}{SEQ}`).
2. Set the **Prefix** — the text that replaces `{PREFIX}` (e.g. `MBR-`).
3. Set the **Starting sequence** — the first number to issue (only takes effect before any numbers are assigned).
4. Set the **Sequence pad length** — how many digits wide the `{SEQ}` portion should be.
5. Watch the **Preview** row update in real time as you adjust settings.
6. Click **Save changes**.

#### 4. Configure Behaviour
Under **Behaviour**:
- **Assign on status** — tick the order statuses that should trigger assignment (Processing, Completed, or both).
- **On full refund** — choose what happens when a trigger order is fully refunded.
- **Customer email** — tick to send the customer a confirmation email when their number is assigned.

#### 5. Enable Chosen Numbers (Optional)
Under **Chosen Number**:
1. Tick **Enable** to activate the feature.
2. Set the **Additional fee** — the extra charge customers pay to choose their own number.
3. Optionally customise the **Fee label** shown in the cart and order (use `{label}` to insert the configured number label).
4. Set the **Reservation TTL** — how many minutes a number stays locked while the customer is checking out.
5. Choose what to do **If chosen number is taken** at the moment the order completes.
6. Click **Save changes**.

---

### Manage Member Numbers
Go to **WooCommerce → Member Numbers** to see the full list of assigned numbers.

- Use the **filter tabs** at the top to narrow by status or type.
- Use the **search box** to find a specific number, customer name, or email.
- Click **Suspend**, **Reactivate**, or **Revoke** on a row to change its status.
- Tick multiple rows and use the **Bulk actions** dropdown to act on several at once.

### Assign a Number Manually
1. Go to **WooCommerce → Member Numbers**.
2. Click the **Assign [Label]** button at the top right.
3. Search for the customer, enter the order ID, and optionally type a specific number (leave blank to auto-generate).
4. Click **Assign [Label]**.

### View or Edit a Customer's Number
Open any user in **Users → All Users** (or **WooCommerce → Customers**) and click **Edit**. The customer's number is shown in the **Member Number** section. You can edit the number directly and save — the plugin validates uniqueness before accepting the change.

### Uninstall Cleanly
If you want all plugin data removed when you delete the plugin:
1. Go to **WooCommerce → Settings → Member Numbers**.
2. Scroll to the **Data** section.
3. Tick **Delete data on uninstall**.
4. Save, then delete the plugin from **Plugins → Installed Plugins**.

> **Warning:** This is irreversible. All assigned numbers, reservations, and settings will be permanently deleted.
