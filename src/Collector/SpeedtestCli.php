<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

/**
 * @see https://www.speedtest.net/fr/apps/cli
 */
class SpeedtestCli extends AbstractCollector
{
    const DEFAULT_UPDATE_DELAY = 60 * 60;  // 1 hour
    const DEFAULT_SCRAPE_NAME = 'default';

    public function isAvailable() {
        exec(sprintf('which %s', $this->getSpeedtestBinPath()), $output, $rc);

        return $rc === 0;
    }

    public function collect(CollectorRegistry $registry)
    {
        $gaugeLabels = $this->getCommonLabels() + [
            'interface_internalIp' => null,
            'interface_name' => null,
            'interface_macAddr' => null,
            'interface_externalIp' => null,
            'server_id' => null,
            'server_name' => null,
            'server_location' => null,
            'server_country' => null,
            'server_host' => null,
            'server_port' => null,
            'server_ip' => null,
            'result_id' => null,
            'result_url' => null,
        ];

        $registry->createGauge(
            'speedtest_return_code',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        $gaugeResultDataPaths = [
            'ping' => [
                'jitter',
                'latency'
            ],
            'download' => [
                'bandwidth',
                'bytes',
                'elapsed'
            ],
            'upload' => [
                'bandwidth',
                'bytes',
                'elapsed'
            ],
        ];
        foreach ($gaugeResultDataPaths as $firstLevelKey => $secondLevelKeys) {
            foreach ($secondLevelKeys as $secondLevelKey) {
                $registry->createGauge(
                    sprintf('speedtest_%s_%s', $firstLevelKey, $secondLevelKey),
                    array_keys($gaugeLabels),
                    null,
                    null,
                    CollectorRegistry::DEFAULT_STORAGE,
                    true
                );
            }
        }

        if ($this->shouldScrape()) {
            try {
                $result = json_decode($this->execCommand(), true);
                $this->saveScrapeState(self::DEFAULT_SCRAPE_NAME, $scrapeData = [
                    'last_scrape' => time(),
                    'result' => $result,
                    'return_code' => 0
                ]);
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'ERROR');

                // Hopefully this error is temporary (e.g. lock) so use latest scraped data instead
                $scrapeData = $this->loadScrapeState(self::DEFAULT_SCRAPE_NAME);
                $scrapeData['return_code'] = $e->getCode();
                $this->saveScrapeState(self::DEFAULT_SCRAPE_NAME, $scrapeData);
            }
        } else {
            $scrapeData = $this->loadScrapeState(self::DEFAULT_SCRAPE_NAME);
        }

        $registry->getGauge('speedtest_return_code')
            ->set($scrapeData['return_code'], $this->getCommonLabels());

        // Prepare labels once with results
        array_walk($gaugeLabels, function(&$v, $k) use ($scrapeData) {
            list($firstLevelKey, $secondLevelKey) = explode('_', $k);
            if (isset($scrapeData['result'][$firstLevelKey][$secondLevelKey])) {
                $v = $scrapeData['result'][$firstLevelKey][$secondLevelKey];
            }
        });

        foreach ($gaugeResultDataPaths as $firstLevelKey => $secondLevelKeys) {
            foreach ($secondLevelKeys as $secondLevelKey) {
                $registry->getGauge(sprintf('speedtest_%s_%s', $firstLevelKey, $secondLevelKey))
                    ->set(
                        $scrapeData['result'][$firstLevelKey][$secondLevelKey],
                        $gaugeLabels
                    );
            }
        }
    }

    /**
     * @return bool
     */
    protected function shouldScrape() {
        $delay = $this->config['scrape_freq']
            ?? self::DEFAULT_UPDATE_DELAY;
        if (!$delay) {
            return false;
        }

        $lastScrape = $this->loadScrapeState(self::DEFAULT_SCRAPE_NAME)['last_scrape'] ?? 0;

        return time() - $lastScrape > $delay;
    }

    /**
     * @return string
     */
    protected function execCommand() {
        $options = '-f json-pretty';

        $fullCmd = sprintf(
            '%s %s',
            $this->getSpeedtestBinPath(),
            $options
        );

        exec(
            $fullCmd,
            $output,
            $rc
        );

        $output = implode("\n", $output);

        if ($rc) {
            $this->log("$fullCmd\n$output", 'ERROR');
            throw new Exception("Command '$fullCmd' returned an error code: $rc ($output)", $rc);
        }

        return $output;
    }

    /**
     * @return string|null
     */
    protected function getSpeedtestBinPath() {
        if (empty($this->config['speedtest_path'])) {
            return 'speedtest';
        }

        return $this->config['speedtest_path'];
    }
}
