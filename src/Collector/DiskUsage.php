<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class DiskUsage extends AbstractCollector
{
    public function collect(CollectorRegistry $registry)
    {
        $registry->createGauge(
            'disk_usage',
            array_keys($this->getGaugeLabels(null)),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        foreach ($this->config['paths'] ?? [] as $pathConfig) {
            $opts = !empty($pathConfig['one_fs']) ? 'x' : '';
            exec("du -sb{$opts} {$pathConfig['path']}", $output, $rc);
            if ($rc && empty($pathConfig['ignore_errors'])) {
                throw new Exception('du returned an error code: ' . $rc);
            }

            $registry->getGauge('disk_usage')
                ->set(preg_replace('/^(\d+).*/', '$1', array_pop($output)), $this->getGaugeLabels($pathConfig['path']));
        }
    }

    protected function getGaugeLabels($path) {
        return $this->getCommonLabels() + ['disk_usage_path' => $path];
    }
}