<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

interface CollectorInterface
{
    /**
     * @param array $config
     */
    public function init(array $config);

    /**
     * @return bool
     */
    public function isAvailable();

    /**
     * @param CollectorRegistry $registry
     * @return array
     */
    public function collect(CollectorRegistry $registry);
}