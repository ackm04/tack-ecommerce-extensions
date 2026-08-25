<?php
/**
 * Keeps the seller's registration policy in cache so no shopper ever waits for it.
 *
 * This job exists to take one outbound HTTP call out of the storefront render path.
 * Block\QuoteList renders on every page and needs the policy to decide which company and
 * custom fields to draw; it now reads cache only
 * (RegistrationConfigProvider::getCached()), and this job is what puts something there.
 *
 * SCHEDULING — the cache TTL (1 hour) is deliberately much longer than the schedule in
 * etc/crontab.xml (every 5 minutes), so in a running store the entry is rewritten long
 * before it can expire and the storefront never sees a gap. The schedule, not the TTL, is
 * therefore what bounds how stale a policy can be, and it is also what bounds the window
 * after a cache flush during which the form degrades to contact fields only.
 *
 * Unconfigured and inactive store views are skipped, so the real request volume is one
 * small GET per store view that actually has TackQuote switched on.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\RegistrationConfigProvider;

class WarmRegistrationConfig
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var RegistrationConfigProvider
     */
    private $registrationConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param RegistrationConfigProvider $registrationConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        Config $config,
        RegistrationConfigProvider $registrationConfig,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->registrationConfig = $registrationConfig;
        $this->logger = $logger;
    }

    /**
     * Refresh the cached policy for every store view that has TackQuote configured.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if (!$store->getIsActive() || !$this->config->isConfigured($storeId)) {
                continue;
            }

            /*
             * One unreachable store view must not stop the others being warmed — a cron
             * job that dies partway through leaves some storefronts silently degraded.
             * RegistrationConfigProvider::refresh() already handles an API-level failure
             * (it keeps the previous policy); this catches anything below that, such as a
             * store whose configuration cannot be read at all.
             */
            try {
                $this->registrationConfig->refresh($storeId);
            } catch (\Exception $e) {
                $this->logger->warning(
                    sprintf(
                        'TackQuote: could not warm the registration config for store %d: %s',
                        $storeId,
                        $e->getMessage()
                    )
                );
            }
        }
    }
}
