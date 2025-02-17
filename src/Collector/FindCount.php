<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class FindCount extends AbstractCollector
{
    public const DEFAULT_UPDATE_DELAY = 0; // No limit

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

            $command = sprintf(
                'find %s %s | wc -l',
                $pathConfig['path'],
                $this->optsToShellArgs($pathConfig['opts'] ?? [])
            );
            $stateConfigKey = md5(json_encode($pathConfig));

            unset($output);
            if ($this->shouldUpdate($stateConfigKey, $pathConfig)) {
                exec(
                    $command,
                    $output,
                    $rc
                );
                $state = [
                    'last_update' => time(),
                    'output' => $output,
                    'rc' => $rc
                ];
                $this->saveScrapeState($stateConfigKey, $state);
            } else {
                $state = $this->loadScrapeState($stateConfigKey);
                $output = $state['output'] ?? '';
                $rc = $state['rc'] ?? 0;
            }

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
            if ($value === null) {
                // Special case for valueless flags (like "-not")
                $return[] = sprintf('%s', escapeshellarg($opt));
            } else {
                $return[] = sprintf('%s %s', escapeshellarg($opt), escapeshellarg($value));
            }
        }

        return implode(' ', $return);
    }

    /**
     * @return bool
     */
    protected function shouldUpdate(string $stateConfigKey, array $pathConfig = []) {
        $delay = $pathConfig['update_freq']
            ?? $this->config['update_freq']
            ?? self::DEFAULT_UPDATE_DELAY;
        if (!$delay) {
            return true;
        }

        $lastUpdate = $this->loadScrapeState($stateConfigKey)['last_update'] ?? 0;

        return  time() - $lastUpdate > $delay;
    }
}
