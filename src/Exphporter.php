<?php

namespace Arrakis\Exphporter;

use Arrakis\Exphporter\Collector\CollectorInterface;
use Symfony\Component\Yaml\Yaml;
use TweedeGolf\PrometheusClient\CollectorRegistry;
use TweedeGolf\PrometheusClient\Format\TextFormatter;
use TweedeGolf\PrometheusClient\Storage\InMemoryAdapter;

class Exphporter
{
    public function run()
    {
        if (!is_readable($configFile = EXPHPORTER_BASE_DIR . '/conf/config.yml')) {
            throw new \Exception('Cannot read config file. Did you create one?');
        }

        $config = Yaml::parseFile($configFile);

        $registry = new CollectorRegistry(new InMemoryAdapter(), null, false);

        foreach ($config['collectors']['enabled'] as $collectorClass) {
            /** @var $collector CollectorInterface */
            $collector = new $collectorClass();
            $collector->init($config['collectors']['configuration'][$collectorClass] ?? []);

            if ($collector->isAvailable()) {
                $collector->collect($registry);
            }
        }

        $formatter = new TextFormatter();
        header('Content-Type: ' . $formatter->getMimeType());
        echo $formatter->format($registry->collect());
    }
}