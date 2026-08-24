<?php
/**
 * The smallest slice of OpenCart 4's engine that the TackQuote admin controller
 * actually touches, so its save/test paths can be exercised without a store, a
 * database, or a web server.
 *
 * These are STUBS, deliberately dumb, and they are not a model of OpenCart. The
 * one thing they reproduce faithfully is the bit the controller depends on:
 * `Controller::__get()` resolving `$this->config`, `$this->user`,
 * `$this->model_setting_setting` and friends out of a Registry, which is how
 * real OpenCart populates them (`system/engine/controller.php` +
 * `system/engine/loader.php`). Everything else is a recorder, so a test can
 * assert on what the controller DID rather than on a mocked return value.
 */

namespace Opencart\System\Engine;

class Registry
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}

class Controller
{
    protected Registry $registry;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    public function __get(string $key)
    {
        return $this->registry->get($key);
    }

    public function __set(string $key, $value): void
    {
        $this->registry->set($key, $value);
    }
}

namespace Tack\Test;

use Opencart\System\Engine\Registry;

class Request
{
    /** @var array<string, mixed> */
    public array $post = [];

    /** @var array<string, mixed> */
    public array $get = [];

    /** @var array<string, mixed> */
    public array $server = ['REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1'];
}

class Response
{
    /** @var array<int, string> */
    public array $headers = [];

    public string $output = '';

    /**
     * Recorded, not performed. A redirect on the save path is the defect this
     * harness exists to catch, so it must be observable rather than fatal.
     */
    public ?string $redirect = null;

    public function addHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    public function setOutput($output): void
    {
        $this->output = (string) $output;
    }

    public function redirect(string $url): void
    {
        $this->redirect = $url;
    }
}

class Config
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}

class User
{
    /** @param array<int, string> $granted "<type>:<route>" pairs this user may do. */
    public function __construct(private array $granted = [])
    {
    }

    public function hasPermission(string $type, string $route): bool
    {
        return in_array($type . ':' . $route, $this->granted, true);
    }
}

/** Loads the extension's REAL language file, so tests fail if a key is missing. */
class Language
{
    /** @var array<string, string> */
    private array $strings = [];

    public function load(string $file): void
    {
        $_ = [];
        require $file;
        $this->strings = array_merge($this->strings, $_);
    }

    public function get(string $key): string
    {
        // Real OpenCart echoes the key back when it is undefined, which is how a
        // missing string reaches a merchant's screen. Mirrored so a test can spot it.
        return $this->strings[$key] ?? $key;
    }

    public function has(string $key): bool
    {
        return isset($this->strings[$key]);
    }
}

class Url
{
    public function link(string $route, string $args = '', bool $secure = false): string
    {
        return 'https://store.example/admin/index.php?route=' . $route . ($args !== '' ? '&' . $args : '');
    }
}

class Session
{
    /** @var array<string, mixed> */
    public array $data = ['user_token' => 'test-token-0123456789abcdef'];
}

class Document
{
    public string $title = '';

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}

/** Records every editSetting() call so a test can assert what was persisted. */
class SettingModel
{
    /** @var array<int, array{group: string, values: array<string, mixed>}> */
    public array $writes = [];

    /** @var array<string, array<string, mixed>> */
    public array $stored = [];

    /** @param array<string, mixed> $values */
    public function editSetting(string $group, array $values, int $storeId = 0): void
    {
        $this->writes[] = ['group' => $group, 'values' => $values];
        $this->stored[$group] = array_merge($this->stored[$group] ?? [], $values);
    }

    /** @return array<string, mixed> */
    public function getSetting(string $group, int $storeId = 0): array
    {
        return $this->stored[$group] ?? [];
    }

    public function deleteSetting(string $group, int $storeId = 0): void
    {
        unset($this->stored[$group]);
    }
}

class EventModel
{
    /** @var array<int, array<string, mixed>> */
    public array $added = [];

    /** @var array<int, string> */
    public array $deleted = [];

    /** @param array<string, mixed> $event */
    public function addEvent(array $event): void
    {
        $this->added[] = $event;
    }

    public function getEventByCode(string $code)
    {
        foreach ($this->added as $event) {
            if ($event['code'] === $code) {
                return $event;
            }
        }

        return false;
    }

    public function deleteEventByCode(string $code): void
    {
        $this->deleted[] = $code;
    }
}

/**
 * Just enough of OpenCart's DB layer to exercise the catalog feed's chunk loop.
 *
 * Parses the trailing `LIMIT <offset>,<limit>` off the SQL and slices a fake
 * product table, and answers `SELECT COUNT(*)` from its size. It records every
 * statement, so a test can assert HOW MANY round trips happened — which is the
 * actual subject of the unbounded-feed fix, not just the row count returned.
 */
class Db
{
    /** @var array<int, string> */
    public array $queries = [];

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows = [])
    {
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;

        if (stripos($sql, 'SELECT COUNT(*)') === 0) {
            return (object) ['row' => ['total' => count($this->rows)], 'rows' => []];
        }

        $slice = $this->rows;

        if (preg_match('/ LIMIT (\d+),(\d+)$/', $sql, $m) === 1) {
            $slice = array_slice($this->rows, (int) $m[1], (int) $m[2]);
        }

        return (object) ['rows' => array_values($slice), 'row' => $slice[0] ?? []];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

class Loader
{
    /** @var array<int, string> */
    public array $languagesLoaded = [];

    /** Captured $data from the last view() call — lets a test inspect what reached the template. */
    public array $viewData = [];

    public string $viewRoute = '';

    public function __construct(private Registry $registry, private string $langFile)
    {
    }

    public function language(string $route): void
    {
        $this->languagesLoaded[] = $route;
        $this->registry->get('language')->load($this->langFile);
    }

    public function model(string $route): void
    {
        // Real OpenCart registers the model as `model_<route with / and _ folded>`.
        $key = 'model_' . str_replace('/', '_', $route);

        if (!$this->registry->has($key)) {
            if ($route === 'setting/setting') {
                $model = new SettingModel();
            } elseif ($route === 'customer/customer_group') {
                $model = new CustomerGroupModel();
            } else {
                $model = new EventModel();
            }

            $this->registry->set($key, $model);
        }
    }

    /**
     * Renders a MARKER, not real Twig.
     *
     * The product-page tests assert on WHERE the marker ends up in the surrounding HTML and
     * on what disappeared from around it — that is the whole subject (does the quote CTA
     * survive removing Add to Cart?). Rendering real Twig would test Twig.
     *
     * Every call is recorded, not just the last: a single product page render now calls
     * view() for the controls and the drawer, and "which one ran" is exactly what a
     * regression here would get wrong.
     *
     * @var array<int, array{route: string, data: array<string, mixed>}>
     */
    public array $viewCalls = [];

    public function view(string $route, array $data = []): string
    {
        $this->viewRoute = $route;
        $this->viewData = $data;
        $this->viewCalls[] = ['route' => $route, 'data' => $data];

        return '<!-- rendered ' . $route . ' -->';
    }

    public function controller(string $route): string
    {
        return '<!-- ' . $route . ' -->';
    }
}

namespace Opencart\System\Library\Cart;

use Opencart\System\Engine\Registry;

/**
 * Stands in for OpenCart's real admin-session user object.
 *
 * Declared in the REAL namespace on purpose. Quotemode::isAdminPreview() does
 * `class_exists('\\Opencart\\System\\Library\\Cart\\User')` and then constructs it from the
 * Registry, exactly as core's maintenance mode does
 * (catalog/controller/startup/maintenance.php:29-31). Stubbing the METHOD instead would have
 * left that branch — the admin-preview exemption — untested, and the exemption is the thing
 * standing between a merchant and a storefront they cannot verify.
 */
class User
{
    private bool $logged;

    public function __construct(Registry $registry)
    {
        $this->logged = (bool) $registry->get('admin_user_logged');
    }

    public function isLogged(): bool
    {
        return $this->logged;
    }
}

namespace Tack\Test;

/**
 * The storefront customer. Only the three accessors the quote-only rule consults.
 *
 * Note the guest case: OpenCart leaves customer_group_id at 0 for a guest
 * (system/library/cart/customer.php:36), which is a sentinel rather than a real group — so
 * the guest is deliberately constructed with 0 here, and it is the CALLER's substitution of
 * `config_customer_group_id` that must make "specific groups" match a guest.
 */
class Customer
{
    public function __construct(private bool $logged = false, private int $groupId = 0)
    {
    }

    public function isLogged(): bool
    {
        return $this->logged;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getEmail(): string
    {
        return $this->logged ? 'buyer@acme-example.com' : '';
    }

    public function getFirstName(): string
    {
        return $this->logged ? 'Grace' : '';
    }

    public function getLastName(): string
    {
        return $this->logged ? 'Hopper' : '';
    }

    public function getTelephone(): string
    {
        return '';
    }
}

/** Two groups, the shape admin/model/customer/customer_group.php:151 returns. */
class CustomerGroupModel
{
    /** @return array<int, array<string, mixed>> */
    public function getCustomerGroups(array $data = []): array
    {
        return [
            ['customer_group_id' => 1, 'name' => 'Default'],
            ['customer_group_id' => 2, 'name' => 'Trade'],
        ];
    }
}

