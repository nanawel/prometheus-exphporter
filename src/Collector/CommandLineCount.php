<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class CommandLineCount extends AbstractCollector
{
    public function collect(CollectorRegistry $registry)
    {
        $registry->createGauge(
            'command_line_count',
            array_keys($this->getGaugeLabels(null, null)),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        // Return code (0 = success, >0 = error)
        $registry->createGauge(
            'command_line_count_return_code',
            array_keys($this->getGaugeLabels(null, null)),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        foreach ($this->config['commands'] ?? [] as $commandConfig) {
            if (empty($commandConfig['command'])) {
                $this->log('CommandLineCount: Missing command. Ignoring.', 'ERROR');
                continue;
            }
            $commandConfig += ['name' => ''];

            exec(
                $commandConfig['command'],
                $output,
                $rc
            );
            if ($rc) {
                $registry->getGauge('command_line_count_return_code')
                    ->set($rc, $this->getGaugeLabels($commandConfig['name'], $commandConfig['command']));
                if (empty($commandConfig['ignore_errors'])) {
                    throw new Exception('The command returned an error code: ' . $rc);
                }
            }

            $registry->getGauge('command_line_count')
                ->set(count($output), $this->getGaugeLabels($commandConfig['name'], $commandConfig['command']));
        }
    }

    protected function getGaugeLabels($name, $path) {
        return $this->getCommonLabels() + ['name' => $name, 'command' => $path];
    }
}