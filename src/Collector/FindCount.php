<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class FindCount extends AbstractCollector
{
    public function collect(CollectorRegistry $registry)
    {
        $registry->createGauge(
            'find_count',
            array_keys($this->getGaugeLabels(null, null)),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        foreach ($this->config['paths'] ?? [] as $pathConfig) {
            if (empty($pathConfig['path'])) {
                $this->log('FindCount: Missing path. Ignoring.', 'ERROR');
                continue;
            }
            $pathConfig += ['name' => ''];

            exec(
                sprintf('find %s %s | wc -l', $pathConfig['path'], $this->optsToShellArgs($pathConfig['opts'] ?? [])),
                $output,
                $rc
            );
            if ($rc && empty($pathConfig['ignore_errors'])) {
                throw new Exception('find returned an error code: ' . $rc);
            }

            $registry->getGauge('find_count')
                ->set(intval(array_pop($output)), $this->getGaugeLabels($pathConfig['name'], $pathConfig['path']));
        }
    }

    protected function getGaugeLabels($name, $path) {
        return $this->getCommonLabels() + ['name' => $name, 'path' => $path];
    }

    protected function optsToShellArgs(array $opts) {
        $return = [];
        foreach ($opts as $opt => $value) {
            $return[] = sprintf('%s %s', escapeshellarg($opt), escapeshellarg($value));
        }

        return implode(' ', $return);
    }
}