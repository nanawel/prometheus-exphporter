<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;
class DiskUsage extends AbstractCollector
{
    const DEFAULT_UPDATE_DELAY = 10 * 60;  // 10 minutes

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
            if ($this->shouldScrape($pathConfig['path'])) {
                $opts = !empty($pathConfig['one_fs']) ? 'x' : '';
                exec("du -sb{$opts} {$pathConfig['path']}", $output, $rc);
                if ($rc && empty($pathConfig['ignore_errors'])) {
                    throw new Exception('du returned an error code: ' . $rc);
                }

                $this->saveScrapeState($pathConfig['path'], $scrapeData = [
                    'last_scrape' => time(),
                    'usage_bytes' => preg_replace('/^(\d+).*/', '$1', array_pop($output)),
                ]);
            } else {
                $scrapeData = $this->loadScrapeState($pathConfig['path']);
            }

            $registry->getGauge('disk_usage')
                ->set($scrapeData['usage_bytes'], $this->getGaugeLabels($pathConfig['path']));
        }
    }

    protected function getGaugeLabels($path) {
        return $this->getCommonLabels() + ['disk_usage_path' => $path];
    }

    /**
     * @string $path
     * @return bool
     */
    protected function shouldScrape($path) {
        foreach ($this->config['paths'] as $pathConfig) {
            if ($pathConfig['path'] === $path && isset($pathConfig['scrape_freq'])) {
                $delay = $pathConfig['scrape_freq'];
            }
        }
        $delay = $delay
            ?? $this->config['scrape_freq']
            ?? self::DEFAULT_UPDATE_DELAY;
        if (!$delay) {
            return false;
        }

        $lastScrape = $this->loadScrapeState($path)['last_scrape'] ?? 0;

        return time() - $lastScrape > $delay;
    }
}
