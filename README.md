# Fluent Forms Extended API

Standalone WordPress plugin that exposes a REST API for [Fluent Forms](https://fluentforms.com/) **form definitions** and **submissions** for headless sites, mobile apps, or external integrations. Reads use Fluent’s public PHP API; writes go through Fluent’s native submission handler (validation, storage, notifications, feeds).

**REST base:** `https://example.com/wp-json/fluent-forms-extended/v1/` (replace with your site URL)

## How it integrates with Fluent Forms

This plugin treats Fluent Forms as the **source of truth** and does **not** query `fluentform_forms` (or other tables) directly. It uses the documented global API:

- [`fluentFormApi('forms')`](https://developers.fluentforms.com/global-functions/) — recommended programmatic entry point  
- [`->forms()`](https://fluentforms.com/docs/fluent-form-php-api/) — paginated form list (we page until all summaries are collected)  
- [`->find($id)`](https://fluentforms.com/docs/fluent-form-php-api/) — load one form  
- [`->form($form)`](https://fluentforms.com/docs/fluent-form-php-api/) — `FormProperties` instance  
- [`->inputs(...)`](https://fluentforms.com/docs/fluent-form-php-api/) — parser-backed metadata (rules, options, labels) merged onto each field  
- [`->fields()`](https://fluentforms.com/docs/fluent-form-php-api/) — decoded editor tree for **structure** (containers, composite parents); combined with `inputs()` in our transformer to emit a **flat** normalised `fields` array (dotted logical ids, `group`, `component`, optional `submit_key`). Only the `submitButton` segment is used for `submit_button` in responses.  

**Submissions** are sent to Fluent Forms via its publicly-exposed AJAX action `fluentform_submit` (the same endpoint Fluent Forms' own JavaScript posts to). The call is made server-side with the WordPress HTTP API (`wp_remote_post()`) and authenticated cookies are forwarded so submissions stay attributed to the originating user. This plugin **does not** reference any class under `FluentForm\App\` or `FluentForm\Framework\`, instantiate any Fluent Forms internal service, catch internal exception types, or query the database — Fluent Forms is treated strictly as a black box accessed through its public surface.

The public REST response shape is **owned by this plugin** so it stays stable if Fluent Forms changes internals.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- **Fluent Forms** installed and **active**, with `fluentFormApi()` available (see [Global Functions](https://developers.fluentforms.com/global-functions/))

## Installation

1. Copy the entire plugin directory into `wp-content/plugins/` (for example as `fluent-forms-rest-api`).
2. In the WordPress admin, go to **Plugins** and activate **Fluent Forms Extended API** (bootstrap file: `fluent-forms-extended-api.php`).
3. Ensure **Fluent Forms** is also active.

No Composer step is required at runtime; a small PSR-4 autoloader ships in the main plugin file. Optional: run `composer dump-autoload` if you extend classes via Composer locally.

## Security note

Endpoints currently use `permission_callback` that always returns `true` (public). **Restrict or authenticate these routes before production use** (application passwords, JWT, custom capability checks, etc.).

## Endpoints

| Method | Route | Description |
|--------|--------|-------------|
| `GET` | `/fluent-forms-extended/v1/forms` | List all forms (`id`, `title`, `status`) |
| `GET` | `/fluent-forms-extended/v1/forms/{id}` | Single form: `id`, `title`, `status`, normalised `fields`, optional `submit_button` |
| `POST` | `/fluent-forms-extended/v1/forms/{id}/submit` | Submit a form (JSON body; keys = Fluent input names, or dotted logical `id` values from GET — see below) |
| `GET` | `/fluent-forms-extended/v1/entries` | Paginated submissions across **all** forms |
| `GET` | `/fluent-forms-extended/v1/entries/{entry_id}` | Single submission by id (404 when the entry does not exist) |
| `GET` | `/fluent-forms-extended/v1/forms/{form_id}/entries` | Paginated submissions for a single form (404 when the form does not exist) |

Full paths:

- `GET /wp-json/fluent-forms-extended/v1/forms`
- `GET /wp-json/fluent-forms-extended/v1/forms/12`
- `POST /wp-json/fluent-forms-extended/v1/forms/12/submit`
- `GET /wp-json/fluent-forms-extended/v1/entries`
- `GET /wp-json/fluent-forms-extended/v1/entries/102`
- `GET /wp-json/fluent-forms-extended/v1/forms/12/entries`

### Listing entries (`GET /entries` and `GET /forms/{form_id}/entries`)

Both routes share the same query string contract and response envelope. They read through the documented Fluent Forms public APIs — `fluentFormApi('submissions')->get()` for the cross-form list and `fluentFormApi('forms')->entryInstance($form)->entries()` for the per-form list — so no Fluent Forms internals are touched.

**Query parameters**

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `page` | int | `1` | 1-indexed; values < 1 are clamped to `1`. |
| `per_page` | int | `20` | Min `1`, max `100`. |
| `sort_by` | string | `id` | One of `id`, `created_at`. Both map to the supported upstream sort (submission id, which is monotonic with `created_at`). Anything else → **HTTP 400**. |
| `sort_order` | string | `DESC` | `ASC` or `DESC` (case-insensitive). Anything else → **HTTP 400**. |

**Permission callback (default)**

Entries contain PII, so entry routes are *not* public. The default `permission_callback` allows callers with the documented Fluent Forms `fluentform_entries_viewer` capability or `manage_options`. Integrators can plug in custom auth via:

- `fluent_forms_extended_api_can_view_entries` — `(bool $allow, WP_REST_Request $request)` — return your own decision (e.g. JWT/application-password gate).

**404 contract**

`GET /forms/{form_id}/entries` validates the form exists *before* querying entries by calling `fluentFormApi('forms')->find($id)`. Missing forms return `HTTP 404`:

```json
{ "success": false, "message": "No form exists for the requested id." }
```

### Fetching a single entry (`GET /entries/{entry_id}`)

Returns one submission by its primary id (the same id surfaced as `entry_id` in list responses). Internally this goes through the documented `fluentFormApi('submissions')->find()` public method, wrapped defensively because the upstream method does not null-guard on missing ids. Unknown ids → `HTTP 404`.

The single-entry body adds `updated_at` and `payment_status` on top of the list-entry shape. `payment_status` is `null` for non-payment forms so clients can distinguish "no payment context" from values like `"paid"` / `"pending"` / `"failed"`.

### Submitting a form (`POST /forms/{id}/submit`)

1. Use **`GET /forms/{id}`** to discover the schema. Each row is a **flat** field definition:
   - **`id`** — stable logical identifier (e.g. `names.first_name` for a name subfield, `email` for a simple field).
   - **`submit_key`** — when present, this is the key Fluent Forms expects in the POST body (e.g. `names_first_name_1`). If omitted, **`id`** is the submit name.
   - **`group`** / **`component`** — for composite-derived rows (`name`, `address`, repeaters, …) so clients can group UI or map provider semantics without nested JSON.
2. Send **`Content-Type: application/json`** with an object whose keys are either each field’s **`submit_key`** (preferred) or **`id`**. The plugin maps dotted **`id`** keys to **`submit_key`** automatically when only the logical id is sent (and the real key is not already present).

Example (classic contact-style form):

```bash
curl -sS -X POST "https://example.com/wp-json/fluent-forms-extended/v1/forms/12/submit" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","message":"Hello world"}'
```

Optional: include `_wp_http_referer` as a string URL if you want Fluent’s `source_url` metadata set to something specific; otherwise the site home URL is used.

Fluent **reCAPTCHA / hCaptcha / Turnstile** and other global checks still apply when configured on the form — pass the same token field names Fluent uses (e.g. `g-recaptcha-response`) in the JSON body when required.

**Extension hooks (auth / rate limits):**

- `fluent_forms_extended_api_can_submit` — `(bool $allow, int $formId, array $payload)`; return `false` to block before the handler runs.
- `fluent_forms_extended_api_submission_payload` — filter the associative array immediately before Fluent’s handler runs (e.g. append trusted server-side fields).
- `fluent_forms_extended_api_validation_field_aliases` — `(array $aliases, string $schemaFieldId)` extend dot/bracket aliases for a known schema field id.
- `fluent_forms_extended_api_validation_field_meta` — `(array $default, string $fieldKey, array $lookup)` supply label/type when the error key is not in the normalised schema.
- `fluent_forms_extended_api_normalize_leaf_field` — `(array $row, array $editorNode, array $entryInputs)` adjust a single normalised field row (including flattened composite children).

## Example requests

```bash
curl -sS "https://example.com/wp-json/fluent-forms-extended/v1/forms"
```

```bash
curl -sS "https://example.com/wp-json/fluent-forms-extended/v1/forms/12"
```

```bash
curl -sS -X POST "https://example.com/wp-json/fluent-forms-extended/v1/forms/12/submit" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","message":"Hello world"}'
```

```bash
curl -sS -u "admin:APP-PASSWORD" \
  "https://example.com/wp-json/fluent-forms-extended/v1/entries?page=1&per_page=20&sort_order=DESC"
```

```bash
curl -sS -u "admin:APP-PASSWORD" \
  "https://example.com/wp-json/fluent-forms-extended/v1/entries/102"
```

```bash
curl -sS -u "admin:APP-PASSWORD" \
  "https://example.com/wp-json/fluent-forms-extended/v1/forms/12/entries?page=2&per_page=50&sort_by=created_at&sort_order=ASC"
```

## Example responses

### `GET /forms`

```json
[
  {
    "id": 12,
    "title": "Contact",
    "status": "published"
  },
  {
    "id": 15,
    "title": "Newsletter",
    "status": "published"
  }
]
```

### `GET /forms/12`

```json
{
  "id": 12,
  "title": "Contact",
  "status": "published",
  "fields": [
    {
      "id": "names.first_name",
      "submit_key": "names_first_name_1",
      "type": "text",
      "group": "names",
      "component": "name.first",
      "label": "First Name",
      "required": true,
      "placeholder": "First Name"
    },
    {
      "id": "names.last_name",
      "submit_key": "names_last_name_1",
      "type": "text",
      "group": "names",
      "component": "name.last",
      "label": "Last Name",
      "required": false,
      "placeholder": ""
    },
    {
      "id": "email",
      "type": "email",
      "label": "Email Address",
      "required": true,
      "placeholder": "Enter your email",
      "validation": {
        "rules": [
          {
            "rule": "email",
            "message": "Please enter a valid email"
          }
        ]
      }
    },
    {
      "id": "dropdown",
      "type": "select",
      "label": "How did you hear about us?",
      "required": false,
      "placeholder": "",
      "options": [
        { "label": "Search Engine", "value": "search" },
        { "label": "Friend", "value": "friend" }
      ]
    }
  ],
  "submit_button": {
    "text": "Submit",
    "align": "left"
  }
}
```

#### Canonical `type` values (adapter contract)

Leaf fields use a small vocabulary so frontends do not need Fluent-specific `element` names: **`text`**, **`textarea`**, **`email`**, **`url`**, **`number`**, **`password`**, **`hidden`**, **`phone`**, **`select`**, **`radio`**, **`checkbox`**, **`date`**, **`file`**, **`rating`**, **`scale`**, **`html`**, **`separator`**, **`tabular_grid`**, and more mapped from Fluent primitives. Unknown Fluent elements fall back to a stripped `input_*` suffix or a generic string, then **`unknown`** when empty.

**Composite** Fluent blocks (name, address, repeaters, etc.) are **flattened** into the top-level `fields` list (no nested `type: "group"` objects). Each derived row includes:

- **`id`** — dotted logical path (e.g. `names.first_name`).
- **`submit_key`** — when different from `id`, the real Fluent input name for POST bodies and validation keys.
- **`group`** — the parent composite’s attribute name (e.g. `names`).
- **`component`** — provider hint plus part (e.g. `name.first`, `address.line1`, `repeat.myFieldKey`).

Optional **`component`** on **simple** (non-composite) fields still appears when the Fluent `element` carries more nuance than the canonical type (e.g. `select_country` → `type: "select"`, `component: "select_country"`).

Name and address **subfields** follow the same visibility rules as Fluent’s parser: disabled editor subfields are omitted from both `inputs()` and this API. Structure is built from the documented **`fields()`** tree plus **`inputs()`** enrichment — raw editor JSON is never exposed on the wire.

**`validation` object:** `required` is expressed only on the row root as **`required`: true|false**. The `validation` payload omits a duplicate `required` flag and drops redundant `rules[]` entries whose `rule` is `required` when the root already marks the field required. Text-like min constraints may appear as **`minLength`** (mapped from Fluent’s `min` rule) for **`text`** / **`textarea`** types.

### `POST /forms/12/submit` — success

```json
{
  "success": true,
  "message": "Form submitted successfully"
}
```

The `message` string is taken from Fluent’s confirmation settings when available (plain text for JSON clients).

### `POST /forms/12/submit` — validation error (HTTP 422 by default)

`errors` is an **array** of objects (stable adapter contract). Each item includes the Fluent **input name** in **`field`** (this matches **`submit_key`** from the schema when set, otherwise **`id`**), human **label** and simplified **type** from the same normalised schema as `GET /forms/{id}`, plus the validation **message** Fluent produced:

```json
{
  "success": false,
  "errors": [
    {
      "field": "email",
      "label": "Email Address",
      "message": "This field is required",
      "type": "email"
    },
    {
      "field": "message",
      "label": "Message",
      "message": "This field is required",
      "type": "textarea"
    }
  ]
}
```

If Fluent reports a key that is not in the form schema (e.g. captcha or global add-on fields), `label` may be empty and `type` will be `unknown`. You can refine those via the `fluent_forms_extended_api_validation_field_meta` filter. Non-standard key spellings can be adjusted with `fluent_forms_extended_api_validation_field_aliases`. Form-level messages use the pseudo-field `_form`.

### `GET /entries` (and `GET /forms/{form_id}/entries`)

```json
{
  "total": 47,
  "current_page": 1,
  "per_page": 20,
  "total_pages": 3,
  "data": [
    {
      "entry_id": 102,
      "form_id": 12,
      "status": "read",
      "created_at": "2026-05-13 09:14:22",
      "user_id": 5,
      "user_ip": "203.0.113.42",
      "browser": "Chrome",
      "device": "Desktop",
      "submission": {
        "names": {
          "first_name": "Ada",
          "last_name": "Lovelace"
        },
        "email": "ada@example.com",
        "message": "Hello world"
      }
    },
    {
      "entry_id": 101,
      "form_id": 15,
      "status": "unread",
      "created_at": "2026-05-12 22:08:11",
      "user_id": null,
      "user_ip": "198.51.100.7",
      "browser": "Safari",
      "device": "iPhone",
      "submission": {
        "email": "guest@example.com"
      }
    }
  ]
}
```

`submission` is the field-name → value map exactly as Fluent Forms stores it (JSON-decoded by the public API). For composite Fluent fields (name, address, repeaters, …) it is a nested object, matching the `submit_key`/`group` structure from `GET /forms/{id}`. `user_id` is `null` for guest submissions.

### `GET /entries/{entry_id}`

```json
{
  "entry_id": 102,
  "form_id": 12,
  "status": "read",
  "created_at": "2026-05-13 09:14:22",
  "updated_at": "2026-05-13 10:01:18",
  "user_id": 5,
  "user_ip": "203.0.113.42",
  "browser": "Chrome",
  "device": "Desktop",
  "payment_status": "paid",
  "submission": {
    "names": { "first_name": "Ada", "last_name": "Lovelace" },
    "email": "ada@example.com",
    "message": "Hello world"
  }
}
```

### `GET /entries/{entry_id}` — unknown entry (HTTP 404)

```json
{
  "success": false,
  "message": "No entry exists for the requested id."
}
```

### `GET /forms/{form_id}/entries` — unknown form (HTTP 404)

```json
{
  "success": false,
  "message": "No form exists for the requested id."
}
```

### `GET /entries?sort_by=foo` — invalid query (HTTP 400)

```json
{
  "success": false,
  "message": "Invalid sort_by value.",
  "allowed_sort_by": ["id", "created_at"]
}
```

### `POST /forms/12/submit` — server / dependency error

```json
{
  "success": false,
  "message": "An unexpected error occurred"
}
```

When Fluent Forms is unavailable, `message` explains the dependency instead.

## Errors

When Fluent Forms is not available, endpoints return JSON error objects with HTTP **503**, for example:

```json
{
  "code": "fluent_forms_extended_api_missing_fluent_forms",
  "message": "Fluent Forms is not installed, not active, or has not finished loading...",
  "data": { "status": 503 }
}
```

Unknown form id returns **404** with code `fluent_forms_extended_api_form_not_found`.

## Project layout

| Path | Role |
|------|------|
| `fluent-forms-extended-api.php` | Plugin header, constants, PSR-4 autoload, bootstraps `Plugin` |
| `src/Plugin.php` | Wires services and registers REST controller on `rest_api_init` |
| `src/Rest/FormsController.php` | `register_rest_route` + `WP_REST_Response` / `WP_Error` (forms + submit) |
| `src/Rest/EntriesController.php` | `register_rest_route` + `WP_REST_Response` for entry routes; permission callback for PII gating |
| `src/Services/FormService.php` | Orchestrates `fluentFormApi('forms')` and normalisation |
| `src/Services/FormSubmissionService.php` | Submissions: payload prep + result mapping (HTTP-status based; zero internal class refs) |
| `src/Services/EntryService.php` | Validates query/form/entry existence, paginates via the public entry APIs |
| `src/Support/FluentFormsGateway.php` | Thin wrapper: `forms()`, `find()`, `form()` |
| `src/Support/FluentEntriesGateway.php` | Thin wrapper: `submissions()->get()`, `submissions()->find()`, `forms()->entryInstance($form)->entries()` |
| `src/Support/FluentSubmissionClient.php` | Loopback `wp_remote_post()` to Fluent Forms' public `fluentform_submit` AJAX action |
| `src/Support/SubmissionResponseNormalizer.php` | Stable JSON for success and generic server errors |
| `src/Support/EntryResponseNormalizer.php` | Maps Submission row objects to the public entry contract |
| `src/Support/ValidationErrorNormalizer.php` | Maps Fluent validation errors + normalised field schema to `errors[]` |
| `src/Support/FieldSchemaTransformer.php` | Flat canonical field schema: `fields()` + `inputs()` merge, composite flattening, `submitButton` |
| `src/Support/FluentFormsDependency.php` | `function_exists('fluentFormApi')` guard |

## Licence

MIT

![Cursor](https://img.shields.io/badge/Cursor-%23000000?style=for-the-badge&logo=Cursor&logoColor=white)
