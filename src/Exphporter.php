<?php

namespace Arrakis\Exphporter;

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
            try {
                /** @var $collector CollectorInterface */
                $collector = new $collectorClass();
                $collector->init($config['collectors']['configuration'][$collectorClass] ?? []);

                if ($collector->isAvailable()) {
                    $collector->collect($registry);
                }
            } catch (\Throwable $e) {
                $this->log("An exception occured while attempting to run collector $collectorClass", 'ERROR');
                $this->log((string) $e, 'ERROR');
            }
        }

        $formatter = new TextFormatter();
        header('Content-Type: ' . $formatter->getMimeType());
        echo $formatter->format($registry->collect());
    }

    /**
     * @param string $message
     * @param string $level
     */
    protected function log($message, $level = 'DEBUG') {
        file_put_contents(
            EXPHPORTER_BASE_DIR . '/data/app.log',
            sprintf("%s [%s] %s\n", date('c'), $level, is_string($message) ? $message : json_encode($message)),
            FILE_APPEND
        );
    }
}