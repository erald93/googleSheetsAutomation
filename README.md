# Google Sheets Automation

A Laravel application that reads data from several Google Sheets files and saves it
to the DB. Which files, tabs and columns are imported, and where each column is
stored, is configured in database tables.

**Disclaimer:** Claude AI was used to help build this project.

## How it works

1. Configuration lives in three tables:
   - `sheet_sources`: the spreadsheets to read
   - `sheet_tabs`: the tab to read in each spreadsheet
   - `column_mappings`: for each tab, which sheet column goes into which
     destination table and column, and its type
2. `php artisan sheets:sync` imports every active tab.

## Setup

```bash
git clone https://github.com/erald93/googleSheetsAutomation.git googleSheetsAutomation
cd googleSheetsAutomation
composer install
cp .env.example .env
php artisan key:generate
```

The default SQLite configuration can be used for a quick trial.

Create the Google credentials (at the bottom section), then set the example spreadsheet:

```
GOOGLE_SHEETS_CREDENTIALS=storage/app/google/credentials.json
GOOGLE_SHEETS_SEED_ID=your-spreadsheet-id
GOOGLE_SHEETS_SEED_TAB=Sheet1
```

Finally:

```bash
php artisan migrate
php artisan db:seed --class=SheetConfigSeeder
php artisan sheets:sync
```

## Configuration

The seeder `database/seeders/SheetConfigSeeder.php` creates an example setup for
the spreadsheet in `.env`. The example expects these headers in the first row of the tab

| Sheet header   | Saved to              | Type    |
|----------------|-----------------------|---------|
| `date`         | `sales.date`          | date    |
| `product_name` | `sales.name`          | string  |
| `price`        | `sales.product_price` | decimal |
| `amount`       | `sales.amount`        | integer |

Supported types: `string`, `text`, `integer`, `decimal`, `date`, `datetime`, `boolean`.

### Changing the configuration

Edit the mapping list (with `php artisan tinker`). The edit is created on the next
  import and filled with data.

- **Add a column:** add a mapping. 
  ```php
  App\Models\ColumnMapping::create([
      'sheet_tab_id' => 1,
      'source_column' => 'cost',
      'destination_table' => 'sales',
      'destination_column' => 'cost',
      'destination_type' => 'decimal',
  ]);
  ```
- **Add a tab:** 
   ```php
   App\Models\SheetTab::create([
      'sheet_source_id' => 1,
      'tab_name' => 'Sheet2',
      'is_active' => true
   ]);
   ```
- **Add a source:** 
   ```php
   App\Models\SheetSource::create([
      'name' => 'Sales B',
      'spreadsheet_id' => 'your-spreadsheet-id',
      'is_active' => true
   ]);
   ```

## Running

```bash
php artisan sheets:sync          # all active tabs
php artisan sheets:import 1      # one tab, by id
```

The scheduler runs `sheets:sync` daily at 02:00 (`APP_TIMEZONE`). It needs one cron entry:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Creating the Google API credentials

The application authenticates with a service account (no user login needed).

1. Open https://console.cloud.google.com/ and sign in. Google requires 2-step
   verification on the account to use the console.
2. Create a project (project picker at the top, then **New project**).
3. Go to **APIs & Services, then Library**, search for **Google Sheets API**, and click **Enable**.
4. Go to **Project Settings, then IAM & Admin, then Service Accounts, then Create service account**. Give it a name and
   finish; no project role is required.
5. Open the service account, then the **Keys** tab, then **Add key, Create new key, JSON**.
   A JSON file is downloaded.
6. Save it at `storage/app/google/credentials.json` (set
   `GOOGLE_SHEETS_CREDENTIALS=/full/path/to/file.json` in `.env`).
7. Create the spreadsheet in Google Sheets https://sheets.new:
8. Copy the `client_email` value from the JSON (it looks like
   `name@project.iam.gserviceaccount.com`) and **share each spreadsheet with that
   address** (Viewer access is enough).
9. The spreadsheet id is the part of its URL between `/d/` and `/edit`.
10. Update the .env file.