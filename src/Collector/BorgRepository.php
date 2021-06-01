<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class BorgRepository extends AbstractCollector
{
    const DEFAULT_UPDATE_DELAY = 60 * 60;  // 1 hour

    public function isAvailable() {
        return !empty($this->config['repositories']);
    }

    public function collect(CollectorRegistry $registry)
    {
        $this->collectRepositoryInfo($registry);
        $this->collectRepositoryContent($registry);
    }
        
    protected function collectRepositoryInfo(CollectorRegistry $registry) {
        $labels = $this->getCommonLabels() + [
            'repository_name' => null,
        ];

        // Sizes
        $sizeMetrics = [
            'total_size',
            'total_csize',
            'unique_size',
            'unique_csize',
        ];
        foreach ($sizeMetrics as $key) {
            $gaugeName = 'borg_repository_' . $key;
            $registry->createGauge(
                $gaugeName,
                array_keys($labels),
                null,
                null,
                CollectorRegistry::DEFAULT_STORAGE,
                true
            );
        }
        // Return code (0 = success, >0 = error)
        $registry->createGauge(
            'borg_repository_info_return_code',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        
        foreach ($this->config['repositories'] as $repositoryConfig) {
            $labels['repository_name'] = $repositoryConfig['name'];
            $scrapeName = $repositoryConfig['name'] . '_repository_info';

            if ($this->shouldScrape($scrapeName)) {
                try {
                    $result = json_decode($this->execCommand(
                        ['info --json', $repositoryConfig['path']], $repositoryConfig['passphrase_file']),
                        true
                    );
                    $this->saveScrapeState($scrapeName, $scrapeData = [
                        'last_scrape' => time(),
                        'metrics' => $result,
                        'return_code' => 0
                    ]);
                } catch (Exception $e) {
                    $this->log($e->getMessage(), 'ERROR');

                    // Hopefully this error is temporary (e.g. lock) so use latest scraped data instead
                    $scrapeData = $this->loadScrapeState($scrapeName);
                    $scrapeData['return_code'] = $e->getCode();
                    $this->saveScrapeState($scrapeName, $scrapeData);
                } 
            } else {
                $scrapeData = $this->loadScrapeState($scrapeName);
            }

            $registry->getGauge('borg_repository_info_return_code')
                ->set($scrapeData['return_code'], $labels);

            // Sizes
            foreach ($sizeMetrics as $key) {
                $gaugeName = 'borg_repository_' . $key;
                $registry->getGauge($gaugeName)
                    ->set($scrapeData['metrics']['cache']['stats'][$key], $labels);
            }
        }
    }

    protected function collectRepositoryContent(CollectorRegistry $registry) {
        $labels = $this->getCommonLabels() + [
            'repository_name' => null,
        ];
        
        $registry->createGauge(
            'borg_repository_archive_count',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        // Last modified (dummy metric)
        $registry->createGauge(
            'borg_repository_last_modified',
            array_merge(array_keys($labels), ['last_modified']),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        // Return code (0 = success, >0 = error)
        $registry->createGauge(
            'borg_repository_content_return_code',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        
        foreach ($this->config['repositories'] as $repositoryConfig) {
            $labels['repository_name'] = $repositoryConfig['name'];
            $scrapeName = $repositoryConfig['name'] . '_repository_content';

            if ($this->shouldScrape($scrapeName)) {
                try {
                    $result = json_decode($this->execCommand(
                        ['list --json', $repositoryConfig['path']], $repositoryConfig['passphrase_file']),
                        true
                    );
                    $this->saveScrapeState($scrapeName, $scrapeData = [
                        'last_scrape' => time(),
                        'metrics' => $result,
                        'return_code' => 0
                    ]);
                } catch (Exception $e) {
                    $this->log($e->getMessage(), 'ERROR');

                    // Hopefully this error is temporary (e.g. lock) so use latest scraped data instead
                    $scrapeData = $this->loadScrapeState($scrapeName);
                    $scrapeData['return_code'] = $e->getCode();
                    $this->saveScrapeState($scrapeName, $scrapeData);
                }
            } else {
                $scrapeData = $this->loadScrapeState($scrapeName);
            }
            
            $registry->getGauge('borg_repository_content_return_code')
                ->set($scrapeData['return_code'], $labels);

            $registry->getGauge('borg_repository_archive_count')
                ->set(count($scrapeData['metrics']['archives']), $labels);
            
            // Last modified (check the latest archive available)
            $lastModified = '';
            foreach ($scrapeData['metrics']['archives'] ?? [] as $archiveInfo) {
                if (!$lastModified || $lastModified < $archiveInfo['time']) {
                    $lastModified = $archiveInfo['time'];
                }
            }
    
            // Last modified
            $registry->getGauge('borg_repository_last_modified')
                ->set(
                    (new \DateTime($lastModified))->getTimestamp(),
                    $labels + ['last_modified' => $lastModified]
                )
            ;
        }
    }

    /**
     * @return bool
     */
    protected function shouldScrape($scrapeName) {
        $delay = $this->config['scrape_freq']
            ?? self::DEFAULT_UPDATE_DELAY;
        if (!$delay) {
            return false;
        }

        $lastScrape = $this->loadScrapeState($scrapeName)['last_scrape'] ?? 0;

        return  time() - $lastScrape > $delay;
    }

    /**
     * @param string $scrapeName
     * @return mixed
     */
    protected function loadScrapeState($scrapeName) {
        return $this->loadState()[$scrapeName] ?? [];
    }
    
    /**
     * @param string $scrapeName
     * @param mixed $data
     */
    protected function saveScrapeState($scrapeName, $data) {
        $state = $this->loadState() ?? [];
        $state[$scrapeName] = $data;
        $this->saveState($state);
    }

    /**
     * @return array
     */
    protected function getCommonLabels() {
        return parent::getCommonLabels() + [
            'borg_host' => gethostname()
        ];
    }

    /**
     * @param string|array $cmd
     * @param $passphraseFile $cmd
     * @return string
     */
    protected function execCommand($cmd, $passphraseFile) {
        if (is_array($cmd)) {
            $cmd = implode(' ', $cmd);
        }

        $fullCmd = sprintf(
            'borg %s',
            $cmd
        );

        putenv('BORG_RELOCATED_REPO_ACCESS_IS_OK=yes'); // Avoid warning with interactive confirmation
        putenv(sprintf('BORG_PASSPHRASE=%s', trim(file_get_contents($passphraseFile))));
        
        exec(
            $fullCmd,
            $output,
            $rc
        );
        
        // Clear env
        putenv('BORG_RELOCATED_REPO_ACCESS_IS_OK');
        putenv('BORG_PASSPHRASE');

        $output = implode("\n", $output);

        if ($rc) {
            $this->log(sprintf("$fullCmd\n%s", json_encode($output, JSON_PRETTY_PRINT)), 'ERROR');
            throw new Exception("Command '$fullCmd' returned an error code: $rc ($output)", $rc);
        }

        return $output;
    }
}