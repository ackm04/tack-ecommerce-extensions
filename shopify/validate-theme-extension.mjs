import assert from 'node:assert/strict';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { describe, test } from 'node:test';
import { gzipSync } from 'node:zlib';

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

/**
 * Which templates each block is allowed on.
 *
 * A per-block map rather than a blanket "product only" rule. The three product
 * blocks answer a question about the item being viewed, so `product` is the only
 * template where they mean anything. A wholesale APPLICATION is not about a
 * product at all — merchants put it on a dedicated page — so pinning it to
 * `product` would have made it unplaceable where it belongs. The map exists so
 * that widening one block cannot silently widen the others.
 */
export const EXPECTED_TEMPLATES = {
  'add-to-quote.liquid': ['product'],
  'request-a-quote.liquid': ['product'],
  'wholesale-price.liquid': ['product'],
  'volume-tiers.liquid': ['product'],
  'wholesale-signup.liquid': ['page', 'index'],
  // The only block that is not about the item being viewed. A buyer's group is
  // a fact about their ACCOUNT, so it is equally meaningful beside a product,
  // in the cart, and on a wholesale landing page.
  'buyer-group-badge.liquid': ['product', 'cart', 'page'],
};

describe('the shipped blocks', () => {
  test('ships exactly the blocks the extension is meant to provide', () => {
    assert.deepStrictEqual(blockFiles().sort(), Object.keys(EXPECTED_TEMPLATES).sort());
  });

  for (const file of blockFiles()) {
    test(`${file} has a valid schema`, () => {
      assert.deepStrictEqual(validateSchemaBody(schemaBodyOf(file)), []);
    });

    test(`${file} is enabled only on its expected templates`, () => {
      const schema = JSON.parse(schemaBodyOf(file));
      assert.deepStrictEqual(schema.enabled_on?.templates, EXPECTED_TEMPLATES[file]);
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

/**
 * Every `'tackquote.x.y' | t` a block asks for, in order of appearance.
 *
 * A missing key does not fail — Shopify renders the literal string
 * `translation missing: en.tackquote.x.y` into the storefront, in front of
 * shoppers. That is the single most likely way this extension embarrasses a
 * merchant, and it is invisible to every other check here.
 */
export function translationKeysUsed() {
  const used = new Set();
  for (const file of blockFiles()) {
    const src = fs.readFileSync(path.join(BLOCKS_DIR, file), 'utf8');
    for (const match of src.matchAll(/'(tackquote\.[a-z0-9_.]+)'\s*\|\s*t\b/g)) {
      used.add(match[1]);
    }
  }
  return [...used].sort();
}

describe('storefront translations', () => {
  const locale = JSON.parse(
    fs.readFileSync(path.join(EXTENSION_DIR, 'locales', 'en.default.json'), 'utf8'),
  );
  const resolve = (key) => key.split('.').reduce((node, part) => node?.[part], locale);

  test('finds the keys the blocks actually use', () => {
    // Guards the regex, not the locale file. If this ever returns nothing, the
    // assertion below would pass while checking nothing at all.
    const used = translationKeysUsed();
    assert.ok(used.length >= 8, `only found ${used.length} translation keys — regex is wrong`);
    assert.ok(used.includes('tackquote.signup.loading'));
  });

  for (const key of translationKeysUsed()) {
    test(`${key} resolves in en.default.json`, () => {
      assert.equal(typeof resolve(key), 'string', `${key} is missing or is not a string`);
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

/**
 * Shopify's file and content size limits for a theme app extension.
 *
 * Verified against shopify.dev's "file and content size limits" page for theme
 * app extensions. Two classes, and the distinction matters when one trips:
 *
 *   ENFORCED  — Shopify REJECTS the deploy. The 100 KB total-Liquid ceiling.
 *   SUGGESTED — Shopify does not reject, it just gets slower for shoppers. The
 *               10 KB compressed ceiling per schema-referenced JavaScript file
 *               and 100 KB compressed for CSS.
 *
 * Both are asserted here, because a suggestion nobody measures is a suggestion
 * nobody keeps — and this extension gains a block, a runtime and a stylesheet
 * rule every time it grows. A deploy that silently fails validation is the
 * failure mode this guards.
 *
 * Compressed sizes use gzip. Shopify does not publish which encoder it measures
 * with, and Brotli would read smaller — so gzip is the conservative choice and
 * a pass here is a pass either way.
 */
export const SIZE_LIMITS = {
  /** Per schema-referenced JS asset, gzipped. Suggested, not enforced. */
  jsGzipBytes: 10 * 1024,
  /** Per schema-referenced CSS asset, gzipped. Suggested, not enforced. */
  cssGzipBytes: 100 * 1024,
  /** Every .liquid file in the extension, added together. ENFORCED. */
  totalLiquidBytes: 100 * 1024,
};

export function gzipBytes(filePath) {
  return gzipSync(fs.readFileSync(filePath)).length;
}

/** Assets named by a `javascript` or `stylesheet` key in any block schema. */
export function schemaReferencedAssets() {
  const found = new Map();
  for (const file of blockFiles()) {
    const schema = JSON.parse(schemaBodyOf(file));
    for (const key of ['javascript', 'stylesheet']) {
      if (schema[key]) found.set(schema[key], key);
    }
  }
  if (found.size === 0) {
    throw new Error('No schema-referenced assets found — refusing to pass vacuously.');
  }
  return [...found.entries()].map(([asset, key]) => ({ asset, key }));
}

/**
 * Every `.liquid` file the extension ships, not just the blocks.
 *
 * `snippets/` counts toward the same total. Measuring only `blocks/` would let
 * the ceiling be breached by a snippet and report a comfortable margin right up
 * until the deploy was rejected.
 */
export function liquidFiles() {
  const dirs = ['blocks', 'snippets'];
  const files = [];
  for (const dir of dirs) {
    const full = path.join(EXTENSION_DIR, dir);
    if (!fs.existsSync(full)) continue;
    for (const name of fs.readdirSync(full)) {
      if (name.endsWith('.liquid')) files.push(path.join(full, name));
    }
  }
  if (files.length === 0) {
    throw new Error('No .liquid files found — refusing to pass vacuously.');
  }
  return files;
}

describe('the size guards can fail', () => {
  // Meta-tests, matching the schema guard above: a size check that cannot report
  // a breach is a number printed for decoration.
  test('gzipBytes grows with the file it measures', () => {
    const assets = schemaReferencedAssets().map(({ asset }) =>
      gzipBytes(path.join(ASSETS_DIR, asset)),
    );
    assert.ok(
      assets.every((n) => n > 0),
      'every measured asset should have a non-zero compressed size',
    );
  });

  test('the limits are the documented ones, not whatever currently passes', () => {
    // Pinned so that a future breach cannot be resolved by raising the ceiling
    // without someone deliberately editing these three numbers.
    assert.equal(SIZE_LIMITS.jsGzipBytes, 10240);
    assert.equal(SIZE_LIMITS.cssGzipBytes, 102400);
    assert.equal(SIZE_LIMITS.totalLiquidBytes, 102400);
  });

  test('finds every liquid file, snippets included', () => {
    const names = liquidFiles().map((f) => path.basename(f));
    assert.ok(names.length >= blockFiles().length, 'should count at least the blocks');
    assert.ok(
      names.some((n) => n.startsWith('tackquote-')),
      'should reach into snippets/ as well as blocks/',
    );
  });
});

describe('Shopify size limits', () => {
  for (const { asset, key } of schemaReferencedAssets()) {
    const limit = key === 'javascript' ? SIZE_LIMITS.jsGzipBytes : SIZE_LIMITS.cssGzipBytes;
    test(`${asset} stays under the ${limit / 1024} KB compressed suggestion`, () => {
      const size = gzipBytes(path.join(ASSETS_DIR, asset));
      assert.ok(
        size <= limit,
        `assets/${asset} is ${size} B gzipped, over the ${limit} B suggestion for a ${key} asset`,
      );
    });
  }

  test('tackquote-shared.js stays under the JS suggestion', () => {
    /*
     * Called out separately because it is the file that grows every time.
     * Every block loads it via `asset_url`, so it is not named by any schema's
     * `javascript` key and the loop above would never look at it — while being
     * the one file whose weight is paid on every page that carries any block.
     */
    const size = gzipBytes(path.join(ASSETS_DIR, 'tackquote-shared.js'));
    assert.ok(
      size <= SIZE_LIMITS.jsGzipBytes,
      `tackquote-shared.js is ${size} B gzipped, over the ${SIZE_LIMITS.jsGzipBytes} B suggestion`,
    );
  });

  test('total Liquid stays under the 100 KB ENFORCED limit', () => {
    const total = liquidFiles().reduce((sum, f) => sum + fs.statSync(f).size, 0);
    assert.ok(
      total <= SIZE_LIMITS.totalLiquidBytes,
      `Liquid totals ${total} B across ${liquidFiles().length} files, over the enforced ${SIZE_LIMITS.totalLiquidBytes} B limit`,
    );
  });

  test('stays under the 30 app blocks Shopify enforces per extension', () => {
    // Raised from 25 to 30 on 2026-02-03 per the shopify.dev changelog.
    assert.ok(blockFiles().length <= 30, `${blockFiles().length} blocks, over the enforced 30`);
  });
});
