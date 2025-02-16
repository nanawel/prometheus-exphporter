<?php

namespace Arrakis\Exphporter\Collector;

use Arrakis\Exphporter\Exphporter;
use TweedeGolf\PrometheusClient\CollectorRegistry;

interface CollectorInterface
{
    /**
     * @param array $config
     */
    public function init(Exphporter $exphporter, array $config);

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