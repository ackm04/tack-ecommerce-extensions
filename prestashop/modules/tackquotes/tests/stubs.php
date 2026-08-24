<?php

/**
 * Minimal PrestaShop stand-ins so tackquotes.php can be loaded and its quote-only
 * guard exercised under plain `php -f`, with no shop, no database and no vendor
 * autoloader.
 *
 * Every stub here reproduces only the behaviour the guard actually depends on, and
 * each one names the real file it was copied from so it can be re-checked when
 * PrestaShop changes. These are NOT a model of PrestaShop; do not extend them into
 * one.
 *
 * @license GPL-2.0-or-later
 */

define('_PS_VERSION_', '8.2.0');
define('TACKQUOTES_TEST_MODE', true);

/** Thrown by the Tools::redirect stub so a refusal is observable instead of fatal. */
class StubRedirect extends Exception
{
}

class Configuration
{
    /** @var array<string,mixed> persisted values (updateValue) */
    public static $persisted = [];

    /** @var array<string,mixed> request-scoped values (set) */
    public static $temporary = [];

    public static function reset()
    {
        self::$persisted = [];
        self::$temporary = [];
    }

    /** Mirrors Configuration::get() precedence: temporary cache shadows the row. */
    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        if (array_key_exists($key, self::$temporary)) {
            return self::$temporary[$key];
        }
        if (array_key_exists($key, self::$persisted)) {
            return self::$persisted[$key];
        }

        return $default;
    }

    /**
     * classes/Configuration.php:369-406 — "Set TEMPORARY a single configuration
     * value". No database write. The stub keeps the two stores separate precisely
     * so a test can assert the persistent row was NOT touched.
     */
    public static function set($key, $values, $idShopGroup = null, $idShop = null)
    {
        self::$temporary[$key] = $values;
    }

    public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null)
    {
        self::$persisted[$key] = $values;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$persisted[$key], self::$temporary[$key]);

        return true;
    }
}

class Tools
{
    /** @var array<string,mixed> */
    public static $request = [];

    /** @var string|null last URL passed to redirect() */
    public static $redirectedTo = null;

    public static function reset()
    {
        self::$request = [];
        self::$redirectedTo = null;
        $_GET = [];
        $_POST = [];
    }

    public static function getValue($key, $defaultValue = false)
    {
        if (array_key_exists($key, self::$request)) {
            return self::$request[$key];
        }
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }

        return $defaultValue;
    }

    public static function getIsset($key)
    {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }

    public static function isSubmit($key)
    {
        return self::getValue($key) !== false;
    }

    /** classes/Tools.php:1291-1294 */
    public static function getAdminToken($string)
    {
        return !empty($string) ? sha1('stub-salt' . $string) : false;
    }

    public static function redirect($url)
    {
        self::$redirectedTo = $url;

        throw new StubRedirect($url);
    }

    public static function strtoupper($str)
    {
        return strtoupper((string) $str);
    }
}

class Tab
{
    public static function getIdFromClassName($className)
    {
        return 42;
    }
}

class Customer
{
    public $id = 0;
    public $firstname = '';
    public $lastname = '';

    /** @var bool controlled by the test */
    public $stubLogged = false;

    /** @var array<int,int[]> id_customer => group ids */
    public static $groupsByCustomer = [];

    public function isLogged($withGuest = false)
    {
        return $this->stubLogged;
    }

    /** classes/Customer.php:1092-1100 */
    public static function getGroupsStatic($idCustomer)
    {
        if (isset(self::$groupsByCustomer[(int) $idCustomer])) {
            return self::$groupsByCustomer[(int) $idCustomer];
        }

        return [1]; // stands in for PS_UNIDENTIFIED_GROUP
    }
}

class Group
{
    public static $all = [
        ['id_group' => 1, 'name' => 'Visitor'],
        ['id_group' => 2, 'name' => 'Guest'],
        ['id_group' => 3, 'name' => 'Customer'],
        ['id_group' => 4, 'name' => 'Wholesale'],
    ];

    public static function getGroups($idLang, $idShop = false)
    {
        return self::$all;
    }
}

class Validate
{
    public static function isAbsoluteUrl($url)
    {
        return (bool) preg_match('/^https?:\/\/./', (string) $url);
    }
}

class StubLink
{
    public function getProductLink($id)
    {
        return 'https://shop.test/product/' . (int) $id;
    }

    public function getPageLink($page, $ssl = null)
    {
        return 'https://shop.test/' . $page;
    }

    public function getModuleLink($module, $controller, $params = [], $ssl = null)
    {
        return 'https://shop.test/module/' . $module . '/' . $controller;
    }
}

class StubContext
{
    public $customer;
    public $link;
    public $controller;
    public $language;
    public $shop;
    public $smarty;

    public function __construct()
    {
        $this->customer = new Customer();
        $this->link = new StubLink();
        $this->language = (object) ['id' => 1];
        $this->shop = (object) ['id' => 1];
        $this->smarty = new StubSmarty();
    }
}

class StubSmarty
{
    public $assigned = [];

    public function assign($vars, $value = null)
    {
        if (is_array($vars)) {
            $this->assigned = array_merge($this->assigned, $vars);
        } else {
            $this->assigned[$vars] = $value;
        }
    }
}

/** Stands in for any front controller; php_self is what the guard reads. */
class StubFrontController
{
    public $php_self = 'index';

    public function __construct($phpSelf = 'index')
    {
        $this->php_self = $phpSelf;
    }
}

class ProductController extends StubFrontController
{
    public $php_self = 'product';
}

abstract class Module
{
    public $name;
    public $tab;
    public $version;
    public $author;
    public $need_instance;
    public $bootstrap;
    public $ps_versions_compliancy;
    public $displayName;
    public $description;
    public $confirmUninstall;
    public $warning;
    public $table = 'module';
    public $identifier = 'id_module';
    public $context;

    /** @var string[] hooks passed to registerHook() */
    public $registeredHooks = [];

    /** @var string[] template paths passed to fetch() */
    public $fetched = [];

    public function __construct()
    {
        $this->context = new StubContext();
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function registerHook($hookName, $shopList = null)
    {
        $this->registeredHooks[] = $hookName;

        return true;
    }

    public function fetch($template)
    {
        $this->fetched[] = $template;

        return '<!--tackquotes-widget-->';
    }

    public function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        return $id;
    }

    public function displayError($msg)
    {
        return 'ERROR:' . $msg;
    }

    public function displayConfirmation($msg)
    {
        return 'OK:' . $msg;
    }
}
