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
        $config = Yaml::parseFile($this->getConfigPath());

        $registry = new CollectorRegistry(new InMemoryAdapter(), null, false);

        foreach ($config['collectors']['enabled'] as $collectorClass) {
            try {
                /** @var $collector CollectorInterface */
                $collector = new $collectorClass();
                $collector->init($this, $config['collectors']['configuration'][$collectorClass] ?? []);

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
    public function log($message, $level = 'DEBUG') {
        $msg = sprintf("%s [%s] %s\n", date('c'), $level, is_string($message) ? $message : json_encode($message));
        file_put_contents($this->getLogPath(), $msg, FILE_APPEND);
        if (getenv('EXPHPORTER_DEBUG')) {
            file_put_contents('php://stderr', $msg);
        }
    }

    public function getConfigPath(): string {
        if (!$configFile = getenv('EXPHPORTER_CONFIG_PATH')) {
            $configFile = EXPHPORTER_BASE_DIR . '/conf/config.yml';
        }
        if (!is_readable($configFile)) {
            throw new \Exception('Cannot read config file. Did you create one?');
        }
        return $configFile;
    }

    public function getLogPath(): string {
        if (!$logPath = getenv('EXPHPORTER_LOG_PATH')) {
            $logPath = EXPHPORTER_BASE_DIR . '/data/app.log';
        }
        return $logPath;
    }
}