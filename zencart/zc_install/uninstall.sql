-- TackQuote for Zen Cart — uninstaller SQL. Removes the settings added by
-- install.sql. Safe to re-run.

DELETE FROM configuration WHERE configuration_key IN (
  'TACK_API_URL', 'TACK_API_KEY', 'TACK_ENABLE_WIDGET', 'TACK_BUTTON_LABEL'
);

DELETE FROM configuration_group WHERE configuration_group_title = 'TackQuote';
