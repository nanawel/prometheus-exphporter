<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class OsRelease extends AbstractCollector
{
    public function isAvailable() {
        return is_readable('/etc/os-release');
    }

    public function collect(CollectorRegistry $registry)
    {
        $data = array_reduce(array_filter(explode("\n", file_get_contents('/etc/os-release'))), function($carry = [], $item) {
            list($key, $value) = array_map(function($el) {
                return trim($el, '"');
            }, explode('=', $item));
            $carry["os_release_{$key}"] = $value;

            return $carry;
        });

        $data += $this->getCommonLabels();

        $registry->createGauge(
            'os_release',
            array_keys($data),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        $registry->getGauge('os_release')
            ->set(1, $data);
    }
}