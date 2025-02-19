<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class FindCountByDate extends AbstractCollector
{
    public const DEFAULT_UPDATE_DELAY = 0; // No limit

    public function collect(CollectorRegistry $registry) {
        $registry->createGauge(
            'find_count_by_date',
            array_keys($this->getGaugeLabels(null, null, null, null)),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        $pathConfigs = [];
        foreach ($this->config['paths'] ?? [] as $pathConfig) {
            if (empty($pathConfig['path'])) {
                $this->log('FindCountByDate: Missing path. Ignoring.', 'ERROR');
                continue;
            }
            $foundPaths = glob($pathConfig['path'], GLOB_ONLYDIR);
            if (empty($foundPaths)) {
                $this->log('FindCountByDate: Invalid or non-existent path. Ignoring.', 'ERROR');
                continue;
            }
            foreach ($foundPaths as $fp) {
                $pathConfigs[] = ['path' => $fp] + $pathConfig;
            }
        }

        foreach ($pathConfigs as $pathConfig) {
            $pathConfig += ['name' => ''];

            $command = sprintf('find %s %s', $pathConfig['path'], $this->optsToShellArgs($pathConfig['opts'] ?? []));
            $this->log("FindCountByDate: $command", 'DEBUG');
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

            $chunksByDate = $this->countByDate($output, $pathConfig);

            foreach ($chunksByDate as $chunk) {
                $registry->getGauge('find_count_by_date')
                    ->set(
                        intval($chunk['count']),
                        $this->getGaugeLabels(
                            $pathConfig['name'],
                            $pathConfig['path'],
                            $chunk['intervalStart'],
                            $chunk['intervalEnd']
                        )
                    );
            }

        }
    }

    protected function getGaugeLabels($name, $path, $intervalStart, $intervalEnd) {
        return $this->getCommonLabels() + [
            'name' => $name,
            'path' => $path,
            'interval_start' => $intervalStart,
            'interval_end' => $intervalEnd
        ];
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
     * @param array $files
     * @param array $pathConfig
     * @return array<int, int>
     */
    protected function countByDate(array $files, array $pathConfig): array {
        $chunksByDate = [];
        $intervalDateRef = ($pathConfig['interval_mode'] ?? 'relative') == 'absolute' ? 0 : time();
        foreach ($files as $file) {
            $fileTime = stat($file)[$pathConfig['use_stat'] ?? 'mtime'];
            $intervalStart = $this->getIntervalStart($fileTime, $pathConfig['group_by'], $intervalDateRef);

            if (!isset($chunksByDate[$intervalStart])) {
                $chunksByDate[$intervalStart] = $this->initChunk($intervalStart, $pathConfig);
            }
            $chunksByDate[$intervalStart]['count']++;
        }

        if ($pathConfig['current_only'] ?? false) {
            $currentIntervalStart = $this->getIntervalStart(time(), $pathConfig['group_by'], $intervalDateRef);

            return [
                $currentIntervalStart => $chunksByDate[$currentIntervalStart]
                    ?? $this->initChunk($currentIntervalStart, $pathConfig)
            ];
        }

        return $chunksByDate;
    }

    /**
     * @param int $date Timestamp
     * @param string $intervalDefinition
     * @param int|null $intervalDateRef Timestamp
     * @return float|int
     */
    protected function getIntervalStart(int $date, string $intervalDefinition, int $intervalDateRef = null) {
        $intervalSeconds = $this->dateIntervalToSeconds($intervalDefinition);
        $zeroDateRef = floor((int) $intervalDateRef % $intervalSeconds);
        $intervalStart = (floor((int) ($date - $zeroDateRef) / $intervalSeconds) * $intervalSeconds) + $zeroDateRef;

        return $intervalStart;
    }

    /**
     * @param string $intervalDefinition
     * @return int
     */
    protected function dateIntervalToSeconds(string $intervalDefinition): int {
        static $intervalSeconds = [];
        if (!isset($intervalSeconds[$intervalDefinition])) {
            $reference = new \DateTimeImmutable();
            $endTime = $reference->add(new \DateInterval($intervalDefinition));

            $intervalSeconds[$intervalDefinition] = $endTime->getTimestamp() - $reference->getTimestamp();
        }

        return $intervalSeconds[$intervalDefinition];
    }

    protected function initChunk(int $intervalStart, array $pathConfig) {
        return [
            'intervalStart' => $intervalStart,
            'intervalEnd' => (new \DateTime("@$intervalStart"))
                ->add((new \DateInterval($pathConfig['group_by'])))
                ->getTimestamp(),
            'count' => 0,
        ];
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
