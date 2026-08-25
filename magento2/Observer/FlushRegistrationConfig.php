<?php
/**
 * Drops the cached registration policy when an admin changes TackQuote's settings.
 *
 * Without this, pasting a new API key — or pointing the module at a different TackQuote
 * deployment — left the policy fetched with the OLD credentials in cache for up to an
 * hour, so the storefront form kept rendering the previous tenant's company and custom
 * fields. RegistrationConfigProvider::flush() had existed since the module's first version
 * with no caller at all.
 *
 * Flush only, deliberately: re-fetching here would put up to one 3-second API call per
 * store view inside the admin's Save request, which on a multi-store install is how a
 * settings save turns into a gateway timeout. Cron\WarmRegistrationConfig re-warms within
 * its 5-minute schedule, and until it does the form degrades to contact fields, which is
 * the same safe fallback used during a TackQuote outage.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use TackQuote\Quotes\Model\RegistrationConfigProvider;

class FlushRegistrationConfig implements ObserverInterface
{
    /**
     * @var RegistrationConfigProvider
     */
    private $registrationConfig;

    /**
     * @param RegistrationConfigProvider $registrationConfig
     */
    public function __construct(RegistrationConfigProvider $registrationConfig)
    {
        $this->registrationConfig = $registrationConfig;
    }

    /**
     * Discard every cached registration policy.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $this->registrationConfig->flush();
    }
}
