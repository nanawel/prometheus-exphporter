<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class FindCountByDate extends AbstractCollector
{
    public function collect(CollectorRegistry $registry)
    {
        $registry->createGauge(
            'find_count_by_date',
            array_keys($this->getGaugeLabels(null, null, null, null)),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        foreach ($this->config['paths'] ?? [] as $pathConfig) {
            if (empty($pathConfig['path'])) {
                $this->log('FindCountByDate: Missing path. Ignoring.', 'ERROR');
                continue;
            }
            $pathConfig += ['name' => ''];

            exec(
                sprintf('find %s %s', $pathConfig['path'], $this->optsToShellArgs($pathConfig['opts'] ?? [])),
                $output,
                $rc
            );
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
            $return[] = sprintf('%s %s', escapeshellarg($opt), escapeshellarg($value));
        }

        return implode(' ', $return);
    }

    protected function countByDate(array $files, array $pathConfig) {
        $chunksByDate = [];
        foreach ($files as $file) {
            $fileTime = stat($file)[$pathConfig['use_stat'] ?? 'mtime'];
            $intervalStart = $this->getIntervalStart($fileTime, $pathConfig['group_by']);

            if (!isset($chunksByDate[$intervalStart])) {
                $chunksByDate[$intervalStart] = $this->initChunk($intervalStart, $pathConfig);
            }
            $chunksByDate[$intervalStart]['count']++;
        }

        if ($pathConfig['current_only'] ?? false) {
            $currentIntervalStart = $this->getIntervalStart(time(), $pathConfig['group_by']);
            
            return [
                $currentIntervalStart => $chunksByDate[$currentIntervalStart]
                    ?? $this->initChunk($currentIntervalStart, $pathConfig)
            ];
        }

        return $chunksByDate;
    }

    protected function getIntervalStart($date, $intervalDefinition) {
        $intervalSeconds = $this->dateIntervalToSeconds($intervalDefinition);

        return floor($date / $intervalSeconds) * $intervalSeconds;
    }

    protected function dateIntervalToSeconds($intervalDefinition)
    {
        static $intervalSeconds = [];
        if (!isset($intervalSeconds[$intervalDefinition])) {
            $reference = new \DateTimeImmutable();
            $endTime = $reference->add(new \DateInterval($intervalDefinition));

            $intervalSeconds[$intervalDefinition] = $endTime->getTimestamp() - $reference->getTimestamp();
        }

        return $intervalSeconds[$intervalDefinition];
    }

    protected function initChunk($intervalStart, $pathConfig) {
        return [
            'intervalStart' => $intervalStart,
            'intervalEnd' => (new \DateTime("@$intervalStart"))
                ->add((new \DateInterval($pathConfig['group_by'])))
                ->getTimestamp(),
            'count' => 0,
        ];
    }
}