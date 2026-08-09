-- TackQuote for Zen Cart — upgrade patch for stores that already ran
-- install.sql BEFORE the tack-connector/ files existed.
--
-- install.sql now creates TACK_CONNECTOR_TOKEN as part of a fresh install.
-- Run THIS file instead if the "TackQuote" configuration group is already
-- present and only the new setting is missing; re-running install.sql on an
-- existing store would create a second "TackQuote" group.
--
-- Safe to re-run: the INSERT is guarded by a NOT EXISTS on the key.

INSERT INTO configuration
  (configuration_title, configuration_key, configuration_value, configuration_description,
   configuration_group_id, sort_order, date_added, use_function, set_function)
SELECT
  'TackQuote connector token', 'TACK_CONNECTOR_TOKEN', '',
  'Bearer token TackQuote must present to read this store''s catalog and orders and to place quote-accepted orders. Generate a long random URL-safe string (letters, digits, - and _), paste it here, and paste the SAME string into TackQuote under Settings > Integrations > Zen Cart. Leave empty to keep the connector switched off.',
  cg.configuration_group_id, 5, now(), NULL, NULL
FROM configuration_group cg
WHERE cg.configuration_group_title = 'TackQuote'
  AND NOT EXISTS (
    SELECT 1 FROM configuration c WHERE c.configuration_key = 'TACK_CONNECTOR_TOKEN'
  );
