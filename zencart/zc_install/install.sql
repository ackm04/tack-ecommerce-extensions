-- TackQuote for Zen Cart — installer SQL.
--
-- Zen Cart's admin "Configuration" screen (admin/configuration.php) auto-renders
-- an editable form for any row present in `configuration_group` + `configuration`
-- — this is the same mechanism Zen Cart core uses for every built-in setting
-- (e.g. STORE_STATUS, SHIPPING_ORIGIN). Running this file gives TackQuote a
-- real admin settings screen without any custom admin PHP page: after import,
-- an admin sees "Configuration > TackQuote" in the left sidebar.
--
-- Zen Cart also auto-defines every `configuration_key` as a PHP constant at
-- bootstrap (see includes/classes/db_helper style loading in
-- includes/application_top.php via `define()` over configuration rows), so
-- TACK_API_URL / TACK_API_KEY / etc. are available anywhere in the storefront
-- and admin without extra code.
--
-- Run this against your Zen Cart database (phpMyAdmin, `mysql < install.sql`,
-- or Zen Cart's admin "Install SQL Patches" tool if enabled).

INSERT INTO configuration_group
  (configuration_group_title, configuration_group_description, sort_order, visible)
VALUES
  ('TackQuote', 'TackQuote B2B quoting connector — API connection and storefront button settings.', 1, 1);

INSERT INTO configuration
  (configuration_title, configuration_key, configuration_value, configuration_description,
   configuration_group_id, sort_order, date_added, use_function, set_function)
SELECT
  'TackQuote API URL', 'TACK_API_URL', 'https://api.tackquote.com/v1',
  'Base URL of the TackQuote API, e.g. https://api.tackquote.com/v1 (no trailing slash). Change only for a custom/staging deployment.',
  configuration_group_id, 1, now(), NULL, NULL
FROM configuration_group WHERE configuration_group_title = 'TackQuote';

INSERT INTO configuration
  (configuration_title, configuration_key, configuration_value, configuration_description,
   configuration_group_id, sort_order, date_added, use_function, set_function)
SELECT
  'TackQuote API Key', 'TACK_API_KEY', '',
  'Your TackQuote API key (TackQuote > Settings > Developer > API Keys). Sent as both `Authorization: Bearer` and `X-Api-Key`.',
  configuration_group_id, 2, now(), NULL, NULL
FROM configuration_group WHERE configuration_group_title = 'TackQuote';

INSERT INTO configuration
  (configuration_title, configuration_key, configuration_value, configuration_description,
   configuration_group_id, sort_order, date_added, use_function, set_function)
SELECT
  'Show "Request a Quote" button', 'TACK_ENABLE_WIDGET', 'true',
  'When true, product info pages show a "Request a Quote" button. It never adds to cart or affects checkout — it only posts a quote request to TackQuote.',
  configuration_group_id, 3, now(), 'zen_cfg_select_option(array(''true'', ''false''), ', NULL
FROM configuration_group WHERE configuration_group_title = 'TackQuote';

INSERT INTO configuration
  (configuration_title, configuration_key, configuration_value, configuration_description,
   configuration_group_id, sort_order, date_added, use_function, set_function)
SELECT
  'Button label', 'TACK_BUTTON_LABEL', 'Request a Quote',
  'Text shown on the storefront button, e.g. "Request a Quote" or "Get a B2B quote".',
  configuration_group_id, 4, now(), NULL, NULL
FROM configuration_group WHERE configuration_group_title = 'TackQuote';

-- Inbound direction (TackQuote -> this store): the Bearer token that
-- tack-connector/index.php accepts on /products and /orders. This is a
-- DIFFERENT secret from TACK_API_KEY above, which authenticates this store when
-- it calls OUT to the TackQuote API. Empty = the connector is switched off and
-- answers 503, so a store that has merely copied the files in never serves
-- catalog or order data to an unauthenticated caller.
INSERT INTO configuration
  (configuration_title, configuration_key, configuration_value, configuration_description,
   configuration_group_id, sort_order, date_added, use_function, set_function)
SELECT
  'TackQuote connector token', 'TACK_CONNECTOR_TOKEN', '',
  'Bearer token TackQuote must present to read this store''s catalog and orders and to place quote-accepted orders. Generate a long random URL-safe string (letters, digits, - and _), paste it here, and paste the SAME string into TackQuote under Settings > Integrations > Zen Cart. Leave empty to keep the connector switched off.',
  configuration_group_id, 5, now(), NULL, NULL
FROM configuration_group WHERE configuration_group_title = 'TackQuote';
