import assert from 'node:assert/strict';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { describe, test } from 'node:test';

/**
 * Validates the TackQuote theme app extension.
 *
 * Run with:  node --test shopify/validate-theme-extension.mjs
 *
 * WHY THIS EXISTS
 * ---------------
 * Liquid and its `{% schema %}` block cannot be type-checked, and a broken schema
 * does not fail loudly — Shopify simply declines to render the block, or the
 * theme editor silently omits it. The highest-value check by far is the comment
 * rule: **Shopify schema JSON supports neither comments nor trailing commas**,
 * unlike ordinary theme files, so both are habits a theme developer brings with
 * them and neither produces a useful error.
 *
 * Doc: https://shopify.dev/docs/apps/build/online-store/theme-app-extensions/configuration
 *
 * NO DEPENDENCIES ON PURPOSE
 * --------------------------
 * This repository has no package.json and no JS test runner — it ships PHP and
 * Liquid. This uses Node's built-in test runner so the guard can actually be run
 * here, rather than living in a repo that no longer contains the files it guards.
 */

const EXTENSION_DIR = path.resolve(import.meta.dirname, 'theme-app-extension');
const BLOCKS_DIR = path.join(EXTENSION_DIR, 'blocks');
const ASSETS_DIR = path.join(EXTENSION_DIR, 'assets');

const SCHEMA_RE = /\{%-?\s*schema\s*-?%\}([\s\S]*?)\{%-?\s*endschema\s*-?%\}/;

/** Every `.liquid` file under `blocks/`. Throws rather than returning [] — an
 *  empty list would make every assertion below pass vacuously. */
export function blockFiles() {
  const files = fs.readdirSync(BLOCKS_DIR).filter((f) => f.endsWith('.liquid'));
  if (files.length === 0) {
    throw new Error(`No .liquid blocks found in ${BLOCKS_DIR} — refusing to pass vacuously.`);
  }
  return files;
}

export function schemaBodyOf(file) {
  const src = fs.readFileSync(path.join(BLOCKS_DIR, file), 'utf8');
  const match = src.match(SCHEMA_RE);
  if (!match) throw new Error(`${file} has no {% schema %} block`);
  return match[1];
}

/**
 * Returns a list of human-readable problems, empty when the schema is valid.
 *
 * Comments and trailing commas are reported BY NAME, because "Unexpected token"
 * from JSON.parse does not tell a theme developer which habit bit them.
 */
export function validateSchemaBody(raw) {
  const errors = [];

  // Both checks run on a copy with string literals blanked, so a `//` inside a
  // label or an https:// URL is not a false positive.
  const withoutStrings = raw.replace(/"(?:[^"\\]|\\.)*"/g, '""');
  if (/\/\/|\/\*/.test(withoutStrings)) {
    errors.push('contains a comment — Shopify schema JSON does not support comments');
  }
  if (/,\s*[}\]]/.test(withoutStrings)) {
    errors.push('contains a trailing comma — Shopify schema JSON does not support them');
  }

  let parsed = null;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    errors.push(`is not valid JSON: ${err instanceof Error ? err.message : String(err)}`);
    return errors;
  }

  for (const key of ['name', 'target']) {
    if (parsed[key] === undefined) errors.push(`is missing the required key "${key}"`);
  }

  // `head`, `compliance_head` and `body` are the app EMBED targets. A
  // product-page block is always `section`.
  if (parsed.target !== undefined && parsed.target !== 'section') {
    errors.push(`has target "${String(parsed.target)}" — app blocks must target "section"`);
  }

  return errors;
}

describe('the validator itself detects breakage', () => {
  // Meta-tests. A schema guard that cannot FAIL is decoration, and this is the
  // one guard standing between a habit and a block that silently never renders.
  const hasError = (body, needle) => validateSchemaBody(body).some((e) => e.includes(needle));
  const VALID = '{ "name": "Add to Quote", "target": "section" }';

  test('accepts a valid schema', () => {
    assert.deepStrictEqual(validateSchemaBody(VALID), []);
  });

  test('rejects a line comment', () => {
    assert.ok(hasError('{\n // note\n "name": "X", "target": "section"\n}', 'comment'));
  });

  test('rejects a block comment', () => {
    assert.ok(hasError('{ /* note */ "name": "X", "target": "section" }', 'comment'));
  });

  test('rejects a trailing comma in an object', () => {
    assert.ok(hasError('{ "name": "X", "target": "section", }', 'trailing comma'));
  });

  test('rejects a trailing comma in an array', () => {
    assert.ok(hasError('{ "name": "X", "target": "section", "settings": [1,] }', 'trailing comma'));
  });

  test('rejects invalid JSON', () => {
    assert.ok(hasError('{ "name": ', 'not valid JSON'));
  });

  test('rejects a missing required key', () => {
    assert.ok(hasError('{ "target": "section" }', '"name"'));
    assert.ok(hasError('{ "name": "X" }', '"target"'));
  });

  test('rejects an app-embed target on a block', () => {
    assert.ok(hasError('{ "name": "X", "target": "head" }', 'must target'));
  });

  test('does NOT flag a URL or a comma inside a string literal', () => {
    assert.deepStrictEqual(
      validateSchemaBody('{ "name": "X", "target": "section", "info": "see https://a.b, ok" }'),
      [],
    );
  });
});

describe('the shipped blocks', () => {
  test('ships the three blocks the extension is meant to provide', () => {
    assert.deepStrictEqual(blockFiles().sort(), [
      'add-to-quote.liquid',
      'request-a-quote.liquid',
      'wholesale-price.liquid',
    ]);
  });

  for (const file of blockFiles()) {
    test(`${file} has a valid schema`, () => {
      assert.deepStrictEqual(validateSchemaBody(schemaBodyOf(file)), []);
    });

    test(`${file} is enabled only on the product template`, () => {
      const schema = JSON.parse(schemaBodyOf(file));
      assert.deepStrictEqual(schema.enabled_on?.templates, ['product']);
    });

    test(`${file} references asset files that exist`, () => {
      const schema = JSON.parse(schemaBodyOf(file));
      for (const key of ['stylesheet', 'javascript']) {
        const asset = schema[key];
        if (!asset) continue;
        assert.ok(
          fs.existsSync(path.join(ASSETS_DIR, asset)),
          `${file} declares ${key} "${asset}" but assets/${asset} does not exist`,
        );
      }
    });

    test(`${file} gives every setting a unique id`, () => {
      const schema = JSON.parse(schemaBodyOf(file));
      const ids = (schema.settings ?? [])
        .filter((s) => s.type !== 'paragraph' && s.type !== 'header')
        .map((s) => s.id);
      assert.deepStrictEqual(ids.length, new Set(ids).size, `${file} has duplicate setting ids`);
    });
  }
});

describe('configuration', () => {
  test('shopify.extension.toml declares only name and type', () => {
    // A theme app extension's behaviour is driven by its blocks and their
    // schema, so its config file is deliberately minimal.
    const toml = fs.readFileSync(path.join(EXTENSION_DIR, 'shopify.extension.toml'), 'utf8');
    const keys = toml
      .split('\n')
      .map((l) => l.split('=')[0].trim())
      .filter((k) => k && !k.startsWith('#') && !k.startsWith('['));
    for (const key of keys) {
      assert.ok(
        ['name', 'type', 'handle'].includes(key),
        `unexpected key "${key}" in shopify.extension.toml`,
      );
    }
  });

  test('the storefront locale file is valid JSON', () => {
    const raw = fs.readFileSync(path.join(EXTENSION_DIR, 'locales', 'en.default.json'), 'utf8');
    assert.doesNotThrow(() => JSON.parse(raw));
  });
});
